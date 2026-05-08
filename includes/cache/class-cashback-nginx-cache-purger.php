<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Инвалидатор nginx fastcgi_cache при изменении WC-товаров.
 *
 * Вычисляет имя файла кэша nginx по той же формуле, что директива
 * `fastcgi_cache_key "$scheme$request_method$host$request_uri";`, и
 * удаляет его через unlink. Доступ к каталогу кэша обеспечивается через
 * shared docker volume + унификацию UID/GID 33:33 между nginx и WP.
 *
 * Mapping cache key → file path:
 *   md5_full = md5(scheme + method + host + path)
 *   levels=1:2 → последний 1 char / 2 предыдущих / md5_full
 *   path = $cache_root/<md5[-1]>/<md5[-3:-1]>/<md5_full>
 *
 * @since 4.0.0
 */
class Cashback_Nginx_Cache_Purger {

    /** Option-флаг (kill-switch без редеплоя). */
    public const OPTION_ENABLED = 'cashback_nginx_purger_enabled';

    /** Default-путь, если env CASHBACK_NGINX_CACHE_PATH не задан. */
    private const DEFAULT_CACHE_ROOT = '/var/cache/nginx/fastcgi';

    /** Префикс лог-сообщений (паттерн consistency с importer'ом). */
    private const LOG_PREFIX = '[Cashback Nginx Purger]';

    /**
     * Кэш результата preflight() на request: null=не проверялось, true/false=результат.
     *
     * @var bool|null
     */
    private static $preflight_result = null;

    /** Включён ли purger (option + env). */
    public static function is_enabled(): bool {
        return (bool) get_option(self::OPTION_ENABLED, '1');
    }

    /** Корень fastcgi_cache (чтение env с fallback). */
    public static function get_cache_root(): string {
        $env = getenv('CASHBACK_NGINX_CACHE_PATH');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        return self::DEFAULT_CACHE_ROOT;
    }

    /**
     * Собрать абсолютный путь cache-файла по URL и HTTP-методу.
     *
     * URL нормализуется: удаляются query/fragment, дефолтный путь = '/'.
     * Хост приводится к нижнему регистру, без порта (за reverse-proxy host
     * совпадает с тем, что видит nginx — без порта в `$host`).
     */
    public static function build_cache_path( string $url, string $method = 'GET' ): string {
        $parsed = wp_parse_url($url);
        $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : 'https';
        $host   = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
        $path   = isset($parsed['path']) && $parsed['path'] !== '' ? (string) $parsed['path'] : '/';

        $key = $scheme . strtoupper($method) . $host . $path;
        $md5 = md5($key);

        return sprintf(
            '%s/%s/%s/%s',
            self::get_cache_root(),
            substr($md5, -1),
            substr($md5, -3, 2),
            $md5
        );
    }

    /**
     * Удалить cache-файл для URL. Idempotent.
     *
     * @return bool true — файла не было ИЛИ успешно удалили; false — ошибка.
     */
    public static function purge_url( string $url ): bool {
        if (!self::is_enabled() || !self::preflight()) {
            return false;
        }

        $path = self::build_cache_path($url);

        if (!file_exists($path)) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- direct unlink (WP_Filesystem не маунтит /var/cache/nginx); @ подавляет E_WARNING при race-condition с nginx, который мог уже удалить файл по inactive=60m.
        $ok = @unlink($path);
        if (!$ok) {
            self::log('warning', 'unlink failed', array( 'path' => $path, 'url' => $url ));
        }
        return (bool) $ok;
    }

    /**
     * Удалить кэш для всех страниц, которые отображают данный товар.
     *
     * @return int Количество вызовов purge_url (== число затронутых URL).
     */
    public static function purge_post( int $post_id, ?string $reason = null ): int {
        if ($post_id <= 0 || !self::is_enabled()) {
            return 0;
        }

        $urls = self::collect_urls_for_post($post_id);
        $count = 0;
        foreach ($urls as $url) {
            self::purge_url($url);
            ++$count;
        }

        if ($count > 0) {
            self::log('debug', 'purged post', array(
                'post_id' => $post_id,
                'reason'  => $reason,
                'urls'    => $count,
            ));
        }

        return $count;
    }

    /**
     * Полный flush кэша (обход cache_root, unlink всех файлов).
     *
     * @return int Количество удалённых файлов.
     */
    public static function purge_all(): int {
        if (!self::is_enabled() || !self::preflight()) {
            return 0;
        }

        $root = self::get_cache_root();
        $count = 0;

        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- direct unlink (WP_Filesystem не маунтит /var/cache/nginx); @ для idempotency при race с nginx inactive-eviction.
                    if (@unlink($file->getPathname())) {
                        ++$count;
                    }
                }
            }
        } catch (\Throwable $e) {
            self::log('warning', 'purge_all failed', array( 'error' => $e->getMessage() ));
        }

        return $count;
    }

    /**
     * Собрать список URL для инвалидации при изменении товара.
     *
     * Текущее покрытие: главная + permalink товара + архивы product_cat/product_tag.
     * Backlog: pagination варианты, query-форсаж URL'ы — если будут жалобы.
     *
     * @return string[]
     */
    private static function collect_urls_for_post( int $post_id ): array {
        $urls = array();

        // Главная — там карточки магазинов в Woodmart Custom Loop.
        $urls[] = home_url('/');

        // Permalink товара (если ещё не удалён).
        $perm = get_permalink($post_id);
        if (is_string($perm) && $perm !== '') {
            $urls[] = $perm;
        }

        // Архивы product_cat / product_tag — товар может присутствовать в каждом.
        // Duck-typing вместо instanceof WP_Term: WP сам гарантирует тип, но
        // get_term_link принимает либо int, либо WP_Term — нам важен term_id.
        if (function_exists('get_the_terms') && function_exists('get_term_link')) {
            foreach (array( 'product_cat', 'product_tag' ) as $taxonomy) {
                $terms = get_the_terms($post_id, $taxonomy);
                if (!is_array($terms)) {
                    continue;
                }
                foreach ($terms as $term) {
                    if (!is_object($term) || !isset($term->term_id)) {
                        continue;
                    }
                    $link = get_term_link((int) $term->term_id, $taxonomy);
                    if (is_string($link) && $link !== '') {
                        $urls[] = $link;
                    }
                }
            }
        }

        // Дедуп URL внутри одного post (на случай если permalink == home_url).
        return array_values(array_unique($urls));
    }

    /**
     * Проверить доступность каталога. Кэширует результат на request,
     * при ошибке логирует ровно один раз.
     */
    private static function preflight(): bool {
        if (self::$preflight_result !== null) {
            return self::$preflight_result;
        }

        $root = self::get_cache_root();
        $ok = is_dir($root) && is_writable($root);
        self::$preflight_result = $ok;

        if (!$ok) {
            self::log('warning', 'cache root unavailable, purger disabled for this request', array(
                'path'     => $root,
                'is_dir'   => is_dir($root),
                'writable' => is_writable($root),
            ));
        }

        return $ok;
    }

    /** Reset preflight-кэша (для unit-тестов). */
    public static function reset_preflight_for_tests(): void {
        self::$preflight_result = null;
    }

    /**
     * Логирование через error_log (Cashback_Logger в плагине отсутствует —
     * используем тот же паттерн, что importer/sync).
     *
     * @param array<string,mixed> $context
     */
    private static function log( string $level, string $msg, array $context = array() ): void {
        $line = self::LOG_PREFIX . ' [' . $level . '] ' . $msg;
        if (!empty($context)) {
            $json = wp_json_encode($context);
            if (is_string($json)) {
                $line .= ' ' . $json;
            }
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
        error_log($line);
    }
}
