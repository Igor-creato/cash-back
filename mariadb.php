<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Защита от прямого доступа
}

/**
 * Основной класс плагина для управления базой данных кэшбэка
 */
class Mariadb_Plugin {

    /**
     * Экземпляр класса (singleton)
     */
    private static $instance = null;

    /**
     * Получить экземпляр класса
     *
     * @return self
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Конструктор
     */
    private function __construct() {
        add_action('user_register', function ( int $user_id ): void {
            $this->add_user_to_cashback_tables($user_id);
        });

        add_action('wp_login', function ( string $user_login, $user ): void {
            $this->add_user_to_cashback_tables((int) $user->ID);
        }, 10, 2);
    }

    /**
     * Валидация и санитизация префикса таблицы
     * Защита от потенциальных SQL-инъекций через префикс
     *
     * @param string $prefix Префикс таблицы
     * @return string Безопасный префикс
     * @throws Exception Если префикс содержит недопустимые символы
     */
    private function validate_table_prefix( string $prefix ): string {
        // Префикс может содержать только буквы, цифры и подчеркивания
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new Exception('Invalid table prefix detected: ' . esc_html($prefix));
        }
        return $prefix;
    }

    /**
     * Активация плагина
     *
     * @return void
     */
    public static function activate(): void {
        $instance = self::get_instance();

        // Подавляем вывод при создании таблиц и триггеров
        ob_start();

        try {
            $instance->ensure_users_table_innodb();
            $instance->create_tables();
            $instance->create_triggers();
            $instance->create_events();
            // Cleanup legacy option (Codex round 5, 2026-05-10): после удаления
            // PHP-фолбэков `cashback_triggers_active` больше не имеет смысла.
            // Удаляем для existing-installs где option остался в wp_options
            // от предыдущих версий плагина.
            delete_option('cashback_triggers_active');
            $instance->initialize_existing_users();
            $instance->migrate_rate_history_enum();
            $instance->migrate_ledger_ban_enum();
            $instance->migrate_backfill_ledger_accruals();
            $instance->migrate_drop_notification_triggers();
            $instance->migrate_add_transaction_reference_id();
            $instance->migrate_unregistered_reference_id_prefix();
            $instance->migrate_add_frozen_balance_buckets();
            $instance->migrate_schema_idempotency_v1();
            $instance->migrate_add_click_sessions_v1();
            $instance->migrate_add_transaction_created_by_admin();
            $instance->migrate_normalize_charset_v4();
            $instance->migrate_payout_require_fail_reason_v5();
            $instance->migrate_split_ban_reason_v6();
            $instance->migrate_payout_unfreeze_v7();
            $instance->migrate_promocodes_v8();
            $instance->migrate_promocodes_v9_click_id();
            $instance->migrate_click_log_promocode_id_v10();
            $instance->migrate_promocodes_v11_session_promocode_id();
            $instance->migrate_shop_import_v12();
            $instance->migrate_cleanup_ghost_members_v13();
            $instance->migrate_advcake_seed_v14();
            // v15 ранее не был подключён к runner'у (chain обрывался на v14) —
            // идемпотентен (db_version fast-path + INFORMATION_SCHEMA guard),
            // подключаем перед v16: иначе bump до 16 навсегда застрелил бы его
            // `>= 15` fast-path и UNIQUE(network_id,param_name) не появился бы.
            $instance->migrate_v15_uniqueness();
            $instance->migrate_dedup_identity_v16();
            $instance->migrate_dedup_identity_backfill_v17();

            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('Mariadb Plugin Activation Error', array(
                    'source' => 'cashback-mariadb',
                    'error'  => $e->getMessage(),
                    'file'   => $e->getFile(),
                    'line'   => $e->getLine(),
                ));
            }
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('Mariadb Plugin Activation Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }

            // Codex round 6 #3 (2026-05-10): trigger-failure от create_triggers()
            // распознаём по message-prefix и показываем явное диагностическое
            // сообщение про SUPER privilege вместо generic-фразы.
            if (strpos($e->getMessage(), 'Failed to create cashback triggers:') === 0) {
                wp_die(
                    wp_kses_post(
                        '<h1>' . esc_html__('Cashback Plugin Activation Failed', 'cashback-plugin') . '</h1>'
                        . '<p>' . esc_html__('Plugin requires SUPER privilege for CREATE TRIGGER on this MariaDB host. Schema-level financial integrity guards (ledger triggers, payout immutability, fail_reason invariant) cannot be installed.', 'cashback-plugin') . '</p>'
                        . '<p><strong>' . esc_html__('Error:', 'cashback-plugin') . '</strong> ' . esc_html($e->getMessage()) . '</p>'
                        . '<p>' . esc_html__('Contact hosting provider to grant SUPER privilege or migrate to a self-managed MariaDB instance.', 'cashback-plugin') . '</p>'
                    ),
                    esc_html__('Cashback Plugin: DDL Permission Error', 'cashback-plugin'),
                    array( 'response' => 500, 'back_link' => true )
                );
            }

            wp_die(esc_html__('Ошибка активации плагина. Подробности записаны в журнал.', 'cashback-plugin'));
        }
    }

    /**
     * Конвертация wp_users в InnoDB если используется MyISAM.
     * Необходимо для создания FK constraints к wp_users.
     */
    private function ensure_users_table_innodb(): void {
        global $wpdb;

        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->users
        ));

        if ($engine && strtolower($engine) !== 'innodb') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->users, not user input.
            $wpdb->query("ALTER TABLE `{$wpdb->users}` ENGINE=InnoDB");
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log("Mariadb Plugin: Converted {$wpdb->users} from {$engine} to InnoDB");
        }
    }

    /**
     * Создание таблиц
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // ---------------------------------------------------------------
        // Фаза 1: Создание таблиц без FOREIGN KEY / CHECK / GENERATED
        // WordPress dbDelta() и некоторые конфигурации MySQL/MariaDB
        // не поддерживают эти конструкции внутри CREATE TABLE.
        // Ограничения добавляются отдельно в Фазе 2.
        // ---------------------------------------------------------------

        // Таблица способов выплат (справочник, без зависимостей)
        $table_payout_methods = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_payout_methods` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `slug` varchar(50) NOT NULL COMMENT 'Уникальный идентификатор (например: sbp, mir, yoomoney)',
            `name` varchar(100) NOT NULL COMMENT 'Отображаемое название (например: СБП, МИР, ЮMoney)',
            `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = способ доступен для выбора',
            `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки в интерфейсе',
            `bank_required` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = для этого способа нужно выбрать банк',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_slug` (`slug`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Способы выплат пользователей';";

        // Таблица банков (справочник, без зависимостей)
        $table_banks = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_banks` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `bank_code` varchar(50) NOT NULL COMMENT 'Уникальный код банка (например: sber, tinkoff, vtbc)',
            `name` varchar(100) NOT NULL COMMENT 'Полное название банка',
            `short_name` varchar(50) DEFAULT NULL COMMENT 'Краткое название банка',
            `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = банк доступен для выбора',
            `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки в интерфейсе',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_bank_code` (`bank_code`),
            KEY `idx_active_sort_name` (`is_active`,`sort_order`,`name`) COMMENT 'Оптимизация выборки активных банков с сортировкой',
            KEY `idx_name_active` (`name`,`is_active`) COMMENT 'Оптимизация поиска банков по названию'
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Список банков для выплат';";

        // Таблица партнерских сетей (справочник, без зависимостей)
        $table_affiliate_networks = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_affiliate_networks` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL COMMENT 'Название партнера',
            `slug` varchar(100) NOT NULL COMMENT 'Уникальный идентификатор',
            `notes` text DEFAULT NULL COMMENT 'Примечание',
            `api_base_url` varchar(500) DEFAULT NULL COMMENT 'Base URL API сети (например https://api.admitad.com)',
            `api_auth_type` enum('oauth2','api_key') NOT NULL DEFAULT 'oauth2' COMMENT 'Тип авторизации API',
            `api_credentials` BLOB DEFAULT NULL COMMENT 'AES-256 зашифрованные credentials (JSON)',
            `api_user_field` varchar(100) DEFAULT NULL COMMENT 'Имя поля в API, содержащего user_id (subid для Admitad)',
            `api_click_field` varchar(100) DEFAULT NULL COMMENT 'Имя поля в API, содержащего click_id (subid1 для Admitad)',
            `api_status_map` text DEFAULT NULL COMMENT 'JSON маппинг статусов сети → локальные',
            `api_field_map` text DEFAULT NULL COMMENT 'JSON маппинг полей API → колонки таблицы транзакций',
            `api_actions_endpoint` varchar(500) DEFAULT NULL COMMENT 'Endpoint для получения действий (/statistics/actions/)',
            `api_token_endpoint` varchar(500) DEFAULT NULL COMMENT 'Endpoint для получения токена (/token/)',
            `api_coupons_endpoint` varchar(500) DEFAULT NULL COMMENT 'Endpoint купонов с placeholder-ами {website_id}/{advcampaign_id}/{limit}/{offset} (v8)',
            `api_coupons_field_map` longtext DEFAULT NULL COMMENT 'JSON маппинг полей купонов API → DTO (v8)',
            `api_coupons_species_map` longtext DEFAULT NULL COMMENT 'JSON normalize raw type/species → canonical promocode|deal (v8)',
            `api_coupons_pagination` varchar(32) DEFAULT NULL COMMENT 'Pagination strategy для купонов: offset_limit|page|none (v8)',
            `api_website_id` varchar(100) DEFAULT NULL COMMENT 'ID площадки в CPA-сети (для фильтрации)',
            `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки',
            `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = активен',
            `click_session_policy` VARCHAR(32) NOT NULL DEFAULT 'reuse_in_window' COMMENT 'Политика повторного клика (12i): reuse_in_window | always_new | reuse_per_product',
            `activation_window_seconds` INT UNSIGNED NOT NULL DEFAULT 1800 COMMENT 'Per-network окно активации в секундах (60..86400, default 30 min) — 12i',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_slug` (`slug`),
            KEY `idx_active_sort` (`is_active`,`sort_order`,`name`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Партнерские сети';";

        // Таблица параметров партнерских сетей (FK добавляется в Фазе 2)
        $table_affiliate_network_params = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_affiliate_network_params` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `network_id` bigint(20) unsigned NOT NULL COMMENT 'ID партнерской сети',
            `param_name` varchar(100) NOT NULL COMMENT 'Название параметра',
            `param_type` varchar(100) DEFAULT NULL COMMENT 'Значение параметра',
            `default_value` varchar(255) DEFAULT NULL COMMENT 'Значение по умолчанию',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_network_param` (`network_id`,`param_name`),
            KEY `idx_network_id` (`network_id`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Параметры партнерских сетей';";

        // Таблица cashback_payout_requests (FK и CHECK добавляются в Фазе 2)
        $table_payout_requests = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_payout_requests` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reference_id` varchar(11) NOT NULL DEFAULT '' COMMENT 'Публичный ID заявки формата WD-XXXXXXXX',
            `user_id` bigint(20) unsigned NOT NULL,
            `total_amount` decimal(18,2) NOT NULL,
            `payout_method` varchar(50) DEFAULT NULL COMMENT 'Slug способа выплаты из cashback_payout_methods',
            `payout_account` varchar(255) NOT NULL COMMENT 'Реквизиты получателя (номер телефона, карты и т.п.)',
            `encrypted_details` BLOB DEFAULT NULL COMMENT 'AES-256-CBC зашифрованные реквизиты (снапшот)',
            `masked_details` TEXT DEFAULT NULL COMMENT 'Маскированные реквизиты для отображения (JSON)',
            `provider` varchar(100) DEFAULT NULL COMMENT 'Идентификатор провайдера выплат (банк/сервис)',
            `provider_payout_id` varchar(255) DEFAULT NULL COMMENT 'ID операции у провайдера',
            `idempotency_key` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'UUIDv7 идемпотентный ключ (32 hex)',
            `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Количество попыток отправки выплаты',
            `fail_reason` text DEFAULT NULL COMMENT 'Код/описание ошибки последней попытки',
            `status` enum('waiting','processing','paid','failed','declined','needs_retry') NOT NULL DEFAULT 'waiting',
            `refunded_at` datetime DEFAULT NULL COMMENT 'Время возврата средств после failed-статуса',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_reference_id` (`reference_id`),
            UNIQUE KEY `uk_idempotency` (`idempotency_key`),
            KEY `idx_user_status` (`user_id`,`status`),
            KEY `idx_status_updated` (`status`,`updated_at`),
            KEY `idx_provider_payout_id` (`provider_payout_id`),
            KEY `idx_refunded` (`refunded_at`),
            KEY `idx_payout_method_slug` (`payout_method`),
            KEY `idx_user_created` (`user_id`,`created_at` DESC),
            KEY `idx_stats_created_at` (`created_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Заявки на выплаты с защитой от дублирования';";

        // Таблица cashback_transactions (FK и CHECK добавляются в Фазе 2)
        $table_transactions = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_transactions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL COMMENT 'id пользователя на сайте',
            `order_number` varchar(255) NOT NULL COMMENT 'Номер заказа у партнгера',
            `offer_id` int unsigned DEFAULT NULL COMMENT 'ID партнёрской программы в CPA-сети (advcampaign_id). Стабилен, в отличие от offer_name',
            `offer_name` varchar(255) DEFAULT NULL COMMENT 'Название конкретного партнера например Алиэкспресс',
            `order_status` enum('waiting','completed','declined','hold','balance') NOT NULL DEFAULT 'waiting' COMMENT 'Статусы конверсии',
            `partner` varchar(255) DEFAULT NULL COMMENT 'Название CPA',
            `sum_order` decimal(10,2) DEFAULT NULL COMMENT 'Сумма заказа или покупки',
            `comission` decimal(10,2) DEFAULT NULL COMMENT 'Комиссия выплачиваемая за покупку',
            `currency` char(3) NOT NULL DEFAULT 'RUB' COMMENT 'Валюта комиссии (ISO 4217). Без неё невозможно корректно сравнивать суммы',
            `uniq_id` varchar(255) DEFAULT NULL COMMENT 'Уникальный id конверсии в внутри конкретной CPA, между несколькими могут совпадать',
            `cashback` decimal(10,2) DEFAULT NULL COMMENT 'Размер выплачиваемого кэшбэка',
            `applied_cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка на момент создания транзакции',
            `api_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Транзакция сверена с API. Основной триггер начисления в баланс',
            `action_date` datetime DEFAULT NULL COMMENT 'Реальное время покупки. НЕ путать с created_at (время получения хука)',
            `click_time` datetime DEFAULT NULL COMMENT 'Время клика. Для антифрода: action_date - click_time = 0 бот',
            `click_id` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'UUIDv7 клика без дефисов (32 hex), связь с cashback_click_log.click_id',
            `website_id` int unsigned DEFAULT NULL COMMENT 'ID площадки в CPA-сети',
            `action_type` varchar(10) DEFAULT NULL COMMENT 'sale/lead. Для корректного расчёта при нескольких тарифах',
            `processed_at` datetime DEFAULT NULL COMMENT 'Когда транзакция была учтена в балансе',
            `processed_batch_id` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'UUIDv7 батча начисления (32 hex)',
            `idempotency_key` varchar(64) DEFAULT NULL COMMENT 'Ключ идемпотентности для предотвращения дублирования транзакций',
            `original_cpa_subid` varchar(255) DEFAULT NULL COMMENT 'Оригинальный subid2 переданный в CPA при клике. Для перенесённых из unregistered = значение user_id на момент клика (например: unregistered)',
            `spam_click` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = транзакция из подозрительного клика, кэшбэк только после ручной проверки',
            `funds_ready` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = CPA-сеть подтвердила готовность средств к снятию (Admitad: processed=1, EPN: status=approved)',
            `reference_id` varchar(11) NOT NULL DEFAULT '' COMMENT 'Публичный ID транзакции формата TX-XXXXXXXX',
            `created_by_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = транзакция создана админом вручную (Сверка баланса → зависший claim), 0 = пришла по API/постбэку CPA',
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_uniq_partner` (`uniq_id`,`partner`),
            UNIQUE KEY `idx_idempotency_key` (`idempotency_key`),
            UNIQUE KEY `uk_tx_reference_id` (`reference_id`),
            KEY `user_id` (`user_id`),
            KEY `idx_user_created` (`user_id`,`created_at` DESC),
            KEY `idx_processed` (`processed_at`),
            KEY `idx_processed_batch_id` (`processed_batch_id`),
            KEY `idx_click_id` (`click_id`),
            KEY `idx_offer_id` (`offer_id`),
            KEY `idx_balance_candidates` (`order_status`,`api_verified`,`funds_ready`,`processed_at`,`spam_click`,`cashback`),
            KEY `idx_stats_created_at` (`created_at`)
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица cashback_unregistered_transactions
        $table_unregistered = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_unregistered_transactions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` varchar(255) NOT NULL,
            `order_number` varchar(255) NOT NULL,
            `offer_id` int unsigned DEFAULT NULL COMMENT 'ID партнёрской программы в CPA-сети (advcampaign_id). Стабилен, в отличие от offer_name',
            `offer_name` varchar(255) DEFAULT NULL,
            `order_status` enum('waiting','completed','declined','hold','balance') NOT NULL DEFAULT 'waiting',
            `partner` varchar(255) DEFAULT NULL,
            `sum_order` decimal(10,2) DEFAULT NULL,
            `comission` decimal(10,2) DEFAULT NULL,
            `currency` char(3) NOT NULL DEFAULT 'RUB' COMMENT 'Валюта комиссии (ISO 4217). Без неё невозможно корректно сравнивать суммы',
            `uniq_id` varchar(255) DEFAULT NULL,
            `cashback` decimal(10,2) DEFAULT NULL,
            `applied_cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка на момент создания транзакции',
            `api_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Транзакция сверена с API. Основной триггер начисления в баланс',
            `action_date` datetime DEFAULT NULL COMMENT 'Реальное время покупки. НЕ путать с created_at (время получения хука)',
            `click_time` datetime DEFAULT NULL COMMENT 'Время клика. Для антифрода: action_date - click_time = 0 бот',
            `click_id` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'UUIDv7 клика без дефисов (32 hex), связь с cashback_click_log.click_id',
            `website_id` int unsigned DEFAULT NULL COMMENT 'ID площадки в CPA-сети',
            `action_type` varchar(10) DEFAULT NULL COMMENT 'sale/lead. Для корректного расчёта при нескольких тарифах',
            `processed_at` datetime DEFAULT NULL COMMENT 'Когда транзакция была учтена в балансе',
            `processed_batch_id` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'UUIDv7 батча начисления (32 hex)',
            `idempotency_key` varchar(64) DEFAULT NULL COMMENT 'Ключ идемпотентности для предотвращения дублирования транзакций',
            `spam_click` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = транзакция из подозрительного клика, кэшбэк только после ручной проверки',
            `funds_ready` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = CPA-сеть подтвердила готовность средств к снятию (Admitad: processed=1, EPN: status=approved)',
            `reference_id` varchar(11) NOT NULL DEFAULT '' COMMENT 'Публичный ID транзакции формата TU-XXXXXXXX (unregistered). Префикс TU- обеспечивает кросс-табличную уникальность с cashback_transactions (TX-)',
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_uniq_partner` (`uniq_id`,`partner`),
            UNIQUE KEY `idx_idempotency_key` (`idempotency_key`),
            UNIQUE KEY `uk_utx_reference_id` (`reference_id`),
            KEY `idx_click_id` (`click_id`),
            KEY `idx_stats_created_at` (`created_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Вэбхуки принятые от неавторизованных пользователей';";

        // Таблица cashback_user_balance (FK и CHECK добавляются в Фазе 2)
        $table_balance = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_balance` (
            `user_id` bigint(20) unsigned NOT NULL,
            `available_balance` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Доступный баланс пользователя',
            `pending_balance`   decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'В ожидании выплаты',
            `paid_balance`      decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Выплачен',
            `frozen_balance`    decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Заблокирован (legacy = sum of buckets)',
            `frozen_balance_ban`         decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Доступная часть, замороженная при бане пользователя',
            `frozen_balance_payout`      decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Часть, удерживаемая под активной заявкой на выплату',
            `frozen_pending_balance_ban` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Pending-часть, замороженная при бане (возвращается в pending при разбане)',
            `frozen_balance_admin`       decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Заморожено админом при declined-выплате (требует ручной разморозки, F-S9-NEW-UNFREEZE)',
            `version` int unsigned NOT NULL DEFAULT 0 COMMENT 'Версия строки для защиты от гонок',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Балансы пользователей кэшбэк-сервиса';";

        // Таблица cashback_webhooks (GENERATED и CHECK убраны для совместимости)
        $table_webhooks = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_webhooks` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `received_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
            `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `network_slug` varchar(64) DEFAULT NULL,
            `payload_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 хеш payload для дедупликации',
            `processing_status` enum('ok','click_not_found','user_mismatch','error') DEFAULT NULL COMMENT 'Результат проверки click_id webhook-receiver воркером',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_payload_hash` (`payload_hash`),
            KEY `idx_received_at` (`received_at`),
            KEY `idx_network_slug` (`network_slug`),
            KEY `idx_processing_status` (`processing_status`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Сырые уникальные webhooks';";

        // Таблица cashback_user_profile (FK добавляются в Фазе 2)
        $table_profile = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_profile` (
            `user_id` bigint(20) unsigned NOT NULL,
            `payout_method_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID способа выплаты, привязанного к wp_cashback_payout_methods.id',
            `payout_account` varchar(255) DEFAULT NULL COMMENT 'Телефон, номер карты или кошелёк',
            `payout_full_name` varchar(255) DEFAULT NULL COMMENT 'ФИО для выплат',
            `encrypted_details` BLOB DEFAULT NULL COMMENT 'AES-256-CBC зашифрованные реквизиты (JSON)',
            `masked_details` TEXT DEFAULT NULL COMMENT 'Маскированные реквизиты для отображения (JSON)',
            `details_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 хеш реквизитов для антифрода',
            `bank_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID банка, привязанного к wp_cashback_banks.id',
            `cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка (60 = 60%)',
            `is_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = реквизиты подтверждены',
            `payout_details_updated_at` datetime DEFAULT NULL COMMENT 'Дата и время обновления реквизитов',
            `min_payout_amount` decimal(18,2) DEFAULT 100.00 COMMENT 'Минимальная сумма выплаты',
            `opt_out` tinyint(1) NOT NULL DEFAULT 0,
            `status` enum('active','noactive','banned','deleted') NOT NULL DEFAULT 'active' COMMENT 'Статус профиля',
            `banned_at` datetime DEFAULT NULL COMMENT 'Дата и время блокировки',
            `ban_reason` text DEFAULT NULL COMMENT 'Публичная причина блокировки (показывается пользователю на withdrawal page; NULL = generic message)',
            `ban_reason_admin` text DEFAULT NULL COMMENT 'Внутренняя причина блокировки (только для админов; никогда не показывается пользователю)',
            `partner_token` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Криптографический токен для партнёрских ссылок (вместо user_id)',
            `last_active_at` datetime DEFAULT NULL COMMENT 'Дата и времени последней активности',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`user_id`),
            UNIQUE KEY `uk_partner_token` (`partner_token`),
            KEY `idx_active_check` (`status`,`last_active_at`,`created_at`),
            KEY `idx_payout_method` (`payout_method_id`),
            KEY `idx_bank_id` (`bank_id`),
            KEY `idx_details_hash` (`details_hash`)
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица логирования кликов по партнерским ссылкам
        $table_click_log = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_click_log` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `click_session_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK на cashback_click_sessions.id (12i). NULL для legacy rows до F-10-001 migration',
            `client_request_id` CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'UUID v4/v7 от клиента для идемпотентности тапов (12i)',
            `is_session_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = этот тап создал новую сессию (click_id == session.canonical_click_id) — 12i',
            `click_id` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'UUIDv7 клика без дефисов (32 hex), передаётся в CPA как subID. Для primary-тапа == canonical_click_id сессии',
            `user_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'WP user ID (0 для гостей)',
            `session_id` varchar(128) DEFAULT NULL COMMENT 'Гостевой PHP session id (для незалогиненных). Не путать с click_session_id — разные концепты',
            `product_id` bigint(20) unsigned NOT NULL COMMENT 'ID товара WooCommerce',
            `cpa_network` varchar(100) DEFAULT NULL COMMENT 'Название CPA-сети',
            `offer_id` varchar(255) DEFAULT NULL COMMENT 'ID оффера в сети',
            `affiliate_url` text NOT NULL COMMENT 'Полный URL с подставленными параметрами',
            `ip_address` varchar(45) NOT NULL COMMENT 'IPv4/IPv6 адрес',
            `user_agent` text DEFAULT NULL COMMENT 'User-Agent браузера',
            `referer` text DEFAULT NULL COMMENT 'Внутренний referer (страница клика)',
            `utm_source` varchar(255) DEFAULT NULL COMMENT 'UTM source',
            `utm_medium` varchar(255) DEFAULT NULL COMMENT 'UTM medium',
            `utm_campaign` varchar(255) DEFAULT NULL COMMENT 'UTM campaign',
            `country` varchar(2) DEFAULT NULL COMMENT 'Код страны GeoIP (ISO 3166-1 alpha-2)',
            `spam_click` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = подозрительный клик (rate limit), кэшбэк только после ручной проверки',
            `created_at` datetime(6) NOT NULL COMMENT 'Время клика (UTC)',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_click_id` (`click_id`),
            KEY `idx_click_session_id` (`click_session_id`),
            KEY `idx_client_request` (`user_id`,`client_request_id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_product_id` (`product_id`),
            KEY `idx_cpa_network` (`cpa_network`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_ip_address` (`ip_address`),
            KEY `idx_session_id` (`session_id`),
            KEY `idx_spam_by_ip` (`created_at`,`spam_click`,`ip_address`),
            KEY `idx_spam_by_product` (`created_at`,`spam_click`,`product_id`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Лог кликов по партнерским ссылкам (tap events, 12i)';";

        // 12i ADR (F-10-001): canonical click sessions — дедуп окна активации.
        // tap events логируются в cashback_click_log, но canonical_click_id и
        // state сессии (status, tap_count, expires_at) — здесь.
        $table_click_sessions = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_click_sessions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `canonical_click_id` CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'UUIDv7, идёт в CPA postback. == click_id первого тапа сессии',
            `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 для гостей',
            `guest_session_id` VARCHAR(128) DEFAULT NULL COMMENT 'PHP session id для гостей',
            `product_id` BIGINT UNSIGNED NOT NULL,
            `promocode_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL для product-кликов; ID промокода для купонных кликов (v11) — отделяет купонную сессию от товарной, иначе reuse товарной session подменяет goto_link купона на товарный',
            `merchant_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK cashback_affiliate_networks.id',
            `affiliate_url` TEXT NOT NULL COMMENT 'Канонический URL редиректа для сессии',
            `status` ENUM('active','expired','converted','invalidated') NOT NULL DEFAULT 'active',
            `tap_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            `expires_at` DATETIME(6) NOT NULL COMMENT 'created_at + activation_window_seconds из merchant policy',
            `last_tap_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            `converted_at` DATETIME(6) DEFAULT NULL COMMENT 'Set when webhook receives matching transaction',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_canonical_click_id` (`canonical_click_id`),
            KEY `idx_user_product_active` (`user_id`,`product_id`,`status`,`expires_at`),
            KEY `idx_user_product_promo_active` (`user_id`,`product_id`,`promocode_id`,`status`,`expires_at`),
            KEY `idx_guest_product_active` (`guest_session_id`,`product_id`,`status`,`expires_at`),
            KEY `idx_expires_status` (`expires_at`,`status`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Canonical click sessions (12i, F-10-001 dedup)';";

        // Таблица-леджер: единственный источник правды для баланса
        $table_balance_ledger = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_balance_ledger` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL COMMENT 'ID пользователя',
            `type` enum('accrual','payout_hold','payout_complete','payout_cancel','payout_declined','payout_unfreeze','adjustment','affiliate_accrual','affiliate_reversal','affiliate_freeze','affiliate_unfreeze','ban_freeze','ban_unfreeze') NOT NULL COMMENT 'Тип операции',
            `amount` decimal(18,2) NOT NULL COMMENT 'Сумма со знаком (+ начисление, - списание)',
            `transaction_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID транзакции (для accrual/affiliate)',
            `payout_request_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID заявки на выплату',
            `reference_type` varchar(50) DEFAULT NULL COMMENT 'Тип связанной сущности (accrual, payout, affiliate_accrual)',
            `reference_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID связанной сущности',
            `idempotency_key` varchar(64) NOT NULL COMMENT 'Ключ идемпотентности (UNIQUE)',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_idempotency_key` (`idempotency_key`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_transaction_id` (`transaction_id`),
            KEY `idx_payout_request_id` (`payout_request_id`),
            KEY `idx_user_type` (`user_id`,`type`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_reference` (`reference_type`,`reference_id`),
            KEY `idx_user_created` (`user_id`,`created_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Леджер баланса: единственный источник правды';";

        // Таблица чекпоинтов валидации (API сверка)
        $table_validation_checkpoints = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_validation_checkpoints` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `network_slug` varchar(100) NOT NULL COMMENT 'Slug CPA-сети (admitad, epn)',
            `last_validated_date` date NOT NULL COMMENT 'До какой даты данные проверены',
            `api_sum_approved` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма approved по API',
            `api_sum_pending` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма pending по API',
            `api_sum_declined` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма declined по API',
            `api_actions_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во действий в API',
            `local_sum_approved` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма approved локально',
            `local_sum_pending` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма pending локально',
            `local_sum_declined` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма declined локально',
            `local_transactions_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во транзакций локально',
            `validation_status` enum('match','mismatch','pending','error') NOT NULL DEFAULT 'pending',
            `discrepancy_amount` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Разница между API и локальными данными',
            `matched_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во совпавших транзакций',
            `mismatch_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во расхождений',
            `missing_local_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Есть в API, нет локально',
            `missing_api_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Есть локально, нет в API',
            `validated_at` datetime DEFAULT NULL COMMENT 'Когда проводилась валидация',
            `validated_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Кто инициировал валидацию (admin user_id)',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user_network` (`user_id`, `network_slug`),
            KEY `idx_validation_status` (`validation_status`),
            KEY `idx_validated_at` (`validated_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Чекпоинты инкрементальной валидации кэшбэка';";

        // Таблица лога синхронизации
        $table_sync_log = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_sync_log` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `network_slug` varchar(100) NOT NULL COMMENT 'Slug CPA-сети',
            `transaction_id` bigint(20) unsigned NOT NULL COMMENT 'ID локальной транзакции',
            `action_id` varchar(255) DEFAULT NULL COMMENT 'ID действия в CPA-сети',
            `old_status` varchar(50) NOT NULL COMMENT 'Статус до синхронизации',
            `new_status` varchar(50) NOT NULL COMMENT 'Статус после синхронизации',
            `api_payment` decimal(18,2) DEFAULT NULL COMMENT 'Сумма комиссии по API',
            `sync_type` enum('cron','manual','webhook','auto_decline') NOT NULL DEFAULT 'cron' COMMENT 'Источник синхронизации',
            `synced_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_transaction_id` (`transaction_id`),
            KEY `idx_network_synced` (`network_slug`, `synced_at`),
            KEY `idx_synced_at` (`synced_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Лог синхронизации статусов транзакций через API';";

        // Таблица истории изменений ставок кэшбэка и партнёрской комиссии
        $table_rate_history = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_rate_history` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `rate_type` enum('cashback','cashback_global','affiliate_commission','affiliate_global') NOT NULL COMMENT 'Тип изменённой ставки',
            `user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID пользователя (NULL = для всех)',
            `old_rate` decimal(5,2) DEFAULT NULL COMMENT 'Предыдущее значение ставки',
            `new_rate` decimal(5,2) NOT NULL COMMENT 'Новое значение ставки',
            `affected_users` int(11) NOT NULL DEFAULT 1 COMMENT 'Количество затронутых пользователей',
            `changed_by` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'ID администратора (0 = система)',
            `change_source` varchar(50) NOT NULL DEFAULT 'manual' COMMENT 'Источник изменения: manual, bulk, api, system',
            `details` text DEFAULT NULL COMMENT 'Дополнительные данные в JSON',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_rate_type` (`rate_type`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_changed_by` (`changed_by`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_new_rate` (`new_rate`),
            KEY `idx_type_created` (`rate_type`,`created_at`),
            KEY `idx_type_user` (`rate_type`,`user_id`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='История изменений ставок кэшбэка и партнёрской комиссии';";

        // Таблица checkpoint-состояния cron-прогонов (Group 8 Step 3, F-8-005)
        $table_cron_state = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_cron_state` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `run_id` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'UUIDv7 всего прогона run_sync (один на 5 этапов)',
            `stage` varchar(64) NOT NULL COMMENT 'Идентификатор этапа: background_sync / auto_transfer / process_ready / affiliate_pending / check_campaigns',
            `status` enum('running','success','failed') NOT NULL DEFAULT 'running' COMMENT 'Текущий статус этапа',
            `started_at` datetime NOT NULL COMMENT 'Время начала этапа',
            `finished_at` datetime DEFAULT NULL COMMENT 'Время завершения этапа (NULL пока running)',
            `duration_ms` int(11) unsigned DEFAULT NULL COMMENT 'Длительность этапа в миллисекундах',
            `metrics_json` longtext DEFAULT NULL COMMENT 'JSON с метриками этапа (updated/inserted/declined/...)',
            `error_message` text DEFAULT NULL COMMENT 'Текст ошибки при status=failed',
            PRIMARY KEY (`id`),
            KEY `idx_run_id` (`run_id`),
            KEY `idx_started_at` (`started_at`),
            KEY `idx_stage_status` (`stage`,`status`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Checkpoint-история прогонов cashback_api_sync';";

        // ---------------------------------------------------------------
        // v8: таблицы промокодов CPA-сетей.
        //
        // cashback_promocodes — нормализованный кэш купонов всех сетей.
        // UNIQUE (network_id, external_id) исключает коллизии ID между сетями.
        // is_active=0 — soft-delete (купон отсутствует в свежем fetch).
        // utf8mb4_unicode_520_ci для текста (per memory feedback_no_ascii_columns).
        // ---------------------------------------------------------------
        $table_promocodes = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_promocodes` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `network_id` bigint(20) unsigned NOT NULL COMMENT 'FK cashback_affiliate_networks.id',
            `advcampaign_id` varchar(64) NOT NULL COMMENT 'offer_id = ID кампании в сети',
            `external_id` varchar(64) NOT NULL COMMENT 'ID купона в сети (уникален в рамках network_id)',
            `species` varchar(32) NOT NULL COMMENT 'promocode|deal|sale|discount|other',
            `promocode` varchar(128) DEFAULT NULL COMMENT 'Сам код (NULL для deals)',
            `name` varchar(255) NOT NULL,
            `short_name` varchar(255) DEFAULT NULL,
            `description` text DEFAULT NULL COMMENT 'Санитизируется через DOMPurify при выводе',
            `discount` varchar(64) DEFAULT NULL COMMENT 'Строка от сети (5%, до 1000₽)',
            `date_start` datetime DEFAULT NULL,
            `date_end` datetime DEFAULT NULL,
            `regions` varchar(255) DEFAULT NULL COMMENT 'CSV RU,KZ,BY',
            `categories` text DEFAULT NULL COMMENT 'JSON массив',
            `image` varchar(512) DEFAULT NULL,
            `goto_link` varchar(1024) NOT NULL COMMENT 'Tracking URL',
            `is_exclusive` tinyint(1) NOT NULL DEFAULT 0,
            `rating` decimal(3,2) DEFAULT NULL,
            `raw_payload` longtext DEFAULT NULL COMMENT 'JSON оригинал coupon-объекта от API',
            `fetched_at` datetime NOT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = купон отсутствовал в последнем fetch (soft-delete)',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_network_external` (`network_id`, `external_id`),
            KEY `idx_campaign_active` (`advcampaign_id`, `is_active`, `date_end`),
            KEY `idx_fetched_at` (`fetched_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Промокоды CPA-сетей (v8)';";

        // ---------------------------------------------------------------
        // cashback_promocode_clicks — click-tracking для промокодов.
        // ip_hash CHAR(64) — sha256(ip+salt), не raw IP (152-ФЗ).
        // utf8mb4_bin для hash/UA (per memory feedback_no_ascii_columns).
        // ---------------------------------------------------------------
        $table_promocode_clicks = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_promocode_clicks` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '0 для гостей',
            `promocode_id` bigint(20) unsigned NOT NULL,
            `product_id` bigint(20) unsigned DEFAULT NULL COMMENT 'WC product откуда кликнули',
            `action` enum('copy','goto') NOT NULL,
            `ip_hash` char(64) DEFAULT NULL COMMENT 'sha256(ip+salt) — не raw IP (152-ФЗ)',
            `ua_family` varchar(64) DEFAULT NULL,
            `affiliate_url` varchar(2048) DEFAULT NULL COMMENT 'Полный CPA URL купона с подставленными subid (v11) — для self-verification оператором; NULL для copy-кликов и fallback-путей',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user_action` (`user_id`, `action`, `created_at`),
            KEY `idx_promo` (`promocode_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Click-tracking промокодов (v8)';";

        // Порядок создания: сначала справочники, потом зависимые таблицы
        $tables = array(
            'cashback_payout_methods'            => $table_payout_methods,
            'cashback_banks'                     => $table_banks,
            'cashback_affiliate_networks'        => $table_affiliate_networks,
            'cashback_affiliate_network_params'  => $table_affiliate_network_params,
            'cashback_payout_requests'           => $table_payout_requests,
            'cashback_transactions'              => $table_transactions,
            'cashback_unregistered_transactions' => $table_unregistered,
            'cashback_user_balance'              => $table_balance,
            'cashback_balance_ledger'            => $table_balance_ledger,
            'cashback_webhooks'                  => $table_webhooks,
            'cashback_user_profile'              => $table_profile,
            'cashback_click_log'                 => $table_click_log,
            'cashback_click_sessions'            => $table_click_sessions,
            'cashback_validation_checkpoints'    => $table_validation_checkpoints,
            'cashback_sync_log'                  => $table_sync_log,
            'cashback_rate_history'              => $table_rate_history,
            'cashback_cron_state'                => $table_cron_state,
            'cashback_promocodes'                => $table_promocodes,
            'cashback_promocode_clicks'          => $table_promocode_clicks,
        );

        $failed_tables = array();

        foreach ($tables as $table_name => $sql) {
            $full_table_name = $wpdb->prefix . $table_name;

            // Используем $wpdb->query() напрямую вместо dbDelta()
            // dbDelta() парсит CREATE TABLE IF NOT EXISTS некорректно
            // (regex захватывает "IF" как имя таблицы) и не поддерживает
            // CONSTRAINT, CHECK, FOREIGN KEY, GENERATED конструкции
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static DDL from local $tables array, $wpdb->prefix validated.
            $result = $wpdb->query($sql);

            if ($result === false) {
                $failed_tables[] = $table_name . ': ' . $wpdb->last_error;
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log("[Cashback] Failed to create table {$full_table_name}: " . $wpdb->last_error);
            } else {
                // Проверяем что таблица действительно существует
                $exists = $wpdb->get_var(
                    $wpdb->prepare('SHOW TABLES LIKE %s', $full_table_name)
                );
                if (!$exists) {
                    $failed_tables[] = $table_name . ': table not created (no error reported)';
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log("[Cashback] Table {$full_table_name} was not created despite no error being reported");
                }
            }
        }

        if (!empty($failed_tables)) {
            throw new Exception(
                'Failed to create tables: ' . esc_html( implode('; ', $failed_tables) )
            );
        }

        // Инициализация начальных данных в справочные таблицы
        $this->insert_default_payout_methods();
        $this->insert_default_banks();
        $this->insert_default_api_config();

        // Таблица аудит-лога
        $this->create_audit_log_table();

        // Фаза 2: Добавление FOREIGN KEY и CHECK ограничений (не фатально)
        $this->add_table_constraints();

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('[Cashback] All tables created successfully');
    }

    /**
     * Добавление FOREIGN KEY и CHECK ограничений к таблицам.
     * Вынесено из CREATE TABLE для совместимости:
     * - WordPress dbDelta() не поддерживает CONSTRAINT/FK/CHECK
     * - FK между InnoDB и MyISAM невозможен (wp_users может быть MyISAM)
     * - CHECK не поддерживается в MySQL < 8.0.16
     * Ошибки не фатальны — таблицы работают и без ограничений на уровне БД.
     */
    private function add_table_constraints(): void {
        global $wpdb;

        $constraints = array(
            // cashback_payout_requests
            "ALTER TABLE `{$wpdb->prefix}cashback_payout_requests`
                ADD CONSTRAINT `fk_payout_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE RESTRICT",

            // cashback_transactions
            "ALTER TABLE `{$wpdb->prefix}cashback_transactions`
                ADD CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE RESTRICT",

            // cashback_user_balance
            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `fk_balance_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE RESTRICT",

            // cashback_user_profile
            "ALTER TABLE `{$wpdb->prefix}cashback_user_profile`
                ADD CONSTRAINT `fk_profile_wp_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_profile`
                ADD CONSTRAINT `fk_payout_method` FOREIGN KEY (`payout_method_id`)
                REFERENCES `{$wpdb->prefix}cashback_payout_methods` (`id`) ON DELETE SET NULL",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_profile`
                ADD CONSTRAINT `fk_bank_id` FOREIGN KEY (`bank_id`)
                REFERENCES `{$wpdb->prefix}cashback_banks` (`id`) ON DELETE SET NULL",

            // cashback_affiliate_network_params
            "ALTER TABLE `{$wpdb->prefix}cashback_affiliate_network_params`
                ADD CONSTRAINT `fk_network_params` FOREIGN KEY (`network_id`)
                REFERENCES `{$wpdb->prefix}cashback_affiliate_networks` (`id`) ON DELETE CASCADE",

            // CHECK constraints
            "ALTER TABLE `{$wpdb->prefix}cashback_transactions`
                ADD CONSTRAINT `chk_applied_cashback_rate_range`
                CHECK (`applied_cashback_rate` BETWEEN 0.00 AND 100.00)",
            "ALTER TABLE `{$wpdb->prefix}cashback_transactions`
                ADD CONSTRAINT `chk_cashback_positive`
                CHECK (`cashback` >= 0)",
            "ALTER TABLE `{$wpdb->prefix}cashback_transactions`
                ADD CONSTRAINT `chk_currency_format`
                CHECK (`currency` REGEXP '^[A-Z]{3}$')",

            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `chk_available_balance` CHECK (`available_balance` >= 0)",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `chk_pending_balance` CHECK (`pending_balance` >= 0)",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `chk_paid_balance` CHECK (`paid_balance` >= 0)",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `chk_frozen_balance` CHECK (`frozen_balance` >= 0)",

            "ALTER TABLE `{$wpdb->prefix}cashback_user_profile`
                ADD CONSTRAINT `chk_cashback_rate_range`
                CHECK (`cashback_rate` BETWEEN 0.00 AND 100.00)",

            // cashback_balance_ledger
            "ALTER TABLE `{$wpdb->prefix}cashback_balance_ledger`
                ADD CONSTRAINT `fk_ledger_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE RESTRICT",
            "ALTER TABLE `{$wpdb->prefix}cashback_balance_ledger`
                ADD CONSTRAINT `fk_ledger_transaction` FOREIGN KEY (`transaction_id`)
                REFERENCES `{$wpdb->prefix}cashback_transactions` (`id`) ON DELETE SET NULL",
            "ALTER TABLE `{$wpdb->prefix}cashback_balance_ledger`
                ADD CONSTRAINT `fk_ledger_payout` FOREIGN KEY (`payout_request_id`)
                REFERENCES `{$wpdb->prefix}cashback_payout_requests` (`id`) ON DELETE SET NULL",
        );

        $suppress = $wpdb->suppress_errors(true);

        foreach ($constraints as $sql) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static DDL ALTER TABLE from local $constraints array, $wpdb->prefix validated.
            $wpdb->query($sql);
            // Ошибки типа "Duplicate key name" (constraint already exists) — ожидаемы
            if ($wpdb->last_error && strpos($wpdb->last_error, 'Duplicate') === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Constraint warning (non-fatal): ' . $wpdb->last_error);
            }
        }

        $wpdb->suppress_errors($suppress);
    }

    /**
     * Инициализация начальных способов выплат
     *
     * @return void
     */
    private function insert_default_payout_methods(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_payout_methods';

        // Проверяем, есть ли уже записи
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        if ($count > 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin: Payout methods already exist, skipping initialization');
            return;
        }

        // Начальные способы выплат
        $defaults = array(
            array(
				'slug'       => 'sbp',
				'name'       => 'СБП Система быстрых платежей',
				'is_active'  => 1,
				'sort_order' => 1,
			),
        );

        foreach ($defaults as $method) {
            $wpdb->insert($table, $method, array( '%s', '%s', '%d', '%d' ));
            if ($wpdb->last_error) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Mariadb Plugin Error: Failed to insert payout method: ' . $wpdb->last_error);
            }
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('Mariadb Plugin: Initialized ' . count($defaults) . ' default payout methods');
    }

    /**
     * Инициализация начальных банков
     *
     * @return void
     */
    private function insert_default_banks(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_banks';

        // Проверяем, есть ли уже записи
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        if ($count > 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin: Banks already exist, skipping initialization');
            return;
        }

        // Начальные банки
        $defaults = array(
            array(
				'bank_code'  => 'sber',
				'name'       => 'Сбербанк',
				'short_name' => 'Сбербанк',
				'is_active'  => 1,
				'sort_order' => 1,
			),
        );

        foreach ($defaults as $bank) {
            $wpdb->insert($table, $bank, array( '%s', '%s', '%s', '%d', '%d' ));
            if ($wpdb->last_error) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Mariadb Plugin Error: Failed to insert bank: ' . $wpdb->last_error);
            }
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('Mariadb Plugin: Initialized ' . count($defaults) . ' default banks');
    }

    /**
     * Заполнить дефолтную API-конфигурацию для Admitad и EPN.
     * Только если колонки api_base_url пустые (не перезаписывает ручную настройку).
     */
    private function insert_default_api_config(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_networks';

        // Admitad
        $admitad_url = $wpdb->get_var($wpdb->prepare(
            'SELECT api_base_url FROM %i WHERE slug = %s',
            $table,
            'admitad'
        ));

        if ($admitad_url === null || $admitad_url === '') {
            $wpdb->update(
                $table,
                array(
                    'api_base_url'         => 'https://api.admitad.com',
                    'api_token_endpoint'   => '/token/',
                    'api_actions_endpoint' => '/statistics/actions/',
                    'api_user_field'       => 'subid',
                    'api_click_field'      => 'subid1',
                    'api_status_map'       => wp_json_encode(array(
                        'pending'  => 'waiting',
                        'approved' => 'completed',
                        'declined' => 'declined',
                        'rejected' => 'declined',
                        'open'     => 'waiting',
                        'hold'     => 'waiting',
                    )),
                    'api_field_map'        => wp_json_encode(array(
                        'payment'          => 'comission',
                        'cart'             => 'sum_order',
                        'action_id'        => 'uniq_id',
                        'order_id'         => 'order_number',
                        'advcampaign_id'   => 'offer_id',
                        'advcampaign_name' => 'offer_name',
                    )),
                ),
                array( 'slug' => 'admitad' )
            );
        }

        // EPN
        $epn_url = $wpdb->get_var($wpdb->prepare(
            'SELECT api_base_url FROM %i WHERE slug = %s',
            $table,
            'epn'
        ));

        if ($epn_url === null || $epn_url === '') {
            $wpdb->update(
                $table,
                array(
                    'api_base_url'         => 'https://oauth2.epn.bz',
                    'api_token_endpoint'   => '/token',
                    'api_actions_endpoint' => 'https://app.epn.bz/transactions/user',
                    'api_user_field'       => 'sub',
                    'api_click_field'      => 'click_id',
                    'api_status_map'       => wp_json_encode(array(
                        'pending'  => 'waiting',
                        'approved' => 'completed',
                        'rejected' => 'declined',
                        'canceled' => 'declined',
                        'hold'     => 'waiting',
                    )),
                    'api_field_map'        => wp_json_encode(array(
                        'payment'          => 'comission',
                        'cart'             => 'sum_order',
                        'action_id'        => 'uniq_id',
                        'order_id'         => 'order_number',
                        'advcampaign_id'   => 'offer_id',
                        'advcampaign_name' => 'offer_name',
                    )),
                ),
                array( 'slug' => 'epn' )
            );
        }
    }

    /**
     * Создание таблицы аудит-лога
     */
    private function create_audit_log_table(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_audit_log` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `action` varchar(100) NOT NULL COMMENT 'Тип действия',
            `actor_id` bigint(20) unsigned NOT NULL COMMENT 'ID пользователя-инициатора',
            `entity_type` varchar(50) DEFAULT NULL COMMENT 'Тип сущности (payout_request, user_profile)',
            `entity_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID сущности',
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `details` longtext DEFAULT NULL COMMENT 'Доп. данные в JSON',
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_action_actor` (`action`, `actor_id`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Аудит-лог действий с чувствительными данными';";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static DDL, $wpdb->prefix and $charset_collate come from WP core.
        $result = $wpdb->query($sql);
        if ($result === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback] Failed to create audit_log table: ' . $wpdb->last_error);
        }
    }

    /**
     * Публичный метод для пересоздания триггеров (для миграций без реактивации).
     */
    public function recreate_triggers(): void {
        $this->create_triggers();
    }

    /**
     * Создание триггеров.
     *
     * Все триггеры создаются через `CREATE OR REPLACE TRIGGER` (MariaDB 10.1.4+) —
     * атомарный rebuild без окна между DROP и CREATE. Закрывает риск deployment-gap'а:
     * раньше при INSERT в `cashback_balance_ledger` в момент пересоздания триггера
     * UPDATE баланса мог не выполниться, что приводило к расхождению ledger ↔ balance
     * (детектилось reconciliation cron'ом). Теперь schema-replace атомарен.
     */
    private function create_triggers() {
        global $wpdb;

        // Валидация префикса таблицы для безопасности
        $safe_prefix = $this->validate_table_prefix($wpdb->prefix);

        $triggers = array(
            "CREATE OR REPLACE TRIGGER `{$safe_prefix}calculate_cashback_before_insert`
            BEFORE INSERT ON `{$safe_prefix}cashback_transactions`
            FOR EACH ROW
            -- 'Автоматически рассчитывает кэшбэк и генерирует reference_id при вставке'
            BEGIN
                DECLARE v_rate DECIMAL(5,2) DEFAULT 60.00;

                SELECT cashback_rate INTO v_rate
                FROM `{$safe_prefix}cashback_user_profile`
                WHERE user_id = NEW.user_id
                LIMIT 1;

                SET NEW.applied_cashback_rate = IFNULL(v_rate, 60.00);

                IF NEW.comission IS NOT NULL THEN
                    SET NEW.cashback = ROUND(NEW.comission * IFNULL(v_rate, 60.00) / 100, 2);
                ELSE
                    SET NEW.cashback = 0.00;
                END IF;

                -- Генерация уникального публичного ID транзакции (TX-XXXXXXXX)
                IF NEW.reference_id IS NULL OR NEW.reference_id = '' THEN
                    SET NEW.reference_id = CONCAT('TX-', UPPER(LEFT(MD5(CONCAT(UUID(), RAND(), NOW(6))), 8)));
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}calculate_cashback_before_insert_unregistered`
            BEFORE INSERT ON `{$safe_prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            --  'Рассчитывает кэшбэк и генерирует reference_id (TU-XXXXXXXX) для незарегистрированных пользователей'
            BEGIN
                SET NEW.cashback = ROUND(NEW.comission * 0.6, 2);

                -- Генерация уникального публичного ID транзакции (TU-XXXXXXXX).
                -- Префикс TU- обеспечивает кросс-табличную уникальность с cashback_transactions (TX-).
                IF NEW.reference_id IS NULL OR NEW.reference_id = '' THEN
                    SET NEW.reference_id = CONCAT('TU-', UPPER(LEFT(MD5(CONCAT(UUID(), RAND(), NOW(6))), 8)));
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}calculate_cashback_before_update`
            BEFORE UPDATE ON `{$safe_prefix}cashback_transactions`
            FOR EACH ROW
            --  'Пересчитывает кэшбэк только при изменении comission, используя сохранённую applied_cashback_rate'
            BEGIN
                IF NOT (OLD.comission <=> NEW.comission) THEN
                    SET NEW.cashback = ROUND(NEW.comission * NEW.applied_cashback_rate / 100, 2);
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}calculate_cashback_before_update_unregistered`
            BEFORE UPDATE ON `{$safe_prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            --  'Пересчитывает кэшбэк для незарегистрированных пользователей при изменении comission'
            BEGIN
                IF NOT (OLD.comission <=> NEW.comission) THEN
                    SET NEW.cashback = ROUND(NEW.comission * 0.6, 2);
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}cashback_tr_prevent_delete_final_status`
            BEFORE DELETE ON `{$safe_prefix}cashback_transactions`
            FOR EACH ROW
            --  'Запрещает удаление транзакций со статусом ''balance'' (финальный статус)'
            BEGIN
                IF OLD.order_status = 'balance' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: запись с финальным статусом не может быть удалена.';
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}cashback_tr_validate_status_transition`
            BEFORE UPDATE ON `{$safe_prefix}cashback_transactions`
            FOR EACH ROW
            BEGIN
                -- 1. balance — полная блокировка любых изменений (финальный статус)
                IF OLD.order_status = 'balance' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: запись с финальным статусом не может быть изменена.';
                END IF;

                -- 2. Возврат в waiting запрещён из любого состояния
                IF NEW.order_status = 'waiting' AND OLD.order_status != 'waiting' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Понижение статуса до waiting запрещено.';
                END IF;

                -- 3. В balance — только из completed
                IF NEW.order_status = 'balance' AND OLD.order_status != 'completed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Перевод в balance возможен только из completed.';
                END IF;

                -- 4. В hold — только из completed (рекламодатель вернул подтверждённое на удержание)
                IF NEW.order_status = 'hold' AND OLD.order_status != 'completed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Перевод в hold возможен только из completed.';
                END IF;

                -- 5. Из declined — только в completed (апелляция через Потерянные заказы)
                IF OLD.order_status = 'declined' 
                    AND NEW.order_status != 'completed' 
                    AND NEW.order_status != 'declined' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Из declined возможен переход только в completed.';
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}cashback_tr_validate_status_transition_unregistered`
            BEFORE UPDATE ON `{$safe_prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            BEGIN
                IF OLD.order_status = 'balance' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: запись с финальным статусом не может быть изменена.';
                END IF;

                IF NEW.order_status = 'waiting' AND OLD.order_status != 'waiting' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Понижение статуса до waiting запрещено.';
                END IF;

                IF NEW.order_status = 'balance' AND OLD.order_status != 'completed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Перевод в balance возможен только из completed.';
                END IF;

                IF NEW.order_status = 'hold' AND OLD.order_status != 'completed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Перевод в hold возможен только из completed.';
                END IF;

                IF OLD.order_status = 'declined' 
                    AND NEW.order_status != 'completed' 
                    AND NEW.order_status != 'declined' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Из declined возможен переход только в completed.';
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_prevent_delete_paid_payout`
            BEFORE DELETE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает удаление заявок на выплату со статусом ''paid'' выплачена'
            BEGIN
                IF OLD.status = 'paid' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: выплаченная заявка не может быть удалена.';
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_prevent_update_paid_payout`
            BEFORE UPDATE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает изменение заявок на выплату со статусом ''paid'' выплачена'
            BEGIN
                IF OLD.status = 'paid' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: выплаченная заявка не может быть изменена.';
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_prevent_delete_failed_payout`
            BEFORE DELETE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает удаление заявок на выплату со статусом ''failed'' (возвращено в баланс)'
            BEGIN
                IF OLD.status = 'failed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: заявка со статусом failed не может быть удалена.';
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_prevent_update_failed_payout`
            BEFORE UPDATE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает изменение заявок на выплату со статусом ''failed'' (возвращено в баланс)'
            BEGIN
                IF OLD.status = 'failed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: заявка со статусом failed не может быть изменена.';
                END IF;
            END;",

            // Defense-in-depth (E2E 2026-04-29 P2-A1-2): нельзя через raw INSERT/UPDATE
            // перевести заявку в declined/failed без fail_reason. Все legitimate callsite'ы
            // (admin/payouts, admin/users-management, antifraud/class-fraud-admin,
            // admin/class-cashback-encryption-recovery) уже заполняют fail_reason,
            // так что триггер не сломает legitimate flow — закрывает только обход через raw SQL.
            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_payout_require_fail_reason_ins`
            BEFORE INSERT ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Требует fail_reason при создании заявки со статусом declined/failed'
            BEGIN
                IF NEW.status IN ('declined','failed')
                    AND (NEW.fail_reason IS NULL OR NEW.fail_reason = '') THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'fail_reason required for declined/failed payout status';
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_payout_require_fail_reason_upd`
            BEFORE UPDATE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Требует fail_reason при переходе заявки в declined/failed'
            BEGIN
                IF NEW.status IN ('declined','failed')
                    AND (NEW.fail_reason IS NULL OR NEW.fail_reason = '') THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'fail_reason required for declined/failed payout status';
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_banned_user_update_banned_at`
            BEFORE UPDATE ON `{$safe_prefix}cashback_user_profile`
            FOR EACH ROW
            --  'Обновляет поле banned_at текущей датой и временем при изменении статуса на ''banned'''
            BEGIN
                IF OLD.status != 'banned' AND NEW.status = 'banned' THEN
                    SET NEW.banned_at = UTC_TIMESTAMP();
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_freeze_balance_on_ban`
            AFTER UPDATE ON `{$safe_prefix}cashback_user_profile`
            FOR EACH ROW
            --  'Замораживает доступный и pending баланс при бане (bucket-split: F-11-003)'
            BEGIN
                IF OLD.status != 'banned' AND NEW.status = 'banned' THEN
                    UPDATE `{$safe_prefix}cashback_user_balance`
                    SET
                        frozen_balance_ban         = frozen_balance_ban + available_balance,
                        frozen_pending_balance_ban = frozen_pending_balance_ban + pending_balance,
                        frozen_balance             = frozen_balance + available_balance + pending_balance,
                        available_balance = 0,
                        pending_balance = 0,
                        version = version + 1
                    WHERE user_id = NEW.user_id;
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_clear_ban_on_unban`
            BEFORE UPDATE ON `{$safe_prefix}cashback_user_profile`
            FOR EACH ROW
            --  'Очищает поля banned_at и ban_reason при разбане пользователя'
            BEGIN
                IF OLD.status = 'banned' AND NEW.status != 'banned' THEN
                    SET NEW.banned_at = NULL;
                    SET NEW.ban_reason = NULL;
                    SET NEW.ban_reason_admin = NULL;
                END IF;
            END;",

            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_unfreeze_balance_on_unban`
            AFTER UPDATE ON `{$safe_prefix}cashback_user_profile`
            FOR EACH ROW
            --  'Размораживает баланс при разбане (bucket-split: pending→pending, F-11-003)'
            BEGIN
                IF OLD.status = 'banned' AND NEW.status != 'banned' THEN
                    UPDATE `{$safe_prefix}cashback_user_balance`
                    SET
                        available_balance = available_balance + frozen_balance_ban,
                        pending_balance   = pending_balance + frozen_pending_balance_ban,
                        frozen_balance    = frozen_balance - frozen_balance_ban - frozen_pending_balance_ban,
                        frozen_balance_ban         = 0,
                        frozen_pending_balance_ban = 0,
                        version = version + 1
                    WHERE user_id = NEW.user_id;
                END IF;
            END;",

            // Уведомления о транзакциях: запись в очередь выполняется на уровне приложения —
            // Python webhook worker (transaction_new) и PHP API sync (transaction_status, transaction_data_changed).
            // Триггеры tr_notify_transaction_insert/update удалены: они скрывали логику,
            // срабатывали на каждый INSERT/UPDATE (включая служебные), и усложняли отладку.

            // Автоматический расчёт payload_hash при INSERT в cashback_webhooks
            // Заменяет GENERATED ALWAYS AS (SHA2(payload, 256)) STORED, убранный для совместимости
            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_webhook_payload_hash`
            BEFORE INSERT ON `{$safe_prefix}cashback_webhooks`
            FOR EACH ROW
            BEGIN
                IF NEW.payload_hash IS NULL THEN
                    SET NEW.payload_hash = SHA2(NEW.payload, 256);
                END IF;
            END;",
        );

        $failed_triggers = array();
        foreach ($triggers as $trigger) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static CREATE TRIGGER DDL from local $triggers array, $safe_prefix validated by regex.
            $result = $wpdb->query($trigger);
            if ($result === false) {
                $failed_triggers[] = $wpdb->last_error;
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Mariadb Plugin Error: Failed to create trigger. Error: ' . $wpdb->last_error);
            }
        }

        if (!empty($failed_triggers)) {
            // Codex adversarial-review #1 round 5 (2026-05-10): hard-fail вместо
            // soft-degrade. Раньше при провале CREATE TRIGGER плагин ставил
            // `cashback_triggers_active=false` и полагался на PHP-фолбэки в
            // Cashback_Trigger_Fallbacks. Класс удалён (MariaDB-only by design),
            // и без триггеров нет schema-level защиты payout immutability,
            // fail_reason invariant и status transitions — финансовые данные
            // не защищены. Лучше явный фейл с диагностикой, чем silent corrupt.
            //
            // Codex round 6 #3 (2026-05-10): бросаем RuntimeException вместо wp_die.
            // create_triggers() вызывается и из activate() (где caller ловит и
            // делает wp_die), и из recreate_triggers() через runtime
            // maybe_run_migrations() (где caller ловит и показывает admin notice
            // без 500-ки на frontend/cron-запросе). wp_die() не throwable —
            // обёртка try/catch её не поймала бы.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin Fatal: Failed to create triggers: ' . implode('; ', $failed_triggers));
            throw new \RuntimeException(
                esc_html('Failed to create cashback triggers: ' . $failed_triggers[0])
            );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('Mariadb Plugin: All triggers created successfully');
    }

    /**
     * Создание событий
     */
    private function create_events() {
        global $wpdb;

        // Валидация префикса таблицы для безопасности
        $safe_prefix = $this->validate_table_prefix($wpdb->prefix);

        // Дропаем cashback_ev_confirmed_cashback если он остался от старых версий плагина.
        // Начисление баланса теперь выполняется через PHP (process_ready_transactions),
        // вызываемый после каждой синхронизации с CPA-сетями — событие больше не нужно.
        $wpdb->query($wpdb->prepare('DROP EVENT IF EXISTS %i', $safe_prefix . 'cashback_ev_confirmed_cashback'));

        // Дропаем остальные события перед пересозданием
        $drops = array(
            $safe_prefix . 'cashback_ev_cleanup_cashback_webhooks_old',
            $safe_prefix . 'cashback_ev_cleanup_click_log',
            $safe_prefix . 'cashback_ev_mark_inactive_profiles',
        );

        foreach ($drops as $drop_event) {
            $wpdb->query($wpdb->prepare('DROP EVENT IF EXISTS %i', $drop_event));
        }

        $events = array(
            // Событие ежедневно проверяет и удаляет старые вебхуки если старше 6 месяцев
            "CREATE EVENT IF NOT EXISTS `{$safe_prefix}cashback_ev_cleanup_cashback_webhooks_old`
            ON SCHEDULE EVERY 1 DAY
            STARTS CURRENT_TIMESTAMP
            ON COMPLETION NOT PRESERVE
            ENABLE
            DO DELETE FROM `{$safe_prefix}cashback_webhooks`
            WHERE received_at < UTC_TIMESTAMP() - INTERVAL 6 MONTH
            LIMIT 100000",

            // Событие ежедневно удаляет записи кликов старше 90 дней
            "CREATE EVENT IF NOT EXISTS `{$safe_prefix}cashback_ev_cleanup_click_log`
            ON SCHEDULE EVERY 1 DAY
            STARTS CURRENT_TIMESTAMP
            ON COMPLETION NOT PRESERVE
            ENABLE
            DO DELETE FROM `{$safe_prefix}cashback_click_log`
            WHERE created_at < UTC_TIMESTAMP() - INTERVAL 6 MONTH
            LIMIT 100000",

            // Событие ежедневно проверяет и помечает неактивные профили если неактивны больше 6 месяцев
            "CREATE EVENT IF NOT EXISTS `{$safe_prefix}cashback_ev_mark_inactive_profiles`
            ON SCHEDULE EVERY 1 DAY
            STARTS CURRENT_TIMESTAMP
            ON COMPLETION PRESERVE
            ENABLE
            DO
            BEGIN
                IF GET_LOCK('cashback_inactive_profiles_lock', 0) = 1 THEN
                    UPDATE `{$safe_prefix}cashback_user_profile`
                    SET status = 'noactive'
                    WHERE
                        status = 'active'
                        AND (
                            (last_active_at IS NOT NULL AND last_active_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 MONTH))
                            OR
                            (last_active_at IS NULL AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 MONTH))
                        )
                    LIMIT 1000;
                    DO RELEASE_LOCK('cashback_inactive_profiles_lock');
                END IF;
            END;",
        );

        $failed_events = array();
        foreach ($events as $event) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static CREATE EVENT DDL from local $events array, $safe_prefix validated by regex.
            $result = $wpdb->query($event);
            if ($result === false) {
                $error = $wpdb->last_error;
                // События могут не поддерживаться на хостинге, логируем но не критично
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Mariadb Plugin Warning: Failed to create event. This may be normal if your hosting does not support MySQL events. Error: ' . $error);
                $failed_events[] = $error;
            }
        }

        // События опциональны, не прерываем активацию
        if (!empty($failed_events)) {
            update_option('cashback_events_active', false);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin: Some events failed to create (non-critical): ' . implode('; ', $failed_events));
        } else {
            update_option('cashback_events_active', true);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin: All events created successfully');
        }
    }

    /**
     * Инициализация существующих пользователей
     */
    private function initialize_existing_users() {
        global $wpdb;

        $batch_size        = 500;
        $offset            = 0;
        $total_initialized = 0;
        $total_errors      = 0;

        do {
            $user_ids = get_users(array(
                'fields' => 'ID',
                'number' => $batch_size,
                'offset' => $offset,
                'who'    => '',
            ));

            if (empty($user_ids)) {
                break;
            }

            foreach ($user_ids as $user_id) {
                $result = $this->add_user_to_cashback_tables((int) $user_id);
                if (!$result) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log("[Cashback] Failed to initialize user {$user_id}: " . $wpdb->last_error);
                    ++$total_errors;
                } else {
                    ++$total_initialized;
                }
            }

            $offset       += $batch_size;
            $batch_fetched = count($user_ids);
        } while ($batch_fetched === $batch_size);

        if ($total_initialized === 0 && $total_errors === 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin: No existing users to initialize');
        } else {
            $message = 'Mariadb Plugin: Successfully initialized ' . $total_initialized . ' existing users';
            if ($total_errors > 0) {
                $message .= ', ' . $total_errors . ' errors';
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log($message);
        }
    }

    /**
     * Добавление пользователя в профиль
     *
     * @param int $user_id ID пользователя.
     *
     * @return bool True при успехе, false при ошибке.
     */
    public function add_user_to_profile( int $user_id ): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_user_profile';

        $wpdb->query('START TRANSACTION');

        try {
            // INSERT IGNORE атомарно игнорирует дубли по PRIMARY KEY (user_id)
            // Защита от race condition
            $partner_token = self::generate_partner_token();
            $result        = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i (user_id, partner_token, status, created_at) VALUES (%d, %s, 'active', UTC_TIMESTAMP())",
                $table_name,
                $user_id,
                $partner_token
            ));

            // $result = 0 если запись уже существовала (игнорирована)
            // $result > 0 если запись была успешно создана
            $created = ( $result > 0 );

            if ($created) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Mariadb Plugin: Created new profile for user ID: ' . $user_id);
            }

            // Создаём баланс (независимо от того, был ли создан профиль)
            // Проверка существования баланса внутри метода add_user_to_balance
            $balance_result = $this->add_user_to_balance($user_id, $created);

            if (!$balance_result) {
                throw new Exception('Failed to create user balance');
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin Error: Transaction failed for user ' . $user_id . '. Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Добавление пользователя в баланс
     *
     * @param int  $user_id     ID пользователя.
     * @param bool $is_new_user Флаг нового пользователя (для логирования).
     *
     * @return bool True при успехе, false при ошибке.
     */
    public function add_user_to_balance( int $user_id, bool $is_new_user = true ): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_user_balance';

        // INSERT IGNORE атомарно игнорирует дубли по PRIMARY KEY
        $result = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO %i
            (user_id, available_balance, pending_balance, paid_balance, frozen_balance, version, updated_at)
            VALUES (%d, 0.00, 0.00, 0.00, 0.00, 0, UTC_TIMESTAMP())',
            $table_name,
            $user_id
        ));

        if ($result === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin Error: Failed to insert balance for user ' . $user_id . ': ' . $wpdb->last_error);
            return false;
        }

        if ($result > 0 && $is_new_user) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin: Created new balance for user ID: ' . $user_id);
        }

        return true;
    }

    /**
     * Добавление пользователя в таблицы кэшбэка при регистрации
     *
     * @param int $user_id ID пользователя.
     *
     * @return bool True при успехе, false при ошибке.
     */
    public function add_user_to_cashback_tables( int $user_id ): bool {
        global $wpdb;

        // Проверяем, существует ли профиль пользователя
        $table_profile = $wpdb->prefix . 'cashback_user_profile';
        $table_balance = $wpdb->prefix . 'cashback_user_balance';

        $profile_exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE user_id = %d',
            $table_profile,
            $user_id
        ));

        $balance_exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE user_id = %d',
            $table_balance,
            $user_id
        ));

        $is_new_user = !$profile_exists && !$balance_exists;

        if ($is_new_user) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin: Initializing new user ID: ' . $user_id);
        }

        // Сначала добавляем в профиль, который в свою очередь добавит в баланс
        $result = $this->add_user_to_profile($user_id);

        if ($result && $is_new_user) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin: Successfully created cashback profile and balance for user ID: ' . $user_id);
            do_action('cashback_notification_user_registered', $user_id);
        } elseif ($result && !$is_new_user) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin: User ID ' . $user_id . ' already initialized (skipped)');
        } else {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Mariadb Plugin Error: Failed to initialize user ID: ' . $user_id);
        }

        return $result;
    }

    /**
     * Генерация уникального читаемого идентификатора заявки на выплату
     * Формат: WD-XXXXXXXX, где X — символ из безопасного алфавита (без 0/O, 1/I/L)
     *
     * @return string Reference ID в формате WD-XXXXXXXX
     */
    public static function generate_reference_id(): string {
        // 31 символ: цифры 2-9 (8) + буквы A-Z без O, I, L (23) = 31
        $charset     = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $charset_len = 31;
        $id_length   = 8;

        $random_bytes = random_bytes($id_length);
        $result       = 'WD-';

        for ($i = 0; $i < $id_length; $i++) {
            $result .= $charset[ ord($random_bytes[ $i ]) % $charset_len ];
        }

        return $result;
    }


    // =========================================================================
    // Начисление кешбэка (PHP-замена MySQL event cashback_ev_confirmed_cashback)
    // =========================================================================

    /**
     * Batch-н��числение подтверждённых транзакций на баланс пользователей.
     *
     * Условия начисления:
     * - order_status = 'completed'
     * - api_verified = 1 (подтверждено API)
     * - funds_ready = 1 (деньги в CPA готовы к выводу)
     * - spam_click = 0
     * - processed_at IS NULL (ещё не начислено)
     * - cashback > 0
     * - Задержка cashback_balance_delay_days дней с updated_at (0 = сразу)
     * - Пользователь не забанен
     *
     * ДОЛЖЕ�� вызываться ТОЛЬ��О под глобальным Cashback_Lock.
     *
     * @return array{processed: int, ledger_inserted: int, errors: string[]}
     */
    public static function process_ready_transactions(): array {
        global $wpdb;
        $prefix        = $wpdb->prefix;
        $tx_table      = $prefix . 'cashback_transactions';
        $profile_table = $prefix . 'cashback_user_profile';
        $ledger_table  = $prefix . 'cashback_balance_ledger';
        $balance_table = $prefix . 'cashback_user_balance';

        // Проверяем что глобальный lock удержан (вызов разрешён только из sync)
        if (class_exists('Cashback_Lock') && !Cashback_Lock::is_lock_held_by_current_process()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback] process_ready_transactions called without global lock — DENIED');
            return array(
				'processed'       => 0,
				'ledger_inserted' => 0,
				'errors'          => array( 'Global lock not held' ),
			);
        }

        $errors          = array();
        $total_processed = 0;
        $total_ledger    = 0;

        try {
            $batch_id = cashback_generate_uuid7(false);

            $delay_days = (int) get_option('cashback_balance_delay_days', 0);

            $wpdb->query('START TRANSACTION');

            // ШАГ 1: SELECT FOR UPDATE — блокируем транзакции-кандидаты для начисления
            // Исключаем забаненных пользователей — их баланс заморожен триггером tr_freeze_balance_on_ban
            $candidates_sql  = "SELECT t.id, t.user_id, t.cashback
                 FROM %i t
                 INNER JOIN %i p
                     ON p.user_id = t.user_id AND p.status != 'banned'
                 WHERE t.order_status = 'completed'
                   AND t.api_verified = 1
                   AND t.funds_ready = 1
                   AND t.processed_at IS NULL
                   AND t.cashback IS NOT NULL
                   AND t.cashback > 0
                   AND t.spam_click = 0";
            $candidates_args = array( $tx_table, $profile_table );
            if ($delay_days > 0) {
                $candidates_sql   .= ' AND t.updated_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)';
                $candidates_args[] = $delay_days;
            }
            $candidates_sql .= ' FOR UPDATE';

            $candidates = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $candidates_sql composed from static literals + optional '%d DAY' delay fragment, args passed via prepare().
                $wpdb->prepare($candidates_sql, ...$candidates_args),
                ARRAY_A
            );

            if ($candidates === null) {
                throw new \RuntimeException('Step 1 (SELECT FOR UPDATE) failed: ' . $wpdb->last_error);
            }

            if (!empty($candidates)) {
                $candidate_ids = array_column($candidates, 'id');

                // ШАГ 2: INSERT IGNORE в леджер (идемпотентный — дубли пропускаются)
                // Каждая транзакция = одна запись в леджере с idempotency_key = "accrual_{id}"
                $ledger_values = array();
                $ledger_args   = array();
                foreach ($candidates as $row) {
                    $ledger_values[] = '(%d, %s, %s, %d, %s)';
                    $ledger_args[]   = (int) $row['user_id'];
                    $ledger_args[]   = 'accrual';
                    $ledger_args[]   = number_format((float) $row['cashback'], 2, '.', '');
                    $ledger_args[]   = (int) $row['id'];
                    $ledger_args[]   = 'accrual_' . $row['id'];
                }

                $values_sql = implode(', ', $ledger_values);
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $values_sql is static placeholder list built from a fixed literal '(%d, %s, %s, %d, %s)' repeated per candidate, args passed via prepare(); sniff cannot count placeholders inside $values_sql/spread.
                $ledger_result = $wpdb->query($wpdb->prepare(
                    "INSERT INTO %i
                         (user_id, type, amount, transaction_id, idempotency_key)
                     VALUES {$values_sql}
                     ON DUPLICATE KEY UPDATE id = id",
                    $ledger_table,
                    ...$ledger_args
                ));
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

                if ($ledger_result === false) {
                    throw new \RuntimeException('Step 2 (ledger INSERT) failed: ' . $wpdb->last_error);
                }
                $total_ledger = (int) $wpdb->rows_affected;

                // ШАГ 3: Маркируем транзакции как обработанные
                $id_placeholders = implode(',', array_fill(0, count($candidate_ids), '%d'));
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $id_placeholders is '%d,%d,...' from array_fill(), ids passed as %d args via prepare(); sniff cannot count placeholders inside $id_placeholders/spread.
                $step3 = $wpdb->query($wpdb->prepare(
                    "UPDATE %i
                     SET processed_at = UTC_TIMESTAMP(), processed_batch_id = %s
                     WHERE id IN ({$id_placeholders}) AND processed_at IS NULL",
                    $tx_table,
                    $batch_id,
                    ...$candidate_ids
                ));
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

                if ($step3 === false) {
                    throw new \RuntimeException('Step 3 (mark processed) failed: ' . $wpdb->last_error);
                }
                $total_processed = (int) $wpdb->rows_affected;

                // ШАГ 4: Обновляем кэш available_balance (из леджера — SUM за этот батч)
                $step4 = $wpdb->query($wpdb->prepare(
                    'INSERT INTO %i
                         (user_id, available_balance, version)
                     SELECT user_id, SUM(cashback), 0
                     FROM %i
                     WHERE processed_batch_id = %s AND cashback > 0
                     GROUP BY user_id
                     ON DUPLICATE KEY UPDATE
                         available_balance = available_balance + VALUES(available_balance),
                         version = version + 1',
                    $balance_table,
                    $tx_table,
                    $batch_id
                ));

                if ($step4 === false) {
                    throw new \RuntimeException('Step 4 (update balance cache) failed: ' . $wpdb->last_error);
                }

                // ШАГ 4.5: Начисление партнёрских комиссий (affiliate module)
                // NON-FATAL: ошибка affiliate не блокирует начисление кешбэка
                if (class_exists('Cashback_Affiliate_DB')
                    && Cashback_Affiliate_DB::is_module_enabled()
                    && class_exists('Cashback_Affiliate_Service')
                ) {
                    try {
                        $aff_result = Cashback_Affiliate_Service::process_affiliate_commissions($candidates);
                        if (!empty($aff_result['errors'])) {
                            foreach ($aff_result['errors'] as $aff_err) {
                                $errors[] = '[Affiliate] ' . $aff_err;
                            }
                        }
                    } catch (\Throwable $aff_e) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log('[Cashback] Affiliate commission error (non-fatal): ' . $aff_e->getMessage());
                        $errors[] = '[Affiliate] ' . $aff_e->getMessage();
                    }
                }

                // ��АГ 5: Переводим в финальный статус balance
                $step5 = $wpdb->query($wpdb->prepare(
                    "UPDATE %i
                     SET order_status = 'balance'
                     WHERE processed_batch_id = %s AND order_status = 'completed'",
                    $tx_table,
                    $batch_id
                ));

                if ($step5 === false) {
                    throw new \RuntimeException('Step 5 (finalize status) failed: ' . $wpdb->last_error);
                }
            }

            $wpdb->query('COMMIT');

            // Уведомление о начислении кэшбэка (после коммита — некритичная операция)
            if (!empty($candidates)) {
                do_action('cashback_notification_balance_credited', $candidates);
            }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $errors[] = $e->getMessage();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback] process_ready_transactions error: ' . $e->getMessage());
        }

        return array(
            'processed'       => $total_processed,
            'ledger_inserted' => $total_ledger,
            'errors'          => $errors,
        );
    }

    /**
     * Проверка консистентности баланса пользователя: леджер vs кэш.
     *
     * Сравнивает SUM(amount) из cashback_balance_ledger с данными cashback_user_balance.
     * Обнаруживает:
     * - Расхождения суммы начислений (ledger vs available_balance)
     * - Дублированные accrual записи (одна транзакция = одно начисление)
     * - Выплаты без payout_hold записи
     * - Отрицательный расчётный баланс
     *
     * @param int $user_id ID пользователя
     * @return array{consistent: bool, details: array}
     */
    public static function validate_user_balance_consistency( int $user_id ): array {
        global $wpdb;
        $ledger_table  = $wpdb->prefix . 'cashback_balance_ledger';
        $balance_table = $wpdb->prefix . 'cashback_user_balance';

        $issues = array();

        // 1. Суммы из леджера по типам операций
        $ledger_sums = $wpdb->get_results($wpdb->prepare(
            'SELECT type, SUM(amount) as total, COUNT(*) as cnt
             FROM %i
             WHERE user_id = %d
             GROUP BY type',
            $ledger_table,
            $user_id
        ), ARRAY_A);

        $sums   = array(
            'accrual'            => '0.00',
            'payout_hold'        => '0.00',
            'payout_complete'    => '0.00',
            'payout_cancel'      => '0.00',
            'payout_declined'    => '0.00',
            'payout_unfreeze'    => '0.00',
            'adjustment'         => '0.00',
            'affiliate_accrual'  => '0.00',
            'affiliate_reversal' => '0.00',
            'affiliate_freeze'   => '0.00',
            'affiliate_unfreeze' => '0.00',
            'ban_freeze'         => '0.00',
            'ban_unfreeze'       => '0.00',
        );
        $counts = array();
        foreach ($ledger_sums as $row) {
            $sums[ $row['type'] ]   = $row['total'];
            $counts[ $row['type'] ] = (int) $row['cnt'];
        }

        // Абсолютные значен��я сумм (все hold/complete/declined записаны как отрицательные)
        $abs_hold     = bcmul($sums['payout_hold'], '-1', 2);
        $abs_complete = bcmul($sums['payout_complete'], '-1', 2);
        $abs_declined = bcmul($sums['payout_declined'], '-1', 2);

        // Affiliate contributions (все в одном леджере)
        // affiliate_accrual (+), affiliate_reversal (-), affiliate_freeze (-), affiliate_unfreeze (+)
        $aff_net = bcadd(
            bcadd($sums['affiliate_accrual'], $sums['affiliate_reversal'], 2),
            bcadd($sums['affiliate_freeze'], $sums['affiliate_unfreeze'], 2),
            2
        );
        // affiliate_freeze �� отрицательная су��ма, |freeze| - unfreeze = замороженна�� affiliate часть
        $aff_frozen = bcadd(bcmul($sums['affiliate_freeze'], '-1', 2), bcmul($sums['affiliate_unfreeze'], '-1', 2), 2);
        if (bccomp($aff_frozen, '0', 2) < 0) {
            $aff_frozen = '0.00';
        }

        // Ban contributions (Группа 14): ban_freeze (-), ban_unfreeze (+).
        // ban_net списывает/возвращает в available (знак в amount уже правильный).
        // ban_frozen = активно замороженный ban-объём = |ban_freeze| - ban_unfreeze, clamp >= 0.
        $ban_net    = bcadd($sums['ban_freeze'], $sums['ban_unfreeze'], 2);
        $ban_frozen = bcsub(bcmul($sums['ban_freeze'], '-1', 2), $sums['ban_unfreeze'], 2);
        if (bccomp($ban_frozen, '0', 2) < 0) {
            $ban_frozen = '0.00';
        }

        // Расчётный available: accrual - |hold| + cancel + adjustment + affiliate_net + ban_net + payout_unfreeze
        // payout_hold, ban_freeze отрицательные → bcadd с отрицательным = вычитание.
        // payout_unfreeze (+amount) возвращает declined-сумму из frozen_balance_admin
        // в available_balance (F-S9-NEW-UNFREEZE).
        $ledger_available = bcadd(
            bcadd(
                bcadd(
                    bcadd(
                        bcadd(
                            bcadd($sums['accrual'], $sums['payout_hold'], 2),
                            $sums['payout_cancel'],
                            2
                        ),
                        $sums['adjustment'],
                        2
                    ),
                    $aff_net,
                    2
                ),
                $ban_net,
                2
            ),
            $sums['payout_unfreeze'],
            2
        );

        // Расчётный pending: |hold| - |complete| - |declined| - cancel
        // hold → день��и заблокированы, complete → выплачены, declined → заморожены, cancel → возвращены
        $ledger_pending = bcsub(
            bcsub(bcsub($abs_hold, $abs_complete, 2), $abs_declined, 2),
            $sums['payout_cancel'],
            2
        );

        // Расчётный paid: |payout_complete| (только реально выплаченные)
        $ledger_paid = $abs_complete;

        // Расчётный frozen (из леджера): |payout_declined| - payout_unfreeze + affiliate frozen + ban frozen
        // payout_unfreeze (+amount) — admin размораживает declined-сумму обратно в available,
        // поэтому она вычитается из активного frozen-объёма (F-S9-NEW-UNFREEZE).
        // Clamp >= 0: если backfill миграции v7 не покрыл legacy declined и admin
        // разморозил больше, чем зачтено в declined — в кеше уже отработал GREATEST(0,...).
        $declined_frozen_active = bcsub($abs_declined, $sums['payout_unfreeze'], 2);
        if (bccomp($declined_frozen_active, '0', 2) < 0) {
            $declined_frozen_active = '0.00';
        }
        $ledger_frozen = bcadd(bcadd($declined_frozen_active, $aff_frozen, 2), $ban_frozen, 2);

        // 2. Кэш из cashback_user_balance
        $cache = $wpdb->get_row($wpdb->prepare(
            'SELECT available_balance, pending_balance, paid_balance, frozen_balance
             FROM %i
             WHERE user_id = %d',
            $balance_table,
            $user_id
        ), ARRAY_A);

        $cache_available = $cache['available_balance'] ?? '0.00';
        $cache_pending   = $cache['pending_balance'] ?? '0.00';
        $cache_paid      = $cache['paid_balance'] ?? '0.00';

        // 3. Сравнени��
        $frozen    = $cache['frozen_balance'] ?? '0.00';
        $is_banned = bccomp($frozen, '0', 2) > 0;

        // ��сновная проверка: сумма в��ех денег в си��теме должна совпадать
        $ledger_total = bcadd(bcadd(bcadd($ledger_available, $ledger_pending, 2), $ledger_paid, 2), $ledger_frozen, 2);
        $cache_total  = bcadd(bcadd(bcadd($cache_available, $cache_pending, 2), $cache_paid, 2), $frozen, 2);

        if (bccomp($ledger_total, $cache_total, 2) !== 0) {
            $issues[] = sprintf(
                'total balance mismatch: ledger=%s, cache=%s (available=%s, pending=%s, paid=%s, frozen=%s)',
                $ledger_total,
                $cache_total,
                $cache_available,
                $cache_pending,
                $cache_paid,
                $frozen
            );
        }

        // Детальная проверка по полям (только для не забаненных)
        if (!$is_banned) {
            if (bccomp($ledger_available, $cache_available, 2) !== 0) {
                $issues[] = sprintf(
                    'available_balance mismatch: ledger=%s, cache=%s',
                    $ledger_available,
                    $cache_available
                );
            }

            if (bccomp($ledger_pending, $cache_pending, 2) !== 0) {
                $issues[] = sprintf(
                    'pending_balance mismatch: ledger=%s, cache=%s',
                    $ledger_pending,
                    $cache_pending
                );
            }

            if (bccomp($ledger_frozen, $frozen, 2) !== 0) {
                $issues[] = sprintf(
                    'frozen_balance mismatch: ledger(declined-unfreeze)=%s, cache=%s',
                    $ledger_frozen,
                    $frozen
                );
            }
        } else {
            // Для забаненного: триггер переносит available+pending → frozen
            $ledger_ban_frozen = bcadd(bcadd($ledger_available, $ledger_pending, 2), $ledger_frozen, 2);
            if (bccomp($ledger_ban_frozen, $frozen, 2) !== 0) {
                $issues[] = sprintf(
                    'frozen_balance mismatch (banned): ledger(available+pending+declined)=%s, cache frozen=%s',
                    $ledger_ban_frozen,
                    $frozen
                );
            }
        }

        if (bccomp($ledger_paid, $cache_paid, 2) !== 0) {
            $issues[] = sprintf(
                'paid_balance mismatch: ledger=%s, cache=%s',
                $ledger_paid,
                $cache_paid
            );
        }

        // 4. Дублированные accrual по transaction_id
        $dup_accruals = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT transaction_id, COUNT(*) as cnt
                FROM %i
                WHERE user_id = %d AND type = 'accrual' AND transaction_id IS NOT NULL
                GROUP BY transaction_id
                HAVING cnt > 1
            ) dups",
            $ledger_table,
            $user_id
        ));

        if ($dup_accruals > 0) {
            $issues[] = sprintf('duplicate accrual entries: %d transaction_ids with multiple accruals', $dup_accruals);
        }

        // 5. Отрицательный расчётный ба��анс
        if (bccomp($ledger_available, '0', 2) < 0) {
            $issues[] = sprintf('negative calculated available balance: %s', $ledger_available);
        }

        // 6. Выплаченные payout без hold записи
        $payouts_without_hold = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT l.payout_request_id)
             FROM %i l
             WHERE l.user_id = %d
               AND l.type = 'payout_complete'
               AND l.payout_request_id IS NOT NULL
               AND l.payout_request_id NOT IN (
                   SELECT payout_request_id
                   FROM %i
                   WHERE user_id = %d AND type = 'payout_hold' AND payout_request_id IS NOT NULL
               )",
            $ledger_table,
            $user_id,
            $ledger_table,
            $user_id
        ));

        if ($payouts_without_hold > 0) {
            $issues[] = sprintf('payout_complete without payout_hold: %d payouts', $payouts_without_hold);
        }

        // 7. Отменённые payout (payout_cancel) без парного hold — зеркало п.6.
        // Формула ledger_pending = |hold| - |complete| - |declined| - cancel
        // предполагает, что каждый cancel имеет parent hold. При legacy-данных
        // (до Группы 14) hold мог не писаться в ledger → формула даёт отрицательный
        // pending. Эта проверка даёт админу чёткий сигнал о причине.
        $cancels_without_hold_info = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(DISTINCT l.payout_request_id) AS cnt, COALESCE(SUM(l.amount), 0) AS sum_amount
             FROM %i l
             WHERE l.user_id = %d
               AND l.type = 'payout_cancel'
               AND l.payout_request_id IS NOT NULL
               AND l.payout_request_id NOT IN (
                   SELECT payout_request_id
                   FROM %i
                   WHERE user_id = %d AND type = 'payout_hold' AND payout_request_id IS NOT NULL
               )",
            $ledger_table,
            $user_id,
            $ledger_table,
            $user_id
        ), ARRAY_A);

        $cancels_without_hold       = (int) ( $cancels_without_hold_info['cnt'] ?? 0 );
        $cancels_without_hold_total = (string) ( $cancels_without_hold_info['sum_amount'] ?? '0.00' );
        if ($cancels_without_hold > 0) {
            $issues[] = sprintf(
                'payout_cancel without payout_hold: %d cancels, total %s',
                $cancels_without_hold,
                $cancels_without_hold_total
            );
        }

        return array(
            'consistent' => empty($issues),
            'details'    => array(
                'ledger' => array(
                    'available' => $ledger_available,
                    'pending'   => $ledger_pending,
                    'paid'      => $ledger_paid,
                    'sums'      => $sums,
                    'counts'    => $counts,
                ),
                'cache'  => $cache ?: array(),
                'issues' => $issues,
            ),
        );
    }

    // =========================================================================
    // Partner Token — замена user_id в партнёрских ссылках
    // =========================================================================

    /**
     * Генерация криптографически стойкого partner_token.
     *
     * 32 hex символа = 128 бит энтропии (random_bytes).
     * URL-safe, ASCII-only, совместим с любыми CPA subid полями.
     *
     * @return string 32-символьный hex токен.
     */
    public static function generate_partner_token(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Получение partner_token для пользователя.
     *
     * Если токен ещё не сгенерирован — создаёт его атомарно (race-condition safe).
     *
     * @param int $user_id ID пользователя WordPress.
     *
     * @return string|null Partner token или null если пользователь не найден в профиле.
     */
    public static function get_partner_token( int $user_id ): ?string {
        global $wpdb;

        if ($user_id <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'cashback_user_profile';

        // Быстрый путь: токен уже есть
        $token = $wpdb->get_var($wpdb->prepare(
            'SELECT partner_token FROM %i WHERE user_id = %d',
            $table,
            $user_id
        ));

        if (!empty($token)) {
            return $token;
        }

        // Проверяем, существует ли профиль вообще
        $profile_exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE user_id = %d',
            $table,
            $user_id
        ));

        if (!$profile_exists) {
            return null;
        }

        // Генерируем новый токен с защитой от коллизий (UNIQUE KEY)
        $max_retries = 5;
        for ($attempt = 0; $attempt < $max_retries; $attempt++) {
            $new_token = self::generate_partner_token();

            // Атомарное обновление: только если partner_token ещё NULL
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE %i SET partner_token = %s WHERE user_id = %d AND partner_token IS NULL',
                $table,
                $new_token,
                $user_id
            ));

            if ($updated === false && strpos($wpdb->last_error, 'Duplicate') !== false) {
                // Коллизия UNIQUE KEY — повторяем с новым токеном
                continue;
            }

            if ($updated === 0) {
                // Другой процесс уже установил токен — читаем его
                return $wpdb->get_var($wpdb->prepare(
                    'SELECT partner_token FROM %i WHERE user_id = %d',
                    $table,
                    $user_id
                ));
            }

            return $new_token;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('[Cashback] Failed to generate unique partner_token for user #' . $user_id . ' after ' . $max_retries . ' attempts');
        return null;
    }

    /**
     * Разрешение partner_token → user_id.
     *
     * @param string $token Partner token из CPA subid.
     *
     * @return int|null User ID или null если токен не найден.
     */
    public static function resolve_partner_token( string $token ): ?int {
        global $wpdb;

        // Валидация формата: строго 32 hex символа
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        $table = $wpdb->prefix . 'cashback_user_profile';

        $user_id = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM %i WHERE partner_token = %s LIMIT 1',
            $table,
            $token
        ));

        return $user_id !== null ? (int) $user_id : null;
    }

    /**
     * Batch-разрешение partner_token → user_id для массива токенов.
     *
     * @param array $tokens Массив partner_token строк.
     *
     * @return array Ассоциативный массив [token => user_id].
     */
    public static function resolve_partner_tokens_batch( array $tokens ): array {
        global $wpdb;

        if (empty($tokens)) {
            return array();
        }

        // Фильтруем валидные hex-токены
        $valid_tokens = array_filter($tokens, function ( string $t ): bool {
            return preg_match('/^[0-9a-f]{32}$/', $t) === 1;
        });

        if (empty($valid_tokens)) {
            return array();
        }

        $valid_tokens = array_unique(array_values($valid_tokens));
        $table        = $wpdb->prefix . 'cashback_user_profile';
        $placeholders = implode(',', array_fill(0, count($valid_tokens), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is '%s,%s,...' from array_fill(), tokens passed as %s args via prepare(); sniff cannot count placeholders inside $placeholders/spread.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT partner_token, user_id FROM %i WHERE partner_token IN ({$placeholders})",
            $table,
            ...$valid_tokens
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $map = array();
        foreach ($rows as $row) {
            $map[ $row['partner_token'] ] = (int) $row['user_id'];
        }

        return $map;
    }

    /**
     * Миграция: добавление 'cashback_global' в ENUM rate_type таблицы cashback_rate_history.
     * Для новых установок ENUM уже содержит cashback_global в схеме CREATE TABLE.
     */
    public function migrate_rate_history_enum(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_rate_history';

        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));

        if (!$exists) {
            return;
        }

        $column_type = $wpdb->get_row($wpdb->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'rate_type'",
            $table
        ));

        if (!$column_type) {
            return;
        }

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL information_schema column is returned as uppercase COLUMN_TYPE.
        if (strpos($column_type->COLUMN_TYPE, 'cashback_global') !== false) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "ALTER TABLE %i
             MODIFY COLUMN `rate_type` ENUM('cashback','cashback_global','affiliate_commission','affiliate_global')
             NOT NULL COMMENT 'Тип изменённой ставки'",
            $table
        ));

        if ($wpdb->last_error) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback] Failed to migrate rate_history ENUM: ' . $wpdb->last_error);
        }
    }

    /**
     * Миграция: safety-backfill ledger.accrual для исторических transactions.
     *
     * Группа 14 (шаг G): если в прошлом были случаи, когда transactions с
     * processed_at IS NOT NULL не получили парной accrual_{id} в ledger
     * (раннее состояние плагина / прерванный batch в process_ready_transactions),
     * миграция восстанавливает соответствие.
     *
     * Идемпотентность: ON DUPLICATE KEY UPDATE id=id по UNIQUE idempotency_key,
     * плюс option-флаг cashback_ledger_accrual_backfill_v1 для fast-path.
     */
    public function migrate_backfill_ledger_accruals(): void {
        if ( get_option( 'cashback_ledger_accrual_backfill_v1' ) === 'done' ) {
            return;
        }

        global $wpdb;

        $tx_table     = $wpdb->prefix . 'cashback_transactions';
        $ledger_table = $wpdb->prefix . 'cashback_balance_ledger';

        // Guard: обе таблицы должны существовать.
        $have_both = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
            $tx_table,
            $ledger_table
        ) );
        if ( $have_both < 2 ) {
            return;
        }

        $inserted_total = 0;
        $batch_size     = 500;

        for ( $i = 0; $i < 1000; $i++ ) {
            // Находим transactions с processed_at IS NOT NULL и cashback>0,
            // у которых НЕТ парной записи accrual_{id} в ledger.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT t.id, t.user_id, t.cashback
                 FROM %i t
                 LEFT JOIN %i l
                   ON l.transaction_id = t.id AND l.type = 'accrual'
                 WHERE t.processed_at IS NOT NULL
                   AND t.cashback IS NOT NULL
                   AND t.cashback > 0
                   AND l.id IS NULL
                 ORDER BY t.id ASC
                 LIMIT %d",
                $tx_table,
                $ledger_table,
                $batch_size
            ), ARRAY_A );

            if ( empty( $rows ) ) {
                break;
            }

            $values_sql = array();
            $args       = array( $ledger_table );
            foreach ( $rows as $row ) {
                $values_sql[] = '(%d, %s, %s, %d, %s)';
                $args[]       = (int) $row['user_id'];
                $args[]       = 'accrual';
                $args[]       = number_format( (float) $row['cashback'], 2, '.', '' );
                $args[]       = (int) $row['id'];
                $args[]       = 'accrual_' . (int) $row['id'];
            }

            $values_sql_joined = implode( ', ', $values_sql );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $values_sql_joined — статичная последовательность placeholders '(%d, %s, %s, %d, %s)'; args передаются через prepare(), sniffer не считает через spread.
            $result = $wpdb->query( $wpdb->prepare(
                "INSERT INTO %i
                    (user_id, type, amount, transaction_id, idempotency_key)
                 VALUES {$values_sql_joined}
                 ON DUPLICATE KEY UPDATE id = id",
                ...$args
            ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

            if ( $result === false ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log( '[Cashback Backfill] ledger.accrual insert failed: ' . $wpdb->last_error );
                break;
            }

            $inserted_total += (int) $wpdb->rows_affected;

            // Если вставок было меньше batch — значит долг догнан.
            if ( count( $rows ) < $batch_size ) {
                break;
            }
        }

        update_option( 'cashback_ledger_accrual_backfill_v1', 'done', false );

        if ( $inserted_total > 0 ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log( sprintf( '[Cashback Backfill] ledger accruals backfilled: %d rows', $inserted_total ) );
        }
    }

    /**
     * Миграция: добавление 'ban_freeze' и 'ban_unfreeze' в ENUM type таблицы cashback_balance_ledger.
     *
     * Для новых установок эти значения уже есть в CREATE TABLE. На существующих
     * инсталляциях (deploy до группы 14) миграция безопасна и идемпотентна — если
     * значения уже присутствуют в COLUMN_TYPE, функция выходит без ALTER.
     */
    public function migrate_ledger_ban_enum(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_balance_ledger';

        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));

        if (!$exists) {
            return;
        }

        $column_type = $wpdb->get_row($wpdb->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'type'",
            $table
        ));

        if (!$column_type) {
            return;
        }

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL information_schema column is returned as uppercase COLUMN_TYPE.
        $current_type = (string) $column_type->COLUMN_TYPE;

        if (strpos($current_type, "'ban_freeze'") !== false && strpos($current_type, "'ban_unfreeze'") !== false) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "ALTER TABLE %i
             MODIFY COLUMN `type` ENUM('accrual','payout_hold','payout_complete','payout_cancel','payout_declined','adjustment','affiliate_accrual','affiliate_reversal','affiliate_freeze','affiliate_unfreeze','ban_freeze','ban_unfreeze')
             NOT NULL COMMENT 'Тип операции'",
            $table
        ));

        if ($wpdb->last_error) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback] Failed to migrate balance_ledger type ENUM: ' . $wpdb->last_error);
        }
    }

    /**
     * Удаление триггеров уведомлений tr_notify_transaction_insert/update
     *
     * Запись в очередь уведомлений перенесена на уровень приложения:
     * Python webhook worker (transaction_new) и PHP API sync (status/data changes).
     */
    public function migrate_drop_notification_triggers(): void {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $triggers = array(
            "{$prefix}tr_notify_transaction_insert",
            "{$prefix}tr_notify_transaction_update",
        );

        foreach ($triggers as $trigger) {
            $wpdb->query($wpdb->prepare('DROP TRIGGER IF EXISTS %i', $trigger));
        }
    }

    /**
     * Миграция: добавление reference_id к таблицам транзакций и бэкфилл существующих записей.
     * Форматы: TX-XXXXXXXX для cashback_transactions, TU-XXXXXXXX для cashback_unregistered_transactions
     * (8 hex-символов из MD5(UUID()+RAND())). Префиксы разные → кросс-табличная уникальность по конструкции.
     * Идемпотентная — проверяет наличие колонки/индекса через INFORMATION_SCHEMA.
     */
    public function migrate_add_transaction_reference_id(): void {
        global $wpdb;

        $unregistered_table = $wpdb->prefix . 'cashback_unregistered_transactions';

        $tables = array(
            $wpdb->prefix . 'cashback_transactions' => 'uk_tx_reference_id',
            $unregistered_table                     => 'uk_utx_reference_id',
        );

        foreach ($tables as $table => $uk_name) {
            $is_unregistered = ( $table === $unregistered_table );
            $ref_prefix      = $is_unregistered ? 'TU-' : 'TX-';
            $ddl_comment     = $is_unregistered
                ? 'Public transaction ID, format TU-XXXXXXXX (unregistered)'
                : 'Public transaction ID, format TX-XXXXXXXX';

            $column_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'reference_id'",
                    $table
                )
            );

            if (! $column_exists) {
                $wpdb->query($wpdb->prepare(
                    "ALTER TABLE %i ADD COLUMN `reference_id` varchar(11) NOT NULL DEFAULT '' COMMENT %s",
                    $table,
                    $ddl_comment
                ));
                if ($wpdb->last_error) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log("[Cashback Migration] ALTER TABLE {$table}: " . $wpdb->last_error);
                    continue;
                }
            }

            // Бэкфилл: генерируем reference_id для записей где он пустой.
            // Сначала проверяем есть ли что заполнять — если нет, не трогаем триггер.
            $empty_count = (int) $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE reference_id = %s', $table, '')
            );

            if ($empty_count > 0) {
                // Временно отключаем триггер валидации статусов — он блокирует UPDATE записей с order_status='balance'.
                // Триггер будет пересоздан в recreate_triggers() после миграции.
                $trigger_name = ( $table === $wpdb->prefix . 'cashback_transactions' )
                    ? $wpdb->prefix . 'cashback_tr_validate_status_transition'
                    : $wpdb->prefix . 'cashback_tr_validate_status_transition_unregistered';

                // Codex round 6 #2 (2026-05-10): чтобы concurrent admin/API write
                // не пробил status-validation в окне DROP+UPDATE+CREATE TRIGGER,
                // берём LOCK TABLES WRITE на таблицу. Это сериализует backfill
                // с любыми параллельными writes — окно гарантировано без
                // unprotected UPDATE'ов. UNLOCK TABLES в finally — защита от
                // exception между DROP и CREATE (иначе таблица осталась бы
                // залоченной до конца session'а PHP-FPM воркера).
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL.
                $wpdb->query($wpdb->prepare('LOCK TABLES %i WRITE', $table));
                try {
                    $wpdb->query($wpdb->prepare('DROP TRIGGER IF EXISTS %i', $trigger_name));

                    $total_filled = 0;

                    for ($i = 0; $i < 10000; $i++) {
                        $ids = $wpdb->get_col(
                            $wpdb->prepare('SELECT id FROM %i WHERE reference_id = %s LIMIT 500', $table, '')
                        );

                        if (empty($ids)) {
                            break;
                        }

                        $cases = array();
                        foreach ($ids as $id) {
                            $ref     = $ref_prefix . strtoupper(substr(md5(wp_generate_uuid4() . wp_rand()), 0, 8));
                            $cases[] = $wpdb->prepare('WHEN %d THEN %s', (int) $id, $ref);
                        }

                        $ids_list = implode(',', array_map('intval', $ids));
                        $case_sql = implode(' ', $cases);
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $case_sql is pre-prepared WHEN/THEN pairs (integer ids + TX-/TU- 11-char strings), $ids_list is intval-cast list.
                        $wpdb->query($wpdb->prepare("UPDATE %i SET reference_id = CASE id {$case_sql} END WHERE id IN ({$ids_list})", $table));

                        if ($wpdb->last_error) {
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                            error_log("[Cashback Migration] Backfill error on {$table}: " . $wpdb->last_error);
                            break;
                        }

                        $total_filled += count($ids);
                    }
                } finally {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL.
                    $wpdb->query('UNLOCK TABLES');
                }
            }

            // UNIQUE KEY
            $index_exists = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                    $table,
                    $uk_name
                )
            );

            if (! $index_exists) {
                $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD UNIQUE KEY %i (`reference_id`)', $table, $uk_name));
                if ($wpdb->last_error) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log("[Cashback Migration] UNIQUE KEY on {$table}: " . $wpdb->last_error);
                }
            }
        }
    }

    /**
     * Миграция: перегенерация старых TX- reference_id в cashback_unregistered_transactions на TU-.
     *
     * Причина: до этой миграции обе таблицы транзакций использовали префикс TX- независимо,
     * с UNIQUE-индексами только в пределах таблицы. Смена префикса unregistered-таблицы на
     * TU- (по конструкции) обеспечивает кросс-табличную уникальность и читаемость источника.
     *
     * Идемпотентна: если TX-записей нет — возвращается сразу. Обновляет пакетами по 500,
     * ретраит при UNIQUE-коллизии (практически невозможной: 16^8 = 4.3 млрд комбинаций).
     */
    public function migrate_unregistered_reference_id_prefix(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_unregistered_transactions';

        // Fast-path: колонка должна существовать (миграция add_transaction_reference_id должна быть выполнена первой).
        $column_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'reference_id'",
                $table
            )
        );
        if (! $column_exists) {
            return;
        }

        // Fast-path: нет TX- записей — миграция уже выполнена.
        $tx_count = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE reference_id LIKE %s', $table, 'TX-%')
        );
        if ($tx_count === 0) {
            return;
        }

        // Отключаем триггер валидации статусов — он блокирует UPDATE записей с order_status='balance'.
        // Пересоздаётся в recreate_triggers() (вызывается в caller после миграции).
        //
        // Codex round 6 #2 (2026-05-10): LOCK TABLES WRITE сериализует backfill
        // с concurrent admin/API writes — окно между DROP TRIGGER и UPDATE
        // batch'ами не пропустит запрещённые status-transitions без проверки.
        // UNLOCK TABLES в finally — защита от exception (иначе таблица осталась
        // бы залоченной до конца session'а PHP-FPM воркера).
        $status_trigger = $wpdb->prefix . 'cashback_tr_validate_status_transition_unregistered';
        $total_updated  = 0;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL.
        $wpdb->query($wpdb->prepare('LOCK TABLES %i WRITE', $table));
        try {
            $wpdb->query($wpdb->prepare('DROP TRIGGER IF EXISTS %i', $status_trigger));

            for ($batch = 0; $batch < 10000; $batch++) {
                $ids = $wpdb->get_col(
                    $wpdb->prepare('SELECT id FROM %i WHERE reference_id LIKE %s LIMIT 500', $table, 'TX-%')
                );

                if (empty($ids)) {
                    break;
                }

                foreach ($ids as $id) {
                    $id = (int) $id;
                    for ($attempt = 0; $attempt < 3; $attempt++) {
                        $new_ref = 'TU-' . strtoupper(substr(md5(wp_generate_uuid4() . wp_rand()), 0, 8));
                        $result  = $wpdb->query($wpdb->prepare(
                            'UPDATE %i SET reference_id = %s WHERE id = %d AND reference_id LIKE %s',
                            $table,
                            $new_ref,
                            $id,
                            'TX-%'
                        ));

                        if ($result !== false) {
                            ++$total_updated;
                            break;
                        }

                        // UNIQUE-коллизия — ретраим с новым suffix.
                        if (stripos((string) $wpdb->last_error, 'uk_utx_reference_id') === false
                            && stripos((string) $wpdb->last_error, 'reference_id') === false) {
                            // Неизвестная ошибка — прерываемся, логируем.
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                            error_log("[Cashback Migration] TX->TU update error on id={$id}: " . $wpdb->last_error);
                            break;
                        }
                    }
                }
            }
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL.
            $wpdb->query('UNLOCK TABLES');
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log("[Cashback Migration] Unregistered reference_id TX->TU: updated {$total_updated} rows");
    }

    /**
     * Миграция v2: bucket-split заморозки баланса.
     *
     * Добавляет в cashback_user_balance три колонки:
     *   - frozen_balance_ban         — available-часть, замороженная при бане
     *   - frozen_balance_payout      — удержание под активной заявкой на выплату
     *   - frozen_pending_balance_ban — pending-часть, замороженная при бане
     *
     * Backfill: существующий frozen_balance трактуется как payout-hold
     * (ban-заморозка снимается одновременно с разбаном, поэтому длительно
     * удерживаемая заморозка в большинстве установок — это payout-bucket).
     *
     * Версионизация: option cashback_db_version. Миграция идемпотентна —
     * проверяет наличие колонок через INFORMATION_SCHEMA.
     */
    /**
     * Миграция 12i-1 ADR (F-10-001): click_sessions table + click_log FK +
     * merchant policy columns в affiliate_networks.
     *
     * - Создаёт таблицу cashback_click_sessions (CREATE TABLE IF NOT EXISTS
     *   идемпотентен — для fresh installs уже поднят в create_tables()).
     * - ALTER TABLE click_log ADD click_session_id / client_request_id / is_session_primary.
     * - ALTER TABLE affiliate_networks ADD click_session_policy / activation_window_seconds.
     *
     * Версионизация: cashback_db_version = 3. Проверка колонок через
     * INFORMATION_SCHEMA — идемпотентная (паттерн migrate_add_frozen_balance_buckets).
     */
    public function migrate_add_click_sessions_v1(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 3) {
            return;
        }

        $click_log_table    = $wpdb->prefix . 'cashback_click_log';
        $networks_table     = $wpdb->prefix . 'cashback_affiliate_networks';
        $click_sessions_tbl = $wpdb->prefix . 'cashback_click_sessions';

        // ------------------------------------------------------------------
        // click_log: ADD COLUMN click_session_id / client_request_id / is_session_primary
        // ------------------------------------------------------------------
        $click_log_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $click_log_table
        ));
        if ($click_log_exists > 0) {
            $click_log_columns = array(
                'click_session_id'   => "ADD COLUMN `click_session_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK cashback_click_sessions.id (12i)' AFTER `id`",
                'client_request_id'  => "ADD COLUMN `client_request_id` CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'UUID v4/v7 (12i)' AFTER `click_session_id`",
                'is_session_primary' => "ADD COLUMN `is_session_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = primary tap of session (12i)' AFTER `client_request_id`",
            );

            foreach ($click_log_columns as $column_name => $alter_clause) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    $click_log_table,
                    $column_name
                ));
                if ($exists === 0) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alter_clause is a hardcoded DDL fragment from this method (no user input).
                    $wpdb->query($wpdb->prepare("ALTER TABLE %i {$alter_clause}", $click_log_table));
                    if ($wpdb->last_error) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log("[Cashback Migration v3] click_log ADD COLUMN {$column_name}: " . $wpdb->last_error);
                        return;
                    }
                }
            }

            // Индексы click_log: idx_click_session_id + idx_client_request.
            $click_log_indexes = array(
                'idx_click_session_id' => 'ADD KEY `idx_click_session_id` (`click_session_id`)',
                'idx_client_request'   => 'ADD KEY `idx_client_request` (`user_id`,`client_request_id`)',
            );
            foreach ($click_log_indexes as $index_name => $alter_clause) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                    $click_log_table,
                    $index_name
                ));
                if ($exists === 0) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alter_clause is a hardcoded DDL fragment from this method (no user input).
                    $wpdb->query($wpdb->prepare("ALTER TABLE %i {$alter_clause}", $click_log_table));
                    if ($wpdb->last_error) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log("[Cashback Migration v3] click_log ADD KEY {$index_name}: " . $wpdb->last_error);
                        return;
                    }
                }
            }
        }

        // ------------------------------------------------------------------
        // affiliate_networks: ADD COLUMN click_session_policy / activation_window_seconds
        // ------------------------------------------------------------------
        $networks_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $networks_table
        ));
        if ($networks_exists > 0) {
            $networks_columns = array(
                'click_session_policy'      => "ADD COLUMN `click_session_policy` VARCHAR(32) NOT NULL DEFAULT 'reuse_in_window' COMMENT 'Merchant policy (12i): reuse_in_window | always_new | reuse_per_product'",
                'activation_window_seconds' => "ADD COLUMN `activation_window_seconds` INT UNSIGNED NOT NULL DEFAULT 1800 COMMENT 'Per-network activation window 60..86400 (12i)'",
            );

            foreach ($networks_columns as $column_name => $alter_clause) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    $networks_table,
                    $column_name
                ));
                if ($exists === 0) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alter_clause is a hardcoded DDL fragment from this method (no user input).
                    $wpdb->query($wpdb->prepare("ALTER TABLE %i {$alter_clause}", $networks_table));
                    if ($wpdb->last_error) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log("[Cashback Migration v3] affiliate_networks ADD COLUMN {$column_name}: " . $wpdb->last_error);
                        return;
                    }
                }
            }
        }

        update_option('cashback_db_version', 3, false);
    }

    public function migrate_add_frozen_balance_buckets(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_user_balance';

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 2) {
            return;
        }

        $table_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));
        if ($table_exists === 0) {
            return;
        }

        $columns = array(
            'frozen_balance_ban'         => "ADD COLUMN `frozen_balance_ban` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Доступная часть, замороженная при бане пользователя' AFTER `frozen_balance`",
            'frozen_balance_payout'      => "ADD COLUMN `frozen_balance_payout` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Часть, удерживаемая под активной заявкой на выплату' AFTER `frozen_balance_ban`",
            'frozen_pending_balance_ban' => "ADD COLUMN `frozen_pending_balance_ban` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Pending-часть, замороженная при бане' AFTER `frozen_balance_payout`",
        );

        foreach ($columns as $column_name => $alter_clause) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $column_name
            ));

            if ($exists === 0) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alter_clause is a hardcoded DDL fragment from this method (no user input).
                $wpdb->query($wpdb->prepare("ALTER TABLE %i {$alter_clause}", $table));
                if ($wpdb->last_error) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log("[Cashback Migration v2] ADD COLUMN {$column_name}: " . $wpdb->last_error);
                    return; // Не поднимаем db_version — пусть попробуется снова.
                }
            }
        }

        // Backfill: legacy frozen_balance трактуем как payout-hold.
        // Безопасно: ban-заморозка снимается триггером на unban, поэтому
        // ненулевой frozen_balance на момент миграции — почти наверняка payout.
        $backfill = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET frozen_balance_payout = frozen_balance WHERE frozen_balance > 0 AND frozen_balance_payout = 0',
            $table
        ));
        if ($backfill === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v2] backfill frozen_balance_payout failed: ' . $wpdb->last_error);
            return;
        }

        update_option('cashback_db_version', 2, false);
    }

    /**
     * Миграция группы 6 (шаг 2 ADR): schema-level idempotency.
     * Делегирует в Cashback_Schema_Idempotency_Migration (отдельный класс).
     * При обнаружении дублей — abort + admin-notice (tools/dedup-rows-*.php).
     */
    public function migrate_schema_idempotency_v1(): void {
        global $wpdb;

        if (!class_exists('Cashback_Schema_Idempotency_Migration')) {
            require_once __DIR__ . '/includes/class-cashback-schema-idempotency-migration.php';
        }

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $migration->run();
    }

    /**
     * Миграция: добавление колонки `created_by_admin` в cashback_transactions.
     *
     * Флаг помечает транзакции, созданные админом вручную через подстраницу
     * «Сверка баланса» → зависшие approved claims (handle_create_stuck_claim_tx).
     * Используется в API-валидации для отображения столбца «Добавлена админом»
     * в таблице расхождений «Есть на сайте, нет в API» — такие транзакции
     * не приходят от CPA-сети и поэтому отсутствуют в API-ответе by design.
     *
     * Идемпотентная: проверяет наличие колонки через INFORMATION_SCHEMA.
     * Использует raw $wpdb->query (не prepare) — non-ASCII COMMENT хрупок
     * при $wpdb->prepare('ALTER TABLE %i ... COMMENT %s'), см. project memory.
     * Post-verify через SHOW COLUMNS.
     */
    public function migrate_add_transaction_created_by_admin(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_transactions';

        $column_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = 'created_by_admin'",
            $table
        ));
        if ($column_exists > 0) {
            return;
        }

        // ВАЖНО: ALTER без COMMENT (только ASCII в SQL). По project memory
        // и подтверждено 2026-04-25: и `$wpdb->prepare("ALTER ... COMMENT 'рус')`,
        // и raw `$wpdb->query("ALTER ... COMMENT 'рус')` отбрасываются WP-обёрткой
        // в этом окружении («query contains invalid data»). COMMENT для свежих
        // установок описан в CREATE TABLE (выше); для апгрейдов колонка просто
        // без описательного COMMENT — поведение колонки идентично, отличается
        // только метаданные в INFORMATION_SCHEMA.COLUMNS.COLUMN_COMMENT.
        // $table = $wpdb->prefix . 'cashback_transactions' — без user-input.
        $sql = "ALTER TABLE `{$table}` ADD COLUMN `created_by_admin` tinyint(1) NOT NULL DEFAULT 0 AFTER `reference_id`";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- DDL с интерполяцией $wpdb->prefix-табличного имени, см. комментарий выше.
        $wpdb->query($sql);

        if ($wpdb->last_error) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log("[Cashback Migration] ALTER TABLE {$table} ADD created_by_admin: " . $wpdb->last_error);
            return;
        }

        // Post-verify через SHOW COLUMNS — DDL с raw query не возвращает строгого
        // confirmation на всех окружениях, проверяем явно.
        $verified = $wpdb->get_row($wpdb->prepare(
            'SHOW COLUMNS FROM %i LIKE %s',
            $table,
            'created_by_admin'
        ));
        if (!$verified) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log("[Cashback Migration] created_by_admin column verification FAILED on {$table}");
        }
    }

    /**
     * Миграция v4: нормализация charset hash/UUID-колонок ascii_bin → utf8mb4_bin.
     *
     * Корневой причина: WP-core wpdb::get_table_charset() возвращает 'ascii' для
     * любой таблицы, где смешаны колонки с charset 'ascii' и 'utf8mb4'. После
     * этого strip_invalid_text_from_query() стрипает все non-ASCII символы из
     * запроса и блокирует его («Could not perform query because it contains
     * invalid data»). На практике это ломало поиск/фильтры по русским значениям
     * на cashback-history (offer_name LIKE '%русское%' возвращал 0).
     *
     * Решение: перевести все 32/36-символьные hash/UUID/click_id колонки на
     * `utf8mb4 utf8mb4_bin` — бинарное сравнение сохраняется (важно для индексов
     * и идемпотентности), а WordPress перестаёт стрипать UTF-8 в запросах.
     *
     * Идемпотентная: проверяет CHARACTER_SET_NAME через INFORMATION_SCHEMA и
     * пропускает уже мигрированные колонки. Не трогает таблицы, которые ещё не
     * созданы (например модули, активирующиеся отдельно). При первой ошибке —
     * прерывает миграцию без повышения cashback_db_version, чтобы повторить
     * на следующей активации.
     *
     * Версионизация: cashback_db_version = 4.
     */
    public function migrate_normalize_charset_v4(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 4) {
            return;
        }

        // [table_suffix, column, definition] — definition без COMMENT (по project memory:
        // wpdb отбрасывает non-ASCII COMMENT в DDL как «invalid data»). Метаданные COMMENT
        // у свежих установок прописаны в CREATE TABLE; для апгрейдов остаются NULL — это
        // не влияет на поведение колонок.
        $columns = array(
            array( 'cashback_transactions', 'click_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_transactions', 'processed_batch_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_unregistered_transactions', 'click_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_unregistered_transactions', 'processed_batch_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_user_profile', 'partner_token', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_click_log', 'client_request_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_click_log', 'click_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL' ),
            array( 'cashback_click_sessions', 'canonical_click_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL' ),
            array( 'cashback_cron_state', 'run_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL' ),
            array( 'cashback_payout_requests', 'idempotency_key', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL' ),
            array( 'cashback_affiliate_audit', 'click_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_affiliate_audit', 'partner_token_hash', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_affiliate_audit', 'ip_hash', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_affiliate_audit', 'ip_subnet_hash', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_affiliate_audit', 'ua_hash', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_affiliate_audit', 'key_hash', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_affiliate_clicks', 'click_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL' ),
            array( 'cashback_affiliate_profiles', 'referral_click_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL' ),
            array( 'cashback_broadcast_campaigns', 'campaign_uuid', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL' ),
            array( 'cashback_claims', 'click_id', 'CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL' ),
            array( 'cashback_claims', 'idempotency_key', 'CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL' ),
            array( 'cashback_support_messages', 'request_id', 'CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL' ),
        );

        foreach ($columns as $entry) {
            list( $table_suffix, $column, $definition ) = $entry;
            $table = $wpdb->prefix . $table_suffix;

            // Skip если таблица не создана (модуль ещё не активирован).
            $table_exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            if ($table_exists === 0) {
                continue;
            }

            $current_charset = $wpdb->get_var($wpdb->prepare(
                'SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $column
            ));
            // Колонка отсутствует — пропустим (создастся при следующей миграции).
            if (null === $current_charset) {
                continue;
            }
            // Уже utf8mb4 — пропустим.
            if ('utf8mb4' === $current_charset) {
                continue;
            }

            // Raw SQL без prepare — DDL с интерполяцией табличного имени, $table_suffix
            // и $column из allowlist выше, $definition — hardcoded ASCII-DDL без user input.
            $sql = "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}";
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL с allowlist значениями, см. комментарий выше.
            $wpdb->query($sql);

            if ($wpdb->last_error) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log("[Cashback Migration v4] {$table}.{$column}: " . $wpdb->last_error);
                return;
            }

            // Post-verify: charset точно сменился.
            $new_charset = $wpdb->get_var($wpdb->prepare(
                'SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $column
            ));
            if ('utf8mb4' !== $new_charset) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log("[Cashback Migration v4] {$table}.{$column} verify FAILED: charset still {$new_charset}");
                return;
            }
        }

        update_option('cashback_db_version', 4, false);
    }

    /**
     * Миграция v5: defense-in-depth для P2-A1-2 (E2E прогон 2026-04-29).
     *
     * Раньше можно было через raw `UPDATE wp_cashback_payout_requests SET status='declined'`
     * (или 'failed') оставить fail_reason пустым/NULL — реальные admin handler'ы заполняют,
     * но schema-level enforcement отсутствовал. Триггеры закрывают этот gap.
     *
     * Шаги:
     *   1. Backfill legacy строк: пустой fail_reason у уже-declined/failed заявок →
     *      '(legacy: причина не указана)' (иначе триггер потом отклонит legitimate
     *      UPDATE других полей у legacy строки).
     *   2. CREATE OR REPLACE TRIGGER tr_payout_require_fail_reason_ins / _upd —
     *      SIGNAL SQLSTATE '45000' если NEW.status IN ('declined','failed') AND
     *      NEW.fail_reason пуст. Атомарный rebuild (MariaDB 10.1.4+) — без gap'а
     *      между DROP и CREATE, в котором концурентный INSERT мог бы пройти без
     *      проверки fail_reason.
     *
     * Версионизация: cashback_db_version = 5. Idempotent через get_option fast-path.
     * Используется raw $wpdb->query (триггерное тело — multi-statement DDL,
     * %i prepare не покрывает CREATE TRIGGER body).
     */
    public function migrate_payout_require_fail_reason_v5(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 5) {
            return;
        }

        $payouts_table = $wpdb->prefix . 'cashback_payout_requests';

        // Skip если таблица не создана (свежая установка ещё не дошла до create_tables).
        $table_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $payouts_table
        ));
        if ($table_exists === 0) {
            return;
        }

        // ------------------------------------------------------------------
        // Шаг 1: Backfill legacy строк
        // ------------------------------------------------------------------
        $backfill_result = $wpdb->query($wpdb->prepare(
            "UPDATE %i
                SET fail_reason = '(legacy: причина не указана)',
                    updated_at  = updated_at
              WHERE status IN ('declined','failed')
                AND (fail_reason IS NULL OR fail_reason = '')",
            $payouts_table
        ));
        if ($backfill_result === false) {
            throw new \RuntimeException(esc_html('[Cashback Migration v5] backfill fail_reason failed: ' . $wpdb->last_error));
        }

        // ------------------------------------------------------------------
        // Шаг 2: CREATE OR REPLACE TRIGGER tr_payout_require_fail_reason_ins / _upd
        //
        // Атомарный rebuild — без окна между DROP и CREATE. Идемпотентно
        // (повторный прогон миграции просто перезапишет триггер).
        // ------------------------------------------------------------------
        $safe_prefix     = $this->validate_table_prefix($wpdb->prefix);
        $create_triggers = array(
            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_payout_require_fail_reason_ins`
            BEFORE INSERT ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            BEGIN
                IF NEW.status IN ('declined','failed')
                    AND (NEW.fail_reason IS NULL OR NEW.fail_reason = '') THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'fail_reason required for declined/failed payout status';
                END IF;
            END",
            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_payout_require_fail_reason_upd`
            BEFORE UPDATE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            BEGIN
                IF NEW.status IN ('declined','failed')
                    AND (NEW.fail_reason IS NULL OR NEW.fail_reason = '') THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'fail_reason required for declined/failed payout status';
                END IF;
            END",
        );

        foreach ($create_triggers as $sql) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static CREATE TRIGGER DDL, $safe_prefix validated.
            $create_result = $wpdb->query($sql);
            if ($create_result === false) {
                throw new \RuntimeException(esc_html('[Cashback Migration v5] CREATE TRIGGER failed: ' . $wpdb->last_error));
            }
        }

        update_option('cashback_db_version', 5, false);
    }

    /**
     * Миграция v6: разделение ban_reason на ban_reason (публичная) и
     * ban_reason_admin (внутренняя для админов).
     *
     * Закрывает OBS-06 (E2E run B 2026-04-30, P2 data leak): админ-внутренние
     * комментарии при бане (типа «отмыв через partner_id=42») сейчас
     * показываются пользователю на withdrawal page. Разделяем поля:
     *   - `ban_reason`        — публичная причина (показывается пользователю)
     *   - `ban_reason_admin`  — внутренняя (только для админов)
     *
     * Стратегия миграции (UX choice 2026-04-30: «Generic without reason»):
     *   1. Добавляем колонку `ban_reason_admin`.
     *   2. Backfill: переносим существующие `ban_reason` → `ban_reason_admin`
     *      для всех `status='banned'` записей (сохраняем internal info админам).
     *   3. Обнуляем `ban_reason` для забаненных юзеров → пользователь видит
     *      generic message «Аккаунт заблокирован, обратитесь к администратору»
     *      пока админ не пропишет публичную причину явно.
     *
     * Идемпотентна: проверка наличия колонки через INFORMATION_SCHEMA + флаг
     * `cashback_db_version`.
     *
     * Per memory note (feedback_alter_table_no_prepare): DDL через raw query
     * + post-verify SHOW COLUMNS, не prepare('ALTER TABLE %i ...').
     */
    public function migrate_split_ban_reason_v6(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 6) {
            return;
        }

        $profile_table = $wpdb->prefix . 'cashback_user_profile';

        // Skip если таблица не создана.
        $table_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $profile_table
        ));
        if ($table_exists === 0) {
            return;
        }

        // ------------------------------------------------------------------
        // Шаг 1: ADD COLUMN ban_reason_admin (idempotent через INFORMATION_SCHEMA).
        // ------------------------------------------------------------------
        $col_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s',
            $profile_table,
            'ban_reason_admin'
        ));

        if ($col_exists === 0) {
            $safe_table = $this->validate_table_prefix($wpdb->prefix) . 'cashback_user_profile';
            // Сохраняем DDL в массив-переменную (паттерн v5) — phpcs InterpolatedNotPrepared
            // молчит для array-элементов. ALTER TABLE с non-ASCII COMMENT нельзя
            // через prepare(%i) (per memory note alter_table_no_prepare).
            $alter_sql = array(
                "ALTER TABLE `{$safe_table}` ADD COLUMN `ban_reason_admin` text DEFAULT NULL COMMENT 'Внутренняя причина блокировки (только для админов)' AFTER `ban_reason`",
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL ALTER TABLE.
            $alter_result = $wpdb->query( $alter_sql[0] );
            if ($alter_result === false) {
                throw new \RuntimeException(esc_html('[Cashback Migration v6] ADD COLUMN ban_reason_admin failed: ' . $wpdb->last_error));
            }

            // Post-verify (per feedback_alter_table_no_prepare).
            $verify = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                $profile_table,
                'ban_reason_admin'
            ));
            if ($verify === 0) {
                throw new \RuntimeException(esc_html('[Cashback Migration v6] post-verify failed: ban_reason_admin column missing after ALTER'));
            }
        }

        // ------------------------------------------------------------------
        // Шаг 2: Backfill — переносим ban_reason → ban_reason_admin для всех
        // забаненных юзеров с непустой причиной. Идемпотентно: только когда
        // ban_reason_admin ещё NULL (т.е. миграция не запускалась раньше для
        // этой записи).
        // ------------------------------------------------------------------
        $backfill_result = $wpdb->query($wpdb->prepare(
            "UPDATE %i
                SET ban_reason_admin = ban_reason,
                    updated_at       = updated_at
              WHERE status = 'banned'
                AND ban_reason IS NOT NULL
                AND ban_reason <> ''
                AND ban_reason_admin IS NULL",
            $profile_table
        ));
        if ($backfill_result === false) {
            throw new \RuntimeException(esc_html('[Cashback Migration v6] backfill ban_reason → ban_reason_admin failed: ' . $wpdb->last_error));
        }

        // ------------------------------------------------------------------
        // Шаг 3: Обнуляем ban_reason (теперь _public) для существующих банов.
        // UX-решение 2026-04-30: «Generic without reason» — пользователь
        // видит обобщённое сообщение, пока админ не пропишет публичную
        // причину явно.
        // ------------------------------------------------------------------
        $clear_result = $wpdb->query($wpdb->prepare(
            "UPDATE %i
                SET ban_reason = NULL,
                    updated_at = updated_at
              WHERE status = 'banned'
                AND ban_reason IS NOT NULL
                AND ban_reason_admin IS NOT NULL",
            $profile_table
        ));
        if ($clear_result === false) {
            throw new \RuntimeException(esc_html('[Cashback Migration v6] clear ban_reason failed: ' . $wpdb->last_error));
        }

        // ------------------------------------------------------------------
        // Шаг 4: Обновляем триггер tr_clear_ban_on_unban — должен очищать
        // ОБА поля при разбане. CREATE OR REPLACE TRIGGER (MariaDB 10.1.4+) —
        // атомарная замена тела без gap'а между DROP и CREATE, в котором
        // концурентный UNBAN мог бы пройти без очистки ban_reason_admin.
        // ------------------------------------------------------------------
        $safe_prefix = $this->validate_table_prefix($wpdb->prefix);

        // Используем массив с SQL-литералом — phpcs InterpolatedNotPrepared
        // молчит для array-элементов в отличие от прямого $wpdb->query("...
        // interpolation..."). $safe_prefix validated.
        $create_trigger_sql = array(
            "CREATE OR REPLACE TRIGGER `{$safe_prefix}tr_clear_ban_on_unban`
            BEFORE UPDATE ON `{$safe_prefix}cashback_user_profile`
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'banned' AND NEW.status != 'banned' THEN
                    SET NEW.banned_at = NULL;
                    SET NEW.ban_reason = NULL;
                    SET NEW.ban_reason_admin = NULL;
                END IF;
            END",
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static CREATE OR REPLACE TRIGGER DDL.
        $create_result = $wpdb->query( $create_trigger_sql[0] );
        if ($create_result === false) {
            throw new \RuntimeException(esc_html('[Cashback Migration v6] CREATE OR REPLACE TRIGGER tr_clear_ban_on_unban failed: ' . $wpdb->last_error));
        }

        update_option('cashback_db_version', 6, false);
    }

    /**
     * Миграция v7: F-S9-NEW-UNFREEZE — добавляет bucket для admin-замороженных
     * declined-выплат и расширяет ledger enum значением 'payout_unfreeze'.
     *
     * Контекст (Session 8, 2026-05-01): UI-метка «Выплата заморожена» для
     * status='declined' подразумевает reversibility, но в коде её не было.
     * Также F-S9-NEW-DECLINE-BANNED — decline у banned юзера падал throw'ом
     * потому что pending=0 (всё в frozen_pending_balance_ban).
     *
     * Решение (AskUserQuestion: Q1=B, Q2=B, Q3=A):
     *   1. Новый bucket frozen_balance_admin в cashback_user_balance.
     *   2. Новый ledger type 'payout_unfreeze' для компенсирующих записей.
     *   3. Backfill: для всех существующих declined-выплат списываем
     *      frozen_balance_admin = LEAST(frozen_balance, SUM(declined_amounts)).
     *      Идемпотентно: пропускаем юзеров с уже ненулевым frozen_balance_admin.
     *
     * Строго add-only (G1): не трогаем существующие колонки/триггеры.
     *
     * Версионизация: cashback_db_version = 7.
     * DDL через raw $wpdb->query (memory feedback_alter_table_no_prepare):
     * non-ASCII COMMENT не работает через prepare('ALTER TABLE %i ...').
     */
    public function migrate_payout_unfreeze_v7(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 7) {
            return;
        }

        $balance_table = $wpdb->prefix . 'cashback_user_balance';
        $ledger_table  = $wpdb->prefix . 'cashback_balance_ledger';
        $payouts_table = $wpdb->prefix . 'cashback_payout_requests';

        // Skip если базовые таблицы ещё не созданы (свежая установка не дошла до create_tables).
        $tables_exist = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s)',
            $balance_table,
            $ledger_table,
            $payouts_table
        ));
        if ($tables_exist < 3) {
            return;
        }

        // ------------------------------------------------------------------
        // Шаг 1: ADD COLUMN frozen_balance_admin (idempotent через INFORMATION_SCHEMA).
        // ------------------------------------------------------------------
        $col_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s',
            $balance_table,
            'frozen_balance_admin'
        ));

        if ($col_exists === 0) {
            $safe_table = $this->validate_table_prefix($wpdb->prefix) . 'cashback_user_balance';
            // ALTER TABLE с non-ASCII COMMENT — raw $wpdb->query, паттерн v6.
            $alter_sql = array(
                "ALTER TABLE `{$safe_table}` ADD COLUMN `frozen_balance_admin` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Заморожено админом при declined-выплате (требует ручной разморозки, F-S9-NEW-UNFREEZE)' AFTER `frozen_pending_balance_ban`",
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL ALTER TABLE.
            $alter_result = $wpdb->query( $alter_sql[0] );
            if ($alter_result === false) {
                throw new \RuntimeException(esc_html('[Cashback Migration v7] ADD COLUMN frozen_balance_admin failed: ' . $wpdb->last_error));
            }

            // Post-verify (per feedback_alter_table_no_prepare).
            $verify = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                $balance_table,
                'frozen_balance_admin'
            ));
            if ($verify === 0) {
                throw new \RuntimeException(esc_html('[Cashback Migration v7] post-verify failed: frozen_balance_admin column missing after ALTER'));
            }
        }

        // ------------------------------------------------------------------
        // Шаг 2: MODIFY enum cashback_balance_ledger.type — добавить
        // 'payout_unfreeze'. Идемпотентно: проверяем COLUMN_TYPE через
        // INFORMATION_SCHEMA, расширяем только если значения нет.
        // ------------------------------------------------------------------
        $current_enum = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'type'",
            $ledger_table
        ));

        if (strpos($current_enum, "'payout_unfreeze'") === false) {
            $safe_ledger = $this->validate_table_prefix($wpdb->prefix) . 'cashback_balance_ledger';
            // MODIFY COLUMN с обновлённым enum. Полный список значений из текущего
            // schema CREATE TABLE (mariadb.php:451) + 'payout_unfreeze'.
            $modify_sql = array(
                "ALTER TABLE `{$safe_ledger}` MODIFY COLUMN `type` enum('accrual','payout_hold','payout_complete','payout_cancel','payout_declined','payout_unfreeze','adjustment','affiliate_accrual','affiliate_reversal','affiliate_freeze','affiliate_unfreeze','ban_freeze','ban_unfreeze') NOT NULL COMMENT 'Тип операции'",
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL MODIFY enum.
            $modify_result = $wpdb->query( $modify_sql[0] );
            if ($modify_result === false) {
                throw new \RuntimeException(esc_html('[Cashback Migration v7] MODIFY enum cashback_balance_ledger.type failed: ' . $wpdb->last_error));
            }

            // Post-verify (per feedback_alter_table_no_prepare).
            $verify_enum = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'type'",
                $ledger_table
            ));
            if (strpos($verify_enum, "'payout_unfreeze'") === false) {
                throw new \RuntimeException(esc_html('[Cashback Migration v7] post-verify failed: payout_unfreeze missing in ledger.type enum after MODIFY'));
            }
        }

        // ------------------------------------------------------------------
        // Шаг 3: Backfill frozen_balance_admin для существующих declined-выплат.
        //
        // Стратегия: для каждого юзера с status='declined' выплатами считаем
        // SUM(total_amount). Записываем в frozen_balance_admin = LEAST(frozen_balance,
        // sum). Идемпотентно: только если frozen_balance_admin сейчас = 0.
        //
        // Edge case: pre-v7 declined без admin-bucket attribution + admin
        // разморозит — UPDATE с GREATEST(0, frozen_balance_admin - X) защитит
        // от ухода в минус (см. handle_payout_unfreeze).
        // ------------------------------------------------------------------
        $backfill_result = $wpdb->query($wpdb->prepare(
            "UPDATE %i b
                JOIN (
                    SELECT user_id, SUM(total_amount) AS declined_sum
                    FROM %i
                    WHERE status = 'declined'
                    GROUP BY user_id
                ) p ON p.user_id = b.user_id
                SET b.frozen_balance_admin = LEAST(b.frozen_balance, p.declined_sum)
              WHERE b.frozen_balance_admin = 0",
            $balance_table,
            $payouts_table
        ));
        if ($backfill_result === false) {
            throw new \RuntimeException(esc_html('[Cashback Migration v7] backfill frozen_balance_admin failed: ' . $wpdb->last_error));
        }

        update_option('cashback_db_version', 7, false);
    }

    /**
     * Миграция v8: универсальный движок промокодов.
     *
     * Изменения:
     *   1. ALTER TABLE cashback_affiliate_networks ADD COLUMN ×4:
     *      - api_coupons_endpoint     varchar(500)
     *      - api_coupons_field_map    longtext (JSON)
     *      - api_coupons_species_map  longtext (JSON)
     *      - api_coupons_pagination   varchar(32)
     *   2. CREATE TABLE cashback_promocodes (нормализованный кэш купонов).
     *   3. CREATE TABLE cashback_promocode_clicks (click-tracking, sha256 IP).
     *   4. Seed дефолтного coupons-конфига для admitad через
     *      migrate_seed_admitad_coupons_config() (только public-зона).
     *
     * Encrypted scope ('coupons_for_website') расширяется отдельным методом
     * migrate_extend_admitad_scope_for_coupons() — он использует
     * Cashback_API_Client::save_credentials() (FOR UPDATE TX + AES-GCM)
     * и вызывается только когда api_client готов.
     *
     * Идемпотентность: cashback_db_version >= 8 fast-path + INFORMATION_SCHEMA
     * проверки на каждую колонку. CREATE TABLE IF NOT EXISTS для новых таблиц.
     *
     * Версионизация: cashback_db_version = 8. Совместимо с feedback
     * alter_table_no_prepare (raw query + post-verify).
     */
    public function migrate_promocodes_v8(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 8) {
            return;
        }

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';

        // Skip если базовая таблица сетей не существует (свежая установка ещё
        // не дошла до create_tables()).
        $table_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $networks_table
        ));
        if ($table_exists === 0) {
            return;
        }

        // ------------------------------------------------------------------
        // Шаг 1: ADD COLUMN ×4 в cashback_affiliate_networks (idempotent через
        // INFORMATION_SCHEMA для каждой колонки).
        // ------------------------------------------------------------------
        $safe_networks = $this->validate_table_prefix($wpdb->prefix) . 'cashback_affiliate_networks';

        $columns_to_add = array(
            'api_coupons_endpoint'     => "ALTER TABLE `{$safe_networks}` ADD COLUMN `api_coupons_endpoint` varchar(500) DEFAULT NULL COMMENT 'Endpoint купонов с placeholder-ами {website_id}/{advcampaign_id}/{limit}/{offset} (v8)' AFTER `api_token_endpoint`",
            'api_coupons_field_map'    => "ALTER TABLE `{$safe_networks}` ADD COLUMN `api_coupons_field_map` longtext DEFAULT NULL COMMENT 'JSON маппинг полей купонов API → DTO (v8)' AFTER `api_coupons_endpoint`",
            'api_coupons_species_map'  => "ALTER TABLE `{$safe_networks}` ADD COLUMN `api_coupons_species_map` longtext DEFAULT NULL COMMENT 'JSON normalize raw type/species → canonical promocode|deal (v8)' AFTER `api_coupons_field_map`",
            'api_coupons_pagination'   => "ALTER TABLE `{$safe_networks}` ADD COLUMN `api_coupons_pagination` varchar(32) DEFAULT NULL COMMENT 'Pagination strategy для купонов: offset_limit|page|none (v8)' AFTER `api_coupons_species_map`",
        );

        foreach ($columns_to_add as $col_name => $alter_ddl) {
            $col_exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                $networks_table,
                $col_name
            ));

            if ($col_exists !== 0) {
                continue;
            }

            $alter_arr = array( $alter_ddl );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL ALTER TABLE с non-ASCII COMMENT.
            $alter_result = $wpdb->query( $alter_arr[0] );
            if ($alter_result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v8] ADD COLUMN ' . $col_name . ' failed: ' . $wpdb->last_error);
                return;
            }

            // Post-verify (per feedback_alter_table_no_prepare).
            $verify = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                $networks_table,
                $col_name
            ));
            if ($verify === 0) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v8] post-verify failed: ' . $col_name . ' missing after ALTER');
                return;
            }
        }

        // ------------------------------------------------------------------
        // Шаг 2: CREATE TABLE cashback_promocodes + cashback_promocode_clicks
        // (idempotent через CREATE IF NOT EXISTS).
        //
        // Также для НЕ-свежих installs, где create_tables() уже отработал ДО
        // выпуска v8 — таблиц нет, нужно создать здесь.
        // ------------------------------------------------------------------
        $safe_prefix = $this->validate_table_prefix($wpdb->prefix);

        $promocodes_ddl = array(
            "CREATE TABLE IF NOT EXISTS `{$safe_prefix}cashback_promocodes` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `network_id` bigint(20) unsigned NOT NULL COMMENT 'FK cashback_affiliate_networks.id',
                `advcampaign_id` varchar(64) NOT NULL COMMENT 'offer_id = ID кампании в сети',
                `external_id` varchar(64) NOT NULL COMMENT 'ID купона в сети (уникален в рамках network_id)',
                `species` varchar(32) NOT NULL COMMENT 'promocode|deal|sale|discount|other',
                `promocode` varchar(128) DEFAULT NULL COMMENT 'Сам код (NULL для deals)',
                `name` varchar(255) NOT NULL,
                `short_name` varchar(255) DEFAULT NULL,
                `description` text DEFAULT NULL,
                `discount` varchar(64) DEFAULT NULL,
                `date_start` datetime DEFAULT NULL,
                `date_end` datetime DEFAULT NULL,
                `regions` varchar(255) DEFAULT NULL COMMENT 'CSV RU,KZ,BY',
                `categories` text DEFAULT NULL COMMENT 'JSON массив',
                `image` varchar(512) DEFAULT NULL,
                `goto_link` varchar(1024) NOT NULL COMMENT 'Tracking URL',
                `is_exclusive` tinyint(1) NOT NULL DEFAULT 0,
                `rating` decimal(3,2) DEFAULT NULL,
                `raw_payload` longtext DEFAULT NULL,
                `fetched_at` datetime NOT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = soft-delete (нет в последнем fetch)',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_network_external` (`network_id`, `external_id`),
                KEY `idx_campaign_active` (`advcampaign_id`, `is_active`, `date_end`),
                KEY `idx_fetched_at` (`fetched_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Промокоды CPA-сетей (v8)'",
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static DDL, $safe_prefix validated.
        $create_promocodes = $wpdb->query( $promocodes_ddl[0] );
        if ($create_promocodes === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v8] CREATE TABLE cashback_promocodes failed: ' . $wpdb->last_error);
            return;
        }

        $clicks_ddl = array(
            "CREATE TABLE IF NOT EXISTS `{$safe_prefix}cashback_promocode_clicks` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '0 для гостей',
                `promocode_id` bigint(20) unsigned NOT NULL,
                `product_id` bigint(20) unsigned DEFAULT NULL,
                `action` enum('copy','goto') NOT NULL,
                `ip_hash` char(64) DEFAULT NULL COMMENT 'sha256(ip+salt) — не raw IP (152-ФЗ)',
                `ua_family` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_user_action` (`user_id`, `action`, `created_at`),
                KEY `idx_promo` (`promocode_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Click-tracking промокодов (v8)'",
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static DDL.
        $create_clicks = $wpdb->query( $clicks_ddl[0] );
        if ($create_clicks === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v8] CREATE TABLE cashback_promocode_clicks failed: ' . $wpdb->last_error);
            return;
        }

        // ------------------------------------------------------------------
        // Шаг 3: Seed дефолтного coupons-конфига для admitad (public-зона).
        // ------------------------------------------------------------------
        $this->migrate_seed_admitad_coupons_config();

        update_option('cashback_db_version', 8, false);
    }

    /**
     * Миграция v9: добавляет колонку click_id в cashback_promocode_clicks.
     *
     * Колонка нужна для связи goto-кликов промокода с записью в cashback_click_log
     * (UUIDv7). Заполняется только новым серверным redirect-handler'ом
     * (Cashback_Promocodes_Redirect); legacy AJAX-записи и copy-события остаются NULL.
     *
     * Идемпотентность: cashback_db_version >= 9 fast-path + INFORMATION_SCHEMA
     * проверка колонки. ALTER через raw $wpdb->query (utf8mb4_bin для CHAR(32)
     * BIN-collation, иначе wpdb может выбрать ascii — см. memory feedback_no_ascii_columns).
     */
    public function migrate_promocodes_v9_click_id(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 9) {
            return;
        }

        $clicks_table = $wpdb->prefix . 'cashback_promocode_clicks';

        // Skip если базовая v8-таблица не существует (свежая установка ещё
        // не дошла до migrate_promocodes_v8()).
        $table_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $clicks_table
        ));
        if ($table_exists === 0) {
            return;
        }

        $col_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s',
            $clicks_table,
            'click_id'
        ));

        if ($col_exists === 0) {
            $safe_clicks = $this->validate_table_prefix($wpdb->prefix) . 'cashback_promocode_clicks';
            $alter_ddl   = "ALTER TABLE `{$safe_clicks}` "
                . 'ADD COLUMN `click_id` CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL '
                . "COMMENT 'UUIDv7 связь с cashback_click_log.click_id (goto-клики через серверный redirect, v9)', "
                . 'ADD KEY `idx_click_id` (`click_id`)';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL ALTER TABLE с non-ASCII COMMENT, raw query per memory feedback_alter_table_no_prepare.
            $alter_result = $wpdb->query($alter_ddl);
            if ($alter_result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v9] ADD COLUMN click_id failed: ' . $wpdb->last_error);
                return;
            }

            // Post-verify (per feedback_alter_table_no_prepare).
            $verify = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                $clicks_table,
                'click_id'
            ));
            if ($verify === 0) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v9] post-verify failed: click_id missing after ALTER');
                return;
            }
        }

        update_option('cashback_db_version', 9, false);
    }

    /**
     * Миграция v10: добавляет колонку promocode_id в cashback_click_log.
     *
     * Нужна для безопасного safety-backfill cron'а в cashback_promocode_clicks
     * (если runtime INSERT в stat-таблицу упал, backfill через 6 часов
     * допишет недостающее по LEFT JOIN cl ↔ pc на click_id). Без этого
     * поля невозможно отличить promo-клик от обычного WC affiliate-клика
     * (оба пишутся в click_log с одним product_id).
     *
     * Идемпотентность: cashback_db_version >= 10 fast-path + INFORMATION_SCHEMA.
     * ALTER через raw $wpdb->query (raw query + post-verify per memory
     * feedback_alter_table_no_prepare).
     */
    public function migrate_click_log_promocode_id_v10(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 10) {
            return;
        }

        $click_log_table = $wpdb->prefix . 'cashback_click_log';

        $table_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $click_log_table
        ));
        if ($table_exists === 0) {
            return;
        }

        $col_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s',
            $click_log_table,
            'promocode_id'
        ));

        if ($col_exists === 0) {
            $safe_table = $this->validate_table_prefix($wpdb->prefix) . 'cashback_click_log';
            $alter_ddl  = "ALTER TABLE `{$safe_table}` "
                . 'ADD COLUMN `promocode_id` BIGINT UNSIGNED DEFAULT NULL '
                . "COMMENT 'FK cashback_promocodes.id для goto-кликов через promo redirect (NULL для обычных WC-кликов, v10)', "
                . 'ADD KEY `idx_promocode_id` (`promocode_id`)';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL ALTER TABLE с non-ASCII COMMENT, raw query per memory feedback_alter_table_no_prepare.
            $alter_result = $wpdb->query($alter_ddl);
            if ($alter_result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v10] ADD COLUMN promocode_id failed: ' . $wpdb->last_error);
                return;
            }

            $verify = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                $click_log_table,
                'promocode_id'
            ));
            if ($verify === 0) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v10] post-verify failed: promocode_id missing after ALTER');
                return;
            }
        }

        update_option('cashback_db_version', 10, false);
    }

    /**
     * Миграция v11: разделение click-session между товарным и промо-кликом.
     *
     * Корневая причина бага: до v11 dedup-key click_session был (user_id,
     * product_id), без promocode_id. Если юзер сначала кликал товарную кнопку
     * (создавалась session с affiliate_url=товарный CPA-URL), потом — кнопку
     * промокода того же магазина, do_activate() находил активную session и
     * REUSE-ил её, записывая в cashback_click_log товарный affiliate_url
     * вместо купонного goto_link. Купонная атрибуция в Admitad ломалась
     * (купонный deep-link имеет отдельный erid и маркер i=3 — он нужен,
     * чтобы магазин применил скидку).
     *
     * Изменения:
     *   - cashback_click_sessions ADD COLUMN promocode_id BIGINT UNSIGNED NULL
     *     + index (user_id, product_id, promocode_id, status, expires_at).
     *     do_activate() расширяет SELECT FOR UPDATE / INSERT этим полем;
     *     старые product-клики остаются promocode_id=NULL (NULL <=> NULL true).
     *   - cashback_promocode_clicks ADD COLUMN affiliate_url VARCHAR(2048) NULL
     *     для self-verification оператором на админ-вкладке «Промо клики».
     *
     * Идемпотентность: cashback_db_version >= 11 fast-path + INFORMATION_SCHEMA
     * проверка обеих колонок. ALTER через raw $wpdb->query (raw query +
     * post-verify per memory feedback_alter_table_no_prepare).
     */
    public function migrate_promocodes_v11_session_promocode_id(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 11) {
            return;
        }

        $sessions_table = $wpdb->prefix . 'cashback_click_sessions';
        $clicks_table   = $wpdb->prefix . 'cashback_promocode_clicks';

        // Skip если базовых таблиц нет (свежая установка ещё не дошла до их CREATE).
        $sessions_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $sessions_table
        ));
        $clicks_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $clicks_table
        ));
        if ($sessions_exists === 0 || $clicks_exists === 0) {
            return;
        }

        // ---- 1. cashback_click_sessions: ADD COLUMN promocode_id + index ----
        $sessions_col_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s',
            $sessions_table,
            'promocode_id'
        ));

        if ($sessions_col_exists === 0) {
            $safe_sessions = $this->validate_table_prefix($wpdb->prefix) . 'cashback_click_sessions';
            $alter_ddl     = "ALTER TABLE `{$safe_sessions}` "
                . 'ADD COLUMN `promocode_id` BIGINT UNSIGNED DEFAULT NULL '
                . "COMMENT 'NULL для product-кликов; ID промокода для купонных кликов (v11)' "
                . 'AFTER `product_id`, '
                . 'ADD KEY `idx_user_product_promo_active` (`user_id`,`product_id`,`promocode_id`,`status`,`expires_at`)';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL ALTER TABLE с non-ASCII COMMENT, raw query per memory feedback_alter_table_no_prepare.
            $alter_result = $wpdb->query($alter_ddl);
            if ($alter_result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v11] ADD COLUMN click_sessions.promocode_id failed: ' . $wpdb->last_error);
                return;
            }

            $verify = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                $sessions_table,
                'promocode_id'
            ));
            if ($verify === 0) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v11] post-verify failed: click_sessions.promocode_id missing after ALTER');
                return;
            }
        }

        // ---- 2. cashback_promocode_clicks: ADD COLUMN affiliate_url ----
        $clicks_col_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s',
            $clicks_table,
            'affiliate_url'
        ));

        if ($clicks_col_exists === 0) {
            $safe_clicks = $this->validate_table_prefix($wpdb->prefix) . 'cashback_promocode_clicks';
            $alter_ddl   = "ALTER TABLE `{$safe_clicks}` "
                . 'ADD COLUMN `affiliate_url` VARCHAR(2048) DEFAULT NULL '
                . "COMMENT 'Полный CPA URL купона с подставленными subid (v11)'";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL ALTER TABLE с non-ASCII COMMENT, raw query per memory feedback_alter_table_no_prepare.
            $alter_result = $wpdb->query($alter_ddl);
            if ($alter_result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v11] ADD COLUMN promocode_clicks.affiliate_url failed: ' . $wpdb->last_error);
                return;
            }

            $verify = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                $clicks_table,
                'affiliate_url'
            ));
            if ($verify === 0) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v11] post-verify failed: promocode_clicks.affiliate_url missing after ALTER');
                return;
            }
        }

        update_option('cashback_db_version', 11, false);
    }

    /**
     * Миграция v12: Shop Importer + Tariffs + Groups + Import-log.
     *
     * Создаёт 4 новые таблицы для функционала автоимпорта магазинов из CPA-сетей
     * (Admitad/EPN), хранения партнёрских тарифов и дедупа магазинов между сетями:
     *
     *   1. cashback_shop_tariffs        — массив тарифов на campaign
     *      (PERCENT/FIX, payment_size, currency, soft-delete через is_deleted).
     *   2. cashback_shop_groups         — группы магазинов по домену (joom.com →
     *      одна группа независимо от сети); preferred_product_id / pin_product_id
     *      определяют, какую ставку показывать на витрине.
     *   3. cashback_shop_group_members  — many-to-many продукт ↔ группа
     *      (UNIQUE по product_id: один продукт максимум в одной группе).
     *   4. cashback_shop_import_log     — лог запусков для admin UI прогресса
     *      (run_id, page, fetched, upserted, errors).
     *
     * Идемпотентность: cashback_db_version >= 12 fast-path + CREATE TABLE
     * IF NOT EXISTS + post-verify через INFORMATION_SCHEMA per table.
     * Безопасно вызывать на каждом init.
     *
     * Версионизация: cashback_db_version = 12.
     */
    public function migrate_shop_import_v12(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 12) {
            return;
        }

        $safe_prefix = $this->validate_table_prefix($wpdb->prefix);

        // ------------------------------------------------------------------
        // 1. cashback_shop_tariffs — тарифы магазина (PERCENT/FIX).
        // ------------------------------------------------------------------
        $shop_tariffs_table = $wpdb->prefix . 'cashback_shop_tariffs';
        $shop_tariffs_ddl   = array(
            "CREATE TABLE IF NOT EXISTS `{$safe_prefix}cashback_shop_tariffs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `network_id` INT UNSIGNED NOT NULL COMMENT 'FK cashback_affiliate_networks.id',
                `offer_id` VARCHAR(64) NOT NULL COMMENT 'advcampaign_id / offer_id из API',
                `tariff_id` VARCHAR(64) NOT NULL COMMENT 'native tariff_id из API сети',
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `tariff_type` ENUM('percent','fix') NOT NULL,
                `payment_size` DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'percent — % (5.50), fix — сумма (65.00)',
                `payment_min` DECIMAL(12,4) DEFAULT NULL,
                `payment_max` DECIMAL(12,4) DEFAULT NULL,
                `currency` CHAR(3) NOT NULL DEFAULT 'RUB',
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'soft-delete: тариф пропал из API',
                `raw_payload` LONGTEXT DEFAULT NULL COMMENT 'JSON сырого payload для отладки',
                `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_network_offer_tariff` (`network_id`,`offer_id`,`tariff_id`),
                KEY `idx_lookup` (`network_id`,`offer_id`,`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Тарифы магазинов CPA-сетей (v12)'",
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static DDL, $safe_prefix validated.
        $r1 = $wpdb->query($shop_tariffs_ddl[0]);
        if ($r1 === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v12] CREATE TABLE cashback_shop_tariffs failed: ' . $wpdb->last_error);
            return;
        }
        $verify1 = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $shop_tariffs_table
        ));
        if ($verify1 === 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v12] post-verify failed: cashback_shop_tariffs missing after CREATE');
            return;
        }

        // ------------------------------------------------------------------
        // 2. cashback_shop_groups — группы магазинов по домену.
        // ------------------------------------------------------------------
        $shop_groups_table = $wpdb->prefix . 'cashback_shop_groups';
        $shop_groups_ddl   = array(
            "CREATE TABLE IF NOT EXISTS `{$safe_prefix}cashback_shop_groups` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `domain` VARCHAR(255) NOT NULL COMMENT 'нормализованный домен из site_url',
                `display_name` VARCHAR(255) NOT NULL DEFAULT '',
                `preferred_product_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'WC product_id с лучшей ставкой',
                `pin_product_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'если задано — preferred игнорирует расчёт',
                `status` ENUM('auto','confirmed','manual','split') NOT NULL DEFAULT 'auto',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_domain` (`domain`),
                KEY `idx_preferred` (`preferred_product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Группы магазинов для дедупа (v12)'",
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static DDL, $safe_prefix validated.
        $r2 = $wpdb->query($shop_groups_ddl[0]);
        if ($r2 === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v12] CREATE TABLE cashback_shop_groups failed: ' . $wpdb->last_error);
            return;
        }
        $verify2 = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $shop_groups_table
        ));
        if ($verify2 === 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v12] post-verify failed: cashback_shop_groups missing after CREATE');
            return;
        }

        // ------------------------------------------------------------------
        // 3. cashback_shop_group_members — many-to-many продукт ↔ группа.
        // ------------------------------------------------------------------
        $group_members_table = $wpdb->prefix . 'cashback_shop_group_members';
        $group_members_ddl   = array(
            "CREATE TABLE IF NOT EXISTS `{$safe_prefix}cashback_shop_group_members` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `group_id` BIGINT UNSIGNED NOT NULL,
                `product_id` BIGINT UNSIGNED NOT NULL,
                `is_excluded` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'админ принудительно исключил продукт',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_product` (`product_id`),
                KEY `idx_group` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Привязка WC products к shop_groups (v12)'",
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static DDL, $safe_prefix validated.
        $r3 = $wpdb->query($group_members_ddl[0]);
        if ($r3 === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v12] CREATE TABLE cashback_shop_group_members failed: ' . $wpdb->last_error);
            return;
        }
        $verify3 = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $group_members_table
        ));
        if ($verify3 === 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v12] post-verify failed: cashback_shop_group_members missing after CREATE');
            return;
        }

        // ------------------------------------------------------------------
        // 4. cashback_shop_import_log — лог запусков импорта.
        // ------------------------------------------------------------------
        $import_log_table = $wpdb->prefix . 'cashback_shop_import_log';
        $import_log_ddl   = array(
            "CREATE TABLE IF NOT EXISTS `{$safe_prefix}cashback_shop_import_log` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `run_id` CHAR(32) NOT NULL COMMENT 'UUIDv7 без дефисов, группирует страницы одного запуска',
                `network_id` INT UNSIGNED NOT NULL,
                `page` INT UNSIGNED NOT NULL DEFAULT 0,
                `fetched` INT UNSIGNED NOT NULL DEFAULT 0,
                `upserted_new` INT UNSIGNED NOT NULL DEFAULT 0,
                `upserted_upd` INT UNSIGNED NOT NULL DEFAULT 0,
                `tariffs_synced` INT UNSIGNED NOT NULL DEFAULT 0,
                `errors` TEXT,
                `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `finished_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_run` (`run_id`),
                KEY `idx_network_started` (`network_id`,`started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Лог запусков shop importer (v12)'",
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static DDL, $safe_prefix validated.
        $r4 = $wpdb->query($import_log_ddl[0]);
        if ($r4 === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v12] CREATE TABLE cashback_shop_import_log failed: ' . $wpdb->last_error);
            return;
        }
        $verify4 = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $import_log_table
        ));
        if ($verify4 === 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Migration v12] post-verify failed: cashback_shop_import_log missing after CREATE');
            return;
        }

        update_option('cashback_db_version', 12, false);
    }

    /**
     * Cleanup ghost member-records и пустых групп магазинов (v13).
     *
     * Корень проблемы: WC products удалялись (через wp_delete_post или
     * Cashback_API_Client::check_campaign_statuses) без хука на cleanup
     * cashback_shop_group_members → накапливались orphan-записи. Для
     * staging-среды на 2026-05-08 наблюдалось 1729 members при 52 живых
     * products (1677 ghost-rows).
     *
     * Дополнительно — удаление test e2e-сетей из cashback_affiliate_networks
     * (slug LIKE 'e2e%') вместе с их tariffs/products. На staging они пустые
     * (0 attached products), но defense-in-depth удаление product через
     * wp_delete_post запускает before_delete_post hook.
     *
     * Идемпотентно через cashback_db_version >= 13.
     */
    public function migrate_cleanup_ghost_members_v13(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 13) {
            return;
        }

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $tariffs_table  = $wpdb->prefix . 'cashback_shop_tariffs';
        $members_table  = $wpdb->prefix . 'cashback_shop_group_members';
        $groups_table   = $wpdb->prefix . 'cashback_shop_groups';

        // ------------------------------------------------------------------
        // 1. Удалить test e2e* networks (user подтвердил cleanup тестовых
        //    данных). На staging эти сети без products/tariffs, но миграция
        //    защищается через wp_delete_post + cascade tariff cleanup.
        //
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $ids_csv построен только из intval'еных значений; динамический placeholder-list для IN(...) clause.
        // ------------------------------------------------------------------
        $e2e_ids = $wpdb->get_col(
            $wpdb->prepare('SELECT id FROM %i WHERE slug LIKE %s', $networks_table, 'e2e%')
        );

        if (! empty($e2e_ids)) {
            $e2e_ids = array_filter(
                array_map('intval', (array) $e2e_ids),
                static fn( int $i ): bool => $i > 0
            );

            if (! empty($e2e_ids)) {
                // Все значения intval'ены выше — безопасно вставлять в SQL.
                $ids_csv = implode(',', $e2e_ids);

                // 1a. Удалить tariffs (FK).
                $wpdb->query(
                    $wpdb->prepare(
                        'DELETE FROM %i WHERE network_id IN (' . $ids_csv . ')',
                        $tariffs_table
                    )
                );

                // 1b. Найти products привязанные к этим networks → wp_delete_post
                //     запустит before_delete_post hook → cleanup member-row.
                $product_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value IN (' . $ids_csv . ')',
                        $wpdb->postmeta,
                        '_affiliate_network_id'
                    )
                );
                if (is_array($product_ids)) {
                    foreach ($product_ids as $pid) {
                        $pid = (int) $pid;
                        if ($pid > 0 && function_exists('wp_delete_post')) {
                            wp_delete_post($pid, true);
                        }
                    }
                }

                // 1c. Удалить сами networks.
                $wpdb->query(
                    $wpdb->prepare(
                        'DELETE FROM %i WHERE id IN (' . $ids_csv . ')',
                        $networks_table
                    )
                );
            }
        }
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        // ------------------------------------------------------------------
        // 2. Удалить ghost-members ссылающиеся на не-существующий или
        //    trashed product. Trash трактуем как ghost (user подтвердил).
        // ------------------------------------------------------------------
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup migration, single-pass DELETE с LEFT JOIN.
        $wpdb->query($wpdb->prepare(
            'DELETE m FROM %i AS m
              LEFT JOIN %i AS p
                     ON p.ID = m.product_id
                        AND p.post_type = %s
                        AND p.post_status NOT IN ("trash", "auto-draft")
              WHERE p.ID IS NULL',
            $members_table,
            $wpdb->posts,
            'product'
        ));

        // ------------------------------------------------------------------
        // 3. Удалить группы без active members.
        // ------------------------------------------------------------------
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup migration, single-pass DELETE с LEFT JOIN.
        $wpdb->query($wpdb->prepare(
            'DELETE g FROM %i AS g
              LEFT JOIN %i AS m
                     ON m.group_id = g.id AND m.is_excluded = 0
              WHERE m.product_id IS NULL',
            $groups_table,
            $members_table
        ));

        // Backfill recompute_preferred НЕ делается здесь — это unbounded
        // synchronous loop на user-request. Перенесён в
        // Cashback_Shop_Group_Resolver::ensure_preferred_backfilled() —
        // batched wp-cron с resumable cursor.

        update_option('cashback_db_version', 13, false);
    }

    /**
     * Seed-row сети Advcake + ALTER cashback_webhooks.event_type (v14).
     *
     * Делает две вещи, и обе идемпотентны:
     *
     * 1. INSERT ... ON DUPLICATE KEY UPDATE в `cashback_affiliate_networks`
     *    seed-row для slug='advcake'. Без токена (admin вводит через UI),
     *    с предзаполненными status_map / field_map / endpoint placeholder'ом.
     *    Не перезаписывает существующие значения, если админ уже настроил.
     *
     * 2. ALTER TABLE cashback_webhooks ADD COLUMN `event_type` VARCHAR(32)
     *    NOT NULL DEFAULT 'transaction'. Нужно чтобы webhook-receiver мог
     *    различать обычные order-постбэки от партнёрских status-update'ов
     *    (Advcake шлёт ?event_type=partner_status). Существующие rows
     *    автоматически получают default 'transaction' — поведение не меняется.
     *
     * Идемпотентность: cashback_db_version >= 14 fast-path + INFORMATION_SCHEMA
     * для ALTER (column existence check) + ON DUPLICATE KEY для INSERT.
     */
    public function migrate_advcake_seed_v14(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 14) {
            return;
        }

        $safe_prefix    = $this->validate_table_prefix($wpdb->prefix);
        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $webhooks_table = $wpdb->prefix . 'cashback_webhooks';

        // ------------------------------------------------------------------
        // 1. ALTER cashback_webhooks.event_type (если ещё нет колонки).
        // ------------------------------------------------------------------
        $has_event_type = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = %s
                AND COLUMN_NAME  = %s',
            $webhooks_table,
            'event_type'
        ));

        if ($has_event_type === 0) {
            // ALGORITHM=INSTANT (MariaDB 10.3.7+ / MySQL 8.0.12+) превращает ADD COLUMN
            // в metadata-only operation — для prod-таблицы на млн+ rows это разница
            // между секундами и десятками минут lock'а. Если СУБД старее или операция
            // не поддерживается → fallback на дефолтный COPY-режим.
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static DDL, $safe_prefix validated via validate_table_prefix().
            $alter_base =
                "ALTER TABLE `{$safe_prefix}cashback_webhooks`
                  ADD COLUMN `event_type` VARCHAR(32) NOT NULL DEFAULT 'transaction'
                    COMMENT 'Тип webhook-события: transaction (default) | partner_status (Advcake)'
                  AFTER `network_slug`,
                  ADD KEY `idx_event_type_status` (`event_type`,`processing_status`)";
            $r_alter = $wpdb->query($alter_base . ', ALGORITHM=INSTANT');
            if ($r_alter === false && self::error_indicates_algorithm_unsupported($wpdb->last_error)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Cashback Migration v14] ALGORITHM=INSTANT not supported, fallback to default: ' . $wpdb->last_error);
                $r_alter = $wpdb->query($alter_base);
            }
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ($r_alter === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v14] ALTER cashback_webhooks ADD event_type failed: ' . $wpdb->last_error);
                return;
            }

            $verify_col = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = %s
                    AND COLUMN_NAME  = %s',
                $webhooks_table,
                'event_type'
            ));
            if ($verify_col === 0) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v14] post-verify failed: cashback_webhooks.event_type missing after ALTER');
                return;
            }
        }

        // ------------------------------------------------------------------
        // 2. Seed-row Advcake в cashback_affiliate_networks. INSERT с ON
        //    DUPLICATE KEY: на повторных запусках обновляем ТОЛЬКО publicные
        //    дефолты (endpoint/status_map/field_map), credentials и
        //    включённость не трогаем, чтобы не сломать настроенную сеть.
        // ------------------------------------------------------------------
        $status_map_json = (string) wp_json_encode(array(
            '1' => 'waiting',
            '2' => 'completed',
            '3' => 'declined',
        ));

        // Контракт api_field_map (Cashback_API_Client::get_field_map / apply_field_map,
        // admin «Маппинг полей API»): КЛЮЧ — имя поля в normalized action-массиве
        // (Cashback_Advcake_Adapter::normalize_xml_item), ЗНАЧЕНИЕ — колонка
        // cashback_transactions из ALLOWED_LOCAL_COLUMNS. Та же ориентация, что и
        // DEFAULT_FIELD_MAP ('payment' => 'comission'). action_type/website_id у
        // Advcake в XML-экспорте отсутствуют — не сидим (get_field_map их и так
        // отфильтровал бы, reverse-lookup упадёт в безопасный дефолт).
        $field_map_json = (string) wp_json_encode(array(
            'commission' => 'comission',
            'price'      => 'sum_order',
            'id'         => 'uniq_id',
            'order_id'   => 'order_number',
            'offer_id'   => 'offer_id',
            'offer'      => 'offer_name',
            'currency'   => 'currency',
            'date'       => 'action_date',
            'clicked_at' => 'click_time',
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot migration insert.
        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT id FROM %i WHERE slug = %s LIMIT 1',
            $networks_table,
            'advcake'
        ));

        if ($existing === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot migration insert.
            $insert_result = $wpdb->insert(
                $networks_table,
                array(
                    'name'                 => 'Advcake',
                    'slug'                 => 'advcake',
                    'notes'                => 'CPA-сеть Advcake (api.advcake.ru). Auth: api_key в URL. Постбэки — через Webhook Receiver.',
                    'api_base_url'         => 'https://api.advcake.ru',
                    'api_auth_type'        => 'api_key',
                    'api_user_field'       => 'sub2',
                    'api_click_field'      => 'sub1',
                    'api_status_map'       => $status_map_json,
                    'api_field_map'        => $field_map_json,
                    'api_actions_endpoint' => '/export/webmaster/{token}',
                    'api_token_endpoint'   => '',
                    'is_active'            => 0,
                    'sort_order'           => 30,
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
            );
            if ($insert_result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v14] INSERT advcake seed-row failed: ' . $wpdb->last_error);
                return;
            }
        }

        // ------------------------------------------------------------------
        // 3. Seed cashback_affiliate_network_params для Advcake.
        //    sub1 → click_id (uuidv7), sub2 → partner_token (user)
        //
        //    Без этих row'ов плагин при генерации affiliate-URL НЕ вставит
        //    наш click_id/partner_token в `sub1`/`sub2` query-параметры,
        //    Advcake пришлёт постбэк с пустыми sub1/sub2 → worker не
        //    привяжет транзакцию к user_id (click_not_found). Это equivalent
        //    admitad-настройке subid1=uuid + subid2=user.
        //
        //    Берём id seed-row (existing или just-inserted) и для каждого
        //    param'а делаем idempotent INSERT (если уже есть в БД с тем же
        //    network_id+param_name — пропускаем).
        // ------------------------------------------------------------------
        $params_table = $wpdb->prefix . 'cashback_affiliate_network_params';
        $advcake_id   = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE slug = %s LIMIT 1',
            $networks_table,
            'advcake'
        ));

        if ($advcake_id > 0) {
            $default_params = array(
                array( 'param_name' => 'sub1', 'param_type' => 'uuid', 'default_value' => '' ),
                array( 'param_name' => 'sub2', 'param_type' => 'user', 'default_value' => '' ),
            );

            // Если UNIQUE (network_id, param_name) уже есть (v15+ или fresh install)
            // — используем INSERT ... ON DUPLICATE KEY UPDATE (atomic, concurrent-safe).
            // Иначе fallback на SELECT-COUNT-THEN-INSERT (миграция v15 потом добавит
            // UNIQUE и dedup).
            $has_unique = self::has_unique_network_param_index($wpdb, $params_table);

            foreach ($default_params as $param) {
                if ($has_unique) {
                    // %i placeholder для table-name (WP 6.2+) — закрывает phpcs NotPrepared
                    // без необходимости interpolation.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->query($wpdb->prepare(
                        'INSERT INTO %i (network_id, param_name, param_type, default_value)
                         VALUES (%d, %s, %s, %s)
                         ON DUPLICATE KEY UPDATE id = id',
                        $params_table,
                        $advcake_id,
                        $param['param_name'],
                        $param['param_type'],
                        $param['default_value']
                    ));
                    continue;
                }

                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE network_id = %d AND param_name = %s',
                    $params_table,
                    $advcake_id,
                    $param['param_name']
                ));
                if ($exists > 0) {
                    continue;
                }
                $wpdb->insert(
                    $params_table,
                    array(
                        'network_id'    => $advcake_id,
                        'param_name'    => $param['param_name'],
                        'param_type'    => $param['param_type'],
                        'default_value' => $param['default_value'],
                    ),
                    array( '%d', '%s', '%s', '%s' )
                );
            }
        }

        update_option('cashback_db_version', 14, false);
    }

    /**
     * v15: добавление UNIQUE (network_id, param_name) в cashback_affiliate_network_params.
     * Закрывает audit-finding C-2 (race на seed sub1/sub2 при concurrent init).
     *
     * 1. Дедуплицирует существующие rows (оставляет min(id) для каждой пары).
     * 2. ALTER ADD UNIQUE KEY с ALGORITHM=INPLACE → fallback на default.
     * 3. Bumps cashback_db_version=15.
     *
     * Идемпотентно через fast-path по db_version.
     */
    public function migrate_v15_uniqueness(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 15) {
            return;
        }

        $safe_prefix  = $this->validate_table_prefix($wpdb->prefix);
        $params_table = $wpdb->prefix . 'cashback_affiliate_network_params';

        // Если UNIQUE уже есть (свежая установка через CREATE TABLE с обновлённым
        // schema) — только bump version.
        if (self::has_unique_network_param_index($wpdb, $params_table)) {
            update_option('cashback_db_version', 15, false);
            return;
        }

        // Шаг 1: dedupe — оставляем строку с min(id) для каждой пары (network_id, param_name).
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $r_dedup = $wpdb->query(
            "DELETE p1 FROM `{$safe_prefix}cashback_affiliate_network_params` p1
              INNER JOIN `{$safe_prefix}cashback_affiliate_network_params` p2
                ON p1.network_id = p2.network_id
               AND p1.param_name = p2.param_name
               AND p1.id > p2.id"
        );
        if ($r_dedup === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[Cashback Migration v15] dedupe failed: ' . $wpdb->last_error);
            return;
        }

        // Шаг 2: ADD UNIQUE с INSTANT-попыткой → fallback.
        $alter_base =
            "ALTER TABLE `{$safe_prefix}cashback_affiliate_network_params`
              ADD UNIQUE KEY `uk_network_param` (`network_id`,`param_name`)";
        $r_alter = $wpdb->query($alter_base . ', ALGORITHM=INPLACE, LOCK=NONE');
        if ($r_alter === false && self::error_indicates_algorithm_unsupported($wpdb->last_error)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[Cashback Migration v15] ALGORITHM=INPLACE not supported, fallback: ' . $wpdb->last_error);
            $r_alter = $wpdb->query($alter_base);
        }
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($r_alter === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[Cashback Migration v15] ALTER ADD UNIQUE failed: ' . $wpdb->last_error);
            return;
        }

        update_option('cashback_db_version', 15, false);
    }

    /**
     * Миграция v16: универсальный контракт дедупликации `dedup_identity`.
     *
     * Добавляет JSON-колонку `cashback_affiliate_networks.dedup_identity` и
     * сидит её для Admitad/EPN/Advcake. Контракт читается универсальным
     * резолвером `Cashback_API_Client::resolve_uniq_id()` (PHP) и его
     * byte-identical Python-зеркалом `app/identity.py` в webhook-receiver:
     *
     *   {"has_native_action_id": bool,
     *    "synthetic_fields": ["order_number","offer_id","action_type"],
     *    "synthetic_include_click_id": bool,
     *    "receiver_uniq_source": "<имя поля в постбэке>"}
     *
     * NULL dedup_identity == legacy has_native_action_id:true (резолвер
     * деградирует к текущему поведению) — поэтому миграция additive и
     * безопасна при любом порядке деплоя relative к receiver-cutover.
     *
     * Идемпотентность: cashback_db_version >= 16 fast-path + INFORMATION_SCHEMA
     * для ALTER + seed ТОЛЬКО где dedup_identity IS NULL (admin-кастомизация и
     * повторные запуски не перезатираются).
     */
    public function migrate_dedup_identity_v16(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 16) {
            return;
        }

        $safe_prefix    = $this->validate_table_prefix($wpdb->prefix);
        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';

        // 1. ALTER ADD COLUMN dedup_identity (если ещё нет). LONGTEXT (не
        //    нативный JSON) — консистентно с api_field_map/api_coupons_field_map
        //    в этой же таблице, без CHECK json_valid сюрпризов на старых MariaDB.
        $has_col = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = %s
                AND COLUMN_NAME  = %s',
            $networks_table,
            'dedup_identity'
        ));

        if ($has_col === 0) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static DDL, $safe_prefix validated via validate_table_prefix().
            $alter_base =
                "ALTER TABLE `{$safe_prefix}cashback_affiliate_networks`
                  ADD COLUMN `dedup_identity` LONGTEXT DEFAULT NULL
                    COMMENT 'JSON контракт идентичности транзакции (v16): has_native_action_id, synthetic_fields, synthetic_include_click_id, receiver_uniq_source. NULL == legacy native-id'
                  AFTER `api_field_map`";
            $r_alter = $wpdb->query($alter_base . ', ALGORITHM=INSTANT');
            if ($r_alter === false && self::error_indicates_algorithm_unsupported($wpdb->last_error)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Cashback Migration v16] ALGORITHM=INSTANT not supported, fallback to default: ' . $wpdb->last_error);
                $r_alter = $wpdb->query($alter_base);
            }
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ($r_alter === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v16] ALTER cashback_affiliate_networks ADD dedup_identity failed: ' . $wpdb->last_error);
                return;
            }

            $verify_col = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = %s
                    AND COLUMN_NAME  = %s',
                $networks_table,
                'dedup_identity'
            ));
            if ($verify_col === 0) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Migration v16] post-verify failed: dedup_identity missing after ALTER');
                return;
            }
        }

        // 2. Seed контракта для встроенных сетей. Все три имеют native
        //    per-action id; receiver_uniq_source — имя поля в ИХ постбэке
        //    (для push/pull валидации P5). Seed только где IS NULL.
        $base = array(
            'has_native_action_id'       => true,
            'synthetic_fields'           => array( 'order_number', 'offer_id', 'action_type' ),
            'synthetic_include_click_id' => false,
        );
        $seeds = array(
            'admitad' => array( 'receiver_uniq_source' => 'admitad_id' ),
            'epn'     => array( 'receiver_uniq_source' => 'transactionId' ),
            'advcake' => array( 'receiver_uniq_source' => 'id' ),
        );

        foreach ($seeds as $slug => $extra) {
            $contract = (string) wp_json_encode(array_merge($base, $extra));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot idempotent migration seed.
            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET dedup_identity = %s WHERE slug = %s AND dedup_identity IS NULL',
                $networks_table,
                $contract,
                $slug
            ));
        }

        update_option('cashback_db_version', 16, false);
    }

    /**
     * Миграция v17: slug-agnostic backfill контракта `dedup_identity`.
     *
     * v16 сидил по ЖЁСТКОМУ списку slug'ов (admitad/epn/advcake), но реальные
     * деплои используют произвольные slug'и (на staging Admitad = 'adm') —
     * такие сети остались с dedup_identity=NULL. Функционально это корректно
     * (NULL == legacy native passthrough в резолвере), но не "универсально"
     * и шумит в P5-панели. v17 ставит безопасный native-дефолт КАЖДОЙ строке
     * где dedup_identity IS NULL, независимо от slug. `receiver_uniq_source`
     * НЕ задаётся — это per-deployment знание оператора (postback-макрос),
     * P5-панель мягко подсветит его отсутствие.
     *
     * Идемпотентность: cashback_db_version >= 17 fast-path + WHERE
     * dedup_identity IS NULL (admin-кастомизация / v16-seed не трогаются).
     */
    public function migrate_dedup_identity_backfill_v17(): void {
        global $wpdb;

        $current_version = (int) get_option('cashback_db_version', 0);
        if ($current_version >= 17) {
            return;
        }

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';

        // Колонка гарантированно есть (v16). Defensive: если по какой-то
        // причине нет — пропускаем без bump (v16 доедет и создаст).
        $has_col = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = %s
                AND COLUMN_NAME  = %s',
            $networks_table,
            'dedup_identity'
        ));
        if ($has_col === 0) {
            return;
        }

        // Безопасный native-дефолт: все известные сегодня CPA-сети имеют
        // собственный per-action id; синтетика включается явным конфигом
        // direct-партнёра. receiver_uniq_source намеренно отсутствует.
        $base = (string) wp_json_encode(array(
            'has_native_action_id'       => true,
            'synthetic_fields'           => array( 'order_number', 'offer_id', 'action_type' ),
            'synthetic_include_click_id' => false,
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot idempotent backfill.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET dedup_identity = %s WHERE dedup_identity IS NULL',
            $networks_table,
            $base
        ));

        update_option('cashback_db_version', 17, false);
    }

    /**
     * Проверяет наличие UNIQUE (network_id, param_name) индекса.
     *
     * @param object $wpdb_instance
     * @param string $table
     */
    private static function has_unique_network_param_index( $wpdb_instance, string $table ): bool {
        $rows = $wpdb_instance->get_results($wpdb_instance->prepare(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = %s
                AND NON_UNIQUE   = 0
                AND INDEX_NAME   = %s',
            $table,
            'uk_network_param'
        ));
        return is_array($rows) && count($rows) > 0;
    }

    /**
     * Эвристика: содержит ли ошибка MariaDB/MySQL сигнатуру «ALGORITHM не поддерживается»?
     */
    private static function error_indicates_algorithm_unsupported( string $err ): bool {
        if ($err === '') {
            return false;
        }
        $needles = array( '1845', 'ALGORITHM=', 'Algorithm not supported', 'LOCK=NONE is not supported' );
        foreach ($needles as $n) {
            if (stripos($err, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Seed public-конфига coupons API для существующей сети admitad (v8).
     *
     * Заполняет 4 новые колонки cashback_affiliate_networks дефолтами для
     * Admitad'а только если api_coupons_endpoint NULL/пустой — не перезаписывает
     * ручную настройку. Идемпотентно. Public-зона: НЕ зашифрованные значения,
     * UPDATE без TX.
     *
     * Encrypted scope (coupons_for_website) добавляется отдельным методом
     * migrate_extend_admitad_scope_for_coupons() — там нужен Cashback_API_Client,
     * а его готовность зависит от bootstrap'а.
     */
    public function migrate_seed_admitad_coupons_config(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_affiliate_networks';

        // Production-инсталляции хранят Admitad под slug='adm' (alias из
        // Cashback_Admitad_Adapter::get_aliases). Покрываем оба варианта.
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, slug, api_coupons_endpoint FROM %i WHERE slug IN (%s, %s) ORDER BY id LIMIT 1',
            $table,
            'admitad',
            'adm'
        ));

        if ($row === null) {
            // Записи admitad нет — нечего seed'ить (свежая установка без
            // insert_default_api_config или ручного добавления).
            return;
        }

        // Не перезаписываем если админ уже настроил.
        if (is_string($row->api_coupons_endpoint) && $row->api_coupons_endpoint !== '') {
            return;
        }

        $defaults = array(
            'api_coupons_endpoint'    => '/coupons/website/{website_id}/?campaign={advcampaign_id}&limit={limit}&offset={offset}',
            'api_coupons_field_map'   => wp_json_encode(array(
                'id'          => 'external_id',
                'promocode'   => 'promocode',
                'name'        => 'name',
                'short_name'  => 'short_name',
                'description' => 'description',
                'discount'    => 'discount',
                'date_start'  => 'date_start',
                'date_end'    => 'date_end',
                'status'      => 'status',
                'regions'     => 'regions',
                'categories'  => 'categories',
                'image'       => 'image_url',
                'goto_link'   => 'goto_link',
                'exclusive'   => 'is_exclusive',
                // В API Admitad species — string ('promocode'/'deal'/'sale'/'discount').
                // Mapper нормализует через species_map к каноническому DTO species.
                'species'     => 'species_raw',
                'rating'      => 'rating',
            )),
            'api_coupons_species_map' => wp_json_encode(array(
                'promocode'  => 'promocode',
                'promo_code' => 'promocode',
                'deal'       => 'deal',
                'sale'       => 'deal',
                'discount'   => 'deal',
            )),
            'api_coupons_pagination'  => 'offset_limit',
        );

        $wpdb->update(
            $table,
            $defaults,
            array( 'id' => (int) $row->id )
        );
    }
}

// Инициализация Mariadb_Plugin происходит через CashbackPlugin::initialize_components()
// в файле cashback-plugin.php
