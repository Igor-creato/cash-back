<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Центральный отправщик email-уведомлений плагина
 *
 * Единая точка для всех email: HTML-шаблон, проверка предпочтений,
 * глобальные переключатели, брендирование.
 */
class Cashback_Email_Sender {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Отправить email пользователю с проверкой предпочтений
     *
     * @param string $to                Email получателя
     * @param string $subject           Тема письма
     * @param string $message           Текст сообщения (plain text, будет обёрнут в HTML)
     * @param string $notification_type Тип уведомления (slug)
     * @param int|null $user_id         ID пользователя (для проверки предпочтений)
     * @return bool Отправлено или нет
     */
    public function send( string $to, string $subject, string $message, string $notification_type, ?int $user_id = null ): bool {
        // Проверяем глобальную настройку
        if (!Cashback_Notifications_DB::is_globally_enabled($notification_type)) {
            return false;
        }

        // Проверяем предпочтения пользователя
        if ($user_id !== null && !Cashback_Notifications_DB::is_enabled($user_id, $notification_type)) {
            return false;
        }

        $html = $this->render_html_template($subject, $message, $user_id);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->get_from_name() . ' <' . $this->get_from_email() . '>',
        );

        return wp_mail($to, $subject, $html, $headers);
    }

    /**
     * Отправить email всем администраторам
     *
     * @param string $subject           Тема письма
     * @param string $message           Текст сообщения
     * @param string $notification_type Тип уведомления
     */
    public function send_admin( string $subject, string $message, string $notification_type ): void {
        if (!Cashback_Notifications_DB::is_globally_enabled($notification_type)) {
            return;
        }

        $admins = get_users(array( 'role__in' => array( 'administrator', 'shop_manager' ) ));
        $html   = $this->render_html_template($subject, $message);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->get_from_name() . ' <' . $this->get_from_email() . '>',
        );

        foreach ($admins as $admin) {
            wp_mail($admin->user_email, $subject, $html, $headers);
        }
    }

    /**
     * Публичная обёртка для предпросмотра письма (используется массовой рассылкой).
     *
     * @param string   $subject Тема.
     * @param string   $message Тело сообщения (будет обёрнуто в брендированный шаблон).
     * @param int|null $user_id ID получателя (для ссылки на настройки уведомлений).
     * @return string HTML-документ.
     */
    public function preview_html( string $subject, string $message, ?int $user_id = null ): string {
        return $this->render_html_template($subject, $message, $user_id);
    }

    /**
     * Обёртка HTML-шаблона письма
     *
     * @param string   $subject Тема (для заголовка)
     * @param string   $message Текст сообщения
     * @param int|null $user_id ID пользователя (для ссылки на настройки)
     * @return string HTML
     */
    private function render_html_template( string $subject, string $message, ?int $user_id = null ): string {
        $site_name   = $this->get_from_name();
        $site_url    = home_url('/');
        $signature   = (string) get_option('cashback_email_signature', '');
        $brand_color = Cashback_Theme_Color::get_brand_color();
        $text_color  = Cashback_Theme_Color::get_contrast_text_color($brand_color);
        $logo_url    = $this->get_logo_url();

        $settings_link = '';
        if ($user_id !== null && function_exists('wc_get_account_endpoint_url')) {
            $settings_link = wc_get_account_endpoint_url('cashback-notifications');
        }

        $html  = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>' . esc_html($subject) . '</title></head>';
        $html .= '<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,Helvetica,sans-serif;">';

        // Контейнер
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;">';
        $html .= '<tr><td align="center" style="padding:24px 16px;">';

        // Карточка
        $html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">';

        // Шапка
        $html .= '<tr><td style="background:' . esc_attr($brand_color) . ';padding:20px 32px;">';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>';

        if ($logo_url !== null) {
            $html .= '<td style="padding-right:12px;vertical-align:middle;">';
            $html .= '<a href="' . esc_url($site_url) . '" style="text-decoration:none;display:inline-block;">';
            $html .= '<img src="' . esc_url($logo_url) . '" height="40" alt="' . esc_attr($site_name) . '" style="display:block;border:0;max-height:40px;width:auto;">';
            $html .= '</a></td>';
        }

        $html .= '<td style="vertical-align:middle;">';
        $html .= '<a href="' . esc_url($site_url) . '" style="color:' . esc_attr($text_color) . ';text-decoration:none;font-size:20px;font-weight:bold;">';
        $html .= esc_html($site_name);
        $html .= '</a></td>';

        $html .= '</tr></table>';
        $html .= '</td></tr>';

        // Тело
        $html .= '<tr><td style="padding:32px;color:#333333;font-size:15px;line-height:1.6;">';
        $html .= '<p style="white-space:pre-line;margin:0 0 16px;">' . wp_kses_post($message) . '</p>';

        if ($signature !== '') {
            $html .= '<p style="white-space:pre-line;margin:24px 0 0;color:#555555;font-size:14px;">';
            $html .= nl2br(esc_html($signature));
            $html .= '</p>';
        }

        $html .= '</td></tr>';

        // Футер
        $html .= '<tr><td style="padding:16px 32px;border-top:1px solid #eee;color:#999999;font-size:12px;">';
        $html .= '<p style="margin:0 0 8px;">' . esc_html__('Это автоматическое сообщение, не отвечайте на него.', 'cashback-plugin') . '</p>';

        if ($settings_link) {
            $html .= '<p style="margin:0;">';
            $html .= '<a href="' . esc_url($settings_link) . '" style="color:' . esc_attr($brand_color) . ';text-decoration:underline;">';
            $html .= esc_html__('Настроить уведомления', 'cashback-plugin');
            $html .= '</a></p>';
        }

        $html .= '</td></tr>';

        $html .= '</table>';
        $html .= '</td></tr></table>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * URL логотипа сайта.
     *
     * Цепочка источников:
     * 1) Woodmart Header Builder (опции whb_main_header → whb_<id>);
     * 2) WP Customize → custom_logo;
     * 3) Site Icon (favicon HD) как мягкий fallback;
     * 4) null — шапка письма рендерится без <img>.
     */
    private function get_logo_url(): ?string {
        $woodmart_logo = $this->get_woodmart_logo_url();
        if ($woodmart_logo !== null) {
            return $woodmart_logo;
        }

        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id > 0) {
            $url = wp_get_attachment_image_url($logo_id, 'medium');
            if ($url !== false && $url !== '') {
                return $url;
            }
        }

        $site_icon = get_site_icon_url(192);
        if (is_string($site_icon) && $site_icon !== '') {
            return $site_icon;
        }

        return null;
    }

    /**
     * Извлечь URL логотипа из Woodmart Header Builder.
     *
     * Структура: get_option('whb_main_header') → ID активного header'а.
     * Опция whb_<id> содержит дерево elements; ищем рекурсивно элемент
     * с params.image (URL/ID вложения) — это и есть логотип.
     */
    private function get_woodmart_logo_url(): ?string {
        $header_id = get_option('whb_main_header');
        if (!is_string($header_id) || $header_id === '') {
            return null;
        }

        $header_data = get_option('whb_' . $header_id);
        if (!is_array($header_data) || empty($header_data['elements'])) {
            return null;
        }

        $image = $this->find_logo_image_in_elements($header_data['elements']);
        if (!is_array($image)) {
            return null;
        }

        if (!empty($image['url']) && is_string($image['url'])) {
            return $image['url'];
        }

        if (!empty($image['id'])) {
            $url = wp_get_attachment_image_url((int) $image['id'], 'medium');
            if ($url !== false && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * Рекурсивный обход элементов Header Builder для поиска параметра image.
     *
     * @param array $elements
     * @return array|null Массив с ключами url/id, либо null.
     */
    private function find_logo_image_in_elements( array $elements ): ?array {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (isset($element['params']['image']) && is_array($element['params']['image'])) {
                $image = $element['params']['image'];
                if (!empty($image['url']) || !empty($image['id'])) {
                    return $image;
                }
            }

            foreach (array( 'elements', 'columns', 'rows', 'children' ) as $nested_key) {
                if (!empty($element[ $nested_key ]) && is_array($element[ $nested_key ])) {
                    $found = $this->find_logo_image_in_elements($element[ $nested_key ]);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Имя отправителя
     */
    private function get_from_name(): string {
        return get_option('cashback_email_sender_name', get_option('blogname', 'Cashback'));
    }

    /**
     * Email отправителя
     *
     * Приоритет: cashback_email_sender_email → admin_email → fallback
     */
    private function get_from_email(): string {
        $sender = get_option('cashback_email_sender_email', '');
        if ($sender && is_email($sender)) {
            return $sender;
        }

        return get_option('admin_email', 'noreply@' . wp_parse_url(home_url(), PHP_URL_HOST));
    }
}
