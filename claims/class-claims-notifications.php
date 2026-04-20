<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claims Notifications Module
 *
 * Sends email notifications for:
 * - Claim creation (to user, slug claim_created)
 * - Status changes (to user, slug claim_status)
 * - New claim alerts (to admins, slug claim_admin_alert)
 *
 * Все письма идут через Cashback_Email_Sender — общая брендированная обёртка
 * (шапка с логотипом, футер со ссылкой «Настроить уведомления»).
 */
class Cashback_Claims_Notifications {

    public function __construct() {
        add_action('cashback_claim_created', array( $this, 'notify_user_created' ), 10, 3);
        add_action('cashback_claim_created', array( $this, 'notify_admin_new_claim' ), 10, 3);
        add_action('cashback_claim_status_changed', array( $this, 'notify_user_status_changed' ), 10, 6);
    }

    /**
     * Notify user when claim is created.
     */
    public function notify_user_created( int $claim_id, int $user_id, array $data ): void {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $subject = sprintf(
            /* translators: %d: claim ID */
            __('Заявка на кэшбэк #%d создана', 'cashback-plugin'),
            $claim_id
        );

        $claims_url = function_exists('wc_get_account_endpoint_url')
            ? (string) wc_get_account_endpoint_url('cashback_lost_cashback')
            : home_url('/my-account/cashback_lost_cashback/');

        $body  = Cashback_Email_Builder::greeting($this->display_name($user));
        $body .= Cashback_Email_Builder::paragraph(
            esc_html__('Ваша заявка на неначисленный кэшбэк принята.', 'cashback-plugin')
        );
        $body .= Cashback_Email_Builder::definition_list(array(
            __('Магазин', 'cashback-plugin')      => (string) ( $data['product_name'] ?? '—' ),
            __('Номер заказа', 'cashback-plugin') => (string) ( $data['order_id'] ?? '—' ),
            __('ID заявки', 'cashback-plugin')    => (string) $claim_id,
        ));
        $body .= Cashback_Email_Builder::button(
            __('Открыть заявку', 'cashback-plugin'),
            $claims_url
        );

        $this->send_to_user($user, $subject, $body, 'claim_created');
    }

    /**
     * Notify admins about new claim.
     */
    public function notify_admin_new_claim( int $claim_id, int $user_id, array $data ): void {
        if (!class_exists('Cashback_Email_Sender')) {
            return;
        }

        $user = get_user_by('id', $user_id);

        $subject = sprintf(
            /* translators: %d: claim ID */
            __('Новая заявка на кэшбэк #%d', 'cashback-plugin'),
            $claim_id
        );

        $body = Cashback_Email_Builder::paragraph(
            sprintf(
                /* translators: %s: username */
                esc_html__('Пользователь %s подал заявку на неначисленный кэшбэк.', 'cashback-plugin'),
                esc_html($user ? $this->display_name($user) : '—')
            )
        );
        $body .= Cashback_Email_Builder::definition_list(array(
            __('Магазин', 'cashback-plugin')      => (string) ( $data['product_name'] ?? '—' ),
            __('Номер заказа', 'cashback-plugin') => (string) ( $data['order_id'] ?? '—' ),
            __('ID заявки', 'cashback-plugin')    => (string) $claim_id,
        ));
        $body .= Cashback_Email_Builder::button(
            __('Открыть в админке', 'cashback-plugin'),
            admin_url('admin.php?page=cashback-claims&action=view&claim_id=' . $claim_id)
        );

        Cashback_Email_Sender::get_instance()->send_admin($subject, $body, 'claim_admin_alert');
    }

    /**
     * Notify user when claim status changes.
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by cashback_claim_status_changed action signature.
    public function notify_user_status_changed( int $claim_id, string $old_status, string $new_status, string $note, string $actor_type, ?int $actor_id ): void {
        global $wpdb;

        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM `{$wpdb->prefix}cashback_claims` WHERE claim_id = %d",
            $claim_id
        ), ARRAY_A);

        if (!$claim) {
            return;
        }

        $user = get_user_by('id', (int) $claim['user_id']);
        if (!$user) {
            return;
        }

        $status_labels = array(
            'submitted'       => __('Отправлена', 'cashback-plugin'),
            'sent_to_network' => __('Отправлена партнёру', 'cashback-plugin'),
            'approved'        => __('Одобрена', 'cashback-plugin'),
            'declined'        => __('Отклонена', 'cashback-plugin'),
        );

        $label = $status_labels[ $new_status ] ?? $new_status;

        $subject = sprintf(
            /* translators: 1: claim ID, 2: new status */
            __('Статус заявки #%1$s изменён на "%2$s"', 'cashback-plugin'),
            $claim_id,
            $label
        );

        $claims_url = function_exists('wc_get_account_endpoint_url')
            ? (string) wc_get_account_endpoint_url('cashback_lost_cashback')
            : home_url('/my-account/cashback_lost_cashback/');

        $body  = Cashback_Email_Builder::greeting($this->display_name($user));
        $body .= Cashback_Email_Builder::paragraph(
            sprintf(
                /* translators: 1: claim ID, 2: new status label */
                esc_html__('Статус вашей заявки #%1$s изменён на «%2$s».', 'cashback-plugin'),
                (int) $claim_id,
                esc_html($label)
            )
        );
        if ($note !== '') {
            $body .= Cashback_Email_Builder::paragraph_multiline(
                __('Комментарий: ', 'cashback-plugin') . $note
            );
        }
        $body .= Cashback_Email_Builder::button(
            __('Открыть заявку', 'cashback-plugin'),
            $claims_url
        );

        $this->send_to_user($user, $subject, $body, 'claim_status');
    }

    /**
     * Унифицированная отправка пользователю через Cashback_Email_Sender.
     */
    private function send_to_user( WP_User $user, string $subject, string $body_html, string $notification_type ): void {
        if (!class_exists('Cashback_Email_Sender')) {
            return;
        }
        Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $body_html,
            $notification_type,
            (int) $user->ID
        );
    }

    private function display_name( WP_User $user ): string {
        return $user->display_name !== '' ? $user->display_name : $user->user_login;
    }
}
