<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Link_Checker_Service {

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function check( string $url, int $user_id = 0 ): array|WP_Error {
        $validated = Cashback_Link_Checker_Url_Validator::validate($url);
        if (!$validated['ok']) {
            return new WP_Error(
                (string) $validated['error'],
                'Ссылка не прошла проверку.',
                array( 'status' => $validated['error'] === 'empty_url' ? 400 : 422 )
            );
        }

        $merchant = $this->find_merchant_by_host((string) $validated['host']);
        if ($merchant === null) {
            return $this->not_available(
                (string) $validated['url'],
                (string) $validated['host'],
                'Этот магазин пока не подключён к кэшбэку Savello.'
            );
        }

        $store = $this->format_store($merchant);
        if (!$this->is_merchant_active($merchant)) {
            return array(
                'status'              => 'not_available',
                'cashback_available'  => false,
                'activation_required' => false,
                'url'                 => $validated['url'],
                'host'                => $validated['host'],
                'store'               => $store,
                'cashback'            => null,
                'conditions'          => array(),
                'message'             => 'Кэшбэк для этого магазина временно недоступен.',
            );
        }

        $cashback = $this->cashback_payload((int) $merchant['ID'], $user_id);
        if ($cashback === null) {
            return array(
                'status'              => 'partner_no_commission',
                'cashback_available'  => false,
                'activation_required' => false,
                'url'                 => $validated['url'],
                'host'                => $validated['host'],
                'store'               => $store,
                'cashback'            => null,
                'conditions'          => array(
                    'Ставка кэшбэка для этого магазина сейчас не опубликована.',
                ),
                'message'             => 'Магазин подключён, но активная ставка кэшбэка пока недоступна.',
            );
        }

        return array(
            'status'              => 'available',
            'cashback_available'  => true,
            'activation_required' => true,
            'url'                 => $validated['url'],
            'host'                => $validated['host'],
            'store'               => $store,
            'cashback'            => $cashback,
            'conditions'          => array(
                'Кэшбэк начисляется после подтверждения заказа магазином и CPA-сетью.',
                'Не закрывайте страницу магазина сразу после перехода по ссылке.',
            ),
            'message'             => 'Кэшбэк доступен. Перед покупкой активируйте переход по ссылке Savello.',
        );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function activate( string $url, string $client_request_id, int $user_id = 0 ): array|WP_Error {
        $validated = Cashback_Link_Checker_Url_Validator::validate($url);
        if (!$validated['ok']) {
            return new WP_Error(
                (string) $validated['error'],
                'Ссылка не прошла проверку.',
                array( 'status' => $validated['error'] === 'empty_url' ? 400 : 422 )
            );
        }

        $merchant = $this->find_merchant_by_host((string) $validated['host']);
        if ($merchant === null || !$this->is_merchant_active($merchant)) {
            return new WP_Error(
                'product_not_indexed',
                'Магазин пока не найден в каталоге кэшбэка.',
                array( 'status' => 404 )
            );
        }

        if (!class_exists('Cashback_Click_Session_Service')) {
            return new WP_Error(
                'activation_unavailable',
                'Активация кэшбэка временно недоступна.',
                array( 'status' => 503 )
            );
        }

        $result = Cashback_Click_Session_Service::activate_for_link_checker(array(
            'product_id'         => (int) $merchant['ID'],
            'target_url'         => (string) $validated['url'],
            'user_id'            => $user_id,
            'ip_address'         => class_exists('Cashback_Encryption') ? Cashback_Encryption::get_client_ip() : '',
            'user_agent'         => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : null,
            'referer'            => (string) $validated['url'],
            'client_request_id'  => $client_request_id,
        ));

        if (($result['status'] ?? '') !== 'ok') {
            return $this->activation_error((string) ($result['status'] ?? 'error'));
        }

        $click_id      = (string) $result['canonical_click_id'];
        $affiliate_url = (string) $result['affiliate_url'];
        $redirect_url  = $this->build_activation_page_url((int) $merchant['ID'], $click_id, $user_id, $affiliate_url);

        return array(
            'status'              => 'ok',
            'redirect_url'        => $redirect_url,
            'activation_page_url' => $redirect_url,
            'affiliate_url'       => $affiliate_url,
            'click_id'            => $click_id,
            'expires_at'          => gmdate('Y-m-d H:i:s', time() + (int) $result['window_seconds']),
            'reused'              => (bool) $result['reused'],
            'tap_count'           => (int) $result['tap_count'],
            'message'             => 'Переход активирован. Кэшбэк появится после подтверждения заказа магазином.',
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function find_merchant_by_host( string $host ): ?array {
        $host = Cashback_Link_Checker_Url_Validator::normalize_host($host);
        if ($host === '') {
            return null;
        }

        foreach ($this->load_merchant_rows() as $row) {
            $domain = $this->normalize_domain((string) ($row['store_domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function load_merchant_rows(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return array();
        }

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $sql = $wpdb->prepare(
            'SELECT p.ID, p.post_title, p.post_status,
                    pm_net.meta_value AS network_id,
                    pm_offer.meta_value AS offer_id,
                    pm_domain.meta_value AS store_domain,
                    pm_status.meta_value AS status_raw,
                    n.name AS network_name,
                    n.slug AS network_slug,
                    n.is_active AS network_active
               FROM %i p
          LEFT JOIN %i pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = %s
          LEFT JOIN %i pm_offer ON p.ID = pm_offer.post_id AND pm_offer.meta_key = %s
          LEFT JOIN %i pm_domain ON p.ID = pm_domain.post_id AND pm_domain.meta_key = %s
          LEFT JOIN %i pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = %s
          LEFT JOIN %i n ON n.id = pm_net.meta_value
              WHERE p.post_type = %s
                AND pm_net.meta_value IS NOT NULL
                AND pm_offer.meta_value IS NOT NULL
                AND pm_domain.meta_value IS NOT NULL',
            $wpdb->posts,
            $wpdb->postmeta,
            '_affiliate_network_id',
            $wpdb->postmeta,
            '_offer_id',
            $wpdb->postmeta,
            '_store_domain',
            $wpdb->postmeta,
            '_cashback_campaign_status_raw',
            $networks_table,
            'product'
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built by $wpdb->prepare() above.
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $merchant
     * @return array<string,mixed>
     */
    private function format_store( array $merchant ): array {
        $product_id = (int) $merchant['ID'];
        $domain     = $this->normalize_domain((string) ($merchant['store_domain'] ?? ''));

        return array(
            'product_id' => $product_id,
            'name'       => (string) ($merchant['post_title'] ?? $domain),
            'domain'     => $domain,
            'network'    => (string) ($merchant['network_slug'] ?? ''),
            'offer_id'   => (string) ($merchant['offer_id'] ?? ''),
            'permalink'  => function_exists('get_permalink') ? (string) get_permalink($product_id) : '',
        );
    }

    /**
     * @param array<string,mixed> $merchant
     */
    private function is_merchant_active( array $merchant ): bool {
        $post_status    = (string) ($merchant['post_status'] ?? '');
        $network_active = (int) ($merchant['network_active'] ?? 1) === 1;
        $status_raw     = strtolower((string) ($merchant['status_raw'] ?? ''));

        return $post_status === 'publish'
            && $network_active
            && !in_array($status_raw, array( 'paused', 'suspend', 'suspended', 'disabled', 'declined' ), true);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cashback_payload( int $product_id, int $user_id ): ?array {
        $computed = class_exists('Cashback_Cashback_Display_Calculator')
            ? Cashback_Cashback_Display_Calculator::compute($product_id, $user_id)
            : array();

        $label = (string) get_post_meta($product_id, '_cashback_display_label', true);
        if ($label === '') {
            $label = 'Кэшбэк';
        }

        if (!empty($computed['formatted'])) {
            return array(
                'label'     => $label,
                'value'     => (string) $computed['formatted'],
                'type'      => (string) ($computed['type'] ?? 'dynamic'),
                'is_multi'  => !empty($computed['is_multi']),
                'currency'  => (string) ($computed['currency'] ?? ''),
            );
        }

        $manual = (string) get_post_meta($product_id, '_cashback_display_value', true);
        if ($manual === '') {
            return null;
        }

        return array(
            'label'     => $label,
            'value'     => $manual,
            'type'      => 'manual',
            'is_multi'  => false,
            'currency'  => '',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function not_available( string $url, string $host, string $message ): array {
        return array(
            'status'              => 'not_available',
            'cashback_available'  => false,
            'activation_required' => false,
            'url'                 => $url,
            'host'                => $host,
            'store'               => null,
            'cashback'            => null,
            'conditions'          => array(),
            'message'             => $message,
        );
    }

    private function normalize_domain( string $value ): string {
        $value = trim(strtolower($value));
        $value = preg_replace('#^https?://#i', '', $value) ?? '';
        $value = preg_replace('#^www\.#i', '', $value) ?? '';
        $value = explode('/', $value)[0];
        return trim($value, "[] \t\n\r\0\x0B.");
    }

    private function build_activation_page_url( int $product_id, string $click_id, int $user_id, string $fallback_url ): string {
        $permalink = function_exists('get_permalink') ? (string) get_permalink($product_id) : '';
        if ($permalink === '') {
            return $fallback_url;
        }

        $args = array(
            'cashback_go' => '1',
            'click_id'    => $click_id,
        );

        if (class_exists('Cashback_Encryption') && method_exists('Cashback_Encryption', 'sign_activation_token')) {
            $args['t'] = Cashback_Encryption::sign_activation_token($click_id, $user_id, time());
        }

        return add_query_arg($args, $permalink);
    }

    private function activation_error( string $status ): WP_Error {
        return match ($status) {
            'rate_limited' => new WP_Error('rate_limited', 'Слишком много попыток. Попробуйте позже.', array( 'status' => 429 )),
            'invalid_product' => new WP_Error('product_not_indexed', 'Магазин пока не найден в каталоге кэшбэка.', array( 'status' => 404 )),
            'no_url' => new WP_Error('no_url', 'Не удалось подготовить ссылку перехода.', array( 'status' => 400 )),
            default => new WP_Error('activation_failed', 'Не удалось активировать кэшбэк. Попробуйте позже.', array( 'status' => 500 )),
        };
    }
}
