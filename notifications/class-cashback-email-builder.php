<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email HTML builder — единый набор переиспользуемых фрагментов тела письма.
 *
 * Все методы возвращают HTML-кусок БЕЗ внешней обёртки (шапка/футер/карточка).
 * Обёртку добавляет Cashback_Email_Sender::preview_html()/send()/send_admin().
 *
 * Используется одновременно: social-auth письмами, password reset, claims,
 * support, фрод-уведомлениями, health-check и другими отправителями.
 */
final class Cashback_Email_Builder {

    /**
     * Приветствие «Здравствуйте, {name}!».
     */
    public static function greeting( string $user_name ): string {
        return '<p>' . sprintf(
            /* translators: %s: user display name */
            esc_html__('Здравствуйте, %s!', 'cashback-plugin'),
            esc_html($user_name)
        ) . '</p>';
    }

    /**
     * Простой параграф из уже экранированного (или заведомо безопасного) текста.
     *
     * Для текста, пришедшего извне, используйте sprintf с esc_html()
     * на стороне вызывающего кода, либо paragraph_multiline() для пользовательского ввода.
     */
    public static function paragraph( string $html_safe_text ): string {
        return '<p>' . $html_safe_text . '</p>';
    }

    /**
     * Параграф из пользовательского/сырого текста (сохраняет переносы строк).
     */
    public static function paragraph_multiline( string $raw_text ): string {
        return '<p style="white-space:pre-line;margin:0 0 16px;">' . esc_html($raw_text) . '</p>';
    }

    /**
     * CTA-кнопка в брендовом цвете.
     */
    public static function button( string $text, string $url ): string {
        $brand    = self::brand_color();
        $text_col = self::text_color_on_brand($brand);

        $html  = '<p style="margin:24px 0;">';
        $html .= '<a href="' . esc_url($url) . '" style="display:inline-block;padding:12px 24px;background:' . esc_attr($brand) . ';color:' . esc_attr($text_col) . ';text-decoration:none;border-radius:4px;font-weight:bold;">';
        $html .= esc_html($text);
        $html .= '</a>';
        $html .= '</p>';

        return $html;
    }

    /**
     * Мелкая подсказка («Если это были не вы — проигнорируйте»).
     */
    public static function note( string $html_safe_text ): string {
        return '<p style="color:#888;font-size:13px;line-height:1.6;margin:16px 0;">' . $html_safe_text . '</p>';
    }

    /**
     * Стандартная подсказка про срок действия ссылки.
     */
    public static function ttl_note( int $ttl_minutes ): string {
        return self::note(
            sprintf(
                /* translators: %d: TTL in minutes */
                esc_html__('Ссылка действительна %d минут. Если это не вы — просто проигнорируйте письмо.', 'cashback-plugin'),
                $ttl_minutes
            )
        );
    }

    /**
     * Security-advice: «если это были не вы — смените пароль и обратитесь в поддержку».
     *
     * @param string|null $support_url URL поддержки. При null — берётся из WC endpoint, либо /my-account/cashback-support/.
     */
    public static function security_advice( ?string $support_url = null ): string {
        if ($support_url === null) {
            $support_url = function_exists('wc_get_account_endpoint_url')
                ? (string) wc_get_account_endpoint_url('cashback-support')
                : home_url('/my-account/cashback-support/');
        }

        /* translators: %s: support page URL */
        $tpl  = __('Если это были не вы — смените пароль и свяжитесь с <a href="%s">поддержкой</a>.', 'cashback-plugin');
        $html = sprintf(
            wp_kses($tpl, array( 'a' => array( 'href' => array() ) )),
            esc_url($support_url)
        );

        return self::note($html);
    }

    /**
     * Блок метаданных: IP/User-Agent/время. Удобно для security-писем.
     */
    public static function meta_block( string $ip = '', string $user_agent = '', ?int $timestamp = null ): string {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $formatted_time = wp_date(
            get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'),
            $timestamp
        );

        $html  = self::divider();
        $html .= '<p style="color:#888;font-size:12px;line-height:1.6;margin:0;">';
        if ($ip !== '') {
            $html .= esc_html__('IP:', 'cashback-plugin') . ' ' . esc_html($ip) . '<br>';
        }
        if ($user_agent !== '') {
            $html .= esc_html__('Браузер:', 'cashback-plugin') . ' ' . esc_html($user_agent) . '<br>';
        }
        $html .= esc_html__('Время:', 'cashback-plugin') . ' ' . esc_html((string) $formatted_time);
        $html .= '</p>';

        return $html;
    }

    /**
     * Горизонтальная линия-разделитель.
     */
    public static function divider(): string {
        return '<hr style="border:none;border-top:1px solid #eee;margin:24px 0;">';
    }

    /**
     * Блок preformatted-текста для дампов/диагностики (health-check, fraud-digest).
     */
    public static function preformatted( string $text ): string {
        return '<pre style="background:#f7f7f9;border:1px solid #eee;border-radius:4px;padding:12px;font-size:12px;line-height:1.5;color:#333;white-space:pre-wrap;word-break:break-word;font-family:Consolas,Menlo,monospace;">'
            . esc_html($text)
            . '</pre>';
    }

    /**
     * Табличка «ключ / значение» — удобно для писем-уведомлений с атрибутами (support-тикет, фрод).
     *
     * @param array<string, string> $rows Ассоциативный массив: уже локализованная метка → значение (сырой текст).
     */
    public static function definition_list( array $rows ): string {
        if ($rows === array()) {
            return '';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 16px;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr>';
            $html .= '<td style="padding:4px 12px 4px 0;color:#666;font-size:14px;vertical-align:top;">' . esc_html((string) $label) . '</td>';
            $html .= '<td style="padding:4px 0;color:#333;font-size:14px;">' . esc_html((string) $value) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    /**
     * Брендовый цвет (делегирует Cashback_Theme_Color, fallback — #2271b1).
     */
    public static function brand_color(): string {
        if (class_exists('Cashback_Theme_Color')) {
            return Cashback_Theme_Color::get_brand_color();
        }
        return '#2271b1';
    }

    /**
     * Контрастный цвет текста для брендового фона.
     */
    public static function text_color_on_brand( string $brand ): string {
        if (class_exists('Cashback_Theme_Color')) {
            return Cashback_Theme_Color::get_contrast_text_color($brand);
        }
        return '#ffffff';
    }

    /**
     * Готовое тело письма «Аккаунт заблокирован» (бан / фрод-блокировка).
     *
     * Унифицирует содержимое двух разных админских путей (ручной бан в
     * users-management + автоблок в антифроде) — у них совпадает всё,
     * кроме инициирующего действия.
     */
    public static function banned_account_body( string $display_name, string $reason, string $admin_email ): string {
        $body  = self::greeting($display_name);
        $body .= self::paragraph(esc_html__('Ваш аккаунт кэшбэк был заблокирован.', 'cashback-plugin'));
        $body .= self::definition_list(array(
            __('Причина', 'cashback-plugin') => $reason,
            __('Баланс', 'cashback-plugin')  => __('Заморожен', 'cashback-plugin'),
        ));
        $body .= self::paragraph(
            sprintf(
                /* translators: %s: admin email */
                esc_html__('Для разблокировки обратитесь к администратору: %s', 'cashback-plugin'),
                '<a href="mailto:' . esc_attr($admin_email) . '">' . esc_html($admin_email) . '</a>'
            )
        );

        return $body;
    }
}
