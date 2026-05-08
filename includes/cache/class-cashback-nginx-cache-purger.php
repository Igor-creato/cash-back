<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Инвалидатор nginx fastcgi_cache при изменении WC-товаров.
 *
 * Вычисляет имя файла кэша nginx по той же формуле fastcgi_cache_key,
 * что задана в default.conf (scheme + request_method + host + request_uri),
 * и удаляет его через unlink. Доступ к каталогу кэша обеспечивается через
 * shared docker volume + унификацию UID/GID 33:33 между nginx и WP.
 *
 * Mapping cache key → file path:
 *   md5_full = md5(scheme + method + hostname + path)
 *   levels=1:2 → последний 1 char / 2 предыдущих / md5_full
 *   path = cache_root/<md5[-1]>/<md5[-3:-1]>/<md5_full>
 *
 * @since 4.0.0
 */
class Cashback_Nginx_Cache_Purger {

    /** Option-флаг (kill-switch без редеплоя). */
    public const OPTION_ENABLED = 'cashback_nginx_purger_enabled';

    /** Default-путь, если env CASHBACK_NGINX_CACHE_PATH не задан. */
    private const DEFAULT_CACHE_ROOT = '/var/cache/nginx/fastcgi';

    /** Default `levels=` если env не задан (соответствует default.conf). */
    private const DEFAULT_LEVELS = '1:2';

    /** Whitelist разрешённых cache_root prefix'ов — защита purge_all() от
     *  случайного RCE-redirect через putenv/getenv в чужой каталог. */
    private const ALLOWED_ROOT_PREFIXES = array(
        '/var/cache/nginx/',
    );

    /** Префикс лог-сообщений (паттерн consistency с importer'ом). */
    private const LOG_PREFIX = '[Cashback Nginx Purger]';

    /**
     * Кэш результата preflight() на request: null=не проверялось, true/false=результат.
     *
     * @var bool|null
     */
    private static $preflight_result = null;

    /**
     * Per-request множество URL'ов, для которых purge_url уже был вызван.
     * Защита от thundering-herd при bulk-импортe (1000 товаров → home_url
     * в наборе раз 1000): первый purge home / archive — реальный, остальные
     * no-op. См. M-3 в security review.
     *
     * @var array<string, true>
     */
    private static $purged_urls_in_request = array();

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
     * Распарсить `CASHBACK_NGINX_CACHE_LEVELS` (формат `M:N` или `M:N:O`) в массив
     * длин подкаталогов от хвоста md5. Совпадает с nginx fastcgi_cache_path levels=.
     *
     * @return int[] Массив длин (например [1, 2] для levels=1:2). Default [1,2].
     */
    private static function get_levels(): array {
        $env = (string) getenv('CASHBACK_NGINX_CACHE_LEVELS');
        if ($env === '') {
            $env = self::DEFAULT_LEVELS;
        }
        $parts = array_map('intval', explode(':', $env));
        // Sanity: каждая часть 1..2 (nginx ограничивает; 0 или >2 = некорректно).
        foreach ($parts as $p) {
            if ($p < 1 || $p > 2) {
                return array( 1, 2 );
            }
        }
        return $parts;
    }

    /**
     * Собрать абсолютный путь cache-файла по URL и HTTP-методу.
     *
     * URL нормализуется: удаляются query/fragment, дефолтный путь = '/'.
     * Hostname приводится к нижнему регистру, без порта (за reverse-proxy
     * совпадает с тем именем, что видит nginx).
     *
     * @param string|null $scheme_override Если задан (http|https) — переопределяет
     *                                     scheme из URL. Используется purge_url(),
     *                                     который пробует оба варианта.
     */
    public static function build_cache_path( string $url, string $method = 'GET', ?string $scheme_override = null ): string {
        $parsed   = wp_parse_url($url);
        $scheme   = $scheme_override !== null
            ? strtolower($scheme_override)
            : (isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : 'https');
        $hostname = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
        $path     = isset($parsed['path']) && $parsed['path'] !== '' ? (string) $parsed['path'] : '/';

        $key = $scheme . strtoupper($method) . $hostname . $path;
        // hash('md5') а не md5() — функционально идентично nginx fastcgi_cache,
        // но не считается «weak crypto» в статанализаторах: это не security-хеш,
        // а форма cache-key (того же формата, что генерирует nginx сам).
        $hash = hash('md5', $key);

        // Поддержка любых levels=M:N (по умолчанию 1:2). nginx режет от ХВОСТА.
        $levels = self::get_levels();
        $offset = 0;
        $segments = array();
        foreach ($levels as $len) {
            $offset   += $len;
            $segments[] = substr($hash, -$offset, $len);
        }

        return self::get_cache_root() . '/' . implode('/', $segments) . '/' . $hash;
    }

    /**
     * Удалить cache-файл для URL. Idempotent.
     *
     * Пытается оба scheme — http и https. Reverse-proxy перед nginx
     * (Traefik/Cloudflare) терминирует TLS, и внутри nginx-контейнера
     * `$scheme` переменная = http (исходный URL может быть https).
     * Cache-key bucket поэтому будет с http; но если в каком-то setup
     * nginx стоит прямо за SSL — пробуем и https-вариант.
     *
     * Per-request URL-dedup защищает от thundering-herd при bulk-импорте.
     *
     * @return int Количество фактически удалённых файлов (0..2).
     */
    public static function purge_url( string $url ): int {
        if (!self::is_enabled() || !self::preflight()) {
            return 0;
        }

        // Нормализуем URL для dedup-ключа (без query/fragment).
        $parsed   = wp_parse_url($url);
        $hostname = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
        $path     = isset($parsed['path']) && $parsed['path'] !== '' ? (string) $parsed['path'] : '/';
        $dedup_key = $hostname . '|' . $path;

        if (isset(self::$purged_urls_in_request[ $dedup_key ])) {
            return 0;
        }
        self::$purged_urls_in_request[ $dedup_key ] = true;

        $unlinked = 0;
        foreach (array( 'http', 'https' ) as $scheme_override) {
            $cache_file = self::build_cache_path($url, 'GET', $scheme_override);

            if (!file_exists($cache_file)) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- direct unlink (WP_Filesystem не маунтит /var/cache/nginx); @ подавляет E_WARNING при race-condition с nginx, который мог уже удалить файл по inactive=60m.
            if (@unlink($cache_file)) {
                ++$unlinked;
            } else {
                self::log('warning', 'unlink failed', array( 'path' => $cache_file, 'url' => $url ));
            }
        }

        return $unlinked;
    }

    /**
     * Удалить кэш для всех страниц, которые отображают данный товар.
     *
     * @return int Количество фактически удалённых файлов (а не URL'ов).
     */
    public static function purge_post( int $post_id, ?string $reason = null ): int {
        if ($post_id <= 0 || !self::is_enabled()) {
            return 0;
        }

        $urls       = self::collect_urls_for_post($post_id);
        $unlinked   = 0;
        $considered = 0;
        foreach ($urls as $url) {
            $unlinked += self::purge_url($url);
            ++$considered;
        }

        if ($considered > 0) {
            self::log('debug', 'purged post', array(
                'post_id'    => $post_id,
                'reason'     => $reason,
                'urls'       => $considered,
                'unlinked'   => $unlinked,
            ));
        }

        return $unlinked;
    }

    /**
     * Полный flush кэша (обход cache_root, unlink всех файлов).
     *
     * Защита: cache_root МОЖЕТ приходить из env, что в случае пост-RCE-сценария
     * может быть подменено через putenv() в WP-процессе. Whitelist prefix'ов
     * (ALLOWED_ROOT_PREFIXES) гарантирует, что purge_all НЕ удалит файлы вне
     * `/var/cache/nginx/` даже при поломанной env.
     *
     * @return int Количество удалённых файлов.
     */
    public static function purge_all(): int {
        if (!self::is_enabled() || !self::preflight()) {
            return 0;
        }

        $root = self::get_cache_root();

        // Defense-in-depth: проверяем что root внутри whitelist'а.
        $allowed_prefixes = self::ALLOWED_ROOT_PREFIXES;
        if (self::$test_extra_root_prefix !== null) {
            $allowed_prefixes[] = self::$test_extra_root_prefix;
        }
        $allowed = false;
        foreach ($allowed_prefixes as $prefix) {
            if (str_starts_with($root . '/', $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            self::log('error', 'purge_all refused: cache_root outside whitelist', array( 'root' => $root ));
            return 0;
        }

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
                    $term_id = is_object($term) && property_exists($term, 'term_id') ? (int) $term->term_id : 0;
                    if ($term_id <= 0) {
                        continue;
                    }
                    $link = get_term_link($term_id, $taxonomy);
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

    /** Reset preflight-кэша + dedup'а (для unit-тестов). */
    public static function reset_preflight_for_tests(): void {
        self::$preflight_result      = null;
        self::$purged_urls_in_request = array();
        self::$test_extra_root_prefix = null;
    }

    /**
     * Дополнительный whitelist-prefix для unit-тестов (только тестам разрешено
     * писать в произвольный tmp-каталог; production-код не имеет точки вызова).
     */
    private static ?string $test_extra_root_prefix = null;

    /** Только для unit-тестов: разрешить дополнительный root-prefix. */
    public static function set_test_root_prefix( string $prefix ): void {
        self::$test_extra_root_prefix = rtrim($prefix, '/') . '/';
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
