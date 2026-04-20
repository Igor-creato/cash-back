<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Сервис личного кабинета для мобильного приложения.
 *
 * Агрегирует: профиль (display_name, status, ban-reason, min_payout, cashback_rate),
 * баланс (available/pending/paid), привязанные социальные аккаунты, партнёрскую ссылку,
 * настройки уведомлений по каналам (email/push).
 *
 * Тонкая обёртка над существующими классами — не дублирует бизнес-логику.
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Me_Service {

    public const NOTIFICATION_PUSH_META  = 'cashback_push_prefs';
    public const NOTIFICATION_EMAIL_META = 'cashback_email_prefs';

    /**
     * Агрегированный профиль.
     *
     * @return array
     */
    public static function get_profile( int $user_id ): array {
        if ($user_id <= 0) {
            throw new InvalidArgumentException('get_profile: user_id must be > 0');
        }
        global $wpdb;

        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return array();
        }

        $balance_table = $wpdb->prefix . 'cashback_user_balance';
        $profile_table = $wpdb->prefix . 'cashback_user_profile';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped read.
        $balance = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT available_balance, pending_balance, paid_balance, frozen_balance
                 FROM %i WHERE user_id = %d',
                $balance_table,
                $user_id
            ),
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped read.
        $profile = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT cashback_rate, status, ban_reason, min_payout_amount, payout_method_id
                 FROM %i WHERE user_id = %d',
                $profile_table,
                $user_id
            ),
            ARRAY_A
        );

        $socials = self::get_linked_socials($user_id);

        return array(
            'user_id'             => $user_id,
            'email'               => $user->user_email,
            'display_name'        => $user->display_name,
            'balance'             => array(
                'available' => (float) ( $balance['available_balance'] ?? 0 ),
                'pending'   => (float) ( $balance['pending_balance'] ?? 0 ),
                'paid'      => (float) ( $balance['paid_balance'] ?? 0 ),
                'frozen'    => (float) ( $balance['frozen_balance'] ?? 0 ),
            ),
            'cashback_rate'       => (float) ( $profile['cashback_rate'] ?? 60.0 ),
            'status'              => (string) ( $profile['status'] ?? 'active' ),
            'ban_reason'          => null === ( $profile['ban_reason'] ?? null ) ? null : (string) $profile['ban_reason'],
            'min_payout'          => (float) ( $profile['min_payout_amount'] ?? 100.0 ),
            'has_payout_settings' => ( (int) ( $profile['payout_method_id'] ?? 0 ) ) > 0,
            'linked_socials'      => $socials,
            'referral_link'       => self::get_referral_link($user_id),
            'created_at'          => $user->user_registered,
        );
    }

    /**
     * Обновить профиль (display_name сейчас — единственное поле).
     *
     * @return array Обновлённый профиль.
     */
    public static function update_profile( int $user_id, array $fields ): array {
        if (isset($fields['display_name'])) {
            $display = sanitize_text_field((string) $fields['display_name']);
            // Ограничиваем длину — БД-столбец VARCHAR(250) по WP-стандарту.
            $display = mb_substr($display, 0, 100);
            if ('' !== $display) {
                wp_update_user(array(
                    'ID'           => $user_id,
                    'display_name' => $display,
                ));
            }
        }

        return self::get_profile($user_id);
    }

    /**
     * Список привязанных соцаккаунтов (для UI).
     *
     * @return array<int,array<string,mixed>>
     */
    private static function get_linked_socials( int $user_id ): array {
        if (!class_exists('Cashback_Social_Auth_DB')) {
            return array();
        }
        $links = Cashback_Social_Auth_DB::get_links_for_user($user_id);
        $out   = array();
        foreach ((array) $links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $out[] = array(
                'provider'      => (string) ( $link['provider'] ?? '' ),
                'display_name'  => (string) ( $link['display_name'] ?? '' ),
                'email'         => (string) ( $link['email_at_link_time'] ?? '' ),
                'avatar_url'    => (string) ( $link['avatar_url'] ?? '' ),
                'linked_at'     => (string) ( $link['linked_at'] ?? '' ),
                'last_login_at' => (string) ( $link['last_login_at'] ?? '' ),
            );
        }
        return $out;
    }

    /**
     * Реферальная ссылка пользователя (`?ref=<partner_token>`).
     */
    private static function get_referral_link( int $user_id ): string {
        if (class_exists('Cashback_Affiliate_Service')) {
            try {
                return Cashback_Affiliate_Service::get_referral_link($user_id);
            } catch (\Throwable $e) {
                unset($e); // fallthrough к generic-ссылке ниже.
            }
        }
        return add_query_arg('ref', (string) $user_id, home_url('/'));
    }

    // =========================================================================
    // Notifications settings
    // =========================================================================

    /**
     * Вернуть текущие настройки каналов по типам уведомлений.
     *
     * Формат:
     *   ['email' => [type => bool], 'push' => [type => bool], 'types' => [type => {label}]]
     */
    public static function get_notification_settings( int $user_id ): array {
        $email_prefs = array();
        if (class_exists('Cashback_Notifications_DB')) {
            $email_prefs = (array) Cashback_Notifications_DB::get_user_preferences($user_id);
        }
        $push_prefs = (array) get_user_meta($user_id, self::NOTIFICATION_PUSH_META, true);

        $types = self::available_types();
        // Заполняем defaults (по умолчанию всё включено).
        $email = array();
        $push  = array();
        foreach ($types as $type => $_label) {
            $email[ $type ] = isset($email_prefs[ $type ]) ? (bool) $email_prefs[ $type ] : true;
            $push[ $type ]  = isset($push_prefs[ $type ]) ? (bool) $push_prefs[ $type ] : true;
        }

        return array(
            'email' => $email,
            'push'  => $push,
            'types' => $types,
        );
    }

    /**
     * Сохранить выборочные тогглы.
     *
     * @param array $changes ['email' => [type => bool], 'push' => [type => bool]]
     */
    public static function update_notification_settings( int $user_id, array $changes ): array {
        $types = self::available_types();

        // Email — через Cashback_Notifications_DB::set_preference (если есть).
        if (isset($changes['email']) && is_array($changes['email']) && class_exists('Cashback_Notifications_DB')) {
            foreach ($changes['email'] as $type => $enabled) {
                if (!isset($types[ $type ])) {
                    continue;
                }
                if (self::is_required_type((string) $type)) {
                    continue; // critical notifications — нельзя отключить.
                }
                Cashback_Notifications_DB::set_preference($user_id, (string) $type, (bool) $enabled);
            }
        }

        // Push — хранится в user_meta (до Phase 8 это просто флаги).
        if (isset($changes['push']) && is_array($changes['push'])) {
            $current = (array) get_user_meta($user_id, self::NOTIFICATION_PUSH_META, true);
            foreach ($changes['push'] as $type => $enabled) {
                if (!isset($types[ $type ])) {
                    continue;
                }
                if (self::is_required_type((string) $type)) {
                    continue;
                }
                $current[ (string) $type ] = (bool) $enabled;
            }
            update_user_meta($user_id, self::NOTIFICATION_PUSH_META, $current);
        }

        return self::get_notification_settings($user_id);
    }

    /**
     * @return array<string,string> type => label
     */
    private static function available_types(): array {
        // Переиспользуем если доступен админский список.
        if (class_exists('Cashback_Notifications_DB') && method_exists('Cashback_Notifications_DB', 'get_user_notification_types')) {
            /** @var array<string,string> $list */
            $list = (array) Cashback_Notifications_DB::get_user_notification_types();
            if (!empty($list)) {
                return $list;
            }
        }
        // Fallback — минимальный набор.
        return array(
            'transaction_new'      => __('New transactions', 'cashback'),
            'transaction_status'   => __('Transaction status changes', 'cashback'),
            'cashback_credited'    => __('Cashback credited', 'cashback'),
            'ticket_reply'         => __('Support replies', 'cashback'),
            'claim_status'         => __('Claim status changes', 'cashback'),
            'affiliate_referral'   => __('New referrals', 'cashback'),
            'affiliate_commission' => __('Referral commission', 'cashback'),
            'broadcast'            => __('Announcements', 'cashback'),
        );
    }

    private static function is_required_type( string $type ): bool {
        return in_array($type, array( 'social_confirm_link', 'social_verify_email' ), true);
    }

    // =========================================================================
    // Account deletion (soft)
    // =========================================================================

    /**
     * Soft-delete аккаунта: status='deleted' + revoke всех refresh-токенов.
     * Требует подтверждение паролем (вызывающий код должен провалидировать).
     *
     * @return array|WP_Error
     */
    public static function soft_delete( int $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_profile';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- targeted update.
        $ok = $wpdb->update(
            $table,
            array( 'status' => 'deleted' ),
            array( 'user_id' => $user_id ),
            array( '%s' ),
            array( '%d' )
        );

        if (false === $ok) {
            return new WP_Error('delete_failed', __('Could not mark account as deleted.', 'cashback'), array( 'status' => 500 ));
        }

        if (class_exists('Cashback_Refresh_Token_Store')) {
            Cashback_Refresh_Token_Store::revoke_all_for_user($user_id, 'account_deleted');
        }

        do_action('cashback_mobile_account_deleted', $user_id);

        return array( 'ok' => true );
    }
}
