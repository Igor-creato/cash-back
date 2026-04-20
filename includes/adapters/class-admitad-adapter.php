<?php

/**
 * Адаптер CPA-сети Admitad
 *
 * OAuth2: Basic Auth + client_credentials grant.
 * Пагинация: offset/limit.
 * Даты: dd.mm.yyyy.
 * Auth header: Authorization: Bearer {token}.
 *
 * @package CashbackPlugin
 * @since   7.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Admitad_Adapter extends Cashback_Network_Adapter_Base {

    /**
     * {@inheritdoc}
     */
    public function get_slug(): string {
        return 'admitad';
    }

    /**
     * {@inheritdoc}
     */
    public function get_aliases(): array {
        return array( 'adm' );
    }

    /**
     * {@inheritdoc}
     *
     * Admitad OAuth2: Basic Auth header + client_credentials grant.
     * Кеширование в transient + runtime cache.
     *
     * Один токен с полным набором scope (например "statistics advcampaigns")
     * работает для всех endpoint'ов.
     */
    public function get_token( array $credentials, array $network_config ): ?string {
        $client_id     = $credentials['client_id'] ?? '';
        $client_secret = $credentials['client_secret'] ?? '';
        $scope         = $credentials['scope'] ?? 'statistics advcampaigns';

        if (empty($client_id) || empty($client_secret)) {
            $this->last_token_error = 'Admitad credentials incomplete (client_id или client_secret пустые)';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: ' . $this->last_token_error);
            return null;
        }

        $cache_key = 'cashback_admitad_token_' . md5($client_id);

        // Проверяем transient
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        // Проверяем runtime кеш
        if (isset($this->token_cache[ $cache_key ])) {
            return $this->token_cache[ $cache_key ];
        }

        $token_url = $this->build_api_url($network_config, 'api_token_endpoint', 'https://api.admitad.com/token/');

        $response = $this->http_post($token_url, array(
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ), array(
            'grant_type' => 'client_credentials',
            'client_id'  => $client_id,
            'scope'      => $scope,
        ));

        if (is_wp_error($response)) {
            $this->last_token_error = 'Admitad token ошибка сети: ' . $response->get_error_message();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: ' . $this->last_token_error);
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['access_token'])) {
            $safe_body = $body;
            if (is_array($safe_body)) {
                unset($safe_body['access_token'], $safe_body['refresh_token'], $safe_body['client_secret']);
            }
            $this->last_token_error = 'Admitad token failed (HTTP ' . $code . ')';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: Admitad token failed. Code: ' . $code . ', Body: ' . wp_json_encode($safe_body));
            return null;
        }

        $token   = $body['access_token'];
        $expires = (int) ( $body['expires_in'] ?? 3600 );

        // Кешируем с запасом 5 минут
        set_transient($cache_key, $token, max(60, $expires - 300));
        $this->token_cache[ $cache_key ] = $token;

        return $token;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidate_token( array $credentials ): void {
        $client_id = $credentials['client_id'] ?? '';
        if ($client_id !== '') {
            $cache_key = 'cashback_admitad_token_' . md5($client_id);
            delete_transient($cache_key);
            unset($this->token_cache[ $cache_key ]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function build_auth_headers( array $credentials, array $network_config ): ?array {
        $token = $this->get_token($credentials, $network_config);
        if (!$token) {
            return null;
        }
        return array( 'Authorization' => 'Bearer ' . $token );
    }

    /**
     * Получить действия из Admitad API (одна страница)
     *
     * @param array $credentials   API credentials
     * @param array $params        Параметры запроса
     * @param array $network_config Конфигурация сети
     * @return array ['success' => bool, 'actions' => [...], 'total' => int, 'error' => string|null]
     */
    public function fetch_actions( array $credentials, array $params, array $network_config ): array {
        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return $this->fetch_error('Failed to authenticate');
        }

        $query_params = array();

        // Поддержка всех subid-вариантов (subid, subid1-subid4)
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null && preg_match('/^subid\d?$/', $key)) {
                $query_params[ $key ] = $value;
            }
        }

        // Даты
        foreach (array( 'date_start', 'date_end', 'status_updated_start', 'status_updated_end' ) as $date_key) {
            if (!empty($params[ $date_key ])) {
                $query_params[ $date_key ] = $params[ $date_key ];
            }
        }

        // Площадка
        if (!empty($params['website'])) {
            $query_params['website'] = $params['website'];
        }

        $query_params['limit']    = min((int) ( $params['limit'] ?? 500 ), 500);
        $query_params['offset']   = (int) ( $params['offset'] ?? 0 );
        $query_params['order_by'] = $params['order_by'] ?? 'datetime';

        $actions_url = $this->build_api_url($network_config, 'api_actions_endpoint', 'https://api.admitad.com/statistics/actions/');
        $url         = $actions_url . '?' . http_build_query($query_params);

        $response = $this->http_get($url, $auth_headers);

        if (is_wp_error($response)) {
            return $this->fetch_error($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // На 401 — сбрасываем кеш токена и повторяем один раз
        if ($code === 401 && empty($params['_retry_after_401'])) {
            $this->invalidate_token($credentials);

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Admitad: 401 on actions endpoint, invalidating token and retrying');

            $params['_retry_after_401'] = true;
            return $this->fetch_actions($credentials, $params, $network_config);
        }

        // 403 insufficient_scope — токен не имеет нужного scope, обновляем
        if ($code === 403 && empty($params['_retry_after_403'])) {
            if (str_contains(wp_remote_retrieve_body($response), 'insufficient_scope')) {
                $this->invalidate_token($credentials);

                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback Admitad: 403 insufficient_scope on actions, invalidating token and retrying');

                $params['_retry_after_403'] = true;
                return $this->fetch_actions($credentials, $params, $network_config);
            }
        }

        if ($code !== 200) {
            return $this->fetch_error("HTTP {$code}: " . $this->safe_error_summary($body));
        }

        $results = $body['results'] ?? array();
        $total   = (int) ( $body['_meta']['count'] ?? count($results) );

        return array(
            'success' => true,
            'actions' => $results,
            'total'   => $total,
            'error'   => null,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function fetch_all_actions( array $credentials, array $params, int $max_pages, array $network_config ): array {
        $all_actions = array();
        $offset      = 0;
        $limit       = 500;
        $total       = 0;
        $page        = 0;

        do {
            $params['offset'] = $offset;
            $params['limit']  = $limit;

            $result = $this->fetch_actions($credentials, $params, $network_config);

            if (!$result['success']) {
                return array(
                    'success' => false,
                    'actions' => $all_actions,
                    'total'   => $total,
                    'error'   => $result['error'],
                );
            }

            $actions = $result['actions'];
            $total   = $result['total'];
            // Дедупликация по стабильному id action: offset-пагинация на изменяемой выборке
            // может вернуть одну и ту же action дважды при повторах/смене статуса между страницами.
            foreach ($actions as $action) {
                $action_id = isset($action['id']) ? (string) $action['id'] : '';
                if ($action_id !== '') {
                    $all_actions[ $action_id ] = $action;
                } else {
                    $all_actions[] = $action;
                }
            }
            $offset += $limit;
            ++$page;

            // Защита от rate limit — пауза между запросами (100ms)
            $actions_count = count($actions);
            if ($actions_count === $limit && $page < $max_pages) {
                usleep(100000);
            }
        } while ($actions_count === $limit && $page < $max_pages);

        return array(
            'success' => true,
            'actions' => array_values($all_actions),
            'total'   => $total,
            'error'   => null,
        );
    }

    /**
     * {@inheritdoc}
     *
     * Admitad: GET /advcampaigns/?limit=500
     * Требует scope 'advcampaigns' в OAuth2 credentials (через пробел с другими scope).
     * Кампания активна при status=active.
     */
    public function fetch_campaigns( array $credentials, array $network_config ): array {
        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return array(
				'success'   => false,
				'campaigns' => array(),
				'error'     => 'Не удалось получить токен Admitad',
			);
        }

        $base_url = rtrim($network_config['api_base_url'] ?? 'https://api.admitad.com', '/');
        $url      = $base_url . '/advcampaigns/';

        $query = array(
			'limit'  => 500,
			'offset' => 0,
		);

        $all_campaigns = array();
        $page          = 0;
        $max_pages     = 20;
        $retried       = false;

        do {
            $query['offset'] = $page * 500;
            $full_url        = $url . '?' . http_build_query($query);

            $response = $this->http_get($full_url, $auth_headers);

            if (is_wp_error($response)) {
                return array(
                    'success'   => false,
                    'campaigns' => $all_campaigns,
                    'error'     => 'HTTP error: ' . $response->get_error_message(),
                );
            }

            $code = wp_remote_retrieve_response_code($response);

            // 401 → сбрасываем токен и повторяем один раз
            if ($code === 401 && !$retried) {
                $retried = true;
                $this->invalidate_token($credentials);

                $auth_headers = $this->build_auth_headers($credentials, $network_config);
                if (!$auth_headers) {
                    return array(
						'success'   => false,
						'campaigns' => $all_campaigns,
						'error'     => 'Token refresh failed',
					);
                }
                continue;
            }

            // 403 insufficient_scope → токен не имеет нужного scope, обновляем
            if ($code === 403 && !$retried) {
                if (str_contains(wp_remote_retrieve_body($response), 'insufficient_scope')) {
                    $retried = true;
                    $this->invalidate_token($credentials);

                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log('Cashback Admitad: 403 insufficient_scope on advcampaigns, invalidating token and retrying');

                    $auth_headers = $this->build_auth_headers($credentials, $network_config);
                    if (!$auth_headers) {
                        return array(
							'success'   => false,
							'campaigns' => $all_campaigns,
							'error'     => 'Token refresh failed after 403 insufficient_scope',
						);
                    }
                    continue;
                }
            }

            if ($code !== 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                return array(
                    'success'   => false,
                    'campaigns' => $all_campaigns,
                    'error'     => "Admitad advcampaigns HTTP {$code}: " . $this->safe_error_summary($body),
                );
            }

            $body    = json_decode(wp_remote_retrieve_body($response), true);
            $results = $body['results'] ?? array();

            foreach ($results as $campaign) {
                $status = strtolower((string) ( $campaign['status'] ?? '' ));

                $all_campaigns[] = array(
                    'id'                => (string) ( $campaign['id'] ?? '' ),
                    'name'              => (string) ( $campaign['name'] ?? '' ),
                    'is_active'         => ( $status === 'active' ),
                    'status'            => $status,
                    'connection_status' => '',
                );
            }

            ++$page;
            if (count($results) < 500 || $page >= $max_pages) {
                break;
            }

            // Rate limit пауза между страницами
            usleep(100000);
        } while (true);

        return array(
            'success'   => true,
            'campaigns' => $all_campaigns,
            'error'     => null,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_status_map(): array {
        return array(
            'pending'              => 'waiting',
            'approved'             => 'completed',
            'approved_but_stalled' => 'completed',
            'declined'             => 'declined',
            'rejected'             => 'declined',
            'open'                 => 'waiting',
            'hold'                 => 'waiting',
        );
    }

    /**
     * Формирует безопасное summary тела ответа Admitad для строк ошибок.
     *
     * В `$body` могут быть order_id, subid, email, results — их нельзя пробрасывать
     * вверх по стеку и писать в логи. Берём только allowlist полей с общей диагностикой.
     *
     * @param mixed $body Распарсенное тело ответа или null.
     * @return string
     */
    private function safe_error_summary( $body ): string {
        if (!is_array($body)) {
            return 'non-json body';
        }
        $allow   = array( 'code', 'error', 'error_description', 'detail', 'status', 'status_code' );
        $summary = array();
        foreach ($allow as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[ $key ];
            if (is_scalar($value)) {
                $summary[ $key ] = (string) $value;
            }
        }
        if (empty($summary)) {
            return 'body redacted';
        }
        $encoded = wp_json_encode($summary);
        return is_string($encoded) ? $encoded : 'body redacted';
    }
}
