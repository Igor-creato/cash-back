<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claims Notifications Module
 *
 * Sends email notifications for:
 * - Claim creation (to user)
 * - Status changes (to user)
 * - New claim alerts (to admins)
 */
class Cashback_Claims_Notifications
{
    public function __construct()
    {
        add_action('cashback_claim_created', [$this, 'notify_user_created'], 10, 3);
        add_action('cashback_claim_created', [$this, 'notify_admin_new_claim'], 10, 3);
        add_action('cashback_claim_status_changed', [$this, 'notify_user_status_changed'], 10, 6);
    }

    /**
     * Notify user when claim is created.
     */
    public function notify_user_created(int $claim_id, int $user_id, array $data): void
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $subject = sprintf(
            /* translators: %d: claim ID */
            __('Заявка на кэшбэк #%d создана', 'cashback-plugin'),
            $claim_id
        );

        $message = sprintf(
            /* translators: 1: product name, 2: order ID, 3: claim ID */
            __('Ваша заявка на неначисленный кэшбэк принята.

Магазин: %1$s
Номер заказа: %2$s
ID заявки: %3$s

Вы можете отслеживать статус заявки в личном кабинете: %4$s', 'cashback-plugin'),
            $data['product_name'] ?? '—',
            $data['order_id'] ?? '—',
            $claim_id,
            wc_get_account_endpoint_url('cashback_lost_cashback')
        );

        $this->send_email($user->user_email, $subject, $message);
    }

    /**
     * Notify admins about new claim.
     */
    public function notify_admin_new_claim(int $claim_id, int $user_id, array $data): void
    {
        $admins = get_users(['role__in' => ['administrator', 'shop_manager']]);

        foreach ($admins as $admin) {
            $subject = sprintf(
                /* translators: %d: claim ID */
                __('Новая заявка на кэшбэк #%d', 'cashback-plugin'),
                $claim_id
            );

            $user = get_user_by('id', $user_id);

            $message = sprintf(
                /* translators: 1: username, 2: product, 3: order ID, 4: claim ID */
                __('Пользователь %1$s подал заявку на неначисленный кэшбэк.

Магазин: %2$s
Номер заказа: %3$s
ID заявки: %4$s

Просмотрите заявку в админ-панели.', 'cashback-plugin'),
                $user ? $user->display_name : '—',
                $data['product_name'] ?? '—',
                $data['order_id'] ?? '—',
                $claim_id
            );

            $this->send_email($admin->user_email, $subject, $message);
        }
    }

    /**
     * Notify user when claim status changes.
     */
    public function notify_user_status_changed(int $claim_id, string $old_status, string $new_status, string $note, string $actor_type, ?int $actor_id): void
    {
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

        $status_labels = [
            'submitted'       => __('Отправлена', 'cashback-plugin'),
            'sent_to_network' => __('Отправлена партнёру', 'cashback-plugin'),
            'approved'        => __('Одобрена', 'cashback-plugin'),
            'declined'        => __('Отклонена', 'cashback-plugin'),
        ];

        $label = $status_labels[$new_status] ?? $new_status;

        $subject = sprintf(
            /* translators: 1: claim ID, 2: new status */
            __('Статус заявки #%1$s изменён на "%2$s"', 'cashback-plugin'),
            $claim_id,
            $label
        );

        $message = sprintf(
            /* translators: 1: claim ID, 2: new status label, 3: admin note, 4: claims URL */
            __('Статус вашей заявки #%1$s изменён на "%2$s".

%3$s

Просмотреть заявку: %4$s', 'cashback-plugin'),
            $claim_id,
            $label,
            $note ? __('Комментарий: ', 'cashback-plugin') . $note : '',
            wc_get_account_endpoint_url('cashback_lost_cashback')
        );

        $this->send_email($user->user_email, $subject, $message);
    }

    /**
     * Send email with plugin branding.
     */
    private function send_email(string $to, string $subject, string $message): void
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
        ];

        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
        $html .= '<h2 style="color: #333;">' . esc_html(get_option('blogname')) . '</h2>';
        $html .= '<p style="white-space: pre-line;">' . wp_kses_post($message) . '</p>';
        $html .= '<hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">';
        $html .= '<p style="color: #999; font-size: 12px;">' . esc_html__('Это автоматическое сообщение, не отвечайте на него.', 'cashback-plugin') . '</p>';
        $html .= '</div>';

        wp_mail($to, $subject, $html, $headers);
    }
}
