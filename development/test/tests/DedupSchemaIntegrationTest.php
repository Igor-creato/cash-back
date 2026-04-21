<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration contract test для UNIQUE-ключей Группы 6 ADR (шаг 5).
 *
 * Цель: убедиться что наши classify_*_insert_error() helper'ы корректно
 * распознают реальный текст Duplicate entry, генерируемый MariaDB.
 * Без этого unit-тесты с мок-строками дают ложное чувство защищённости —
 * если MariaDB изменит формат (новая версия, другая locale), helper'ы
 * молча вернут 'other' вместо 'duplicate_*'.
 *
 * Требует env переменных:
 *   CASHBACK_TEST_DB_HOST (default 127.0.0.1)
 *   CASHBACK_TEST_DB_PORT (default 3306)
 *   CASHBACK_TEST_DB_USER (required)
 *   CASHBACK_TEST_DB_PASSWORD (optional, '' по умолчанию)
 *   CASHBACK_TEST_DB_NAME (required)
 *
 * При отсутствии креденшелов или недоступной БД — markTestSkipped.
 * Таблицы создаются как TEMPORARY — session-scoped, автоматический cleanup.
 */
#[Group('dedup')]
#[Group('dedup-integration')]
final class DedupSchemaIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('CASHBACK_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('CASHBACK_TEST_DB_PORT') ?: '3306';
        $user = getenv('CASHBACK_TEST_DB_USER');
        $pass = getenv('CASHBACK_TEST_DB_PASSWORD');
        $name = getenv('CASHBACK_TEST_DB_NAME');

        if (!is_string($user) || $user === '' || !is_string($name) || $name === '') {
            $this->markTestSkipped('CASHBACK_TEST_DB_USER / CASHBACK_TEST_DB_NAME не заданы — интеграционные тесты пропущены.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            $this->pdo = new PDO($dsn, $user, is_string($pass) ? $pass : '', array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ));
        } catch (\PDOException $e) {
            $this->markTestSkipped('MariaDB недоступна: ' . $e->getMessage());
        }

        // Загружаем production-helper'ы (classify_* methods).
        if (!class_exists('Cashback_Claims_Manager')) {
            require_once dirname(__DIR__, 3) . '/claims/class-claims-manager.php';
        }
        if (!class_exists('Cashback_Fraud_Device_Id')) {
            require_once dirname(__DIR__, 3) . '/antifraud/class-fraud-device-id.php';
        }
        if (!class_exists('Cashback_Partner_Management_Admin')) {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) {
                $wpdb = new class() { public string $prefix = 'wp_'; };
            }
            require_once dirname(__DIR__, 3) . '/partner/partner-management.php';
        }
        if (!class_exists('Cashback_Support_Admin')) {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) {
                $wpdb = new class() { public string $prefix = 'wp_'; };
            }
            require_once dirname(__DIR__, 3) . '/support/admin-support.php';
        }

        $this->create_test_tables();
    }

    protected function tearDown(): void
    {
        // TEMPORARY tables уходят с PDO-сессией автоматически; явно null для GC.
        $this->pdo = null;
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────
    // Contract tests: каждый проверяет что реальный MariaDB Duplicate entry
    // правильно классифицируется соответствующим helper'ом.
    // ────────────────────────────────────────────────────────────

    public function test_uk_click_user_violation_classified_as_duplicate_click(): void
    {
        $this->assertNotNull($this->pdo);
        $this->pdo->exec("INSERT INTO cb_test_claims (user_id, click_id, product_id, order_id, order_value, order_date, status, probability_score, is_suspicious, ip_address, user_agent) VALUES (42, 'CLK-0001', 1, 'ORD-1', 100.00, NOW(), 'submitted', 0.5, 0, '1.1.1.1', 'ua')");

        $err = $this->provoke_duplicate(
            "INSERT INTO cb_test_claims (user_id, click_id, product_id, order_id, order_value, order_date, status, probability_score, is_suspicious, ip_address, user_agent) VALUES (42, 'CLK-0001', 1, 'ORD-2', 100.00, NOW(), 'submitted', 0.5, 0, '1.1.1.1', 'ua')"
        );

        $this->assertStringContainsString('Duplicate entry', $err);
        $this->assertSame('duplicate_click', Cashback_Claims_Manager::classify_insert_error($err));
    }

    public function test_uk_user_idempotency_violation_classified_as_duplicate_idempotency(): void
    {
        $this->assertNotNull($this->pdo);
        $this->pdo->exec("INSERT INTO cb_test_claims (user_id, click_id, product_id, order_id, order_value, order_date, status, probability_score, is_suspicious, ip_address, user_agent, idempotency_key) VALUES (42, 'CLK-A', 1, 'ORD-A', 100.00, NOW(), 'submitted', 0.5, 0, '1.1.1.1', 'ua', 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5')");

        $err = $this->provoke_duplicate(
            "INSERT INTO cb_test_claims (user_id, click_id, product_id, order_id, order_value, order_date, status, probability_score, is_suspicious, ip_address, user_agent, idempotency_key) VALUES (42, 'CLK-B', 1, 'ORD-B', 100.00, NOW(), 'submitted', 0.5, 0, '1.1.1.1', 'ua', 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5')"
        );

        $this->assertStringContainsString('Duplicate entry', $err);
        $this->assertSame('duplicate_idempotency', Cashback_Claims_Manager::classify_insert_error($err));
    }

    public function test_uk_merchant_order_violation_classified_as_duplicate_order(): void
    {
        $this->assertNotNull($this->pdo);
        $this->pdo->exec("INSERT INTO cb_test_claims (user_id, click_id, product_id, merchant_id, order_id, order_value, order_date, status, probability_score, is_suspicious, ip_address, user_agent) VALUES (42, 'CLK-X', 1, 100, 'ORDER-SHARED', 100.00, NOW(), 'submitted', 0.5, 0, '1.1.1.1', 'ua')");

        $err = $this->provoke_duplicate(
            "INSERT INTO cb_test_claims (user_id, click_id, product_id, merchant_id, order_id, order_value, order_date, status, probability_score, is_suspicious, ip_address, user_agent) VALUES (43, 'CLK-Y', 1, 100, 'ORDER-SHARED', 100.00, NOW(), 'submitted', 0.5, 0, '1.1.1.1', 'ua')"
        );

        $this->assertStringContainsString('Duplicate entry', $err);
        $this->assertSame('duplicate_order', Cashback_Claims_Manager::classify_insert_error($err));
    }

    public function test_uk_user_session_device_violation_classified(): void
    {
        $this->assertNotNull($this->pdo);
        $uuid = 'a1b2c3d4-5e6f-4a7b-8c9d-e0f1a2b3c4d5';
        $this->pdo->exec("INSERT INTO cb_test_fraud (device_id, user_id, ip_address, first_seen, last_seen) VALUES ('{$uuid}', 42, '1.1.1.1', '2026-04-21 10:00:00', '2026-04-21 10:00:00')");

        $err = $this->provoke_duplicate(
            "INSERT INTO cb_test_fraud (device_id, user_id, ip_address, first_seen, last_seen) VALUES ('{$uuid}', 42, '2.2.2.2', '2026-04-21 15:00:00', '2026-04-21 15:00:00')"
        );

        $this->assertStringContainsString('Duplicate entry', $err);
        $this->assertSame(
            'duplicate_user_session',
            Cashback_Fraud_Device_Id::classify_fraud_device_insert_error($err)
        );
    }

    public function test_uk_request_id_violation_classified(): void
    {
        $this->assertNotNull($this->pdo);
        $req = 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5';
        $this->pdo->exec("INSERT INTO cb_test_support_msg (ticket_id, user_id, message, is_admin, request_id) VALUES (1, 42, 'msg', 1, '{$req}')");

        $err = $this->provoke_duplicate(
            "INSERT INTO cb_test_support_msg (ticket_id, user_id, message, is_admin, request_id) VALUES (1, 42, 'msg retry', 1, '{$req}')"
        );

        $this->assertStringContainsString('Duplicate entry', $err);
        $this->assertSame(
            'duplicate_request_id',
            Cashback_Support_Admin::classify_support_message_insert_error($err)
        );
    }

    public function test_uniq_slug_violation_classified_as_duplicate_slug(): void
    {
        $this->assertNotNull($this->pdo);
        $this->pdo->exec("INSERT INTO cb_test_affiliate_networks (name, slug) VALUES ('Admitad', 'admitad')");

        $err = $this->provoke_duplicate(
            "INSERT INTO cb_test_affiliate_networks (name, slug) VALUES ('Admitad-v2', 'admitad')"
        );

        $this->assertStringContainsString('Duplicate entry', $err);
        $this->assertSame(
            'duplicate_slug',
            Cashback_Partner_Management_Admin::classify_partner_insert_error($err)
        );
    }

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    private function provoke_duplicate( string $sql ): string
    {
        try {
            $this->pdo->exec($sql);
            $this->fail('Ожидалось PDOException на duplicate-key, но INSERT прошёл успешно.');
        } catch (\PDOException $e) {
            return $e->getMessage();
        }
    }

    private function create_test_tables(): void
    {
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS cb_test_claims');
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS cb_test_fraud');
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS cb_test_support_msg');
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS cb_test_affiliate_networks');

        // Minimal subset колонок; UNIQUE-ключи — как в production-миграции шага 2.
        $this->pdo->exec(
            'CREATE TEMPORARY TABLE cb_test_claims (
                claim_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                click_id char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                merchant_id int unsigned DEFAULT NULL,
                product_id bigint(20) unsigned NOT NULL,
                order_id varchar(255) NOT NULL,
                order_value decimal(10,2) NOT NULL,
                order_date datetime NOT NULL,
                status enum("draft","submitted","sent_to_network","approved","declined") NOT NULL DEFAULT "draft",
                probability_score decimal(5,2) NOT NULL DEFAULT 0.00,
                is_suspicious tinyint(1) NOT NULL DEFAULT 0,
                ip_address varchar(45) NOT NULL,
                user_agent text NOT NULL,
                idempotency_key char(36) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (claim_id),
                UNIQUE KEY uk_click_user (click_id, user_id),
                UNIQUE KEY uk_user_idempotency (user_id, idempotency_key),
                UNIQUE KEY uk_merchant_order (merchant_id, order_id)
            ) ENGINE=InnoDB'
        );

        $this->pdo->exec(
            'CREATE TEMPORARY TABLE cb_test_fraud (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                device_id char(36) NOT NULL,
                user_id bigint(20) unsigned DEFAULT NULL,
                ip_address varchar(45) NOT NULL,
                first_seen datetime NOT NULL,
                last_seen datetime NOT NULL,
                session_date DATE GENERATED ALWAYS AS (DATE(first_seen)) STORED,
                PRIMARY KEY (id),
                UNIQUE KEY uk_user_session_device (user_id, session_date, device_id)
            ) ENGINE=InnoDB'
        );

        $this->pdo->exec(
            'CREATE TEMPORARY TABLE cb_test_support_msg (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ticket_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                message text NOT NULL,
                is_admin tinyint(1) NOT NULL DEFAULT 0,
                is_read tinyint(1) NOT NULL DEFAULT 0,
                request_id char(36) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_request_id (request_id)
            ) ENGINE=InnoDB'
        );

        $this->pdo->exec(
            'CREATE TEMPORARY TABLE cb_test_affiliate_networks (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                slug varchar(100) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_slug (slug)
            ) ENGINE=InnoDB'
        );
    }
}
