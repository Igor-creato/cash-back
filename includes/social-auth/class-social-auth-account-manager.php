<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Account Manager для модуля социальной авторизации.
 *
 * Реализует четыре ветки callback-flow:
 *  A. Существующая связка → логин, обновление токенов и last_login.
 *  B. Email провайдера совпадает с существующим WP-юзером → pending `confirm_link` + письмо.
 *  C. Email отсутствует у провайдера → pending `email_prompt` + редирект на форму email.
 *  D. Email есть, юзера нет → создание user (+связка) + pending `email_verify` + письмо.
 *
 * Также содержит фильтр `authenticate`, блокирующий вход по паролю юзеров
 * с мета `cashback_social_pending = 1` до подтверждения через email.
 *
 * @since 1.1.0
 */
class Cashback_Social_Auth_Account_Manager {

    /**
     * Мета-ключи.
     */
    public const META_PENDING  = 'cashback_social_pending';
    public const META_PROVIDER = 'cashback_social_provider';
    public const META_VIA      = 'cashback_social_via';

    /**
     * Типы pending-записей.
     */
    public const KIND_CONFIRM_LINK = 'confirm_link';
    public const KIND_EMAIL_PROMPT = 'email_prompt';
    public const KIND_EMAIL_VERIFY = 'email_verify';

    /**
     * Типы уведомлений (email templates).
     */
    public const NOTIFY_CONFIRM_LINK = 'social_confirm_link';
    public const NOTIFY_VERIFY_EMAIL = 'social_verify_email';

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
    }

    /**
     * Основной диспетчер после exchange + fetch_user_info.
     *
     * @param array<string, mixed> $profile      Структура из fetch_user_info.
     * @param array<string, mixed> $token_set    Структура из exchange_code.
     * @param array<string, mixed> $session_data Содержит redirect_after, referral_snapshot, ip, user_agent, scope_phone.
     * @return array{action:string, redirect_url?:string, message?:string, token?:string}
     */
    public function handle_callback(
        Cashback_Social_Provider_Interface $provider,
        array $profile,
        array $token_set,
        array $session_data,
        \WP_REST_Request $request
    ): array {
        unset($request);

        $provider_id = $provider->get_id();
        $external_id = isset($profile['external_id']) ? (string) $profile['external_id'] : '';
        $email       = isset($profile['email']) ? (string) $profile['email'] : '';
        $email       = $email !== '' ? sanitize_email($email) : '';

        if ($external_id === '') {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'account_manager',
                'error'    => 'missing_external_id',
            ));
            return array(
                'action'  => 'error',
                'message' => __('Провайдер не вернул идентификатор пользователя.', 'cashback-plugin'),
            );
        }

        $ip             = isset($session_data['ip']) ? (string) $session_data['ip'] : '';
        $user_agent     = isset($session_data['user_agent']) ? (string) $session_data['user_agent'] : '';
        $redirect_after = isset($session_data['redirect_after']) ? (string) $session_data['redirect_after'] : home_url('/');
        $safe_redirect  = wp_validate_redirect($redirect_after, home_url('/'));
        $referral       = isset($session_data['referral_snapshot']) && is_array($session_data['referral_snapshot'])
            ? $session_data['referral_snapshot']
            : array();

        // -----------------------------------------------------------------
        // Ветка account_link: юзер уже залогинен (привязка из ЛК).
        // -----------------------------------------------------------------
        if (is_user_logged_in()) {
            return $this->handle_account_link_branch(
                $provider,
                $profile,
                $token_set,
                array(
                    'ip'         => $ip,
                    'user_agent' => $user_agent,
                )
            );
        }

        // -----------------------------------------------------------------
        // Ветка A: связка уже существует → логин.
        // -----------------------------------------------------------------
        $link = Cashback_Social_Auth_DB::find_link($provider_id, $external_id);
        if (is_array($link) && !empty($link['id'])) {
            $link_id = (int) $link['id'];
            $user_id = (int) $link['user_id'];

            $user = get_userdata($user_id);
            if (!$user) {
                Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                    'provider' => $provider_id,
                    'stage'    => 'branch_a',
                    'error'    => 'link_user_missing',
                    'link_id'  => $link_id,
                ));
                return array(
                    'action'  => 'error',
                    'message' => __('Аккаунт, связанный с этой соцсетью, был удалён. Обратитесь в поддержку.', 'cashback-plugin'),
                );
            }

            // Если юзер помечен pending — не логиним, пересылаем письмо подтверждения.
            // Старый токен мог истечь или потеряться; генерируем новый и повторяем
            // отправку, иначе пользователь получит UI-сообщение «проверьте почту»,
            // но никакого письма не придёт.
            $pending_flag = (int) get_user_meta($user_id, self::META_PENDING, true);
            if ($pending_flag === 1) {
                $verify_token = Cashback_Social_Auth_DB::save_pending(
                    self::KIND_EMAIL_VERIFY,
                    array(
                        'user_id'        => $user_id,
                        'link_id'        => $link_id,
                        'provider'       => $provider_id,
                        'redirect_after' => $safe_redirect,
                    ),
                    $ip
                );

                if ($verify_token !== '') {
                    $this->send_verify_email_email($user_id, $provider_id, $verify_token);
                    Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_PENDING_CREATED, array(
                        'kind'     => self::KIND_EMAIL_VERIFY,
                        'provider' => $provider_id,
                        'user_id'  => $user_id,
                        'reason'   => 'resend_on_branch_a',
                        'ip'       => $ip,
                    ));
                } else {
                    Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                        'provider' => $provider_id,
                        'stage'    => 'branch_a_pending_resend',
                        'error'    => 'save_pending_failed',
                        'user_id'  => $user_id,
                    ));
                }

                Cashback_Social_Auth_Session::clear($provider_id);

                return array(
                    'action'  => 'pending',
                    'message' => __('Мы отправили письмо для подтверждения регистрации. Проверьте почту.', 'cashback-plugin'),
                );
            }

            // Обновить токены (refresh_token, возможно scope).
            $refresh = isset($token_set['refresh_token']) && is_string($token_set['refresh_token']) ? $token_set['refresh_token'] : '';
            if ($refresh !== '') {
                $expires_at = null;
                if (!empty($token_set['expires_in'])) {
                    $expires_at = gmdate('Y-m-d H:i:s', time() + (int) $token_set['expires_in']);
                }
                Cashback_Social_Auth_Token_Store::save_tokens(
                    $link_id,
                    $refresh,
                    $expires_at,
                    isset($token_set['scope']) ? (string) $token_set['scope'] : ''
                );
            }

            Cashback_Social_Auth_DB::touch_last_login($link_id, $ip, $user_agent);

            $this->login_user($user_id);

            Cashback_Social_Auth_Audit::log('login_existing', array(
                'provider' => $provider_id,
                'user_id'  => $user_id,
                'link_id'  => $link_id,
                'ip'       => $ip,
            ));
            Cashback_Social_Auth_Session::clear($provider_id);

            return array(
                'action'       => 'login',
                'redirect_url' => $safe_redirect,
            );
        }

        // -----------------------------------------------------------------
        // Ветка B: email есть у провайдера и совпадает с существующим WP-юзером.
        // -----------------------------------------------------------------
        if ($email !== '') {
            $existing_user = get_user_by('email', $email);
            if ($existing_user instanceof WP_User) {
                $payload = array(
                    'provider'          => $provider_id,
                    'external_id'       => $external_id,
                    'profile'           => $this->sanitize_profile($profile),
                    'token_set'         => $this->filter_token_set($token_set),
                    'existing_user_id'  => (int) $existing_user->ID,
                    'referral_snapshot' => $referral,
                    'redirect_after'    => $safe_redirect,
                    'ip'                => $ip,
                    'user_agent'        => $user_agent,
                );
                $token   = Cashback_Social_Auth_DB::save_pending(self::KIND_CONFIRM_LINK, $payload, $ip);
                if ($token === '') {
                    return array(
                        'action'  => 'error',
                        'message' => __('Не удалось подготовить подтверждение. Повторите попытку позже.', 'cashback-plugin'),
                    );
                }

                $this->send_confirm_link_email($existing_user, $provider_id, $token);

                Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_PENDING_CREATED, array(
                    'kind'     => self::KIND_CONFIRM_LINK,
                    'provider' => $provider_id,
                    'user_id'  => (int) $existing_user->ID,
                    'ip'       => $ip,
                ));
                Cashback_Social_Auth_Session::clear($provider_id);

                return array(
                    'action'  => 'pending',
                    'message' => __('Мы отправили письмо для подтверждения привязки. Проверьте почту.', 'cashback-plugin'),
                    'token'   => $token,
                );
            }
        }

        // -----------------------------------------------------------------
        // Ветка C: email отсутствует — просим у пользователя.
        // -----------------------------------------------------------------
        if ($email === '') {
            $payload = array(
                'provider'          => $provider_id,
                'external_id'       => $external_id,
                'profile'           => $this->sanitize_profile($profile),
                'token_set'         => $this->filter_token_set($token_set),
                'referral_snapshot' => $referral,
                'redirect_after'    => $safe_redirect,
                'ip'                => $ip,
                'user_agent'        => $user_agent,
            );
            $token   = Cashback_Social_Auth_DB::save_pending(self::KIND_EMAIL_PROMPT, $payload, $ip);
            if ($token === '') {
                return array(
                    'action'  => 'error',
                    'message' => __('Не удалось подготовить форму ввода email. Повторите попытку позже.', 'cashback-plugin'),
                );
            }

            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_PENDING_CREATED, array(
                'kind'     => self::KIND_EMAIL_PROMPT,
                'provider' => $provider_id,
                'ip'       => $ip,
            ));
            Cashback_Social_Auth_Session::clear($provider_id);

            $prompt_url = rest_url('cashback/v1/social/email-prompt-form');
            $prompt_url = add_query_arg('token', $token, $prompt_url);

            return array(
                'action'       => 'prompt_email',
                'redirect_url' => $prompt_url,
                'token'        => $token,
            );
        }

        // -----------------------------------------------------------------
        // Ветка D: email есть, WP-юзера нет → создаём (pending=1) + verify-письмо.
        // -----------------------------------------------------------------
        $created = $this->create_pending_user_and_link($provider, $profile, $token_set, $session_data, $email);
        if (!empty($created['error'])) {
            return array(
                'action'  => 'error',
                'message' => (string) $created['error'],
            );
        }

        Cashback_Social_Auth_Session::clear($provider_id);

        return array(
            'action'  => 'pending',
            'message' => __('Мы отправили письмо для подтверждения регистрации. Проверьте почту.', 'cashback-plugin'),
            'token'   => isset($created['token']) ? (string) $created['token'] : '',
        );
    }

    /**
     * POST /social/email-prompt — обработка ввода email пользователем.
     *
     * @return array{action:string, redirect_url?:string, message?:string}
     */
    public function handle_email_prompt_submission( \WP_REST_Request $request ): array {
        $token   = sanitize_text_field((string) $request->get_param('token'));
        $email   = sanitize_email((string) $request->get_param('email'));
        $consent = (int) $request->get_param('consent');

        if ($token === '' || $email === '') {
            return array(
                'action'  => 'error',
                'message' => __('Заполните email.', 'cashback-plugin'),
            );
        }
        if (!is_email($email)) {
            return array(
                'action'  => 'error',
                'message' => __('Некорректный email.', 'cashback-plugin'),
            );
        }
        if ($consent !== 1) {
            return array(
                'action'  => 'error',
                'message' => __('Необходимо согласиться с условиями.', 'cashback-plugin'),
            );
        }

        // Email уже зарегистрирован?
        if (get_user_by('email', $email) instanceof WP_User) {
            return array(
                'action'  => 'error',
                'message' => __('Этот email уже зарегистрирован. Используйте соответствующий способ входа.', 'cashback-plugin'),
            );
        }

        $pending = Cashback_Social_Auth_DB::consume_pending($token);
        if (!is_array($pending) || ( $pending['kind'] ?? '' ) !== self::KIND_EMAIL_PROMPT) {
            return array(
                'action'  => 'error',
                'message' => __('Ссылка устарела или уже использована. Запросите новый вход через социальную сеть.', 'cashback-plugin'),
            );
        }

        $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : array();

        $provider_id = isset($payload['provider']) ? (string) $payload['provider'] : '';
        $provider    = $this->resolve_provider($provider_id);
        if (!$provider) {
            return array(
                'action'  => 'error',
                'message' => __('Провайдер недоступен.', 'cashback-plugin'),
            );
        }

        $profile          = is_array($payload['profile'] ?? null) ? $payload['profile'] : array();
        $profile['email'] = $email;
        $token_set        = is_array($payload['token_set'] ?? null) ? $payload['token_set'] : array();
        $session_data     = array(
            'redirect_after'    => isset($payload['redirect_after']) ? (string) $payload['redirect_after'] : home_url('/'),
            'referral_snapshot' => is_array($payload['referral_snapshot'] ?? null) ? $payload['referral_snapshot'] : array(),
            'ip'                => isset($payload['ip']) ? (string) $payload['ip'] : '',
            'user_agent'        => isset($payload['user_agent']) ? (string) $payload['user_agent'] : '',
        );

        $created = $this->create_pending_user_and_link($provider, $profile, $token_set, $session_data, $email);
        if (!empty($created['error'])) {
            return array(
                'action'  => 'error',
                'message' => (string) $created['error'],
            );
        }

        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_PENDING_CONSUMED, array(
            'kind'     => self::KIND_EMAIL_PROMPT,
            'provider' => $provider_id,
        ));

        return array(
            'action'  => 'pending',
            'message' => __('Мы отправили письмо для подтверждения регистрации. Проверьте почту.', 'cashback-plugin'),
        );
    }

    /**
     * GET /social/confirm?token=… — подтверждение (confirm_link | email_verify).
     *
     * @return array{action:string, redirect_url?:string, message?:string}
     */
    public function handle_confirm( \WP_REST_Request $request ): array {
        $token = sanitize_text_field((string) $request->get_param('token'));
        if ($token === '') {
            return array(
                'action'  => 'error',
                'message' => __('Нет токена подтверждения.', 'cashback-plugin'),
            );
        }

        $pending = Cashback_Social_Auth_DB::consume_pending($token);
        if (!is_array($pending)) {
            return array(
                'action'  => 'error',
                'message' => __('Ссылка устарела или уже использована. Запросите новый вход через социальную сеть.', 'cashback-plugin'),
            );
        }

        $kind    = isset($pending['kind']) ? (string) $pending['kind'] : '';
        $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : array();

        if ($kind === self::KIND_CONFIRM_LINK) {
            return $this->confirm_link_finish($payload);
        }

        if ($kind === self::KIND_EMAIL_VERIFY) {
            return $this->email_verify_finish($payload);
        }

        return array(
            'action'  => 'error',
            'message' => __('Некорректный тип подтверждения.', 'cashback-plugin'),
        );
    }

    /**
     * Фильтр `authenticate`: блокирует вход по паролю юзерам с pending-флагом.
     *
     * @param mixed $user
     * @return mixed
     */
    public function block_pending_login( $user ) {
        if (!( $user instanceof WP_User )) {
            return $user;
        }
        $flag = (int) get_user_meta($user->ID, self::META_PENDING, true);
        if ($flag === 1) {
            return new WP_Error(
                'cashback_social_pending',
                __('Подтвердите регистрацию через письмо, которое мы отправили.', 'cashback-plugin')
            );
        }
        return $user;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Ветка «привязка из ЛК»: пользователь уже залогинен, нажал «Привязать».
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $token_set
     * @param array<string, mixed> $session_data
     * @return array{action:string, redirect_url?:string, message?:string}
     */
    private function handle_account_link_branch(
        Cashback_Social_Provider_Interface $provider,
        array $profile,
        array $token_set,
        array $session_data
    ): array {
        $provider_id     = $provider->get_id();
        $external_id     = isset($profile['external_id']) ? (string) $profile['external_id'] : '';
        $current_user_id = (int) get_current_user_id();
        $ip              = isset($session_data['ip']) ? (string) $session_data['ip'] : '';
        $user_agent      = isset($session_data['user_agent']) ? (string) $session_data['user_agent'] : '';

        $redirect_base = function_exists('wc_get_account_endpoint_url')
            ? (string) wc_get_account_endpoint_url('cashback-social')
            : home_url('/my-account/cashback-social/');

        $existing = Cashback_Social_Auth_DB::find_link($provider_id, $external_id);
        if (is_array($existing) && !empty($existing['id'])) {
            $owner_id = (int) $existing['user_id'];
            if ($owner_id === $current_user_id) {
                // Уже привязано к тому же юзеру — просто успех.
                $link_id = (int) $existing['id'];
                Cashback_Social_Auth_DB::touch_last_login($link_id, $ip, $user_agent);
                Cashback_Social_Auth_Session::clear($provider_id);
                return array(
                    'action'       => 'login',
                    'redirect_url' => add_query_arg('cashback_social_linked', $provider_id, $redirect_base),
                );
            }

            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'account_link',
                'error'    => 'already_linked_to_other_user',
                'user_id'  => $current_user_id,
            ));
            Cashback_Social_Auth_Session::clear($provider_id);
            return array(
                'action'       => 'login',
                'redirect_url' => add_query_arg('cashback_social_error', 'already_linked', $redirect_base),
            );
        }

        // Создаём связку на текущего юзера.
        $link_id = Cashback_Social_Auth_DB::save_link(array(
            'user_id'            => $current_user_id,
            'provider'           => $provider_id,
            'sub_provider'       => isset($profile['sub_provider']) ? $profile['sub_provider'] : null,
            'external_id'        => $external_id,
            'email_at_link_time' => isset($profile['email']) ? (string) $profile['email'] : null,
            'display_name'       => isset($profile['name']) ? (string) $profile['name'] : null,
            'avatar_url'         => isset($profile['avatar']) ? (string) $profile['avatar'] : null,
            'link_ip'            => $ip,
            'link_user_agent'    => $user_agent,
        ));

        if ($link_id <= 0) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'account_link_save_link',
                'user_id'  => $current_user_id,
            ));
            Cashback_Social_Auth_Session::clear($provider_id);
            return array(
                'action'       => 'login',
                'redirect_url' => add_query_arg('cashback_social_error', 'account_error', $redirect_base),
            );
        }

        $refresh = isset($token_set['refresh_token']) && is_string($token_set['refresh_token']) ? $token_set['refresh_token'] : '';
        if ($refresh !== '') {
            $expires_at = null;
            if (!empty($token_set['expires_in'])) {
                $expires_at = gmdate('Y-m-d H:i:s', time() + (int) $token_set['expires_in']);
            }
            Cashback_Social_Auth_Token_Store::save_tokens(
                $link_id,
                $refresh,
                $expires_at,
                isset($token_set['scope']) ? (string) $token_set['scope'] : ''
            );
        }

        Cashback_Social_Auth_DB::touch_last_login($link_id, $ip, $user_agent);

        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_LINK_CREATED, array(
            'provider' => $provider_id,
            'user_id'  => $current_user_id,
            'link_id'  => $link_id,
            'context'  => 'account_link',
        ));

        // Security-уведомление о новой привязке (не спамим: только при создании).
        $this->send_account_linked_notice($current_user_id, $provider_id, $ip, $user_agent);

        Cashback_Social_Auth_Session::clear($provider_id);

        return array(
            'action'       => 'login',
            'redirect_url' => add_query_arg('cashback_social_linked', $provider_id, $redirect_base),
        );
    }

    /**
     * Создать user + связку + pending(email_verify) + письмо.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $token_set
     * @param array<string, mixed> $session_data
     * @return array{token?:string, user_id?:int, link_id?:int, error?:string}
     */
    private function create_pending_user_and_link(
        Cashback_Social_Provider_Interface $provider,
        array $profile,
        array $token_set,
        array $session_data,
        string $email
    ): array {
        $provider_id    = $provider->get_id();
        $ip             = isset($session_data['ip']) ? (string) $session_data['ip'] : '';
        $user_agent     = isset($session_data['user_agent']) ? (string) $session_data['user_agent'] : '';
        $referral       = isset($session_data['referral_snapshot']) && is_array($session_data['referral_snapshot'])
            ? $session_data['referral_snapshot']
            : array();
        $redirect_after = isset($session_data['redirect_after']) ? (string) $session_data['redirect_after'] : home_url('/');
        $redirect_after = wp_validate_redirect($redirect_after, home_url('/'));

        // Восстанавливаем реферальные cookies в $_COOKIE ДО wp_insert_user,
        // чтобы affiliate-хук (user_register priority 20) их подхватил.
        if (!empty($referral)) {
            Cashback_Social_Auth_Session::restore_referral_snapshot($referral);
        }

        $user_login = $this->generate_unique_login($email, $profile);
        $password   = wp_generate_password(24, true, true);

        $user_data = array(
            'user_login'   => $user_login,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => isset($profile['first_name']) ? (string) $profile['first_name'] : '',
            'last_name'    => isset($profile['last_name']) ? (string) $profile['last_name'] : '',
            'display_name' => isset($profile['name']) && $profile['name'] !== ''
                ? (string) $profile['name']
                : $user_login,
            'role'         => $this->default_role(),
        );

        $user_id = wp_insert_user($user_data);
        if (is_wp_error($user_id)) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'insert_user',
                'error'    => $user_id->get_error_message(),
            ));
            return array(
                'error' => $user_id->get_error_message(),
            );
        }
        $user_id = (int) $user_id;

        update_user_meta($user_id, self::META_PENDING, 1);
        update_user_meta($user_id, self::META_PROVIDER, $provider_id);
        update_user_meta($user_id, self::META_VIA, 1);

        $link_id = Cashback_Social_Auth_DB::save_link(array(
            'user_id'            => $user_id,
            'provider'           => $provider_id,
            'sub_provider'       => isset($profile['sub_provider']) ? $profile['sub_provider'] : null,
            'external_id'        => isset($profile['external_id']) ? (string) $profile['external_id'] : '',
            'email_at_link_time' => $email,
            'display_name'       => isset($profile['name']) ? (string) $profile['name'] : null,
            'avatar_url'         => isset($profile['avatar']) ? (string) $profile['avatar'] : null,
            'link_ip'            => $ip,
            'link_user_agent'    => $user_agent,
        ));

        if ($link_id <= 0) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'save_link',
                'user_id'  => $user_id,
            ));
            return array(
                'error' => __('Не удалось создать связку с социальной сетью.', 'cashback-plugin'),
            );
        }

        // Сохраняем refresh_token, если вернулся.
        $refresh = isset($token_set['refresh_token']) && is_string($token_set['refresh_token']) ? $token_set['refresh_token'] : '';
        if ($refresh !== '') {
            $expires_at = null;
            if (!empty($token_set['expires_in'])) {
                $expires_at = gmdate('Y-m-d H:i:s', time() + (int) $token_set['expires_in']);
            }
            Cashback_Social_Auth_Token_Store::save_tokens(
                $link_id,
                $refresh,
                $expires_at,
                isset($token_set['scope']) ? (string) $token_set['scope'] : ''
            );
        }

        $verify_token = Cashback_Social_Auth_DB::save_pending(
            self::KIND_EMAIL_VERIFY,
            array(
                'user_id'        => $user_id,
                'link_id'        => $link_id,
                'provider'       => $provider_id,
                'redirect_after' => $redirect_after,
            ),
            $ip
        );

        if ($verify_token === '') {
            return array(
                'error' => __('Не удалось подготовить письмо подтверждения.', 'cashback-plugin'),
            );
        }

        $this->send_verify_email_email($user_id, $provider_id, $verify_token);

        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_LINK_CREATED, array(
            'provider' => $provider_id,
            'user_id'  => $user_id,
            'link_id'  => $link_id,
            'status'   => 'pending_verify',
        ));

        return array(
            'token'   => $verify_token,
            'user_id' => $user_id,
            'link_id' => $link_id,
        );
    }

    /**
     * Завершение ветки B (подтверждение привязки существующему юзеру).
     *
     * @param array<string, mixed> $payload
     * @return array{action:string, redirect_url?:string, message?:string}
     */
    private function confirm_link_finish( array $payload ): array {
        $provider_id = isset($payload['provider']) ? (string) $payload['provider'] : '';
        $user_id     = isset($payload['existing_user_id']) ? (int) $payload['existing_user_id'] : 0;
        $external_id = isset($payload['external_id']) ? (string) $payload['external_id'] : '';
        $profile     = is_array($payload['profile'] ?? null) ? $payload['profile'] : array();
        $token_set   = is_array($payload['token_set'] ?? null) ? $payload['token_set'] : array();
        $ip          = isset($payload['ip']) ? (string) $payload['ip'] : '';
        $user_agent  = isset($payload['user_agent']) ? (string) $payload['user_agent'] : '';

        $redirect_after = isset($payload['redirect_after']) ? (string) $payload['redirect_after'] : home_url('/');
        $redirect_after = wp_validate_redirect($redirect_after, home_url('/'));

        if ($user_id <= 0 || $provider_id === '' || $external_id === '') {
            return array(
                'action'  => 'error',
                'message' => __('Повреждённые данные подтверждения.', 'cashback-plugin'),
            );
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return array(
                'action'  => 'error',
                'message' => __('Пользователь не найден.', 'cashback-plugin'),
            );
        }

        // Возможно связка уже создана за время ожидания — проверяем.
        $existing = Cashback_Social_Auth_DB::find_link($provider_id, $external_id);
        if (is_array($existing) && !empty($existing['id'])) {
            $link_id = (int) $existing['id'];
        } else {
            $link_id = Cashback_Social_Auth_DB::save_link(array(
                'user_id'            => $user_id,
                'provider'           => $provider_id,
                'sub_provider'       => isset($profile['sub_provider']) ? $profile['sub_provider'] : null,
                'external_id'        => $external_id,
                'email_at_link_time' => isset($profile['email']) ? (string) $profile['email'] : null,
                'display_name'       => isset($profile['name']) ? (string) $profile['name'] : null,
                'avatar_url'         => isset($profile['avatar']) ? (string) $profile['avatar'] : null,
                'link_ip'            => $ip,
                'link_user_agent'    => $user_agent,
            ));
        }

        if ($link_id <= 0) {
            return array(
                'action'  => 'error',
                'message' => __('Не удалось создать связку.', 'cashback-plugin'),
            );
        }

        $refresh = isset($token_set['refresh_token']) && is_string($token_set['refresh_token']) ? $token_set['refresh_token'] : '';
        if ($refresh !== '') {
            $expires_at = null;
            if (!empty($token_set['expires_in'])) {
                $expires_at = gmdate('Y-m-d H:i:s', time() + (int) $token_set['expires_in']);
            }
            Cashback_Social_Auth_Token_Store::save_tokens(
                $link_id,
                $refresh,
                $expires_at,
                isset($token_set['scope']) ? (string) $token_set['scope'] : ''
            );
        }

        Cashback_Social_Auth_DB::touch_last_login($link_id, $ip, $user_agent);

        $this->login_user($user_id);

        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_LINK_CREATED, array(
            'provider' => $provider_id,
            'user_id'  => $user_id,
            'link_id'  => $link_id,
            'status'   => 'confirmed_link',
        ));
        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_PENDING_CONSUMED, array(
            'kind'    => self::KIND_CONFIRM_LINK,
            'user_id' => $user_id,
        ));

        // Security-уведомление: новая связка через email-подтверждение.
        $this->send_account_linked_notice($user_id, $provider_id, $ip, $user_agent);

        return array(
            'action'       => 'login',
            'redirect_url' => $redirect_after,
        );
    }

    /**
     * Завершение ветки D (double opt-in после регистрации через соц.сеть).
     *
     * @param array<string, mixed> $payload
     * @return array{action:string, redirect_url?:string, message?:string}
     */
    private function email_verify_finish( array $payload ): array {
        $user_id = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        if ($user_id <= 0) {
            return array(
                'action'  => 'error',
                'message' => __('Повреждённые данные подтверждения.', 'cashback-plugin'),
            );
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return array(
                'action'  => 'error',
                'message' => __('Пользователь не найден.', 'cashback-plugin'),
            );
        }

        $redirect_after = isset($payload['redirect_after']) ? (string) $payload['redirect_after'] : home_url('/');
        $redirect_after = wp_validate_redirect($redirect_after, home_url('/'));

        // Снимаем pending-флаг.
        delete_user_meta($user_id, self::META_PENDING);

        // touch_last_login по связке (если известен link_id).
        $link_id = isset($payload['link_id']) ? (int) $payload['link_id'] : 0;
        if ($link_id > 0) {
            Cashback_Social_Auth_DB::touch_last_login($link_id, '', '');
        }

        $this->login_user($user_id);

        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_PENDING_CONSUMED, array(
            'kind'    => self::KIND_EMAIL_VERIFY,
            'user_id' => $user_id,
        ));

        return array(
            'action'       => 'login',
            'redirect_url' => $redirect_after,
        );
    }

    /**
     * Авторизовать пользователя (cookie + current_user + wp_login).
     */
    private function login_user( int $user_id ): void {
        if ($user_id <= 0) {
            return;
        }
        wp_clear_auth_cookie();
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        $user = get_userdata($user_id);
        if ($user instanceof WP_User) {
            do_action('wp_login', $user->user_login, $user);
        }
    }

    /**
     * Сгенерировать уникальный user_login на основе email + профиля.
     *
     * @param array<string, mixed> $profile
     */
    private function generate_unique_login( string $email, array $profile ): string {
        $base = '';

        // Пытаемся использовать локальную часть email.
        if ($email !== '' && strpos($email, '@') !== false) {
            list( $local ) = explode('@', $email, 2);
            $base          = sanitize_user((string) $local, true);
        }

        if ($base === '' && !empty($profile['name'])) {
            $base = sanitize_user((string) $profile['name'], true);
        }
        if ($base === '') {
            $base = 'user';
        }

        // WordPress ограничивает длину user_login 60 символами.
        $base = substr($base, 0, 50);

        $login = $base;
        $i     = 1;
        while (username_exists($login)) {
            $login = $base . '_' . $i;
            if ($i > 200) {
                $login = $base . '_' . wp_rand(1000, 999999);
                break;
            }
            ++$i;
        }

        return $login;
    }

    /**
     * Роль по умолчанию для вновь создаваемых пользователей.
     */
    private function default_role(): string {
        if (class_exists('WooCommerce')) {
            return 'customer';
        }
        $default = (string) get_option('default_role', 'subscriber');
        return $default !== '' ? $default : 'subscriber';
    }

    /**
     * Отправить письмо «подтвердите привязку» (ветка B).
     *
     * Делегирует Cashback_Social_Auth_Emails (брендированная шапка + кнопка в
     * цвет активной темы + футер с поддержкой).
     */
    private function send_confirm_link_email( WP_User $user, string $provider_id, string $token ): void {
        if (!class_exists('Cashback_Social_Auth_Emails')) {
            return;
        }
        $confirm_url = add_query_arg('token', $token, rest_url('cashback/v1/social/confirm'));

        if (class_exists('Cashback_Social_Auth_Audit')) {
            Cashback_Social_Auth_Audit::log('email_send_attempt', array(
                'kind'      => 'social_confirm_link',
                'provider'  => $provider_id,
                'user_id'   => (int) $user->ID,
                'recipient' => (string) $user->user_email,
            ));
        }

        $sent = Cashback_Social_Auth_Emails::instance()->send_confirm_link(
            $user,
            $this->provider_label($provider_id),
            $confirm_url,
            15
        );
        if (!$sent && class_exists('Cashback_Social_Auth_Audit')) {
            Cashback_Social_Auth_Audit::log('email_send_failed', array(
                'kind'      => 'social_confirm_link',
                'provider'  => $provider_id,
                'user_id'   => (int) $user->ID,
                'recipient' => (string) $user->user_email,
            ));
        }
    }

    /**
     * Отправить письмо «подтвердите регистрацию» (ветка D, double opt-in).
     */
    private function send_verify_email_email( int $user_id, string $provider_id, string $token ): void {
        if (!class_exists('Cashback_Social_Auth_Emails')) {
            return;
        }
        $confirm_url = add_query_arg('token', $token, rest_url('cashback/v1/social/confirm'));

        $recipient = '';
        $user_data = get_userdata($user_id);
        if ($user_data instanceof WP_User) {
            $recipient = (string) $user_data->user_email;
        }

        if (class_exists('Cashback_Social_Auth_Audit')) {
            Cashback_Social_Auth_Audit::log('email_send_attempt', array(
                'kind'      => 'social_verify_email',
                'provider'  => $provider_id,
                'user_id'   => $user_id,
                'recipient' => $recipient,
            ));
        }

        $sent = Cashback_Social_Auth_Emails::instance()->send_verify_email(
            $user_id,
            $this->provider_label($provider_id),
            $confirm_url,
            15
        );
        if (!$sent && class_exists('Cashback_Social_Auth_Audit')) {
            Cashback_Social_Auth_Audit::log('email_send_failed', array(
                'kind'      => 'social_verify_email',
                'provider'  => $provider_id,
                'user_id'   => $user_id,
                'recipient' => $recipient,
            ));
        }
    }

    /**
     * Отправить security-уведомление об успешной привязке соцсети (account_linked).
     *
     * Вызывается только при создании новой связки (не при повторном логине),
     * чтобы не спамить юзера.
     */
    private function send_account_linked_notice( int $user_id, string $provider_id, string $ip, string $user_agent ): void {
        if (!class_exists('Cashback_Social_Auth_Emails')) {
            return;
        }
        Cashback_Social_Auth_Emails::instance()->send_account_linked(
            $user_id,
            $this->provider_label($provider_id),
            $ip,
            $user_agent
        );
    }

    /**
     * Чистый список полей профиля для payload (без PII сверх необходимого).
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function sanitize_profile( array $profile ): array {
        $allowed = array( 'external_id', 'email', 'name', 'first_name', 'last_name', 'avatar', 'phone', 'sub_provider' );
        $out     = array();
        foreach ($allowed as $key) {
            if (isset($profile[ $key ])) {
                $out[ $key ] = $profile[ $key ];
            }
        }
        return $out;
    }

    /**
     * Payload не должен содержать access_token — только refresh_token/scope/expires.
     *
     * @param array<string, mixed> $token_set
     * @return array<string, mixed>
     */
    private function filter_token_set( array $token_set ): array {
        return array(
            'refresh_token' => isset($token_set['refresh_token']) ? $token_set['refresh_token'] : null,
            'expires_in'    => isset($token_set['expires_in']) ? (int) $token_set['expires_in'] : 0,
            'scope'         => isset($token_set['scope']) ? (string) $token_set['scope'] : '',
        );
    }

    /**
     * Получить человеко-читаемое название провайдера.
     */
    private function provider_label( string $provider_id ): string {
        switch ($provider_id) {
            case 'yandex':
                return 'Яндекс ID';
            case 'vkid':
                return 'VK ID';
            default:
                return $provider_id;
        }
    }

    /**
     * Резолвер провайдера по id.
     */
    private function resolve_provider( string $id ): ?Cashback_Social_Provider_Interface {
        if (!class_exists('Cashback_Social_Auth_Providers')) {
            return null;
        }
        $registry = Cashback_Social_Auth_Providers::instance();
        $provider = $registry->get($id);
        if (!$provider) {
            return null;
        }
        return $provider;
    }
}
