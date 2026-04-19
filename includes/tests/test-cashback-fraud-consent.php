<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Smoke-тесты для Cashback_Fraud_Consent.
 *
 * Запуск: WP-CLI eval-file либо подключение из временного admin-скрипта при
 * отладке. PHPUnit для плагина не настроен, поэтому тесты оформлены как
 * самостоятельный исполняемый файл, выводящий PASS/FAIL.
 *
 * Использует временного юзера (создаётся и удаляется внутри теста), чтобы
 * не загрязнять боевую базу. Запускать ТОЛЬКО на dev/staging-окружении.
 *
 * Пример:
 *   wp eval-file wp-content/plugins/cash-back/includes/tests/test-cashback-fraud-consent.php
 */

if (!class_exists('Cashback_Fraud_Consent')) {
    require_once dirname(__DIR__) . '/class-cashback-fraud-consent.php';
}

/**
 * Минимальный контейнер для assert + repórt.
 */
final class Cashback_Fraud_Consent_Test_Runner {

    /** @var array<int, array{name:string, ok:bool, msg:string}> */
    private array $results = array();

    private function assert_true( bool $cond, string $name, string $msg = '' ): void {
        $this->results[] = array(
            'name' => $name,
            'ok'   => $cond,
            'msg'  => $msg,
        );
    }

    public function run(): void {
        $this->setup_required_after();

        $this->test_validate_without_checkbox_returns_error();
        $this->test_validate_with_checkbox_no_error();
        $this->test_record_and_has_consent();
        $this->test_legacy_user_has_implied_consent();
        $this->test_new_user_without_consent_returns_false();
        $this->test_withdraw_consent_clears_state();

        $this->print_results();
    }

    /**
     * Гарантирует наличие OPTION_REQUIRED_AFTER (имитируем активацию).
     */
    private function setup_required_after(): void {
        if (get_option(Cashback_Fraud_Consent::OPTION_REQUIRED_AFTER, null) === null) {
            add_option(Cashback_Fraud_Consent::OPTION_REQUIRED_AFTER, current_time('mysql'));
        }
    }

    private function create_test_user( string $registered_at = '' ): int {
        $login = 'cb_consent_test_' . wp_generate_password(8, false, false);
        $email = $login . '@example.test';
        $uid   = wp_insert_user(array(
            'user_login' => $login,
            'user_email' => $email,
            'user_pass'  => wp_generate_password(20),
            'role'       => 'subscriber',
        ));
        if (is_wp_error($uid)) {
            return 0;
        }
        $uid = (int) $uid;

        if ($registered_at !== '') {
            global $wpdb;
            // user_registered меняем напрямую, т.к. wp_update_user его не трогает.
            $wpdb->update(
                $wpdb->users,
                array( 'user_registered' => $registered_at ),
                array( 'ID' => $uid )
            );
            clean_user_cache($uid);
        }
        return $uid;
    }

    private function cleanup_user( int $uid ): void {
        if ($uid > 0) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($uid);
        }
    }

    private function test_validate_without_checkbox_returns_error(): void {
        $_POST = array(); // нет чекбокса.
        $errors = new WP_Error();
        $result = Cashback_Fraud_Consent::validate_consent($errors, 'u', 'e@x.test');
        $this->assert_true(
            $result instanceof WP_Error && $result->get_error_code() === 'cashback_fraud_consent_required',
            'validate without checkbox -> WP_Error',
            'Expected WP_Error with code cashback_fraud_consent_required'
        );
    }

    private function test_validate_with_checkbox_no_error(): void {
        $_POST = array( Cashback_Fraud_Consent::POST_FIELD => '1' );
        $errors = new WP_Error();
        $result = Cashback_Fraud_Consent::validate_consent($errors, 'u', 'e@x.test');
        $this->assert_true(
            $result instanceof WP_Error && !$result->has_errors(),
            'validate with checkbox -> no error',
            'Expected empty WP_Error'
        );
        $_POST = array();
    }

    private function test_record_and_has_consent(): void {
        $uid = $this->create_test_user();
        if ($uid === 0) {
            $this->assert_true(false, 'record_consent: setup', 'cannot create test user');
            return;
        }

        Cashback_Fraud_Consent::record_consent($uid, '127.0.0.1');

        $consent_at = get_user_meta($uid, Cashback_Fraud_Consent::META_KEY_CONSENT_AT, true);
        $version    = get_user_meta($uid, Cashback_Fraud_Consent::META_KEY_CONSENT_VERSION, true);
        $ip         = get_user_meta($uid, Cashback_Fraud_Consent::META_KEY_CONSENT_IP, true);

        $this->assert_true(is_string($consent_at) && $consent_at !== '', 'record_consent saves META_KEY_CONSENT_AT');
        $this->assert_true($version === Cashback_Fraud_Consent::CURRENT_CONSENT_VERSION, 'record_consent saves version');
        $this->assert_true($ip === '127.0.0.1', 'record_consent saves valid IP');
        $this->assert_true(Cashback_Fraud_Consent::has_consent($uid), 'has_consent true after record');

        $this->cleanup_user($uid);
    }

    private function test_legacy_user_has_implied_consent(): void {
        // Дата регистрации ДО required_after.
        $required_after = (string) get_option(Cashback_Fraud_Consent::OPTION_REQUIRED_AFTER);
        $past           = gmdate('Y-m-d H:i:s', strtotime($required_after) - DAY_IN_SECONDS);

        $uid = $this->create_test_user($past);
        if ($uid === 0) {
            $this->assert_true(false, 'legacy: setup', 'cannot create test user');
            return;
        }

        $this->assert_true(
            Cashback_Fraud_Consent::has_consent($uid),
            'legacy user (registered before required_after) has implied consent'
        );

        $this->cleanup_user($uid);
    }

    private function test_new_user_without_consent_returns_false(): void {
        // Дата регистрации ПОСЛЕ required_after.
        $required_after = (string) get_option(Cashback_Fraud_Consent::OPTION_REQUIRED_AFTER);
        $future         = gmdate('Y-m-d H:i:s', strtotime($required_after) + DAY_IN_SECONDS);

        $uid = $this->create_test_user($future);
        if ($uid === 0) {
            $this->assert_true(false, 'new user: setup', 'cannot create test user');
            return;
        }

        $this->assert_true(
            !Cashback_Fraud_Consent::has_consent($uid),
            'new user without explicit consent -> has_consent false'
        );

        $this->cleanup_user($uid);
    }

    private function test_withdraw_consent_clears_state(): void {
        $uid = $this->create_test_user(gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS));
        if ($uid === 0) {
            $this->assert_true(false, 'withdraw: setup', 'cannot create test user');
            return;
        }
        Cashback_Fraud_Consent::record_consent($uid, '10.0.0.1');
        $this->assert_true(Cashback_Fraud_Consent::has_consent($uid), 'pre-withdraw: has_consent true');

        Cashback_Fraud_Consent::withdraw_consent($uid);
        $this->assert_true(!Cashback_Fraud_Consent::has_consent($uid), 'post-withdraw: has_consent false');
        $this->assert_true(
            get_user_meta($uid, Cashback_Fraud_Consent::META_KEY_CONSENT_AT, true) === '',
            'post-withdraw: META_KEY_CONSENT_AT cleared'
        );

        $this->cleanup_user($uid);
    }

    private function print_results(): void {
        $pass = 0;
        $fail = 0;
        echo "\n=== Cashback_Fraud_Consent tests ===\n";
        foreach ($this->results as $r) {
            $status = $r['ok'] ? 'PASS' : 'FAIL';
            echo sprintf("[%s] %s%s\n", $status, $r['name'], $r['msg'] !== '' && !$r['ok'] ? ' -- ' . $r['msg'] : '');
            if ($r['ok']) {
                $pass++;
            } else {
                $fail++;
            }
        }
        echo sprintf("\nTotal: %d passed, %d failed\n", $pass, $fail);
    }
}

( new Cashback_Fraud_Consent_Test_Runner() )->run();
