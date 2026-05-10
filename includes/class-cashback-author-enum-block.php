<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Author_Enum_Block
 *
 * Блокирует утечку username админов через WordPress author enumeration:
 *   - `?author=<id>` ядро WP резолвит в SELECT user_nicename и 301-редиректит
 *     на /author/<slug>/, раскрывая логин для brute-force атак на wp-login.
 *   - `/author/<slug>/` напрямую отдаёт title с username и canonical-link на
 *     /wp-json/wp/v2/users/<id>.
 *
 * REST `/wp/v2/users` уже закрыт через `permission_callback`, но author
 * query-string обходит этот фильтр на уровне rewrite_rules. Закрываем
 * на template_redirect (priority=0, до WP redirect_canonical) и через
 * redirect_canonical filter — single source of truth для обоих путей.
 *
 * Не блокирует wp-admin и пользователей с `list_users` (админ может
 * легитимно открывать author-архивы для модерации).
 *
 * @since 1.3.0
 */
final class Cashback_Author_Enum_Block {

    public static function register(): void {
        add_action('template_redirect', array( __CLASS__, 'block_author_query' ), 0);
        add_filter('redirect_canonical', array( __CLASS__, 'block_canonical_author' ), 10, 2);
    }

    /**
     * Перехват `?author=<id>` и /author/<slug>/ до WP-канонического редиректа.
     */
    public static function block_author_query(): void {
        if (is_admin() || current_user_can('list_users')) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check, no state mutation.
        $has_author_query = isset($_GET['author']) && '' !== $_GET['author'];
        $is_author_path   = (bool) preg_match(
            '#(?:^|/)author/[^/]+/?#i',
            (string) wp_parse_url($request_uri, PHP_URL_PATH)
        );

        if (!$has_author_query && !$is_author_path) {
            return;
        }

        wp_safe_redirect(home_url('/'), 301);
        exit;
    }

    /**
     * Блокирует canonical-редирект на /author/<slug>/, который WordPress
     * пытается выполнить даже после нашего template_redirect в edge-cases.
     *
     * @param string|false $redirect_url   Computed canonical redirect.
     * @param string       $requested_url  Original requested URL (unused, required by filter signature).
     * @return string|false
     */
    public static function block_canonical_author( $redirect_url, $requested_url ) {
        unset( $requested_url );
        if (is_string($redirect_url) && false !== stripos($redirect_url, '/author/')) {
            return false;
        }
        return $redirect_url;
    }
}

Cashback_Author_Enum_Block::register();
