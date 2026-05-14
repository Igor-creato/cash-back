<?php

/**
 * Адаптер CPA-сети Advcake.
 *
 * Auth: token-in-URL. Токен хранится в `api_credentials.api_key` зашифрованным
 * и подставляется адаптером в placeholder `{token}` пути `api_actions_endpoint`.
 * OAuth2-handshake'а нет — `get_token()` возвращает расшифрованный api_key,
 * `build_auth_headers()` всегда `null` (никаких HTTP-заголовков авторизации).
 *
 * Orders API: GET {api_base_url}/export/webmaster/{token}?... — XML-ответ
 * с одной страницей за вызов (нет offset/limit; ограничение «не более 7 дней»
 * через `date_from`/`date_to` или `update_from`/`update_to`).
 *
 * Click-matching: наш `click_id` (UUIDv7 32-hex) передаётся в Advcake через
 * `sub1` при формировании партнёрской ссылки и возвращается в постбэке и
 * XML под элементом `<sub1>`.
 *
 * Stats API и shop-importer не поддерживаются в v1 — `fetch_campaigns*`
 * и `fetch_shop_tariffs()` отдают success-stub с пустым списком, чтобы
 * v12-импортёр пропустил Advcake (магазины создаются админом вручную).
 *
 * @package CashbackPlugin
 * @since   14.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Advcake_Adapter extends Cashback_Network_Adapter_Base {

    /**
     * Максимальное окно реконсилиации в днях. Advcake hard-limit: запрос с
     * `update_from`/`update_to` шире 7 дней возвращает ошибку.
     */
    private const MAX_WINDOW_DAYS = 7;

    /**
     * Дефолтный путь endpoint'а заказов с placeholder для токена.
     */
    private const DEFAULT_ACTIONS_ENDPOINT = '/export/webmaster/{token}';

    /**
     * {@inheritdoc}
     */
    public function get_slug(): string {
        return 'advcake';
    }

    /**
     * {@inheritdoc}
     */
    public function get_aliases(): array {
        return array( 'adv', 'advcake.ru' );
    }

    /**
     * {@inheritdoc}
     *
     * Advcake использует «постоянный» токен webmaster'а в URL — никакого
     * OAuth-обмена нет. Возвращаем расшифрованный api_key из credentials.
     */
    public function get_token( array $credentials, array $network_config ): ?string {
        $api_key = trim((string) ( $credentials['api_key'] ?? '' ));

        if ($api_key === '') {
            $this->last_token_error = 'Advcake api_key пуст — заполните токен в Настройках API.';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Advcake: ' . $this->last_token_error);
            return null;
        }

        // Lightweight character-set guard: токен Advcake — URL-safe строка
        // (буквы, цифры, '-', '_'). Если в credentials попало что-то с пробелами
        // или символами, ломающими URL — отказываем сразу с понятной ошибкой,
        // чтобы не получить 400/404 от api.advcake.ru.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $api_key)) {
            $this->last_token_error = 'Advcake api_key содержит недопустимые символы (ожидаются [A-Za-z0-9_-]).';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Advcake: ' . $this->last_token_error);
            return null;
        }

        return $api_key;
    }

    /**
     * {@inheritdoc}
     *
     * Advcake авторизуется токеном в URL — HTTP-заголовков авторизации нет.
     */
    public function build_auth_headers( array $credentials, array $network_config ): ?array {
        return null;
    }

    /**
     * Получить одну страницу действий из Advcake API.
     *
     * @param array $credentials   Расшифрованные credentials (`api_key`)
     * @param array $params        Параметры (`update_from`, `update_to`, `offer_id`, …)
     * @param array $network_config Строка из cashback_affiliate_networks
     * @return array ['success'=>bool,'actions'=>[],'total'=>int,'error'=>?string]
     */
    public function fetch_actions( array $credentials, array $params, array $network_config ): array {
        $token = $this->get_token($credentials, $network_config);
        if ($token === null) {
            return $this->fetch_error($this->last_token_error !== '' ? $this->last_token_error : 'Не удалось получить токен Advcake');
        }

        $base_url = rtrim((string) ( $network_config['api_base_url'] ?? 'https://api.advcake.ru' ), '/');
        $endpoint = (string) ( $network_config['api_actions_endpoint'] ?? '' );
        if ($endpoint === '') {
            $endpoint = self::DEFAULT_ACTIONS_ENDPOINT;
        }

        // Поддерживаем как path-placeholder `/export/webmaster/{token}`, так и
        // абсолютный URL в `api_actions_endpoint`. Подстановка только raw-token
        // в путь — для query-частей надо использовать rawurlencode при сборке.
        $url_path = str_replace('{token}', rawurlencode($token), $endpoint);
        $url      = preg_match('#^https?://#i', $url_path) ? $url_path : $base_url . '/' . ltrim($url_path, '/');

        $query = $this->build_query_params($params);
        if ($query !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
        }

        $response = $this->http_get($url, array());

        if (is_wp_error($response)) {
            return $this->fetch_error('HTTP error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // 401/403 — токен невалиден/отозван. Кеша токена у Advcake нет (он
        // не запрашивается заново — это просто api_key), поэтому invalidate
        // токена не имеет смысла; просто отдаём ошибку, чтобы админ обновил
        // токен в Настройках API.
        if ($code === 401 || $code === 403) {
            return $this->fetch_error("HTTP {$code}: токен Advcake отвергнут — обновите api_key в Настройках API.");
        }

        // 5xx — Advcake-side ошибка/балансер. До 2 retry с экспоненциальным
        // backoff (паттерн идентичный admitad-адаптеру).
        $retry_attempt = (int) ( $params['_retry_5xx_attempt'] ?? 0 );
        if ($code >= 500 && $code < 600 && $retry_attempt < 2) {
            $next_attempt = $retry_attempt + 1;
            $delay        = (int) apply_filters(
                'cashback_advcake_5xx_retry_delay_seconds',
                $next_attempt,
                $next_attempt,
                $code
            );
            if ($delay > 0) {
                sleep($delay);
            }

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log("Cashback Advcake: HTTP {$code} on actions, retry attempt {$next_attempt} of 2");

            $params['_retry_5xx_attempt'] = $next_attempt;
            return $this->fetch_actions($credentials, $params, $network_config);
        }

        if ($code !== 200) {
            return $this->fetch_error("HTTP {$code}: " . $this->safe_error_summary($body));
        }

        $parsed = $this->parse_xml_actions($body);
        if (!$parsed['success']) {
            return $this->fetch_error($parsed['error']);
        }

        return array(
            'success' => true,
            'actions' => $parsed['actions'],
            'total'   => count($parsed['actions']),
            'error'   => null,
        );
    }

    /**
     * {@inheritdoc}
     *
     * Advcake возвращает все действия за указанный период одним XML-ответом
     * (нет offset/limit пагинации). $max_pages в API контракте игнорируется
     * для consistency с базовым интерфейсом — фактический запрос один.
     */
    public function fetch_all_actions( array $credentials, array $params, int $max_pages, array $network_config ): array {
        unset( $max_pages ); // Advcake не пагинирует — параметр оставлен только для совместимости с интерфейсом.

        $clamped = $this->clamp_window_params($params);

        $result = $this->fetch_actions($credentials, $clamped, $network_config);

        if (!$result['success']) {
            return $result;
        }

        // Дедупликация по uniq action id (`id` XML). Безопасно перестраиваем
        // массив в нумерованный — каждая action имеет стабильный `id` от Advcake.
        $by_id = array();
        foreach ($result['actions'] as $action) {
            $action_id = isset($action['id']) ? (string) $action['id'] : '';
            if ($action_id !== '') {
                $by_id[ $action_id ] = $action;
            } else {
                $by_id[] = $action;
            }
        }

        return array(
            'success' => true,
            'actions' => array_values($by_id),
            'total'   => count($by_id),
            'error'   => null,
        );
    }

    /**
     * {@inheritdoc}
     *
     * Advcake `/stat` API возвращает агрегированную статистику по офферам,
     * но не список «подключённых программ» в формате, подходящем для shop
     * importer'а v12. В v1 интеграции магазины Advcake создаются админом
     * вручную через WC, поэтому возвращаем success-stub с пустым списком —
     * импортёр естественным образом пропустит сеть.
     */
    public function fetch_campaigns( array $credentials, array $network_config ): array {
        return array(
            'success'   => true,
            'campaigns' => array(),
            'error'     => null,
        );
    }

    /**
     * {@inheritdoc}
     *
     * См. {@see fetch_campaigns()}. В v1 stub — импорт магазинов Advcake
     * выполняется админом вручную; reuse того же пустого ответа.
     */
    public function fetch_campaigns_detailed( array $credentials, array $network_config, int $offset = 0, int $limit = 100 ): array {
        return array(
            'success'     => true,
            'campaigns'   => array(),
            'has_next'    => false,
            'next_offset' => 0,
            'error'       => null,
        );
    }

    /**
     * {@inheritdoc}
     *
     * Advcake не отдаёт per-campaign тарифы в публичном API (только
     * агрегированный bid в `/stat`). В v1 — stub.
     */
    public function fetch_shop_tariffs( array $credentials, array $network_config, string $campaign_id ): array {
        return array(
            'success' => true,
            'tariffs' => array(),
            'error'   => null,
        );
    }

    /**
     * {@inheritdoc}
     *
     * Числовые статусы Advcake (см. документацию API): 1 — новый,
     * 2 — подтверждён, 3 — отменён.
     */
    public function get_default_status_map(): array {
        return array(
            '1' => 'waiting',
            '2' => 'completed',
            '3' => 'declined',
        );
    }

    // =========================================================================
    // Внутренние helpers
    // =========================================================================

    /**
     * Собрать query-string из reconciliation-параметров.
     *
     * Поддерживаемые ключи: `date_from`, `date_to`, `update_from`, `update_to`,
     * `days`, `ids`, `offer_id`, `offer`, `basket`, `paid`, `payment_status`.
     * Все остальные — игнорируются, чтобы случайно не отправить sensitive-параметры.
     *
     * @param array $params
     * @return string
     */
    private function build_query_params( array $params ): string {
        $allowed = array(
            'date_from',
            'date_to',
            'update_from',
            'update_to',
            'days',
            'ids',
            'offer_id',
            'offer',
            'basket',
            'paid',
            'payment_status',
        );

        $query = array();
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $value = $params[ $key ];
            if ($value === '' || $value === null) {
                continue;
            }
            $query[ $key ] = is_scalar($value) ? (string) $value : '';
        }

        return http_build_query($query);
    }

    /**
     * Усечь окно реконсилиации до 7 дней (Advcake hard-limit).
     *
     * Если переданы `update_from` и `update_to` шире 7 дней — сдвигаем
     * `update_from` к `update_to - 7 days`. Тот же подход для `date_from`/`date_to`.
     *
     * @param array $params
     * @return array
     */
    private function clamp_window_params( array $params ): array {
        $pairs = array(
            array( 'update_from', 'update_to' ),
            array( 'date_from', 'date_to' ),
        );

        foreach ($pairs as $pair) {
            list( $from_key, $to_key ) = $pair;
            $from = isset($params[ $from_key ]) ? (string) $params[ $from_key ] : '';
            $to   = isset($params[ $to_key ])   ? (string) $params[ $to_key ]   : '';
            if ($from === '' || $to === '') {
                continue;
            }

            $from_ts = strtotime($from . ' 00:00:00');
            $to_ts   = strtotime($to . ' 23:59:59');
            if ($from_ts === false || $to_ts === false || $to_ts <= $from_ts) {
                continue;
            }

            $window_days = (int) floor(( $to_ts - $from_ts ) / DAY_IN_SECONDS);
            if ($window_days > self::MAX_WINDOW_DAYS) {
                $new_from         = $to_ts - ( self::MAX_WINDOW_DAYS * DAY_IN_SECONDS );
                $params[ $from_key ] = gmdate('Y-m-d', $new_from);
            }
        }

        return $params;
    }

    /**
     * Распарсить XML-ответ Advcake в нормализованный actions-array.
     *
     * Защищено от XXE через `LIBXML_NONET | LIBXML_NOCDATA` и
     * `libxml_disable_entity_loader()` (PHP < 8) — внешние сущности
     * не загружаются, DTD игнорируется.
     *
     * Возвращает массив с ключами совместимыми с Admitad-нормализацией:
     *  - `id`             — uniq action id (XML `<id>`)
     *  - `order_id`       — номер заказа партнёра (XML `<order_id>`)
     *  - `status`         — числовой статус (XML `<status>`) как строка
     *  - `payment`        — комиссия (XML `<commission>`) в float
     *  - `cart`           — сумма заказа (XML `<price>`) в float
     *  - `commission`     — alias для `payment` (для прямого field-map'инга)
     *  - `price`          — alias для `cart`
     *  - `sub1`           — наш click_id
     *  - `sub2`           — наш partner_token (если передавали)
     *  - `sub3..sub5`     — опциональные
     *  - `clicked_at`     — время клика
     *  - `date`           — дата создания заказа
     *  - `dateChange`     — дата последнего изменения
     *  - `offer_id`       — id оффера в Advcake
     *  - `offer`          — название оффера
     *  - `currency`       — валюта (по умолчанию RUB)
     *  - `payment_status` — open|on_hold|balance|processing|withdrawal|not_apply
     *  - `paid`           — yes/no
     *  - `category`, `customer`, `course`, `link_hash`, `landing_id`, `keyword` — опциональные
     *
     * @param string $body Сырой XML-ответ
     * @return array{success: bool, actions: array, error: string}
     */
    private function parse_xml_actions( string $body ): array {
        $body = trim($body);
        if ($body === '') {
            return array(
                'success' => false,
                'actions' => array(),
                'error'   => 'Empty XML body',
            );
        }

        // Минимальные требования плагина — PHP 8.0+ (см. cashback-plugin.php).
        // С 8.0 libxml не разрешает внешние сущности по умолчанию: флага
        // LIBXML_NONET достаточно для XXE-safe парсинга, никакой extra-defence
        // через libxml_disable_entity_loader() (deprecated в 8.0+) не нужен.
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return array(
                'success' => false,
                'actions' => array(),
                'error'   => 'Malformed XML response',
            );
        }

        // Корневой элемент должен называться `items`; внутри `<item>` элементы.
        $items_node = ( $xml->getName() === 'items' ) ? $xml : ( $xml->items ?? null );

        $actions = array();

        if ($items_node !== null) {
            foreach ($items_node->item as $item) {
                $action = $this->normalize_xml_item($item);
                if ($action !== null) {
                    $actions[] = $action;
                }
            }
        }

        return array(
            'success' => true,
            'actions' => $actions,
            'error'   => '',
        );
    }

    /**
     * Нормализовать один `<item>` XML в action-array.
     *
     * @param SimpleXMLElement $item
     * @return array<string, mixed>|null
     */
    private function normalize_xml_item( SimpleXMLElement $item ): ?array {
        // Минимальная sanity-проверка: либо id, либо order_id должен быть
        // непустой — иначе action бесполезен для reconciliation.
        $id       = isset($item->id) ? trim((string) $item->id) : '';
        $order_id = isset($item->order_id) ? trim((string) $item->order_id) : '';
        if ($id === '' && $order_id === '') {
            return null;
        }

        $action = array(
            'id'             => $id,
            'order_id'       => $order_id,
            'status'         => isset($item->status) ? trim((string) $item->status) : '',
            'commission'     => isset($item->commission) ? (float) $item->commission : 0.0,
            'price'          => isset($item->price) ? (float) $item->price : 0.0,
            'currency'       => isset($item->currency) ? strtoupper(trim((string) $item->currency)) : 'RUB',
            'date'           => isset($item->date) ? trim((string) $item->date) : '',
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- SimpleXMLElement property mirrors Advcake XML element name `<dateChange>`.
            'dateChange'     => isset($item->dateChange) ? trim((string) $item->dateChange) : '',
            'clicked_at'     => isset($item->clicked_at) ? trim((string) $item->clicked_at) : '',
            'click_id'       => isset($item->click_id) ? trim((string) $item->click_id) : '',
            'ip'             => isset($item->ip) ? trim((string) $item->ip) : '',
            'reason'         => isset($item->reason) ? trim((string) $item->reason) : '',
            'paid'           => isset($item->paid) ? trim((string) $item->paid) : '',
            'payment_status' => isset($item->payment_status) ? trim((string) $item->payment_status) : '',
            'bid'            => isset($item->bid) ? trim((string) $item->bid) : '',
            'offer'          => isset($item->offer) ? trim((string) $item->offer) : '',
            'offer_id'       => isset($item->offer_id) ? trim((string) $item->offer_id) : '',
            'category'       => isset($item->category) ? trim((string) $item->category) : '',
            'customer'       => isset($item->customer) ? trim((string) $item->customer) : '',
            'course'         => isset($item->course) ? trim((string) $item->course) : '',
            'link_hash'      => isset($item->link_hash) ? trim((string) $item->link_hash) : '',
            'landing_id'     => isset($item->landing_id) ? trim((string) $item->landing_id) : '',
            'keyword'        => isset($item->keyword) ? trim((string) $item->keyword) : '',
            'sub1'           => isset($item->sub1) ? trim((string) $item->sub1) : '',
            'sub2'           => isset($item->sub2) ? trim((string) $item->sub2) : '',
            'sub3'           => isset($item->sub3) ? trim((string) $item->sub3) : '',
            'sub4'           => isset($item->sub4) ? trim((string) $item->sub4) : '',
            'sub5'           => isset($item->sub5) ? trim((string) $item->sub5) : '',
        );

        // Алиасы для совместимости с api_field_for() lookup'ом, где admin
        // может оставить дефолтный map. Эти алиасы намеренно дублируют
        // канонические ключи XML — лишний overhead копейки, surface стабильности
        // для reconciliation куда важнее.
        $action['payment'] = $action['commission'];
        $action['cart']    = $action['price'];

        // funds_ready: CPA-сеть подтвердила готовность средств к выплате
        // (контракт `Cashback_API_Client::resolve_funds_ready()`). Без этого
        // флага process_ready_transactions() не начислит cashback в баланс
        // юзера, даже если order_status='completed' и api_verified=1.
        //
        // У Advcake источник истины — `<payment_status>`:
        //   open           — неподтверждённая, ещё может быть отказ
        //   on_hold        — на холде у рекламодателя
        //   balance        — согласованная (мы получим) ← READY
        //   processing     — ожидает оплаты рекламодателем ← READY
        //   withdrawal     — комиссия уже выведена нам ← READY
        //   not_apply      — не подлежит выплате
        $action['funds_ready'] = in_array(
            strtolower((string) $action['payment_status']),
            array( 'balance', 'processing', 'withdrawal' ),
            true
        ) ? 1 : 0;

        return $action;
    }

    /**
     * Формирует безопасное summary тела ответа Advcake для строк ошибок.
     *
     * Body может быть XML с заказами — нельзя логировать целиком (PII:
     * order_id, sub1=click_id, sub2=partner_token). Усекаем до первых
     * 200 символов и заменяем все элементы `<item>...</item>` плейсхолдером.
     *
     * @param mixed $body
     * @return string
     */
    private function safe_error_summary( $body ): string {
        if (!is_string($body)) {
            return 'non-string body';
        }
        $body = (string) preg_replace('#<item>.*?</item>#is', '<item>[redacted]</item>', $body);
        if (strlen($body) > 200) {
            $body = substr($body, 0, 200) . '…';
        }
        $body = trim($body);
        return $body === '' ? 'empty body' : $body;
    }
}
