<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable Generic.Formatting.DisallowMultipleStatements.SameLine,Squiz.Functions.MultiLineFunctionDeclaration.ContentAfterBrace,Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace,NormalizedArrays.Arrays.ArrayBraceSpacing.SpaceAfterArrayOpenerSingleLine,NormalizedArrays.Arrays.ArrayBraceSpacing.SpaceBeforeArrayCloserSingleLine,NormalizedArrays.Arrays.ArrayBraceSpacing.SpaceAfterArrayOpenerMultiLine,NormalizedArrays.Arrays.ArrayBraceSpacing.SpaceBeforeArrayCloserMultiLine,NormalizedArrays.Arrays.CommaAfterLast.FoundSingleLine,Universal.WhiteSpace.CommaSpacing.TooMuchSpaceAfter,WordPress.Arrays.ArrayIndentation.CloseBraceNotAligned -- Legacy file uses aligned arrays; GitModified reports these formatting sniffs inconsistently on changed aligned blocks.

/**
 * Сервис управления click-sessions (12i-2 ADR, F-10-001).
 *
 * Инкапсулирует dedup-логику через SELECT FOR UPDATE по
 * (user_id, product_id, status='active', expires_at>UTC_TIMESTAMP()), merchant policy clamp
 * (reuse_in_window / always_new / reuse_per_product) и запись сессии + tap-event'а
 * в cashback_click_sessions / cashback_click_log.
 *
 * Общий сервис для двух entry-point'ов кликов:
 *   - REST /wp-json/cashback/v1/activate (browser extension, POST, auth).
 *   - Legacy GET ?cashback_click={product_id} (traditional browser, в т.ч. гости).
 *
 * Rate-limit и idempotency-replay (client_request_id) решают разные задачи на уровне
 * transport'а: REST отвечает JSON, legacy GET — HTTP-кодами + cookie + redirect.
 * Поэтому rate-limit вшит в service (политика одинакова), а idempotency-replay
 * остаётся в caller'ах — replay'ит готовый HTTP-response.
 *
 * @since 5.1.0
 */
final class Cashback_Click_Session_Service {

    private const ACTIVATION_WINDOW = 30 * MINUTE_IN_SECONDS;

    // Rate limiting — копия политики из Cashback_REST_API / WC_Affiliate_URL_Params.
    private const RATE_LIMIT_WINDOW      = 60;
    private const RATE_PER_PRODUCT_SPAM  = 3;
    private const RATE_PER_PRODUCT_BLOCK = 10;
    private const RATE_GLOBAL_SPAM       = 10;
    private const RATE_GLOBAL_BLOCK      = 60;

    // Группа 13 iter-3: hard subnet-only cap для гостей. Защита от UA-rotation:
    // основной guest-bucket keyed по (subnet+UA-family), что attacker обходит
    // перебором Chrome/Firefox/Edge/Safari/Opera/YandexBrowser. Этот cap не шардится
    // и ловит sustained rotation. Threshold = 2.5× per-family block, чтобы не
    // сжимать легитимные cross-browser NAT-пулы ниже обычного уровня.
    private const RATE_GLOBAL_HARD_BLOCK_GUEST = 150;

    private const POLICY_ALLOWLIST = array( 'reuse_in_window', 'always_new', 'reuse_per_product' );
    private const POLICY_DEFAULT   = 'reuse_in_window';
    private const WINDOW_MIN       = 60;
    private const WINDOW_MAX       = 86400;

    /**
     * F-2-001 п.4: whitelist для имён CPA-параметров.
     *
     * Разрешены ASCII-буквы, цифры, `_`, `-`; длина 1..32. Отсекает мусорные ключи
     * (пробелы, кириллица, спецсимволы), которые добавят в affiliate URL невалидный
     * query-параметр — CPA-сеть его отбрасывает, атрибуция ломается.
     *
     * Используется в трёх save-путях (product meta, bulk network params, single param update)
     * и как defensive re-check в build_affiliate_url (на случай, если старые "грязные"
     * строки остались в БД до установки этой валидации).
     */
    private const PARAM_NAME_REGEX = '/^[a-zA-Z0-9_\-]{1,32}$/';

    private function __construct() {}

    /**
     * F-2-001 п.4: публичный whitelist-валидатор имени CPA-параметра.
     */
    public static function is_valid_affiliate_param_name( string $name ): bool {
        return (bool) preg_match(self::PARAM_NAME_REGEX, $name);
    }

    /**
     * Принимаем только http/https в финальном destination URL.
     *
     * Используется как последний барьер перед wp_redirect — отсекает javascript:,
     * data:, file: и другие schema-injection vectors.
     */
    public static function is_safe_http_url( string $url ): bool {
        if ($url === '') {
            return false;
        }
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, array( 'http', 'https' ), true);
    }

    /**
     * Активация click-session для (user_id, product_id).
     *
     * Атомарно резервирует или переиспользует сессию через SELECT FOR UPDATE,
     * пишет tap event в click_log. Rate-limit, policy/window clamp и fallback на
     * дефолты при misconfig — внутри метода.
     *
     * @param array{
     *   product_id: int,
     *   user_id: int,
     *   ip_address: string,
     *   user_agent?: ?string,
     *   referer?: ?string,
     *   client_request_id?: ?string,
     *   force_spam?: bool,
     * } $args
     *
     * @return array{
     *   status: 'ok'|'invalid_product'|'no_url'|'rate_limited'|'error',
     *   rate_status?: 'normal'|'spam'|'blocked',
     *   canonical_click_id?: string,
     *   affiliate_url?: string,
     *   session_pk?: int,
     *   tap_count?: int,
     *   is_primary?: bool,
     *   reused?: bool,
     *   cpa_network?: ?string,
     *   window_seconds?: int,
     *   client_request_id?: ?string,
     * }
     */
    public static function activate( array $args ): array {
        $product_id        = isset($args['product_id']) ? (int) $args['product_id'] : 0;
        $user_id           = isset($args['user_id']) ? (int) $args['user_id'] : 0;
        $ip_address        = isset($args['ip_address']) ? (string) $args['ip_address'] : '';
        $user_agent        = array_key_exists('user_agent', $args) ? ( $args['user_agent'] !== null ? (string) $args['user_agent'] : null ) : null;
        $referer           = array_key_exists('referer', $args) ? ( $args['referer'] !== null ? (string) $args['referer'] : null ) : null;
        $client_request_id = self::normalize_client_request_id($args['client_request_id'] ?? null);
        $force_spam        = !empty($args['force_spam']);

        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'external') {
            return array( 'status' => 'invalid_product' );
        }

        $base_url = $product->get_product_url();
        if (empty($base_url)) {
            return array( 'status' => 'no_url' );
        }

        $network_id  = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        $cpa_network = self::get_network_slug_by_id($network_id);
        $offer_id    = (string) get_post_meta($product_id, '_offer_id', true);

        // phpcs:disable Generic.Formatting.DisallowMultipleStatements.SameLine,Squiz.Functions.MultiLineFunctionDeclaration.ContentAfterBrace,Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace,NormalizedArrays.Arrays.ArrayBraceSpacing.SpaceAfterArrayOpenerSingleLine,NormalizedArrays.Arrays.ArrayBraceSpacing.SpaceBeforeArrayCloserSingleLine,NormalizedArrays.Arrays.CommaAfterLast.FoundSingleLine,Universal.WhiteSpace.CommaSpacing.TooMuchSpaceAfter -- GitModified sniffs misread legacy aligned arrays around this changed block.
        return self::do_activate(array(
            'source'            => 'wc_product',
            'product_id'        => $product_id,
            'network_id'        => $network_id,
            'cpa_network'       => $cpa_network,
            'offer_id'          => $offer_id,
            'base_url'          => $base_url,
            'user_id'           => $user_id,
            'ip_address'        => $ip_address,
            'user_agent'        => $user_agent,
            'referer'           => $referer,
            'client_request_id' => $client_request_id,
            'force_spam'        => $force_spam,
            'promocode_id'      => null,
        ));
    }

    public static function activate_for_link_checker( array $args ): array {
        $product_id        = isset($args['product_id']) ? (int) $args['product_id'] : 0;
        $target_url        = isset($args['target_url']) ? (string) $args['target_url'] : '';
        $user_id           = isset($args['user_id']) ? (int) $args['user_id'] : 0;
        $ip_address        = isset($args['ip_address']) ? (string) $args['ip_address'] : '';
        $user_agent        = array_key_exists('user_agent', $args) ? ( $args['user_agent'] !== null ? (string) $args['user_agent'] : null ) : null;
        $referer           = array_key_exists('referer', $args) ? ( $args['referer'] !== null ? (string) $args['referer'] : null ) : null;
        $client_request_id = self::normalize_client_request_id($args['client_request_id'] ?? null);
        $canonical_click_id = isset($args['canonical_click_id']) ? sanitize_text_field((string) $args['canonical_click_id']) : '';
        if ($product_id <= 0) {
            return array( 'status' => 'invalid_product' );
        }

        $safe_url = class_exists('Cashback_Link_Checker_Url_Validator')
            ? Cashback_Link_Checker_Url_Validator::validate($target_url)
            : array( 'ok' => self::is_safe_http_url($target_url) );
        if (empty($safe_url['ok'])) {
            return array( 'status' => 'no_url' );
        }

        $network_id  = isset($args['network_id']) ? (int) $args['network_id'] : (int) get_post_meta($product_id, '_affiliate_network_id', true);
        $cpa_network = isset($args['cpa_network']) && is_scalar($args['cpa_network'])
            ? sanitize_key((string) $args['cpa_network'])
            : self::get_network_slug_by_id($network_id);
        $offer_id    = isset($args['offer_id']) && is_scalar($args['offer_id'])
            ? sanitize_text_field((string) $args['offer_id'])
            : (string) get_post_meta($product_id, '_offer_id', true);

        $ctx = array(
            'source'            => 'link_checker',
            'product_id'        => $product_id,
            'network_id'        => $network_id,
            'cpa_network'       => $cpa_network,
            'offer_id'          => $offer_id,
            'base_url'          => $target_url,
            'user_id'           => $user_id,
            'ip_address'        => $ip_address,
            'user_agent'        => $user_agent,
            'referer'           => $referer,
            'client_request_id' => $client_request_id,
            'canonical_click_id' => $canonical_click_id,
            'force_spam'        => !empty($args['force_spam']),
            'promocode_id'      => null,
            'utm_source'        => 'link_checker',
            'utm_medium'        => isset($args['utm_medium']) ? (string) $args['utm_medium'] : 'shortcode',
            'utm_campaign'      => isset($args['utm_campaign']) ? (string) $args['utm_campaign'] : '',
        );

        return self::do_activate($ctx);
    }

    /**
     * Активация click-session для прямой ссылки на товар магазина.
     *
     * Deeplink уже создан caller'ом через CPA API с заранее сгенерированным
     * click_id, поэтому здесь переиспользуется общий TX/log flow без повторной
     * подстановки tracking-параметров.
     *
     * @param array{
     *   product_id: int,
     *   network_id: int,
     *   direct_url: string,
     *   affiliate_url: string,
     *   canonical_click_id: string,
     *   user_id: int,
     *   ip_address: string,
     *   user_agent?: ?string,
     *   referer?: ?string,
     *   client_request_id?: ?string,
     *   force_spam?: bool,
     * } $args
     *
     * @return array see activate()
     */
    public static function activate_for_direct_url( array $args ): array {
        $product_id         = isset($args['product_id']) ? (int) $args['product_id'] : 0;
        $network_id         = isset($args['network_id']) ? (int) $args['network_id'] : 0;
        $direct_url         = isset($args['direct_url']) ? (string) $args['direct_url'] : '';
        $affiliate_url      = isset($args['affiliate_url']) ? (string) $args['affiliate_url'] : '';
        $canonical_click_id = isset($args['canonical_click_id']) ? sanitize_text_field((string) $args['canonical_click_id']) : '';
        $user_id            = isset($args['user_id']) ? (int) $args['user_id'] : 0;
        $ip_address         = isset($args['ip_address']) ? (string) $args['ip_address'] : '';
        $user_agent         = array_key_exists('user_agent', $args) ? ( $args['user_agent'] !== null ? (string) $args['user_agent'] : null ) : null;
        $referer            = array_key_exists('referer', $args) ? ( $args['referer'] !== null ? (string) $args['referer'] : null ) : null;
        $client_request_id  = self::normalize_client_request_id($args['client_request_id'] ?? null);

        if ($product_id <= 0 || $network_id <= 0 || $canonical_click_id === '') {
            return array( 'status' => 'invalid_product' );
        }

        if (!self::is_safe_http_url($direct_url) || !self::is_safe_http_url($affiliate_url)) {
            return array( 'status' => 'no_url' );
        }

        return self::do_activate(array(
            'source'             => 'direct_product_url',
            'product_id'         => $product_id,
            'network_id'         => $network_id,
            'cpa_network'        => self::get_network_slug_by_id($network_id),
            'offer_id'           => (string) get_post_meta($product_id, '_offer_id', true),
            'base_url'           => $direct_url,
            'affiliate_url'      => $affiliate_url,
            'canonical_click_id' => $canonical_click_id,
            'user_id'            => $user_id,
            'ip_address'         => $ip_address,
            'user_agent'         => $user_agent,
            'referer'            => $referer,
            'client_request_id'  => $client_request_id,
            'force_spam'         => !empty($args['force_spam']),
            'promocode_id'       => null,
            'utm_source'         => 'link_checker',
            'utm_medium'         => 'shortcode',
            'utm_campaign'       => '',
        ));
    }

    /**
     * Активация click-session для клика по карточке промокода.
     *
     * Тот же return-shape, что и у activate(). Использует тот же TX-сценарий
     * (FOR UPDATE dedup на click_sessions, log_click), но base_url берётся из
     * goto_link купона, а network_id — из строки промокода.
     *
     * Требования к caller'у:
     *   - product_id ОБЯЗАТЕЛЕН (cashback_click_log.product_id NOT NULL); это
     *     source product (товар, к которому привязан промокод по network+offer).
     *   - goto_link ОБЯЗАТЕЛЕН и должен быть валидным http/https URL.
     *   - network_id > 0 (для подстановки CPA-параметров).
     *
     * @param array{
     *   promocode_id: int,
     *   product_id: int,
     *   network_id: int,
     *   goto_link: string,
     *   user_id: int,
     *   ip_address: string,
     *   user_agent?: ?string,
     *   referer?: ?string,
     *   client_request_id?: ?string,
     *   force_spam?: bool,
     * } $args
     *
     * @return array see activate()
     */
    public static function activate_for_promocode( array $args ): array {
        $promocode_id      = isset($args['promocode_id']) ? (int) $args['promocode_id'] : 0;
        $product_id        = isset($args['product_id']) ? (int) $args['product_id'] : 0;
        $network_id        = isset($args['network_id']) ? (int) $args['network_id'] : 0;
        $goto_link         = isset($args['goto_link']) ? (string) $args['goto_link'] : '';
        $user_id           = isset($args['user_id']) ? (int) $args['user_id'] : 0;
        $ip_address        = isset($args['ip_address']) ? (string) $args['ip_address'] : '';
        $user_agent        = array_key_exists('user_agent', $args) ? ( $args['user_agent'] !== null ? (string) $args['user_agent'] : null ) : null;
        $referer           = array_key_exists('referer', $args) ? ( $args['referer'] !== null ? (string) $args['referer'] : null ) : null;
        $client_request_id = self::normalize_client_request_id($args['client_request_id'] ?? null);
        $force_spam        = !empty($args['force_spam']);

        if ($promocode_id <= 0 || $product_id <= 0 || $network_id <= 0) {
            return array( 'status' => 'invalid_product' );
        }

        if ($goto_link === '' || !self::is_safe_http_url($goto_link)) {
            return array( 'status' => 'no_url' );
        }

        $cpa_network = self::get_network_slug_by_id($network_id);
        $offer_id    = (string) get_post_meta($product_id, '_offer_id', true);

        return self::do_activate(array(
            'source'            => 'promocode',
            'product_id'        => $product_id,
            'network_id'        => $network_id,
            'cpa_network'       => $cpa_network,
            'offer_id'          => $offer_id,
            'base_url'          => $goto_link,
            'user_id'           => $user_id,
            'ip_address'        => $ip_address,
            'user_agent'        => $user_agent,
            'referer'           => $referer,
            'client_request_id' => $client_request_id,
            'force_spam'        => $force_spam,
            'promocode_id'      => $promocode_id,
        ));
    }

    private static function normalize_client_request_id( mixed $value ): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = sanitize_text_field((string) $value);
        if ($normalized === '') {
            return null;
        }

        $compact_uuid = str_replace('-', '', $normalized);
        if (preg_match('/^[A-Fa-f0-9]{32}$/', $compact_uuid)) {
            return strtolower($compact_uuid);
        }

        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $normalized);
        if (!is_string($safe) || $safe === '') {
            return null;
        }

        if (strlen($safe) <= 32) {
            return $safe;
        }

        return substr(hash('sha256', $safe), 0, 32);
    }

    /**
     * Общий TX-блок активации click-session.
     *
     * @param array{
     *   source: 'wc_product'|'promocode'|'link_checker'|'direct_product_url',
     *   product_id: int,
     *   network_id: int,
     *   cpa_network: ?string,
     *   offer_id: string,
     *   base_url: string,
     *   affiliate_url?: string,
     *   canonical_click_id?: string,
     *   user_id: int,
     *   ip_address: string,
     *   user_agent: ?string,
     *   referer: ?string,
     *   client_request_id: ?string,
     *   force_spam: bool,
     *   promocode_id: ?int,
     *   utm_source?: string,
     *   utm_medium?: string,
     *   utm_campaign?: string,
     * } $ctx
     */
    private static function do_activate( array $ctx ): array {
        global $wpdb;

        $product_id        = (int) $ctx['product_id'];
        $network_id        = (int) $ctx['network_id'];
        $cpa_network       = $ctx['cpa_network'];
        $offer_id          = trim((string) ($ctx['offer_id'] ?? ''));
        $offer_key         = class_exists('Cashback_Offer_Key') && is_string($cpa_network)
            ? Cashback_Offer_Key::from_parts($cpa_network, $offer_id)
            : null;
        $base_url          = (string) $ctx['base_url'];
        $user_id           = (int) $ctx['user_id'];
        $ip_address        = (string) $ctx['ip_address'];
        $user_agent        = $ctx['user_agent'];
        $referer           = $ctx['referer'];
        $client_request_id = $ctx['client_request_id'];
        $force_spam        = (bool) $ctx['force_spam'];

        $rate_status = self::get_click_rate_status($user_id, $ip_address, (string) ( $user_agent ?? '' ), $product_id);
        if ($rate_status === 'blocked') {
            return array(
                'status'      => 'rate_limited',
                'rate_status' => $rate_status,
            );
        }

        // CPA-сеть + merchant policy.
        // Защита от провала расшифровки credentials (напр. пост-rotation, когда
        // api_credentials в БД зашифрованы retired-ключом): get_network_config()
        // кидает RuntimeException из Cashback_Encryption::decrypt(). Для activate
        // credentials не нужны — нужны только policy/window, которые имеют
        // fail-safe дефолты. Глотаем исключение, чтобы активация не падала в 500
        // из-за проблемы ключей, требующей действия администратора.
        $merchant_config = null;
        if ($cpa_network) {
            try {
                $merchant_config = Cashback_API_Client::get_instance()->get_network_config($cpa_network);
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                error_log('[Cashback Click Session] get_network_config(' . $cpa_network . ') failed, falling back to defaults: ' . $e->getMessage());
                $merchant_config = null;
            }
        }

        // 12i-2 ADR (F-10-001): fail-safe clamp policy/window — защита от misconfig.
        $policy = (string) ( $merchant_config['click_session_policy'] ?? self::POLICY_DEFAULT );
        if (!in_array($policy, self::POLICY_ALLOWLIST, true)) {
            $policy = self::POLICY_DEFAULT;
        }
        $window = (int) ( $merchant_config['activation_window_seconds'] ?? self::ACTIVATION_WINDOW );
        if ($window < self::WINDOW_MIN || $window > self::WINDOW_MAX) {
            $window = self::ACTIVATION_WINDOW;
        }
        $merchant_id = isset($merchant_config['id']) ? (int) $merchant_config['id'] : 0;

        // Гости (user_id=0) не должны шарить сессию по общей dedup-строке `(user_id=0, product_id)`:
        // два независимых гостя на одном product получили бы одну сессию. Принудительно always_new.
        if ($user_id <= 0) {
            $policy = 'always_new';
        }
        if ((string) ( $ctx['source'] ?? '' ) === 'direct_product_url') {
            $policy = 'always_new';
        }

        $spam_flag  = ( $rate_status === 'spam' || $force_spam ) ? 1 : 0;
        $sessions_t = $wpdb->prefix . 'cashback_click_sessions';
        $click_log_t    = $wpdb->prefix . 'cashback_click_log';
        $current_source = (string) ( $ctx['source'] ?? '' );

        // v11: dedup-key включает promocode_id, чтобы промо-клик не reuse-ил
        // товарную session (был баг — купонный goto_link терялся, в click_log
        // писался товарный affiliate_url). NULL для product-кликов остаётся
        // backwards-совместимо с product-сессиями до v11.
        $promocode_id_for_dedup = isset($ctx['promocode_id']) && (int) $ctx['promocode_id'] > 0
            ? (int) $ctx['promocode_id']
            : null;

        // 12i-2 ADR (F-10-001): TX + SELECT FOR UPDATE на click_sessions.
        // Параллельные /activate от одного user'а на тот же product получают
        // одну и ту же сессию через row-lock (без dedup дали бы 2 сессии).
        $wpdb->query('START TRANSACTION');
        try {
            $existing = null;
            if ($policy !== 'always_new') {
                // expires_at пишется через gmdate() (UTC), поэтому сравнение тоже в UTC:
                // NOW() возвращает server local time — timezone mismatch рушит dedup
                // (production bug: каждый клик создавал новую сессию при server != UTC).
                // wpdb prepare() с %d не умеет NULL → две explicit ветки.
                if ($promocode_id_for_dedup === null) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- FOR UPDATE inside TX (Group 8 pattern).
                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT s.id, s.canonical_click_id, s.affiliate_url, s.tap_count
                           FROM %i s
                          WHERE s.user_id = %d AND s.product_id = %d
                            AND s.promocode_id IS NULL
                            AND s.status = 'active' AND s.expires_at > UTC_TIMESTAMP()
                            AND NOT (
                                %s = 'wc_product'
                                AND EXISTS (
                                    SELECT 1
                                      FROM %i l
                                     WHERE l.click_session_id = s.id
                                       AND l.is_session_primary = 1
                                       AND l.utm_source = %s
                                )
                            )
                          ORDER BY s.created_at DESC LIMIT 1
                          FOR UPDATE",
                        $sessions_t,
                        $user_id,
                        $product_id,
                        $current_source,
                        $click_log_t,
                        'link_checker'
                    ), ARRAY_A);
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- FOR UPDATE inside TX (Group 8 pattern).
                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT s.id, s.canonical_click_id, s.affiliate_url, s.tap_count
                           FROM %i s
                          WHERE s.user_id = %d AND s.product_id = %d
                            AND s.promocode_id = %d
                            AND s.status = 'active' AND s.expires_at > UTC_TIMESTAMP()
                            AND NOT (
                                %s = 'wc_product'
                                AND EXISTS (
                                    SELECT 1
                                      FROM %i l
                                     WHERE l.click_session_id = s.id
                                       AND l.is_session_primary = 1
                                       AND l.utm_source = %s
                                )
                            )
                          ORDER BY s.created_at DESC LIMIT 1
                          FOR UPDATE",
                        $sessions_t,
                        $user_id,
                        $product_id,
                        $promocode_id_for_dedup,
                        $current_source,
                        $click_log_t,
                        'link_checker'
                    ), ARRAY_A);
                }
            }

            if (is_array($existing) && !empty($existing['id'])) {
                // Reuse: inc tap_count + last_tap_at = UTC (match gmdate-written expires_at).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Update inside TX.
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET tap_count = tap_count + 1, last_tap_at = UTC_TIMESTAMP(6) WHERE id = %d',
                    $sessions_t,
                    (int) $existing['id']
                ));

                $canonical_click_id = (string) $existing['canonical_click_id'];
                $affiliate_url      = (string) $existing['affiliate_url'];
                $session_pk         = (int) $existing['id'];
                $tap_count          = ( (int) $existing['tap_count'] ) + 1;
                $is_primary         = false;
                $reused             = true;
            } else {
                // New session: canonical_click_id = UUID v7 (time-ordered), unless
                // caller generated it first for a CPA deeplink API.
                $canonical_click_id = !empty($ctx['canonical_click_id'])
                    ? sanitize_text_field((string) $ctx['canonical_click_id'])
                    : cashback_generate_uuid7(false);
                $affiliate_url      = !empty($ctx['affiliate_url'])
                    ? (string) $ctx['affiliate_url']
                    : self::build_affiliate_url_with_parts($base_url, $network_id, $product_id, $user_id, $canonical_click_id);
                if (empty($affiliate_url)) {
                    $affiliate_url = $base_url;
                }

                $expires_datetime = gmdate('Y-m-d H:i:s.u', time() + $window);

                // wpdb prepare с %d не умеет NULL → две explicit ветки INSERT
                // (для product-клика promocode_id=NULL, для промо — int).
                if ($promocode_id_for_dedup === null) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert inside TX.
                    $wpdb->query($wpdb->prepare(
                        'INSERT INTO %i
                            (canonical_click_id, user_id, product_id, promocode_id, merchant_id,
                             affiliate_url, status, tap_count, expires_at)
                         VALUES (%s, %d, %d, NULL, %d, %s, %s, %d, %s)',
                        $sessions_t,
                        $canonical_click_id,
                        $user_id,
                        $product_id,
                        $merchant_id,
                        $affiliate_url,
                        'active',
                        1,
                        $expires_datetime
                    ));
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert inside TX.
                    $wpdb->query($wpdb->prepare(
                        'INSERT INTO %i
                            (canonical_click_id, user_id, product_id, promocode_id, merchant_id,
                             affiliate_url, status, tap_count, expires_at)
                         VALUES (%s, %d, %d, %d, %d, %s, %s, %d, %s)',
                        $sessions_t,
                        $canonical_click_id,
                        $user_id,
                        $product_id,
                        $promocode_id_for_dedup,
                        $merchant_id,
                        $affiliate_url,
                        'active',
                        1,
                        $expires_datetime
                    ));
                }
                $session_pk = (int) $wpdb->insert_id;
                $tap_count  = 1;
                $is_primary = true;
                $reused     = false;
            }

            // Tap event: логируем каждый запрос в click_log, даже если сессия reuse.
            // Для primary тапа click_id == canonical_click_id; для повторов — свой UUID.
            $tap_click_id = $is_primary ? $canonical_click_id : cashback_generate_uuid7(false);
            $promocode_id = isset($ctx['promocode_id']) && $ctx['promocode_id'] !== null
                ? (int) $ctx['promocode_id']
                : null;
            $log_data = array(
                'click_id'           => $tap_click_id,
                'click_session_id'   => $session_pk,
                'client_request_id'  => $client_request_id,
                'is_session_primary' => $is_primary ? 1 : 0,
                'user_id'            => $user_id,
                'product_id'         => $product_id,
                'cpa_network'        => $cpa_network,
                'offer_id'           => $offer_id,
                'offer_key'          => $offer_key,
                'affiliate_url'      => $affiliate_url,
                'ip_address'         => $ip_address,
                'user_agent'         => $user_agent,
                'spam_click'         => $spam_flag,
                'referer'            => $referer,
                'promocode_id'       => $promocode_id,
            );
            $log_data['utm_source']   = (string) ( $ctx['utm_source'] ?? '' );
            $log_data['utm_medium']   = (string) ( $ctx['utm_medium'] ?? '' );
            $log_data['utm_campaign'] = (string) ( $ctx['utm_campaign'] ?? '' );
            self::log_click($log_data);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Click Session] activate TX error: ' . get_class($e) . ' ' . $e->getMessage());
            return array( 'status' => 'error' );
        }

        return array(
            'status'             => 'ok',
            'rate_status'        => $rate_status,
            'canonical_click_id' => $canonical_click_id,
            'affiliate_url'      => $affiliate_url,
            'session_pk'         => $session_pk,
            'tap_count'          => $tap_count,
            'is_primary'         => $is_primary,
            'reused'             => $reused,
            'cpa_network'        => $cpa_network,
            'window_seconds'     => $window,
            'client_request_id'  => $client_request_id,
        );
    }

    /**
     * Rate-limit статус (NAT-safe, Группа 13).
     *
     * 2 уровня: узкий (subject+product) и глобальный (subject). Subject:
     *   - Авторизованный ($user_id>0): user_id → квота персональная (NAT-safe).
     *   - Гость: /24 (IPv4) или /64 (IPv6) + UA-family → NAT-пул делит bucket,
     *     разные браузерные семейства изолированы. Fallback на raw IP если
     *     extract_subnet() вернул пусто (невалидный IP).
     *
     * @return 'normal'|'spam'|'blocked'
     */
    private static function get_click_rate_status( int $user_id, string $ip_address, string $user_agent, int $product_id ): string {
        $window = self::RATE_LIMIT_WINDOW;

        $hard_subnet = '';
        if ($user_id > 0) {
            $subject = 'u:' . $user_id;
        } else {
            $hard_subnet = class_exists('Cashback_Affiliate_Service')
                ? \Cashback_Affiliate_Service::extract_subnet($ip_address)
                : '';
            $family = class_exists('Cashback_Affiliate_Service')
                ? \Cashback_Affiliate_Service::normalize_ua($user_agent)['family']
                : 'unknown';
            $subject = ($hard_subnet !== '') ? ('n:' . $hard_subnet . '|' . $family) : ('r:' . $ip_address . '|' . $family);
        }

        $pp_scope = 'pp_' . substr(sha1($subject . '|' . $product_id), 0, 40);
        $gl_scope = 'gl_' . substr(sha1($subject), 0, 40);

        if (!class_exists('Cashback_Rate_Limiter')) {
            require_once __DIR__ . '/class-cashback-rate-limiter.php';
        }

        try {
            $counter = \Cashback_Rate_Limiter::counter_backend();
            $pp      = $counter->increment($pp_scope, $window, self::RATE_PER_PRODUCT_BLOCK);
            $gl      = $counter->increment($gl_scope, $window, self::RATE_GLOBAL_BLOCK);

            // Hard subnet-only cap для гостей — defeats UA-rotation bypass (iter-3).
            // Неделится по UA-family: attacker'а, перебирающего Chrome/FF/Edge/…, ловит общий cap.
            // Для залогиненных не нужен — scope уже `u:<uid>`, один бюджет на человека.
            $hard = null;
            if ($user_id === 0 && $hard_subnet !== '') {
                $hard_scope = 'gh_' . substr(sha1('h:' . $hard_subnet), 0, 40);
                $hard       = $counter->increment($hard_scope, $window, self::RATE_GLOBAL_HARD_BLOCK_GUEST);
            }
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Rate-limit backend diagnostic (group 7, step 7).
            error_log('[cashback-click-session] rate-limit backend error: ' . $e->getMessage());

            return 'normal';
        }

        $pp_hits   = (int) $pp['hits'];
        $gl_hits   = (int) $gl['hits'];
        $hard_hits = is_array($hard) ? (int) $hard['hits'] : 0;

        if ($pp_hits >= self::RATE_PER_PRODUCT_BLOCK
            || $gl_hits >= self::RATE_GLOBAL_BLOCK
            || $hard_hits >= self::RATE_GLOBAL_HARD_BLOCK_GUEST
        ) {
            return 'blocked';
        }

        if ($pp_hits > self::RATE_PER_PRODUCT_SPAM || $gl_hits > self::RATE_GLOBAL_SPAM) {
            return 'spam';
        }

        return 'normal';
    }

    /**
     * Построение affiliate URL с подстановкой CPA-параметров.
     *
     * Универсальная версия: принимает base_url + network_id напрямую, чтобы
     * можно было использовать как для WC external product (base = product_url),
     * так и для промокода (base = goto_link купона).
     *
     * Если product_id > 0 — мерджит postmeta `_affiliate_product_params` поверх
     * сетевых (overrides по ключу + добавление новых). Для промокодов передаём
     * source product_id, к которому привязан купон по network+offer.
     */
    private static function build_affiliate_url_with_parts( string $base_url, int $network_id, ?int $product_id, int $user_id, string $click_id ): string {
        $params = self::build_affiliate_tracking_params($network_id, $product_id, $user_id, $click_id);
        if (empty($params)) {
            return $base_url;
        }

        return add_query_arg($params, $base_url);
    }

    /**
     * Возвращает tracking-параметры CPA-сети для уже созданного click_id.
     *
     * Имена параметров берутся только из `cashback_affiliate_network_params`
     * и product-level overrides `_affiliate_product_params`; код не добавляет
     * fallback-имён вроде subid/sub1.
     *
     * @return array<string,string>
     */
    public static function build_affiliate_tracking_params( int $network_id, ?int $product_id, int $user_id, string $click_id ): array {
        global $wpdb;

        if ($network_id <= 0) {
            return array();
        }

        // Сначала загружаем параметры сети
        $params_table = $wpdb->prefix . 'cashback_affiliate_network_params';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
        $network_rows = $wpdb->get_results($wpdb->prepare(
            'SELECT param_name, param_type
             FROM %i
             WHERE network_id = %d
             ORDER BY id ASC',
            $params_table,
            $network_id
        ), ARRAY_A);

        $merged       = array();
        $key_to_index = array();
        foreach ($network_rows as $i => $row) {
            $merged[ $i ]                       = array(
                'key'   => $row['param_name'],
                'value' => $row['param_type'],
            );
            $key_to_index[ $row['param_name'] ] = $i;
        }

        // Мерж индивидуальных параметров товара: переопределяют или дополняют сетевые
        if ($product_id !== null && $product_id > 0) {
            $product_params = get_post_meta($product_id, '_affiliate_product_params', true);
            if (is_array($product_params) && !empty($product_params)) {
                foreach ($product_params as $pp) {
                    if (empty($pp['key'])) {
                        continue;
                    }
                    if (isset($key_to_index[ $pp['key'] ])) {
                        $merged[ $key_to_index[ $pp['key'] ] ]['value'] = $pp['value'];
                    } else {
                        $merged[] = array(
                            'key'   => $pp['key'],
                            'value' => $pp['value'],
                        );
                    }
                }
            }
        }

        if (empty($merged)) {
            return array();
        }

        // Получаем partner_token (криптографически стойкий, вместо user_id)
        $partner_token = null;
        if ($user_id > 0 && class_exists('Mariadb_Plugin')) {
            $partner_token = Mariadb_Plugin::get_partner_token($user_id);
        }

        $params = array();
        foreach ($merged as $param) {
            if (empty($param['key']) || empty($param['value'])) {
                continue;
            }

            // F-2-001 п.4: defensive re-check — admin save-paths валидируют param_name
            // через whitelist, но данные в БД могли прийти из до-whitelist периода.
            if (!self::is_valid_affiliate_param_name((string) $param['key'])) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: grep [Cashback Click Session] Rejected invalid param name.
                error_log('[Cashback Click Session] Rejected invalid affiliate param name: ' . substr((string) $param['key'], 0, 80));
                continue;
            }

            $param_type = strtolower(trim($param['value']));

            if ($param_type === 'user') {
                // partner_token вместо user_id — защита от IDOR и перебора
                $params[ $param['key'] ] = $partner_token !== null ? $partner_token : 'unregistered';
            } elseif ($param_type === 'uuid') {
                $params[ $param['key'] ] = $click_id;
            } else {
            $params[ (string) $param['key'] ] = (string) $param['value'];
            }
        }

        return $params;
    }

    /**
     * Получение slug CPA-сети по ID сети.
     */
    private static function get_network_slug_by_id( int $network_id ): ?string {
        global $wpdb;

        if ($network_id <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'cashback_affiliate_networks';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
        $slug = $wpdb->get_var($wpdb->prepare(
            'SELECT slug FROM %i WHERE id = %d AND is_active = 1',
            $table,
            $network_id
        ));

        return $slug ?: null;
    }

    /**
     * Логирование клика в cashback_click_log.
     *
     * Использует wpdb::insert (а не raw INSERT) ради корректной поддержки
     * nullable полей (promocode_id для WC-flow = NULL, для promo-flow = id).
     * 12i-2 ADR (F-10-001): click_session_id / client_request_id / is_session_primary
     * пишутся явно; legacy-вызовы без этих полей получают NULL/0 (backward-compat).
     */
    private static function log_click( array $data ): bool {
        global $wpdb;

        $table      = $wpdb->prefix . 'cashback_click_log';
        $created_at = ( new \DateTimeImmutable('now', new \DateTimeZone('UTC')) )->format('Y-m-d H:i:s.u');

        $row = array(
            'click_id'           => (string) $data['click_id'],
            'click_session_id'   => isset($data['click_session_id']) ? (int) $data['click_session_id'] : null,
            'client_request_id'  => self::normalize_client_request_id($data['client_request_id'] ?? null),
            'is_session_primary' => !empty($data['is_session_primary']) ? 1 : 0,
            'user_id'            => (int) $data['user_id'],
            'product_id'         => (int) $data['product_id'],
            'cpa_network'        => (string) ( $data['cpa_network'] ?? '' ),
            'offer_id'           => (string) ( $data['offer_id'] ?? '' ),
            'offer_key'          => (string) ( $data['offer_key'] ?? '' ),
            'affiliate_url'      => (string) ( $data['affiliate_url'] ?? '' ),
            'ip_address'         => (string) ( $data['ip_address'] ?? '' ),
            'user_agent'         => (string) ( $data['user_agent'] ?? '' ),
            'referer'            => (string) ( $data['referer'] ?? '' ),
            'spam_click'         => (int) ( $data['spam_click'] ?? 0 ),
            'created_at'         => $created_at,
        );
        $fmt = array( '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );
        if (isset($data['utm_source']) && (string) $data['utm_source'] !== '') {
            $row['utm_source'] = sanitize_text_field((string) $data['utm_source']);
            $fmt[]             = '%s';
        }

        if (isset($data['utm_medium']) && (string) $data['utm_medium'] !== '') {
            $row['utm_medium'] = sanitize_text_field((string) $data['utm_medium']);
            $fmt[]             = '%s';
        }

        if (isset($data['utm_campaign']) && (string) $data['utm_campaign'] !== '') {
            $row['utm_campaign'] = sanitize_text_field((string) $data['utm_campaign']);
            $fmt[]               = '%s';
        }

        // promocode_id (v10) — опциональное поле, передаём только при наличии чтобы
        // INSERT не падал на старых БД до миграции v10.
        if (isset($data['promocode_id']) && $data['promocode_id'] !== null && (int) $data['promocode_id'] > 0) {
            $row['promocode_id'] = (int) $data['promocode_id'];
            $fmt[]               = '%d';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; insert is the operation.
        $result = $wpdb->insert($table, $row, $fmt);

        if (false === $result) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Click Session] Failed to log click: ' . $wpdb->last_error);
            return false;
        }

        return true;
    }
}
