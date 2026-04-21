<?php

/**
 * Bootstrap-вариант без определения CB_ENCRYPTION_KEY — для тестов fail-closed
 * веток, где Cashback_Encryption::is_configured() должен вернуть false.
 *
 * Запуск: ./vendor/bin/phpunit --bootstrap development/test/bootstrap-no-encryption-key.php
 *
 * См. ADR Группа 4, finding F-1-001.
 */

declare(strict_types=1);

// Сигнал для bootstrap.php — НЕ определять CB_ENCRYPTION_KEY.
$GLOBALS['_cb_test_skip_encryption_key'] = true;

require_once __DIR__ . '/bootstrap.php';
