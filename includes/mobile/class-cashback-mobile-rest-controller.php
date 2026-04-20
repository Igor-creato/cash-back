<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST-контроллер мобильного приложения (namespace `cashback/v2`).
 *
 * Фазы 1–2: auth endpoints — login / refresh / logout / logout-all / register /
 * verify-email / password/forgot / password/reset.
 * Прочие endpoints (me, stores, favorites, push и т.д.) добавляются в последующих фазах.
 *
 * Все /auth/* (кроме logout) — публичные, защищены `Cashback_Rate_Limiter` тира `critical`,
 * CAPTCHA-проверкой при grey-score >= 20, и anti-enumeration generic-ответами.
 *
 * @since 1.1.0
 */
class Cashback_Mobile_REST_Controller {

    public const NAMESPACE = 'cashback/v2';

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function init(): void {
        $instance = self::get_instance();
        add_action('rest_api_init', array( $instance, 'register_routes' ));
    }

    private function __construct() {}

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/auth/login', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_login' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'email'       => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'password'    => array(
                    'type'     => 'string',
                    'required' => true,
                    // Пароль НЕ санитизируем (может содержать спецсимволы).
                ),
                'device_id'   => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'device_name' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'platform'    => array(
                    'type'              => 'string',
                    'required'          => false,
                    'enum'              => array( 'ios', 'android', 'web' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'app_version' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/auth/refresh', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_refresh' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'refresh' => array(
                    'type'     => 'string',
                    'required' => true,
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/auth/logout', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_logout' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'refresh' => array(
                    'type'     => 'string',
                    'required' => true,
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/auth/logout-all', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_logout_all' ),
            'permission_callback' => array( Cashback_JWT_Auth::class, 'require_authenticated_user' ),
        ));

        register_rest_route(self::NAMESPACE, '/auth/register', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_register' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'email'         => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'password'      => array(
                    'type'     => 'string',
                    'required' => true,
                ),
                'referral_code' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'captcha_token' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/auth/verify-email', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_verify_email' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'token'       => array(
                    'type'     => 'string',
                    'required' => true,
                ),
                'device_id'   => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'device_name' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'platform'    => array(
                    'type'              => 'string',
                    'required'          => false,
                    'enum'              => array( 'ios', 'android', 'web' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'app_version' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/auth/password/forgot', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_password_forgot' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'email'         => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'captcha_token' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/auth/password/reset', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_password_reset' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'token'        => array(
                    'type'     => 'string',
                    'required' => true,
                ),
                'new_password' => array(
                    'type'     => 'string',
                    'required' => true,
                ),
            ),
        ));

        // -----------------------------------------------------------------
        // Каталог магазинов (public read)
        // -----------------------------------------------------------------

        register_rest_route(self::NAMESPACE, '/stores', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_stores_list' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'page'     => array(
                    'type'    => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ),
                'per_page' => array(
                    'type'    => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 50,
                ),
                'search'   => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'category' => array(
                    'type'     => 'integer',
                    'required' => false,
                    'minimum'  => 0,
                ),
                'sort'     => array(
                    'type'    => 'string',
                    'enum'    => array( 'popular', 'name', 'cashback_desc' ),
                    'default' => 'popular',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/stores/(?P<product_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_store_detail' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'product_id' => array(
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/categories', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_categories_list' ),
            'permission_callback' => '__return_true',
        ));

        // -----------------------------------------------------------------
        // Me (Bearer required)
        // -----------------------------------------------------------------
        $auth_cb = array( Cashback_JWT_Auth::class, 'require_authenticated_user' );

        register_rest_route(self::NAMESPACE, '/me', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_me_get' ),
                'permission_callback' => $auth_cb,
            ),
            array(
                'methods'             => 'PATCH',
                'callback'            => array( $this, 'handle_me_patch' ),
                'permission_callback' => $auth_cb,
                'args'                => array(
                    'display_name' => array(
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'handle_me_delete' ),
                'permission_callback' => $auth_cb,
                'args'                => array(
                    'password' => array(
                        'type'     => 'string',
                        'required' => true,
                    ),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/notifications/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_notifications_get' ),
                'permission_callback' => $auth_cb,
            ),
            array(
                'methods'             => 'PATCH',
                'callback'            => array( $this, 'handle_notifications_patch' ),
                'permission_callback' => $auth_cb,
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/transactions', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_me_transactions' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'page'      => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
                'per_page'  => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
				),
                'status'    => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'partner'   => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'store'     => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'date_from' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'date_to'   => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/affiliate', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_affiliate_overview' ),
            'permission_callback' => $auth_cb,
        ));

        register_rest_route(self::NAMESPACE, '/me/affiliate/accruals', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_affiliate_accruals' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
                'per_page' => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
				),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/affiliate/referrals', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_affiliate_referrals' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
                'per_page' => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
				),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/payouts', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_payouts_history' ),
                'permission_callback' => $auth_cb,
                'args'                => array(
                    'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
                    'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					),
                    'status'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_payouts_create' ),
                'permission_callback' => $auth_cb,
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/payouts/methods', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_payouts_methods' ),
            'permission_callback' => $auth_cb,
        ));

        register_rest_route(self::NAMESPACE, '/me/payouts/banks', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_payouts_banks' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'q' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
            ),
        ));

        // -----------------------------------------------------------------
        // Claims
        // -----------------------------------------------------------------

        register_rest_route(self::NAMESPACE, '/me/clicks', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_clicks_list' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'page'      => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
                'per_page'  => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 50,
				),
                'store'     => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'date_from' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'date_to'   => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/clicks/(?P<click_id>[a-f0-9-]{1,64})/check-eligibility', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_click_check_eligibility' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'click_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/clicks/(?P<click_id>[a-f0-9-]{1,64})/calculate-score', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_click_calculate_score' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'click_id'    => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'order_date'  => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'order_value' => array(
					'type'     => 'number',
					'required' => true,
				),
                'comment'     => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/claims', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_claims_list' ),
                'permission_callback' => $auth_cb,
                'args'                => array(
                    'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
                    'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 50,
					),
                    'status'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_claims_create' ),
                'permission_callback' => $auth_cb,
                'args'                => array(
                    'click_id'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
                    'order_id'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
                    'order_value' => array(
						'type'     => 'number',
						'required' => true,
					),
                    'order_date'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
                    'comment'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/claims/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_claims_detail' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'id' => array(
					'type'     => 'integer',
					'required' => true,
					'minimum'  => 1,
				),
            ),
        ));

        // -----------------------------------------------------------------
        // Support (tickets)
        // -----------------------------------------------------------------

        register_rest_route(self::NAMESPACE, '/me/tickets', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_tickets_list' ),
                'permission_callback' => $auth_cb,
                'args'                => array(
                    'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
                    'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 50,
					),
                    'status'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_tickets_create' ),
                'permission_callback' => $auth_cb,
            ),
        ));

        $ticket_id_args = array(
            'id' => array(
                'type'     => 'integer',
                'required' => true,
                'minimum'  => 1,
            ),
        );

        register_rest_route(self::NAMESPACE, '/me/tickets/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_tickets_detail' ),
            'permission_callback' => $auth_cb,
            'args'                => $ticket_id_args,
        ));

        register_rest_route(self::NAMESPACE, '/me/tickets/(?P<id>\d+)/messages', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_tickets_reply' ),
            'permission_callback' => $auth_cb,
            'args'                => $ticket_id_args,
        ));

        register_rest_route(self::NAMESPACE, '/me/tickets/(?P<id>\d+)/close', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_tickets_close' ),
            'permission_callback' => $auth_cb,
            'args'                => $ticket_id_args,
        ));

        register_rest_route(self::NAMESPACE, '/me/tickets/(?P<id>\d+)/attachments/(?P<att_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_ticket_attachment_download' ),
            'permission_callback' => '__return_true', // авторизация через HMAC-token, не через Bearer.
            'args'                => array(
                'id'     => array(
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ),
                'att_id' => array(
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ),
                'token'  => array(
                    'type'     => 'string',
                    'required' => true,
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/favorites', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_favorites_list' ),
                'permission_callback' => $auth_cb,
                'args'                => array(
                    'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
                    'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 50,
					),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_favorites_add' ),
                'permission_callback' => $auth_cb,
                'args'                => array(
                    'product_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
                ),
            ),
        ));

        // -----------------------------------------------------------------
        // Push tokens
        // -----------------------------------------------------------------
        register_rest_route(self::NAMESPACE, '/me/push-tokens', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_push_register' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'expo_token'  => array(
					'type'     => 'string',
					'required' => true,
				),
                'device_id'   => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'platform'    => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'ios', 'android' ),
				),
                'app_version' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
                'locale'      => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/push-tokens/(?P<device_id>[A-Za-z0-9\-_]{1,128})', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'handle_push_revoke' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'device_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/favorites/(?P<product_id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'handle_favorites_remove' ),
            'permission_callback' => $auth_cb,
            'args'                => array(
                'product_id' => array(
					'type'     => 'integer',
					'required' => true,
					'minimum'  => 1,
				),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/me/payouts/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_payouts_settings_get' ),
                'permission_callback' => $auth_cb,
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'handle_payouts_settings_put' ),
                'permission_callback' => $auth_cb,
            ),
        ));

        // -----------------------------------------------------------------
        // Meta (public)
        // -----------------------------------------------------------------

        register_rest_route(self::NAMESPACE, '/meta/app-config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_app_config' ),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NAMESPACE, '/meta/health', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_health' ),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NAMESPACE, '/auth/social/(?P<provider>[a-z0-9]+)/exchange', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_social_exchange' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'provider'      => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                    'enum'              => Cashback_Social_Mobile_Exchange::SUPPORTED_PROVIDERS,
                ),
                'auth_code'     => array(
                    'type'     => 'string',
                    'required' => true,
                ),
                'code_verifier' => array(
                    'type'     => 'string',
                    'required' => true,
                ),
                'redirect_uri'  => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'esc_url_raw',
                ),
                'vk_device_id'  => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'device_id'     => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'device_name'   => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'platform'      => array(
                    'type'              => 'string',
                    'required'          => false,
                    'enum'              => array( 'ios', 'android', 'web' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'app_version'   => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    // =========================================================================
    // Обработчики
    // =========================================================================

    /**
     * POST /cashback/v2/auth/login
     */
    public function handle_login( \WP_REST_Request $request ) {
        $ip = Cashback_Encryption::get_client_ip();

        // Rate limit: 5/мин по IP (tier critical). Используем ключ `mobile_auth_login`
        // с fallback user_id=0 (до верификации не знаем, кто это).
        if (Cashback_Rate_Limiter::is_blocked_ip($ip)) {
            return self::error_response('rate_limited', 'Too many requests', 429);
        }

        $email       = trim((string) $request->get_param('email'));
        $password    = (string) $request->get_param('password');
        $device_info = $this->collect_device_info($request);

        $check = Cashback_Rate_Limiter::check('mobile_auth_login', 0, $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many login attempts', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        try {
            $result = Cashback_JWT_Auth::login($email, $password, $device_info);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback][JWT] login failed: ' . $e->getMessage());
            return self::error_response('rest_jwt_server_error', 'Server error', 500);
        }

        if (is_wp_error($result)) {
            Cashback_Rate_Limiter::record_violation($ip, 'rate_limit');
            return self::wp_error_response($result);
        }

        return self::token_pair_response($result);
    }

    /**
     * POST /cashback/v2/auth/refresh
     */
    public function handle_refresh( \WP_REST_Request $request ) {
        $ip = Cashback_Encryption::get_client_ip();

        if (Cashback_Rate_Limiter::is_blocked_ip($ip)) {
            return self::error_response('rate_limited', 'Too many requests', 429);
        }

        $check = Cashback_Rate_Limiter::check('mobile_auth_refresh', 0, $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many refresh attempts', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $refresh     = (string) $request->get_param('refresh');
        $device_info = $this->collect_device_info($request);

        try {
            $result = Cashback_JWT_Auth::refresh($refresh, $device_info);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback][JWT] refresh failed: ' . $e->getMessage());
            return self::error_response('rest_jwt_server_error', 'Server error', 500);
        }

        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }

        return self::token_pair_response($result);
    }

    /**
     * POST /cashback/v2/auth/logout
     *
     * Публичный: клиент может вызвать, даже если access-токен уже истёк.
     * Отзывает один refresh-токен; 200 OK даже если токена нет (не раскрываем существование).
     */
    public function handle_logout( \WP_REST_Request $request ) {
        $ip    = Cashback_Encryption::get_client_ip();
        $check = Cashback_Rate_Limiter::check('mobile_auth_logout', 0, $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many attempts', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $refresh = (string) $request->get_param('refresh');
        try {
            Cashback_JWT_Auth::logout($refresh);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback][JWT] logout failed: ' . $e->getMessage());
        }
        return new \WP_REST_Response(array( 'ok' => true ), 200);
    }

    /**
     * POST /cashback/v2/auth/logout-all
     *
     * Требует валидный Bearer access-токен. Отзывает ВСЕ активные refresh-токены этого юзера.
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API callback signature requires WP_REST_Request parameter.
    public function handle_logout_all( \WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return self::error_response('rest_jwt_required', 'Authorization required', 401);
        }

        $revoked = Cashback_JWT_Auth::logout_all($user_id);

        return new \WP_REST_Response(array(
            'ok'             => true,
            'revoked_tokens' => $revoked,
        ), 200);
    }

    /**
     * POST /cashback/v2/auth/register
     *
     * Всегда возвращает 200 {ok:true, status:"pending"} при валидных email+password —
     * чтобы атакующий не мог определить, существует ли email в системе.
     * Реальные ошибки (weak password, невалидный email) — 4xx.
     */
    public function handle_register( \WP_REST_Request $request ) {
        $ip = Cashback_Encryption::get_client_ip();

        if (Cashback_Rate_Limiter::is_blocked_ip($ip)) {
            return self::error_response('rate_limited', 'Too many requests', 429);
        }

        $check = Cashback_Rate_Limiter::check('mobile_auth_register', 0, $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many registration attempts', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $captcha_error = $this->maybe_verify_captcha($request, $ip);
        if (null !== $captcha_error) {
            return $captcha_error;
        }

        $context = array(
            'ip'         => $ip ?: '',
            'user_agent' => $this->get_user_agent(),
        );

        $result = Cashback_Mobile_Registration::register(
            array(
                'email'         => (string) $request->get_param('email'),
                'password'      => (string) $request->get_param('password'),
                'referral_code' => (string) $request->get_param('referral_code'),
            ),
            $context
        );

        if (!empty($result['error']) && $result['error'] instanceof \WP_Error) {
            Cashback_Rate_Limiter::record_violation($ip, 'rate_limit');
            return self::wp_error_response($result['error']);
        }

        return new \WP_REST_Response(array(
            'ok'     => true,
            'status' => $result['status'] ?? 'pending',
        ), 200);
    }

    /**
     * POST /cashback/v2/auth/verify-email
     * При успехе — выдаёт JWT-пару (эквивалент /auth/login).
     */
    public function handle_verify_email( \WP_REST_Request $request ) {
        $ip = Cashback_Encryption::get_client_ip();

        $check = Cashback_Rate_Limiter::check('mobile_auth_verify', 0, $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many attempts', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $token       = (string) $request->get_param('token');
        $device_info = $this->collect_device_info($request);

        try {
            $result = Cashback_Mobile_Registration::verify_email($token, $device_info);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][JWT] verify-email failed: ' . $e->getMessage());
            return self::error_response('rest_jwt_server_error', 'Server error', 500);
        }

        if (is_wp_error($result)) {
            Cashback_Rate_Limiter::record_violation($ip, 'rate_limit');
            return self::wp_error_response($result);
        }

        return self::token_pair_response($result);
    }

    /**
     * POST /cashback/v2/auth/password/forgot
     * Всегда возвращает 200 generic — anti-enumeration.
     */
    public function handle_password_forgot( \WP_REST_Request $request ) {
        $ip = Cashback_Encryption::get_client_ip();

        if (Cashback_Rate_Limiter::is_blocked_ip($ip)) {
            return self::error_response('rate_limited', 'Too many requests', 429);
        }

        $check = Cashback_Rate_Limiter::check('mobile_auth_forgot', 0, $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many attempts', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $captcha_error = $this->maybe_verify_captcha($request, $ip);
        if (null !== $captcha_error) {
            return $captcha_error;
        }

        $email   = (string) $request->get_param('email');
        $context = array(
            'ip'         => $ip ?: '',
            'user_agent' => $this->get_user_agent(),
        );

        try {
            Cashback_Mobile_Password_Reset::forgot($email, $context);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][JWT] forgot failed: ' . $e->getMessage());
            // Даже при сбое — generic success (anti-enumeration).
        }

        return new \WP_REST_Response(array( 'ok' => true ), 200);
    }

    /**
     * POST /cashback/v2/auth/password/reset
     */
    public function handle_password_reset( \WP_REST_Request $request ) {
        $ip = Cashback_Encryption::get_client_ip();

        $check = Cashback_Rate_Limiter::check('mobile_auth_reset', 0, $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many attempts', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $token        = (string) $request->get_param('token');
        $new_password = (string) $request->get_param('new_password');

        try {
            $result = Cashback_Mobile_Password_Reset::reset($token, $new_password);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][JWT] reset failed: ' . $e->getMessage());
            return self::error_response('rest_jwt_server_error', 'Server error', 500);
        }

        if (is_wp_error($result)) {
            Cashback_Rate_Limiter::record_violation($ip, 'rate_limit');
            return self::wp_error_response($result);
        }

        return new \WP_REST_Response($result, 200);
    }

    // =========================================================================
    // Meta handlers (public)
    // =========================================================================

    public function handle_app_config( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request);
        $this->rate_limit_or_bail('mobile_meta_config');
        $config = Cashback_Mobile_App_Config::get();
        $resp   = new \WP_REST_Response($config, 200);
        // Позволяем клиентам кешировать 5 минут (легко обновляется при изменении опций).
        $resp->header('Cache-Control', 'public, max-age=300');
        return $resp;
    }

    public function handle_health( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request);
        return new \WP_REST_Response(array(
            'ok'       => true,
            'time_utc' => gmdate('c'),
            'version'  => defined('CASHBACK_PLUGIN_VERSION') ? (string) constant('CASHBACK_PLUGIN_VERSION') : '',
        ), 200);
    }

    // =========================================================================
    // Me handlers (Bearer required)
    // =========================================================================

    public function handle_me_get( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request);
        $this->rate_limit_or_bail('mobile_me_read');
        $user_id = (int) get_current_user_id();
        return new \WP_REST_Response(Cashback_Mobile_Me_Service::get_profile($user_id), 200);
    }

    public function handle_me_patch( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_me_write');
        $user_id = (int) get_current_user_id();
        $fields  = array();
        if (null !== $request->get_param('display_name')) {
            $fields['display_name'] = (string) $request->get_param('display_name');
        }
        $profile = Cashback_Mobile_Me_Service::update_profile($user_id, $fields);
        return new \WP_REST_Response($profile, 200);
    }

    public function handle_me_delete( \WP_REST_Request $request ): \WP_REST_Response {
        $ip    = Cashback_Encryption::get_client_ip();
        $check = Cashback_Rate_Limiter::check('mobile_me_delete', (int) get_current_user_id(), $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many attempts', 429, array( 'retry_after' => $check['retry_after'] ));
        }

        $user_id  = (int) get_current_user_id();
        $password = (string) $request->get_param('password');
        $user     = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return self::error_response('rest_unauthorized', 'Not authorized', 401);
        }

        if (!wp_check_password($password, $user->user_pass, $user_id)) {
            Cashback_Rate_Limiter::record_violation($ip, 'rate_limit');
            return self::error_response('invalid_password', 'Password verification failed', 401);
        }

        $result = Cashback_Mobile_Me_Service::soft_delete($user_id);
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 200);
    }

    public function handle_notifications_get( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request);
        $this->rate_limit_or_bail('mobile_me_read');
        $user_id = (int) get_current_user_id();
        return new \WP_REST_Response(Cashback_Mobile_Me_Service::get_notification_settings($user_id), 200);
    }

    public function handle_notifications_patch( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_me_write');
        $user_id = (int) get_current_user_id();
        $changes = array();
        $email   = $request->get_param('email');
        $push    = $request->get_param('push');
        if (is_array($email)) {
            $changes['email'] = $email;
        }
        if (is_array($push)) {
            $changes['push'] = $push;
        }
        $settings = Cashback_Mobile_Me_Service::update_notification_settings($user_id, $changes);
        return new \WP_REST_Response($settings, 200);
    }

    public function handle_me_transactions( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_me_transactions');
        $user_id = (int) get_current_user_id();
        $page    = Cashback_Mobile_Transactions_Service::paginate($user_id, array(
            'page'      => (int) $request->get_param('page'),
            'per_page'  => (int) $request->get_param('per_page'),
            'status'    => (string) $request->get_param('status'),
            'partner'   => (string) $request->get_param('partner'),
            'store'     => (string) $request->get_param('store'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to'   => (string) $request->get_param('date_to'),
        ));
        return new \WP_REST_Response($page, 200);
    }

    public function handle_affiliate_overview( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request);
        $this->rate_limit_or_bail('mobile_affiliate_overview');
        $user_id = (int) get_current_user_id();
        return new \WP_REST_Response(Cashback_Mobile_Affiliate_Service::get_overview($user_id), 200);
    }

    public function handle_affiliate_accruals( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_affiliate_read');
        $user_id = (int) get_current_user_id();
        $page    = Cashback_Mobile_Affiliate_Service::paginate_accruals(
            $user_id,
            (int) $request->get_param('page'),
            (int) $request->get_param('per_page')
        );
        return new \WP_REST_Response($page, 200);
    }

    public function handle_affiliate_referrals( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_affiliate_read');
        $user_id = (int) get_current_user_id();
        $page    = Cashback_Mobile_Affiliate_Service::paginate_referrals(
            $user_id,
            (int) $request->get_param('page'),
            (int) $request->get_param('per_page')
        );
        return new \WP_REST_Response($page, 200);
    }

    public function handle_payouts_history( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_payouts_read');
        $user_id = (int) get_current_user_id();
        $page    = Cashback_Mobile_Payouts_Service::paginate_history($user_id, array(
            'page'     => (int) $request->get_param('page'),
            'per_page' => (int) $request->get_param('per_page'),
            'status'   => (string) $request->get_param('status'),
        ));
        return new \WP_REST_Response($page, 200);
    }

    public function handle_payouts_methods( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request);
        $this->rate_limit_or_bail('mobile_payouts_read');
        return new \WP_REST_Response(array( 'items' => Cashback_Mobile_Payouts_Service::list_methods() ), 200);
    }

    public function handle_payouts_banks( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_payouts_read');
        $q = (string) $request->get_param('q');
        return new \WP_REST_Response(array( 'items' => Cashback_Mobile_Payouts_Service::search_banks($q) ), 200);
    }

    public function handle_payouts_settings_get( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request);
        $this->rate_limit_or_bail('mobile_payouts_read');
        $user_id = (int) get_current_user_id();
        return new \WP_REST_Response(Cashback_Mobile_Payouts_Service::get_settings($user_id), 200);
    }

    public function handle_payouts_settings_put( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request);
        return self::error_response(
            'not_implemented',
            'Payout settings update is not yet available in the mobile API. It will be enabled after the Cashback_Withdrawal_Service extraction.',
            501
        );
    }

    public function handle_payouts_create( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request);
        return self::error_response(
            'not_implemented',
            'Payout creation is not yet available in the mobile API. It will be enabled after the Cashback_Withdrawal_Service extraction.',
            501
        );
    }

    // -------------------------------------------------------------------------
    // Claims
    // -------------------------------------------------------------------------

    public function handle_clicks_list( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_claims_read');
        $user_id = (int) get_current_user_id();
        $filters = array(
            'store'     => (string) $request->get_param('store'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to'   => (string) $request->get_param('date_to'),
        );
        $page    = Cashback_Mobile_Claims_Service::paginate_clicks(
            $user_id,
            (int) $request->get_param('page'),
            (int) $request->get_param('per_page'),
            $filters
        );
        return new \WP_REST_Response($page, 200);
    }

    public function handle_click_check_eligibility( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_claims_write');
        $user_id  = (int) get_current_user_id();
        $click_id = (string) $request->get_param('click_id');
        $result   = Cashback_Mobile_Claims_Service::check_eligibility($user_id, $click_id);
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 200);
    }

    public function handle_click_calculate_score( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_claims_write');
        $user_id  = (int) get_current_user_id();
        $click_id = (string) $request->get_param('click_id');
        $payload  = array(
            'order_date'  => (string) $request->get_param('order_date'),
            'order_value' => (float) $request->get_param('order_value'),
            'comment'     => (string) $request->get_param('comment'),
        );
        $result   = Cashback_Mobile_Claims_Service::calculate_score($user_id, $click_id, $payload);
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 200);
    }

    public function handle_claims_list( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_claims_read');
        $user_id = (int) get_current_user_id();
        $page    = Cashback_Mobile_Claims_Service::paginate_claims(
            $user_id,
            (int) $request->get_param('page'),
            (int) $request->get_param('per_page'),
            (string) $request->get_param('status')
        );
        return new \WP_REST_Response($page, 200);
    }

    public function handle_claims_create( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_claims_create');
        $user_id = (int) get_current_user_id();
        $result  = Cashback_Mobile_Claims_Service::create_claim($user_id, array(
            'click_id'    => (string) $request->get_param('click_id'),
            'order_id'    => (string) $request->get_param('order_id'),
            'order_value' => (float) $request->get_param('order_value'),
            'order_date'  => (string) $request->get_param('order_date'),
            'comment'     => (string) $request->get_param('comment'),
        ));
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 201);
    }

    public function handle_claims_detail( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_claims_read');
        $user_id = (int) get_current_user_id();
        $result  = Cashback_Mobile_Claims_Service::get_claim($user_id, (int) $request->get_param('id'));
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 200);
    }

    // -------------------------------------------------------------------------
    // Support
    // -------------------------------------------------------------------------

    public function handle_tickets_list( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_support_read');
        $user_id = (int) get_current_user_id();
        $page    = Cashback_Mobile_Support_Service::paginate_tickets(
            $user_id,
            (int) $request->get_param('page'),
            (int) $request->get_param('per_page'),
            (string) $request->get_param('status')
        );
        return new \WP_REST_Response($page, 200);
    }

    public function handle_tickets_create( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_support_write');
        $user_id = (int) get_current_user_id();
        $result  = Cashback_Mobile_Support_Service::create_ticket(
            $user_id,
            array(
                'subject'      => (string) $request->get_param('subject'),
                'body'         => (string) $request->get_param('body'),
                'priority'     => (string) $request->get_param('priority'),
                'related_type' => (string) $request->get_param('related_type'),
                'related_id'   => (int) $request->get_param('related_id'),
            ),
            (array) ( $request->get_file_params()['support_files'] ?? array() )
        );
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 201);
    }

    public function handle_tickets_detail( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_support_read');
        $user_id = (int) get_current_user_id();
        $result  = Cashback_Mobile_Support_Service::get_ticket($user_id, (int) $request->get_param('id'));
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 200);
    }

    public function handle_tickets_reply( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_support_write');
        $user_id   = (int) get_current_user_id();
        $ticket_id = (int) $request->get_param('id');
        $body      = (string) $request->get_param('body');
        $files     = (array) ( $request->get_file_params()['support_files'] ?? array() );
        $result    = Cashback_Mobile_Support_Service::reply($user_id, $ticket_id, $body, $files);
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 200);
    }

    public function handle_tickets_close( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_support_write');
        $user_id   = (int) get_current_user_id();
        $ticket_id = (int) $request->get_param('id');
        $result    = Cashback_Mobile_Support_Service::close_ticket($user_id, $ticket_id);
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 200);
    }

    /**
     * GET attachment — авторизация через HMAC-token, не через Bearer.
     * Поведение: serve_file завершает запрос через wp_die/exit.
     */
    public function handle_ticket_attachment_download( \WP_REST_Request $request ): \WP_REST_Response {
        $token = (string) $request->get_param('token');
        if ('' === $token) {
            return self::error_response('token_required', 'Signed token is required', 400);
        }
        Cashback_Mobile_Support_Service::serve_attachment_by_token($token);
        // serve_file завершает запрос сам; если мы здесь — что-то пошло не так.
        return self::error_response('download_failed', 'Download did not complete', 500);
    }

    // -------------------------------------------------------------------------
    // Push tokens
    // -------------------------------------------------------------------------

    public function handle_push_register( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_push_write');
        $user_id = (int) get_current_user_id();

        $result = Cashback_Push_Token_Store::register(
            $user_id,
            (string) $request->get_param('expo_token'),
            (string) $request->get_param('device_id'),
            (string) $request->get_param('platform'),
            array(
                'app_version' => (string) $request->get_param('app_version'),
                'locale'      => (string) $request->get_param('locale'),
            )
        );
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response(array(
            'ok' => true,
            'id' => (int) $result,
        ), 200);
    }

    public function handle_push_revoke( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_push_write');
        $user_id   = (int) get_current_user_id();
        $device_id = (string) $request->get_param('device_id');
        $n         = Cashback_Push_Token_Store::revoke_by_device($user_id, $device_id);
        return new \WP_REST_Response(array(
            'ok'      => true,
            'revoked' => $n,
        ), 200);
    }

    // -------------------------------------------------------------------------
    // Favorites
    // -------------------------------------------------------------------------

    public function handle_favorites_list( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_favorites_read');
        $user_id = (int) get_current_user_id();
        $page    = Cashback_Mobile_Favorites_Service::paginate(
            $user_id,
            (int) $request->get_param('page'),
            (int) $request->get_param('per_page')
        );
        return new \WP_REST_Response($page, 200);
    }

    public function handle_favorites_add( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_favorites_write');
        $user_id    = (int) get_current_user_id();
        $product_id = (int) $request->get_param('product_id');
        $result     = Cashback_Mobile_Favorites_Service::add($user_id, $product_id);
        if (is_wp_error($result)) {
            return self::wp_error_response($result);
        }
        return new \WP_REST_Response($result, 200);
    }

    public function handle_favorites_remove( \WP_REST_Request $request ): \WP_REST_Response {
        $this->rate_limit_or_bail('mobile_favorites_write');
        $user_id    = (int) get_current_user_id();
        $product_id = (int) $request->get_param('product_id');
        $result     = Cashback_Mobile_Favorites_Service::remove($user_id, $product_id);
        return new \WP_REST_Response($result, 200);
    }

    /**
     * Rate-limit guard для read-обработчиков без кастомной логики.
     * При превышении лимита — заканчивает запрос кодом 429.
     */
    private function rate_limit_or_bail( string $action ): void {
        $ip    = Cashback_Encryption::get_client_ip();
        $check = Cashback_Rate_Limiter::check($action, (int) get_current_user_id(), $ip);
        if (!$check['allowed']) {
            wp_send_json(
                array(
                    'code'        => 'rate_limited',
                    'message'     => 'Too many requests',
                    'retry_after' => $check['retry_after'],
                ),
                429
            );
            exit;
        }
    }

    /**
     * GET /cashback/v2/stores
     * Пагинированный список магазинов с поиском и фильтрами.
     * Public read, но если клиент передал Bearer — заполним is_favorited.
     */
    public function handle_stores_list( \WP_REST_Request $request ): \WP_REST_Response {
        // ETag для клиентского кеша: version(каталога) + hash(params).
        $version     = Cashback_Mobile_Stores_Service::get_catalog_version();
        $params_hash = substr(md5((string) wp_json_encode(array(
            'page'     => (int) $request->get_param('page'),
            'per_page' => (int) $request->get_param('per_page'),
            'search'   => (string) $request->get_param('search'),
            'category' => (int) $request->get_param('category'),
            'sort'     => (string) $request->get_param('sort'),
            'user_id'  => (int) get_current_user_id(), // favorite state влияет на ответ
        ))), 0, 16);
        $etag        = '"' . $version . '-' . $params_hash . '"';

        // If-None-Match → 304.
        $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim(sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_IF_NONE_MATCH']))) : '';
        if ('' !== $inm && $inm === $etag) {
            $resp = new \WP_REST_Response(null, 304);
            $resp->header('ETag', $etag);
            $resp->header('Cache-Control', 'private, max-age=60');
            return $resp;
        }

        $ip    = Cashback_Encryption::get_client_ip();
        $check = Cashback_Rate_Limiter::check('mobile_stores_list', get_current_user_id(), $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many requests', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $page_data = Cashback_Mobile_Stores_Service::get_instance()->paginate(
            array(
                'page'     => (int) $request->get_param('page'),
                'per_page' => (int) $request->get_param('per_page'),
                'search'   => (string) $request->get_param('search'),
                'category' => (int) $request->get_param('category'),
                'sort'     => (string) $request->get_param('sort'),
            ),
            (int) get_current_user_id()
        );

        $resp = new \WP_REST_Response($page_data, 200);
        $resp->header('ETag', $etag);
        $resp->header('Cache-Control', 'private, max-age=60');
        return $resp;
    }

    /**
     * GET /cashback/v2/stores/{product_id}
     */
    public function handle_store_detail( \WP_REST_Request $request ): \WP_REST_Response {
        $ip    = Cashback_Encryption::get_client_ip();
        $check = Cashback_Rate_Limiter::check('mobile_stores_detail', get_current_user_id(), $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many requests', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $product_id = (int) $request->get_param('product_id');
        $store      = Cashback_Mobile_Stores_Service::get_instance()->get_by_id($product_id, (int) get_current_user_id());
        if (null === $store) {
            return self::error_response('store_not_found', 'Store not found', 404);
        }

        return new \WP_REST_Response($store, 200);
    }

    /**
     * GET /cashback/v2/categories
     * Список категорий (product_cat), в которых есть хотя бы один магазин.
     */
    public function handle_categories_list( \WP_REST_Request $request ): \WP_REST_Response {
        unset($request); // параметры не используются.
        $ip    = Cashback_Encryption::get_client_ip();
        $check = Cashback_Rate_Limiter::check('mobile_categories', get_current_user_id(), $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many requests', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $items = Cashback_Mobile_Stores_Service::get_instance()->get_categories();
        return new \WP_REST_Response(array( 'items' => $items ), 200);
    }

    /**
     * POST /cashback/v2/auth/social/{provider}/exchange
     *
     * Обмен auth_code (полученного в Expo через expo-auth-session + PKCE) на JWT-пару.
     * Поддерживает yandex и vkid.
     */
    public function handle_social_exchange( \WP_REST_Request $request ) {
        $ip       = Cashback_Encryption::get_client_ip();
        $provider = (string) $request->get_param('provider');

        if (Cashback_Rate_Limiter::is_blocked_ip($ip)) {
            return self::error_response('rate_limited', 'Too many requests', 429);
        }

        $check = Cashback_Rate_Limiter::check('mobile_auth_social_' . $provider, 0, $ip);
        if (!$check['allowed']) {
            return self::error_response('rate_limited', 'Too many attempts', 429, array(
                'retry_after' => $check['retry_after'],
            ));
        }

        $payload = array(
            'auth_code'     => (string) $request->get_param('auth_code'),
            'code_verifier' => (string) $request->get_param('code_verifier'),
            'redirect_uri'  => (string) $request->get_param('redirect_uri'),
            'vk_device_id'  => (string) $request->get_param('vk_device_id'),
        );

        $device_info = $this->collect_device_info($request);
        $request_ctx = array(
            'ip'         => $ip ?: '',
            'user_agent' => $this->get_user_agent(),
        );

        try {
            $result = Cashback_Social_Mobile_Exchange::exchange($provider, $payload, $device_info, $request_ctx);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][JWT] social exchange failed: ' . $e->getMessage());
            return self::error_response('rest_jwt_server_error', 'Server error', 500);
        }

        if (is_wp_error($result)) {
            Cashback_Rate_Limiter::record_violation($ip, 'rate_limit');
            return self::wp_error_response($result);
        }

        return self::token_pair_response($result);
    }

    // =========================================================================
    // Хелперы
    // =========================================================================

    /**
     * Проверить CAPTCHA если её требует `Cashback_Captcha::should_require()`.
     * Возвращает WP_REST_Response с ошибкой или null если проверка пройдена.
     */
    private function maybe_verify_captcha( \WP_REST_Request $request, string $ip ): ?\WP_REST_Response {
        if (!class_exists('Cashback_Captcha')) {
            return null;
        }
        $captcha = Cashback_Captcha::get_instance();
        if (!$captcha->should_require($ip)) {
            return null;
        }
        $token = (string) $request->get_param('captcha_token');
        if ('' === $token) {
            return self::error_response('captcha_required', 'Captcha required', 428);
        }
        if (!$captcha->verify_token($token, $ip)) {
            Cashback_Rate_Limiter::record_violation($ip, 'captcha_fail');
            return self::error_response('captcha_invalid', 'Captcha verification failed', 403);
        }
        return null;
    }

    private function get_user_agent(): string {
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            return sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT']));
        }
        return '';
    }

    /**
     * @return array<string,?string>
     */
    private function collect_device_info( \WP_REST_Request $request ): array {
        $ua = '';
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $ua = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT']));
        }

        return array(
            'device_id'   => (string) $request->get_param('device_id') !== '' ? (string) $request->get_param('device_id') : null,
            'device_name' => (string) $request->get_param('device_name') !== '' ? (string) $request->get_param('device_name') : null,
            'platform'    => (string) $request->get_param('platform') !== '' ? (string) $request->get_param('platform') : null,
            'app_version' => (string) $request->get_param('app_version') !== '' ? (string) $request->get_param('app_version') : null,
            'ip'          => Cashback_Encryption::get_client_ip() ?: null,
            'user_agent'  => '' !== $ua ? $ua : null,
        );
    }

    /**
     * @param array{access:string,refresh:string,access_expires_at:int,refresh_expires_at:string,user_id:int} $pair
     */
    private static function token_pair_response( array $pair ): \WP_REST_Response {
        return new \WP_REST_Response(array(
            'access_token'       => $pair['access'],
            'token_type'         => 'Bearer',
            'expires_in'         => max(0, (int) $pair['access_expires_at'] - time()),
            'access_expires_at'  => (int) $pair['access_expires_at'],
            'refresh_token'      => $pair['refresh'],
            'refresh_expires_at' => $pair['refresh_expires_at'],
            'user_id'            => $pair['user_id'],
        ), 200);
    }

    /**
     * @param array<string,mixed> $extra
     */
    private static function error_response( string $code, string $message, int $status, array $extra = array() ): \WP_REST_Response {
        return new \WP_REST_Response(array_merge(array(
            'code'    => $code,
            'message' => $message,
        ), $extra), $status);
    }

    private static function wp_error_response( \WP_Error $error ): \WP_REST_Response {
        $data   = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
        return new \WP_REST_Response(array(
            'code'    => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ), $status);
    }
}
