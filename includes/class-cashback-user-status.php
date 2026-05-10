<?php
/**
 * Класс для проверки статуса пользователя (banned)
 *
 * Централизованная проверка статуса пользователя для блокировки
 * забаненных пользователей от доступа к функционалу кэшбэк-сервиса.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс Cashback_User_Status
 */
class Cashback_User_Status {

    /**
     * Per-request flag: текст сообщения о бане, установленный block_banned_login().
     * Читается override_login_error() — обходит clearfy-pro/anti-enumeration плагины,
     * которые маскируют все login-ошибки одним generic-сообщением через `login_errors`.
     *
     * @var string
     */
    private static $banned_login_message = '';

    /**
     * Проверяет, забанен ли пользователь
     *
     * @param int $user_id ID пользователя
     * @return bool true если забанен
     */
    public static function is_user_banned( int $user_id ): bool {
        global $wpdb;

        $table  = $wpdb->prefix . 'cashback_user_profile';
        $status = $wpdb->get_var( $wpdb->prepare(
            'SELECT status FROM %i WHERE user_id = %d',
            $table,
            $user_id
        ) );

        return $status === 'banned';
    }

    /**
     * Получает информацию о бане пользователя.
     *
     * Возвращает ПУБЛИЧНУЮ причину `ban_reason` (для user-facing message
     * через get_banned_message) и `banned_at` (для audit-log).
     *
     * Codex adversarial-review round 14 (2026-05-10): метод вызывается с
     * frontend-path (фильтр `wp_authenticate_user` через block_banned_login,
     * cashback-withdrawal.php). Поле `ban_reason_admin` (v6 column) НЕ
     * читается callers'ом из return-значения — оно осталось как dead-branch
     * с OBS-06 (admin handler читает его через свой собственный SELECT в
     * admin/users-management.php, защищённый round-13 schema-guard'ом). Чтобы
     * frontend login/cabinet НЕ упирались в SQL error 1054 при transient
     * v6 migration failure, не селектим ban_reason_admin отсюда.
     *
     * @param int $user_id ID пользователя
     * @return array{banned_at:?string,ban_reason:?string}|null
     */
    public static function get_ban_info( int $user_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_user_profile';
        $info  = $wpdb->get_row( $wpdb->prepare(
            "SELECT banned_at, ban_reason FROM %i
             WHERE user_id = %d AND status = 'banned'",
            $table,
            $user_id
        ), ARRAY_A );

        if ( ! $info ) {
            return null;
        }

        // Гарантируем наличие ключа ban_reason (публичная причина).
        $info['ban_reason'] = isset( $info['ban_reason'] ) ? (string) $info['ban_reason'] : '';

        return $info;
    }

    /**
     * Генерирует ПУБЛИЧНОЕ сообщение для забаненного пользователя.
     *
     * Использует только `ban_reason` (публичная причина). Поле `ban_reason_admin`
     * НИКОГДА не показывается пользователю. Если публичная причина пустая —
     * показываем generic message (OBS-06 fix + UX choice 2026-04-30).
     *
     * @param array|null $ban_info Информация о бане из get_ban_info()
     * @return string Сообщение для пользователя
     */
    public static function get_banned_message( ?array $ban_info = null ): string {
        if ( $ban_info && ! empty( $ban_info['ban_reason'] ) ) {
            return sprintf(
                /* translators: %s: публичная причина блокировки аккаунта */
                __('Ваш аккаунт заблокирован. Причина: %s. Для разблокировки обратитесь к администратору.', 'cashback-plugin'),
                esc_html( $ban_info['ban_reason'] )
            );
        }

        return __('Ваш аккаунт заблокирован. Для разблокировки обратитесь к администратору.', 'cashback-plugin');
    }

    /**
     * Фильтр `wp_authenticate_user`: блокирует логин забаненных пользователей.
     *
     * Срабатывает ПОСЛЕ проверки пароля, ДО установки auth-cookie. Возврат
     * WP_Error отменяет логин и показывает стандартный экран ошибки WP.
     *
     * Закрывает OBS-05 (E2E run B 2026-04-30): banned юзер с status='banned'
     * в `cashback_user_profile` всё равно мог залогиниться и видеть личный
     * кабинет (хоть withdrawal был заблокирован отдельно).
     *
     * @param WP_User|WP_Error $user
     * @return WP_User|WP_Error
     */
    public static function block_banned_login( $user ) {
        // Если на этапе аутентификации уже WP_Error (неверный пароль и пр.) —
        // не вмешиваемся, чтобы не маскировать другие ошибки.
        if ( ! ( $user instanceof WP_User ) ) {
            return $user;
        }

        if ( ! self::is_user_banned( (int) $user->ID ) ) {
            return $user;
        }

        $ban_info     = self::get_ban_info( (int) $user->ID );
        $banned_msg   = self::get_banned_message( $ban_info );

        // Сохраняем сообщение в per-request static — override_login_error()
        // подставит его обратно, если anti-enumeration плагин (clearfy-pro и пр.)
        // через `login_errors` filter заменил на generic «Неверный логин/пароль».
        self::$banned_login_message = $banned_msg;

        // Audit-log: фиксируем попытку логина забаненным юзером (VULN-03).
        // 152-ФЗ ст. 9 + ЦБ требования — security event на финансовом сервисе.
        // G2 ADR audit-log-completeness: try/Throwable обёртка обязательна.
        if ( class_exists( 'Cashback_Encryption' ) ) {
            try {
                Cashback_Encryption::write_audit_log(
                    'banned_login_attempt',
                    0,
                    'user',
                    (int) $user->ID,
                    array(
                        'login'     => $user->user_login,
                        'banned_at' => $ban_info['banned_at'] ?? null,
                    )
                );
            } catch ( \Throwable $e ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Audit telemetry fail-soft (G2 ADR).
                error_log( '[cashback-audit] banned_login_attempt: ' . $e->getMessage() );
            }
        }

        return new WP_Error( 'cashback_user_banned', $banned_msg );
    }

    /**
     * Фильтр `woocommerce_login_errors`: заменяет generic-сообщение WC
     * на наше специфичное «Ваш аккаунт заблокирован...» для banned юзеров.
     *
     * Закрывает OBS-05 cosmetic (E2E run B 2026-04-30): после `block_banned_login`
     * возвращает WP_Error с кодом `cashback_user_banned`, но WC показывает свой
     * generic «Неверный логин или пароль» (защита от перебора). Здесь
     * проверяем коды ошибок и подставляем читаемое сообщение, если бан.
     *
     * @param WP_Error $errors
     * @return WP_Error
     */
    /**
     * Фильтр `login_errors` (WP core) с приоритетом > 10 — перезаписывает
     * generic-сообщения от anti-enumeration плагинов (clearfy-pro и пр.) на
     * наше специфичное «Ваш аккаунт заблокирован...», если в текущем запросе
     * уже сработал block_banned_login() и установил per-request flag.
     *
     * Это работает для WC (line ~1096 class-wc-form-handler.php применяет
     * apply_filters( 'login_errors', $e->getMessage() )) и для стандартного
     * WP wp-login.php.
     *
     * @param string $message
     * @return string
     */
    public static function override_login_error( $message ) {
        if ( self::$banned_login_message !== '' ) {
            return self::$banned_login_message;
        }
        return $message;
    }

    public static function override_wc_login_error( $errors ) {
        if ( ! ( $errors instanceof WP_Error ) ) {
            return $errors;
        }

        $codes = $errors->get_error_codes();
        if ( empty( $codes ) || ! in_array( 'cashback_user_banned', $codes, true ) ) {
            return $errors;
        }

        // Берём первое сообщение от нашего кода (set'нутого block_banned_login).
        $messages = $errors->get_error_messages( 'cashback_user_banned' );
        if ( empty( $messages ) ) {
            return $errors;
        }

        // Возвращаем WP_Error с ТОЛЬКО нашим сообщением — WC отрисует его
        // на странице логина без подмеса generic-текста.
        return new WP_Error( 'cashback_user_banned', $messages[0] );
    }
}
