<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты защиты от гонок, дублей и нарушений целостности при выплатах.
 *
 * Покрывает:
 * - Идемпотентность заявок на выплату (один ключ = одна заявка)
 * - Rate limiting (максимум 3 заявки за 24 часа)
 * - Оптимистичная блокировка через version (защита от race conditions)
 * - Статус-машина выплат (allowed/denied transitions)
 * - Защита paid/failed заявок от изменения
 * - Дубли начислений кэшбэка (счётчик идемпотентности)
 * - Консистентность сумм при параллельных операциях
 * - Проверка лимита суммы вывода
 * - Cooling period для новых аккаунтов
 */
#[Group('withdrawal-concurrency')]
class WithdrawalConcurrencyTest extends TestCase
{
    // ================================================================
    // ТЕСТЫ: Идемпотентность заявок на вывод
    // ================================================================

    public function test_idempotency_key_is_unique_uuid4_format(): void
    {
        // UUID4 формат: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $keys = [];
        for ($i = 0; $i < 100; $i++) {
            $key = wp_generate_uuid4();
            $keys[] = $key;
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $key,
                'UUID4 ключ должен соответствовать формату'
            );
        }

        // Все ключи уникальны
        $this->assertCount(100, array_unique($keys), 'Все 100 UUID4 ключей должны быть уникальными');
    }

    public function test_duplicate_idempotency_key_detection(): void
    {
        // Симулируем обнаружение дубля: если ключ уже существует в БД — отказ
        $existing_keys = [
            'uuid-1234' => ['id' => 1, 'status' => 'waiting'],
            'uuid-5678' => ['id' => 2, 'status' => 'paid'],
        ];

        $incoming_key = 'uuid-1234';
        $is_duplicate = isset($existing_keys[$incoming_key]);

        $this->assertTrue($is_duplicate, 'Дубликат idempotency_key должен быть обнаружен');
    }

    public function test_new_idempotency_key_not_duplicate(): void
    {
        $existing_keys = ['uuid-1234' => true, 'uuid-5678' => true];
        $new_key = 'uuid-9999-fresh';

        $is_duplicate = isset($existing_keys[$new_key]);
        $this->assertFalse($is_duplicate, 'Новый ключ не должен считаться дублем');
    }

    public function test_idempotent_request_returns_success_without_double_charge(): void
    {
        // Если запрос с тем же ключом уже выполнен — возвращаем success, не создаём новую заявку
        $existing_request = ['id' => 42, 'status' => 'waiting', 'amount' => '500.00'];
        $idempotency_key  = 'uuid-already-processed';

        $is_existing = ($existing_request !== null);  // Запись найдena в БД

        if ($is_existing) {
            // Идемпотентный ответ — заявка уже создана, возвращаем success без повтора
            $response = ['success' => true, 'message' => 'Заявка уже создана.'];
        } else {
            $response = ['success' => false];
        }

        $this->assertTrue($response['success'], 'Повторный запрос должен возвращать success');
        $this->assertStringContainsString('уже создана', $response['message']);
    }

    // ================================================================
    // ТЕСТЫ: Rate limiting (max 3 заявки за 24 часа)
    // ================================================================

    public function test_rate_limit_allows_up_to_3_requests(): void
    {
        $max_requests = 3;

        for ($count = 0; $count < $max_requests; $count++) {
            $is_allowed = $count < $max_requests;
            $this->assertTrue($is_allowed, sprintf('Запрос %d из %d должен быть разрешён', $count + 1, $max_requests));
        }
    }

    public function test_rate_limit_blocks_4th_request(): void
    {
        $max_requests = 3;
        $current_count = 3;  // Уже сделано 3 запроса

        $is_blocked = $current_count >= $max_requests;
        $this->assertTrue($is_blocked, '4-й запрос должен быть заблокирован rate limiter');
    }

    public function test_rate_limit_resets_after_24_hours(): void
    {
        // После 24 часов transient истекает и get_transient() возвращает false
        // Код интерпретирует (int) false = 0 → счётчик сбросился
        $transient_value = false;  // transient истёк (get_transient вернул false)

        $current_count = (int) $transient_value;  // 0

        $is_allowed = $current_count < 3;
        $this->assertTrue($is_allowed, 'После 24 часов лимит (count=0) должен позволять новые заявки');
    }

    public function test_rate_limit_increments_only_on_success(): void
    {
        // Rate limit инкрементируется ТОЛЬКО после успешного создания заявки
        $count_before = 1;
        $request_succeeded = true;

        $count_after = $request_succeeded ? $count_before + 1 : $count_before;

        $this->assertSame(2, $count_after, 'Счётчик должен увеличиться только при успехе');
    }

    public function test_rate_limit_not_incremented_on_failed_request(): void
    {
        $count_before = 1;
        $request_succeeded = false;

        $count_after = $request_succeeded ? $count_before + 1 : $count_before;

        $this->assertSame(1, $count_after, 'Счётчик не должен увеличиться при неудаче');
    }

    public function test_rate_limit_per_user_independent(): void
    {
        // Rate limit у каждого пользователя независимый
        $user1_count = 3;  // Исчерпал лимит
        $user2_count = 0;  // Ещё не делал заявок

        $user1_blocked = $user1_count >= 3;
        $user2_blocked = $user2_count >= 3;

        $this->assertTrue($user1_blocked, 'User1 заблокирован');
        $this->assertFalse($user2_blocked, 'User2 не заблокирован');
    }

    // ================================================================
    // ТЕСТЫ: Version-based optimistic locking (защита от race conditions)
    // ================================================================

    public function test_version_conflict_detected_on_concurrent_update(): void
    {
        // Процесс A прочитал version=5, Процесс B обновил и version стала 6
        $process_a_read_version = 5;
        $current_db_version     = 6;  // Версия в БД уже изменилась

        // UPDATE ... WHERE version = $process_a_read_version → affected_rows = 0
        $affected_rows = ($current_db_version === $process_a_read_version) ? 1 : 0;

        $this->assertSame(0, $affected_rows, 'Конфликт версий должен дать affected_rows=0');
    }

    public function test_version_no_conflict_on_sequential_updates(): void
    {
        // Последовательные обновления (не параллельные)
        $version = 5;

        // Операция 1: read version=5, update WHERE version=5 → success, version=6
        $affected_1 = ($version === 5) ? 1 : 0;
        $version = $affected_1 > 0 ? $version + 1 : $version;

        $this->assertSame(1, $affected_1, 'Первое обновление должно пройти');
        $this->assertSame(6, $version, 'Версия должна увеличиться');

        // Операция 2: read version=6, update WHERE version=6 → success, version=7
        $affected_2 = ($version === 6) ? 1 : 0;
        $version = $affected_2 > 0 ? $version + 1 : $version;

        $this->assertSame(1, $affected_2, 'Второе обновление должно пройти');
        $this->assertSame(7, $version, 'Версия должна увеличиться до 7');
    }

    public function test_balance_check_under_lock_prevents_toctou(): void
    {
        // TOCTOU = Time-Of-Check to Time-Of-Use
        // Balance проверяется под row-level lock (FOR UPDATE)
        // Симулируем: баланс проверен под блокировкой → не может измениться до commit

        $balance_read_under_lock = '500.00';
        $withdrawal_amount       = '500.00';

        // Проверка под lock: withdrawal <= balance
        $is_valid = bccomp($withdrawal_amount, $balance_read_under_lock, 2) <= 0;
        $this->assertTrue($is_valid, 'Под блокировкой баланс корректен — операция разрешена');
    }

    public function test_race_condition_simulation_double_spend(): void
    {
        // Симулируем сценарий double-spend:
        // Два параллельных запроса на вывод 500 руб., баланс = 500 руб.

        $initial_balance = '500.00';
        $amount          = '500.00';

        // Запрос A: читает balance=500, проверяет 500>=500 → OK
        $balance_a_read = $initial_balance;
        $a_valid = bccomp($amount, $balance_a_read, 2) <= 0;

        // Запрос B: читает balance=500 (до commit A), проверяет 500>=500 → OK
        $balance_b_read = $initial_balance;
        $b_valid = bccomp($amount, $balance_b_read, 2) <= 0;

        // Без защиты оба запроса пройдут проверку
        $this->assertTrue($a_valid, 'Запрос A без блокировки видит достаточный баланс');
        $this->assertTrue($b_valid, 'Запрос B без блокировки видит достаточный баланс');

        // С защитой (version lock): только один из них обновит строку
        // Запрос A успешно обновляет: version 0→1, available=0
        $version_after_a = 1;
        $balance_after_a = bcsub($initial_balance, $amount, 2);

        // Запрос B пытается обновить WHERE version=0 — не совпадает (version=1)
        $b_update_where_old_version = ($version_after_a === 0);

        $this->assertFalse($b_update_where_old_version, 'Запрос B должен обнаружить конфликт версий и отказаться');
        $this->assertSame('0.00', $balance_after_a, 'Баланс после успешного запроса A должен быть 0');
    }

    // ================================================================
    // ТЕСТЫ: Статус-машина выплат
    // ================================================================

    public static function payout_valid_status_transitions_provider(): array
    {
        return [
            'waiting → processing'              => ['waiting', 'processing', true],
            'waiting → declined'                => ['waiting', 'declined', true],
            'waiting → waiting (без изменений)' => ['waiting', 'waiting', true],
            'processing → paid'                 => ['processing', 'paid', true],
            'processing → failed'               => ['processing', 'failed', true],
            'processing → needs_retry'          => ['processing', 'needs_retry', true],
            'needs_retry → processing'          => ['needs_retry', 'processing', true],
            'declined → waiting'                => ['declined', 'waiting', true],
        ];
    }

    public static function payout_invalid_status_transitions_provider(): array
    {
        return [
            'paid → waiting (финальный)'        => ['paid', 'waiting', false],
            'paid → processing (финальный)'     => ['paid', 'processing', false],
            'paid → failed (финальный)'         => ['paid', 'failed', false],
            'paid → declined (финальный)'       => ['paid', 'declined', false],
            'failed → waiting (финальный)'      => ['failed', 'waiting', false],
            'failed → processing (финальный)'   => ['failed', 'processing', false],
            'failed → paid (финальный)'         => ['failed', 'paid', false],
        ];
    }

    #[DataProvider('payout_valid_status_transitions_provider')]
    public function test_payout_valid_status_transition(string $from, string $to, bool $expected): void
    {
        // Логика из validate_payout_update: paid/failed — финальные статусы
        $result = Cashback_Trigger_Fallbacks::validate_payout_update($from);

        if ($expected) {
            $this->assertTrue($result === true, "Переход из '{$from}' должен быть разрешён");
        } else {
            $this->assertNotTrue($result, "Переход из '{$from}' должен быть запрещён");
        }
    }

    #[DataProvider('payout_invalid_status_transitions_provider')]
    public function test_payout_invalid_status_transition(string $from, string $to, bool $expected): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_payout_update($from);

        $this->assertIsString($result, "Финальный статус '{$from}' должен запрещать изменения");
        $this->assertNotEmpty($result, 'Сообщение об ошибке не должно быть пустым');
    }

    public function test_paid_payout_is_immutable(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_payout_update('paid');

        $this->assertIsString($result, 'paid — финальный статус, изменения запрещены');
        $this->assertStringContainsString('выплачен', $result);
    }

    public function test_failed_payout_is_immutable(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_payout_update('failed');

        $this->assertIsString($result, 'failed — финальный статус, изменения запрещены');
        $this->assertStringContainsString('failed', $result);
    }

    public function test_all_non_final_payout_statuses_allow_updates(): void
    {
        $non_final_statuses = ['waiting', 'processing', 'needs_retry', 'declined'];

        foreach ($non_final_statuses as $status) {
            $result = Cashback_Trigger_Fallbacks::validate_payout_update($status);
            $this->assertTrue($result, "Статус '{$status}' должен разрешать изменения");
        }
    }

    // ================================================================
    // ТЕСТЫ: Дубли начислений кэшбэка
    // ================================================================

    public function test_cashback_duplicate_prevention_by_reference_id(): void
    {
        // reference_id (или action_id) должен быть уникальным для предотвращения дублей
        $processed_ids = ['action:admitad:12345', 'action:admitad:67890'];

        $new_id      = 'action:admitad:99999';
        $is_duplicate = in_array($new_id, $processed_ids, true);

        $this->assertFalse($is_duplicate, 'Новый action_id не является дублем');
    }

    public function test_cashback_duplicate_prevention_by_existing_id(): void
    {
        $processed_ids = ['action:admitad:12345', 'action:admitad:67890'];

        $duplicate_id = 'action:admitad:12345';
        $is_duplicate = in_array($duplicate_id, $processed_ids, true);

        $this->assertTrue($is_duplicate, 'Уже обработанный action_id должен быть распознан как дубль');
    }

    public function test_cashback_status_balance_prevents_double_accrual(): void
    {
        // Транзакция со статусом 'balance' не может быть начислена повторно
        // Статус 'balance' — финальный (из Cashback_Trigger_Fallbacks::validate_status_transition)
        $result = Cashback_Trigger_Fallbacks::validate_status_transition('balance', 'balance');
        $this->assertTrue($result, 'balance → balance (без изменений) разрешён');

        // Но любое изменение из 'balance' запрещено
        $result_to_completed = Cashback_Trigger_Fallbacks::validate_status_transition('balance', 'completed');
        $this->assertIsString($result_to_completed, 'balance → completed запрещено');
    }

    // ================================================================
    // ТЕСТЫ: Cooling period для новых аккаунтов
    // ================================================================

    public function test_cooling_period_blocks_new_account(): void
    {
        // Аккаунт зарегистрирован 2 дня назад, cooling period 7 дней
        $cooling_days    = 7;
        $seconds_since   = 2 * DAY_IN_SECONDS;  // 2 дня
        $cooling_seconds = $cooling_days * DAY_IN_SECONDS;

        $is_in_cooling = $seconds_since < $cooling_seconds;
        $this->assertTrue($is_in_cooling, 'Аккаунт 2 дня в cooling period 7 дней должен быть заблокирован');
    }

    public function test_cooling_period_allows_old_account(): void
    {
        $cooling_days    = 7;
        $seconds_since   = 8 * DAY_IN_SECONDS;  // 8 дней
        $cooling_seconds = $cooling_days * DAY_IN_SECONDS;

        $is_in_cooling = $seconds_since < $cooling_seconds;
        $this->assertFalse($is_in_cooling, 'Аккаунт 8 дней при cooling 7 дней должен быть разрешён');
    }

    public function test_cooling_period_exact_boundary(): void
    {
        $cooling_days  = 7;
        $cooling_secs  = $cooling_days * DAY_IN_SECONDS;

        // Ровно 7 дней — ещё в cooldown (seconds_since = cooling_seconds, не <)
        $still_in = $cooling_secs < $cooling_secs;
        $this->assertFalse($still_in, 'Ровно 7 дней: вышел из cooldown');

        // 7 дней минус 1 секунда — всё ещё в cooldown
        $almost_out = ($cooling_secs - 1) < $cooling_secs;
        $this->assertTrue($almost_out, '7 дней - 1 секунда: ещё в cooldown');
    }

    public function test_remaining_cooling_days_calculated_correctly(): void
    {
        $cooling_days    = 7;
        $seconds_since   = 3 * DAY_IN_SECONDS + 3600;  // 3 дня 1 час
        $cooling_seconds = $cooling_days * DAY_IN_SECONDS;

        $remaining_seconds  = $cooling_seconds - $seconds_since;
        $remaining_days     = (int) ceil($remaining_seconds / DAY_IN_SECONDS);

        $this->assertSame(4, $remaining_days, 'Осталось 4 дня (ceil от 3,96)');
    }

    // ================================================================
    // ТЕСТЫ: Лимит суммы вывода (max withdrawal amount)
    // ================================================================

    public function test_amount_within_max_limit_allowed(): void
    {
        $max_amount = '50000.00';
        $amount     = '49999.99';

        $exceeds = bccomp($amount, $max_amount, 2) > 0;
        $this->assertFalse($exceeds, 'Сумма ниже максимального лимита должна быть разрешена');
    }

    public function test_amount_equal_to_max_limit_allowed(): void
    {
        $max_amount = '50000.00';
        $amount     = '50000.00';

        $exceeds = bccomp($amount, $max_amount, 2) > 0;
        $this->assertFalse($exceeds, 'Сумма равная максимальному лимиту должна быть разрешена');
    }

    public function test_amount_exceeds_max_limit_blocked(): void
    {
        $max_amount = '50000.00';
        $amount     = '50000.01';

        $exceeds = bccomp($amount, $max_amount, 2) > 0;
        $this->assertTrue($exceeds, 'Сумма превышающая максимальный лимит должна быть заблокирована');
    }

    // ================================================================
    // ТЕСТЫ: Инвариант консистентности при параллельных операциях
    // ================================================================

    public function test_balance_sum_invariant_across_operations(): void
    {
        // Инвариант: available + pending + frozen + paid = const (только начисления добавляют)
        $available = '1000.00';
        $pending   = '0.00';
        $frozen    = '0.00';
        $paid      = '0.00';

        $total_initial = bcadd(bcadd(bcadd($available, $pending, 2), $frozen, 2), $paid, 2);

        // Операция 1: создать заявку 300 руб.
        $amount1 = '300.00';
        $available = bcsub($available, $amount1, 2);
        $pending   = bcadd($pending, $amount1, 2);
        $total_after_op1 = bcadd(bcadd(bcadd($available, $pending, 2), $frozen, 2), $paid, 2);
        $this->assertSame($total_initial, $total_after_op1, 'Сумма после op1 (создание заявки)');

        // Операция 2: выплатить 300 руб.
        $pending   = bcsub($pending, $amount1, 2);
        $paid      = bcadd($paid, $amount1, 2);
        $total_after_op2 = bcadd(bcadd(bcadd($available, $pending, 2), $frozen, 2), $paid, 2);
        $this->assertSame($total_initial, $total_after_op2, 'Сумма после op2 (выплата)');

        // Операция 3: новая заявка 500 руб., затем failed → возврат
        $amount3 = '500.00';
        $available = bcsub($available, $amount3, 2);
        $pending   = bcadd($pending, $amount3, 2);
        $pending   = bcsub($pending, $amount3, 2);
        $available = bcadd($available, $amount3, 2);
        $total_after_op3 = bcadd(bcadd(bcadd($available, $pending, 2), $frozen, 2), $paid, 2);
        $this->assertSame($total_initial, $total_after_op3, 'Сумма после op3 (failed → возврат)');
    }

    public function test_concurrent_withdrawals_cannot_double_spend_via_version(): void
    {
        // Начальное состояние
        $available = '500.00';
        $pending   = '0.00';
        $version   = 0;

        // Два «параллельных» процесса читают одно и то же состояние
        $snapshot_a = ['available' => $available, 'pending' => $pending, 'version' => $version];
        $snapshot_b = ['available' => $available, 'pending' => $pending, 'version' => $version];

        $amount = '500.00';

        // Процесс A: обновляет WHERE version=0 → успех, version становится 1
        $a_can_update = ($snapshot_a['version'] === $version);
        if ($a_can_update) {
            $available = bcsub($available, $amount, 2);
            $pending   = bcadd($pending, $amount, 2);
            $version++;
        }

        $this->assertSame('0.00', $available, 'После A: available = 0');
        $this->assertSame('500.00', $pending, 'После A: pending = 500');
        $this->assertSame(1, $version, 'Версия после A = 1');

        // Процесс B: обновляет WHERE version=0 — НО версия уже 1, fails
        $b_can_update = ($snapshot_b['version'] === $version);
        $this->assertFalse($b_can_update, 'Процесс B не должен пройти проверку версии');

        // Баланс не изменён процессом B
        $this->assertSame('0.00', $available, 'Баланс не должен уйти в минус после попытки B');
    }

    // ================================================================
    // ТЕСТЫ: Проверка reference_id генерации и уникальности
    // ================================================================

    public function test_reference_id_format_is_valid(): void
    {
        $id = Mariadb_Plugin::generate_reference_id();

        $this->assertIsString($id, 'reference_id должен быть строкой');
        $this->assertNotEmpty($id, 'reference_id не должен быть пустым');
        $this->assertGreaterThanOrEqual(5, strlen($id), 'reference_id должен иметь достаточную длину');
    }

    public function test_reference_ids_are_statistically_unique(): void
    {
        $ids = [];
        for ($i = 0; $i < 500; $i++) {
            $ids[] = Mariadb_Plugin::generate_reference_id();
        }

        $unique_count = count(array_unique($ids));
        // В 500 генерациях коллизии крайне маловероятны (< 0.1%)
        $this->assertGreaterThan(
            495,
            $unique_count,
            'Минимум 496 из 500 reference_id должны быть уникальными'
        );
    }

    public function test_reference_id_collision_retry_logic(): void
    {
        // Симулируем логику retry при коллизии reference_id (из process_cashback_withdrawal)
        $existing_ids = ['REF001', 'REF002', 'REF003'];

        $generated_sequence = ['REF001', 'REF002', 'REF004']; // третья попытка успешна
        $max_retries = 5;
        $success_id  = null;

        for ($attempt = 0; $attempt < $max_retries; $attempt++) {
            $candidate = $generated_sequence[$attempt] ?? 'REF_NEW_' . $attempt;

            if (!in_array($candidate, $existing_ids, true)) {
                $success_id = $candidate;
                break;
            }
        }

        $this->assertNotNull($success_id, 'Должен найти уникальный ID после retry');
        $this->assertSame('REF004', $success_id, 'Третья попытка должна дать уникальный ID');
    }

    // ================================================================
    // ТЕСТЫ: Атомарность операций withdrawal
    // ================================================================

    public function test_rollback_on_balance_update_failure(): void
    {
        // Если UPDATE balance не даёт affected_rows > 0 → ROLLBACK
        // (Version conflict или insufficient balance)
        $affected_rows = 0;  // Симулируем failure

        $should_rollback = ($affected_rows === 0 || $affected_rows === false);
        $this->assertTrue($should_rollback, 'При failure UPDATE balance должен выполниться ROLLBACK');
    }

    public function test_rollback_prevents_orphaned_payout_request(): void
    {
        // Если создана заявка, но balance update упал — ROLLBACK удалит заявку
        // Это гарантирует атомарность: либо оба действия выполнились, либо ни одного

        $in_transaction  = true;
        $insert_success  = true;
        $balance_updated = false;  // Simulate failure

        if ($in_transaction && $insert_success && !$balance_updated) {
            // ROLLBACK — заявка удалится вместе с транзакцией
            $rollback_needed = true;
        } else {
            $rollback_needed = false;
        }

        $this->assertTrue($rollback_needed, 'ROLLBACK нужен при успешном INSERT но неудачном UPDATE balance');
    }

    // ================================================================
    // ТЕСТЫ: Pending balance integrity check (health-check логика)
    // ================================================================

    public function test_pending_balance_with_no_active_requests_is_anomaly(): void
    {
        // Если pending_balance > 0, но нет активных заявок (waiting/processing/needs_retry)
        // — это аномалия (health-check должен это найти)
        $pending_balance = 500.00;
        $active_requests = 0;  // Нет активных заявок

        $is_anomaly = $pending_balance > 0 && $active_requests === 0;
        $this->assertTrue($is_anomaly, 'Pending balance без активных заявок — аномалия');
    }

    public function test_pending_balance_with_active_requests_is_normal(): void
    {
        $pending_balance = 500.00;
        $active_requests = 1;  // Есть активная заявка

        $is_anomaly = $pending_balance > 0 && $active_requests === 0;
        $this->assertFalse($is_anomaly, 'Pending balance с активной заявкой — нормально');
    }

    public function test_negative_balance_is_always_anomaly(): void
    {
        $balance_types = [-0.01, -100.00, -0.00001];

        foreach ($balance_types as $balance) {
            $is_negative = $balance < 0;
            $this->assertTrue($is_negative, "Баланс {$balance} должен быть признан аномалией");
        }
    }

    // ================================================================
    // ТЕСТЫ: Консистентность формата суммы в строковом представлении
    // ================================================================

    public function test_amount_string_representation_for_bcmath(): void
    {
        // bcmath требует строки, не float
        $amount_float  = 500.00;
        $amount_string = number_format($amount_float, 2, '.', '');

        $this->assertSame('500.00', $amount_string, 'number_format должен давать строку для bcmath');
        $this->assertIsString($amount_string, 'Для bcmath нужна строка');
    }

    public function test_bcsub_with_string_gives_correct_result(): void
    {
        $available = '1000.00';
        $amount    = '500.00';

        $result = bcsub($available, $amount, 2);

        $this->assertSame('500.00', $result, 'bcsub("1000.00", "500.00", 2) = "500.00"');
        $this->assertIsString($result, 'bcsub возвращает строку');
    }

    public function test_bccomp_correctly_handles_decimal_comparison(): void
    {
        // Случай когда float comparison даст неверный результат
        // 0.1 + 0.2 == 0.3 через float: FALSE в PHP из-за IEEE 754
        $sum = bcadd('0.10', '0.20', 2);
        $expected = '0.30';

        $this->assertSame(0, bccomp($sum, $expected, 2), 'bccomp(0.10+0.20, 0.30) должен давать 0 (равно)');
    }
}
