<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression-тест: `transaction_data_changed` notification читает свежий
 * cashback из БД, а не из `$update_data['cashback']`.
 *
 * Codex adversarial-review round 8 (2026-05-10): после рефакторинга
 * MariaDB-only (commit 31bab44) sync_update_local перестал заполнять
 * `$update_data['cashback']` — пересчёт делает DB-триггер
 * `calculate_cashback_before_update`. Но `enqueue_notification_on_update()`
 * по-прежнему берёт `new_cashback = isset($update_data['cashback']) ? ... : $old_cashback`.
 * При commission-only обновлении (commission_changed=true, status_changed=false)
 * `update_data['cashback']` не задано → `new_cashback = old_cashback`,
 * хотя в БД триггер уже пересчитал значение. Уведомление врёт пользователю.
 *
 * Тест проверяет:
 *   1. enqueue_notification_on_update НЕ выводит new_cashback из
 *      `$update_data['cashback']` (шаблон Codex round 8 удалён).
 *   2. caller (sync_update_local closure) рефетчит cashback из БД ПОСЛЕ
 *      `$wpdb->update()` и передаёт его в helper.
 */
#[Group('database')]
#[Group('regression')]
#[Group('notifications')]
final class ApiClientCashbackNotificationRefetchTest extends TestCase
{
    private function api_client_src(): string
    {
        $path    = dirname(__DIR__, 3) . '/includes/class-cashback-api-client.php';
        $content = file_get_contents($path);
        $this->assertIsString($content, 'class-cashback-api-client.php must be readable');
        return $content;
    }

    /**
     * Извлекает тело private-метода (между `private function NAME(` и
     * закрывающей `}` метода). Простой балансировщик `{`/`}`.
     */
    private function extract_private_method_body(string $src, string $method_name): string
    {
        $sig = 'private function ' . $method_name . '(';
        $pos = strpos($src, $sig);
        $this->assertNotFalse($pos, "Метод {$method_name} должен существовать");

        $brace_open = strpos($src, '{', $pos);
        $this->assertNotFalse($brace_open);

        $depth = 0;
        $end   = $brace_open;
        $len   = strlen($src);
        for ($i = $brace_open; $i < $len; $i++) {
            $ch = $src[ $i ];
            if ($ch === '{') {
                ++$depth;
            } elseif ($ch === '}') {
                --$depth;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        return substr($src, $brace_open, $end - $brace_open + 1);
    }

    // =========================================================================
    // 1. helper больше не использует $update_data['cashback']
    // =========================================================================

    public function test_enqueue_notification_does_not_read_cashback_from_update_data(): void
    {
        $body = $this->extract_private_method_body(
            $this->api_client_src(),
            'enqueue_notification_on_update'
        );

        // После Codex round 8 fix запрещён паттерн вида
        // `$new_cashback = isset($update_data['cashback']) ? ...`,
        // т.к. sync_update_local больше не заполняет update_data['cashback'].
        $this->assertDoesNotMatchRegularExpression(
            "/new_cashback\\s*=\\s*isset\\(\\s*\\\$update_data\\[\\s*'cashback'\\s*\\]/",
            $body,
            'enqueue_notification_on_update НЕ должен выводить new_cashback '
            . "из \$update_data['cashback'] — это поле больше не заполняется "
            . 'после MariaDB-only рефакторинга, fallback вернёт old_cashback '
            . 'и уведомление будет врать пользователю.'
        );
    }

    public function test_helper_uses_post_trigger_cashback_value(): void
    {
        $src = $this->api_client_src();

        // Сигнатура helper'а должна принимать пост-триггерное значение
        // cashback (string или float — оба валидны), либо рефетчить
        // изнутри. Проверяем оба паттерна.
        $accepts_param = preg_match(
            '/private\s+function\s+enqueue_notification_on_update\([^)]*\$new_cashback[\w_]*[^)]*\)/',
            $src
        ) === 1;

        $body = $this->extract_private_method_body(
            $src,
            'enqueue_notification_on_update'
        );

        $refetches_internally = preg_match(
            '/SELECT\s+cashback\s+FROM/i',
            $body
        ) === 1;

        $this->assertTrue(
            $accepts_param || $refetches_internally,
            'enqueue_notification_on_update должен либо принимать '
            . 'новый параметр $new_cashback_after_trigger, либо рефетчить '
            . 'cashback из БД (SELECT cashback FROM …) — иначе '
            . 'transaction_data_changed payload будет врать о new_cashback'
        );
    }

    // =========================================================================
    // 2. caller рефетчит cashback после $wpdb->update и передаёт в helper
    // =========================================================================

    public function test_sync_update_local_refetches_cashback_after_update(): void
    {
        $src = $this->api_client_src();

        // Грубая локализация: ищем место в файле, где идёт sync UPDATE +
        // вызов enqueue_notification_on_update. Между ними должен быть
        // SELECT cashback (рефетч после триггерного пересчёта).
        $enqueue_pos = strpos($src, '$this->enqueue_notification_on_update');
        $this->assertNotFalse(
            $enqueue_pos,
            'Должен существовать вызов enqueue_notification_on_update'
        );

        // Берём 4000 байт ДО вызова — sync_update_local logic.
        $window_start = max(0, $enqueue_pos - 4000);
        $window       = substr($src, $window_start, $enqueue_pos - $window_start);

        $this->assertMatchesRegularExpression(
            '/SELECT\s+cashback\s+FROM/i',
            $window,
            'caller (sync_update_local) должен SELECT-ить свежий cashback '
            . 'из БД ПОСЛЕ $wpdb->update — DB-триггер пересчитал поле, '
            . 'старое значение из $local стало stale.'
        );
    }

    /**
     * Codex round 9 (2026-05-10): refetch может вернуть null (SQL-сбой,
     * deadlock, lock-wait timeout, гонка с удалением строки). Старый код
     * откатывался на `$old_cashback` — это reintroduce'ит тот самый Bug #2,
     * который мы только что починили (silent-lying payload). Тест требует
     * fail-closed поведения: при null helper НЕ должен подставлять old_cashback,
     * а должен либо пропустить enqueue либо явно сигналить ошибку.
     */
    public function test_helper_does_not_fall_back_to_old_cashback_on_null_refetch(): void
    {
        $body = $this->extract_private_method_body(
            $this->api_client_src(),
            'enqueue_notification_on_update'
        );

        // Запрещаем тернарник вида
        // `$new_cashback = $X !== null ? ... : $old_cashback`
        // — это и есть тот самый silent-fallback который ругает Codex round 9.
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$new_cashback\\s*=\\s*\\\$new_cashback_after_trigger\\s*!==\\s*null[\\s\\S]{0,200}:\\s*\\\$old_cashback/",
            $body,
            'enqueue_notification_on_update НЕ должен fallback на $old_cashback ' .
            'когда $new_cashback_after_trigger === null — это reintroduce\'ит ' .
            'silent-lying payload (Codex round 9). Вместо этого: skip enqueue + error_log.'
        );

        // И положительный признак fail-closed: helper при null должен
        // либо логировать диагностику, либо явно return'ить из data_changed
        // ветки. Регулярка — наличие либо error_log с "cashback", либо
        // паттерна `if ($new_cashback_after_trigger === null) ... return`.
        $has_diagnostic = preg_match(
            "/error_log\\s*\\(\\s*[^\\)]*cashback[^\\)]*\\)/i",
            $body
        ) === 1;

        $has_null_guard = preg_match(
            "/\\\$new_cashback_after_trigger\\s*===\\s*null[\\s\\S]{0,200}return\\s*;/",
            $body
        ) === 1;

        $this->assertTrue(
            $has_diagnostic || $has_null_guard,
            'helper должен иметь fail-closed branch на null-refetch: ' .
            'либо error_log диагностику, либо явный `=== null) return;`. ' .
            'Без этого Codex round 9 silent-degradation остаётся открытым.'
        );
    }

    /**
     * Codex round 10 (2026-05-10): refetch — часть transactional success
     * criteria. Если SELECT упал (lock-wait timeout, deadlock) — нельзя
     * COMMIT'ить UPDATE и идти в enqueue с `null` (silent loss). Caller
     * обязан зеркалить существующий error-handling после $wpdb->update:
     * `$wpdb->last_error` check + `throw_if_deadlock` + ROLLBACK +
     * `++$update_errors` + `return`.
     */
    public function test_caller_checks_last_error_after_cashback_refetch(): void
    {
        $src = $this->api_client_src();

        $enqueue_pos = strpos($src, '$this->enqueue_notification_on_update');
        $this->assertNotFalse(
            $enqueue_pos,
            'enqueue_notification_on_update должен вызываться'
        );

        // Берём окно от 4000 байт ДО enqueue (там должен быть refetch + error handling).
        // Codex round 16: после введения counter ordering + post-refetch ++$updated
        // окно расширено чтобы покрыть всю rollback-capable секцию.
        $window_start = max(0, $enqueue_pos - 4000);
        $window       = substr($src, $window_start, $enqueue_pos - $window_start);

        // SELECT cashback должен присутствовать (round 8 invariant).
        $select_pos = strrpos($window, 'SELECT cashback FROM');
        $this->assertNotFalse(
            $select_pos,
            'caller должен SELECT-ить cashback из БД ПОСЛЕ $wpdb->update'
        );

        // ПОСЛЕ SELECT cashback и ДО enqueue должны быть error-handling
        // паттерны: last_error check, throw_if_deadlock, ROLLBACK,
        // ++update_errors. Берём фрагмент после SELECT.
        $tail = substr($window, $select_pos);

        $this->assertMatchesRegularExpression(
            '/\$wpdb->last_error/',
            $tail,
            'Caller ДОЛЖЕН проверить $wpdb->last_error после SELECT cashback — '
            . 'без этого refetch failure конвертируется в silent loss '
            . 'transaction_data_changed уведомления (Codex round 10 HIGH).'
        );

        $this->assertMatchesRegularExpression(
            '/throw_if_deadlock\s*\(\s*\$wpdb\s*\)/',
            $tail,
            'Caller ДОЛЖЕН вызвать throw_if_deadlock($wpdb) после refetch — '
            . 'deadlock/lock-wait должен ре-выбрасываться для retry на верхнем '
            . 'уровне, не глотаться (Codex round 10 HIGH).'
        );

        $this->assertMatchesRegularExpression(
            '/\$wpdb->query\s*\(\s*[\'"]ROLLBACK[\'"]\s*\)/',
            $tail,
            'Caller ДОЛЖЕН ROLLBACK\'ить TX при refetch failure — без этого '
            . 'UPDATE+trigger пересчёт коммитится, но уведомление теряется.'
        );

        $this->assertMatchesRegularExpression(
            '/\+\+\$update_errors/',
            $tail,
            'Caller ДОЛЖЕН инкрементить $update_errors на refetch failure '
            . '— без этого ошибка не учитывается в sync-статистике, и retry '
            . 'не сработает.'
        );
    }

    /**
     * Codex round 13 (2026-05-10): refetch + ROLLBACK сейчас выполняется
     * безусловно после $wpdb->update. Status-only sync (status_changed=true,
     * commission/cart=false) не нуждается в refetched cashback — helper'у он
     * не передаётся (status_changed branch выходит раньше). Но transient
     * SELECT failure после валидного status update теперь rollback'ает всю
     * транзакцию. refetch ДОЛЖЕН быть scoped к ветке, которой он реально
     * нужен: !$status_changed && ($commission_changed || $cart_changed).
     */
    public function test_refetch_scoped_to_data_changed_path(): void
    {
        $src = $this->api_client_src();

        $enqueue_pos = strpos($src, '$this->enqueue_notification_on_update');
        $this->assertNotFalse($enqueue_pos);

        // Берём окно ДО enqueue: refetch должен быть обёрнут в условие
        // на data_changed branch.
        $window_start = max(0, $enqueue_pos - 3000);
        $window       = substr($src, $window_start, $enqueue_pos - $window_start);

        $select_pos = strrpos($window, 'SELECT cashback FROM');
        $this->assertNotFalse($select_pos, 'refetch SELECT cashback должен присутствовать');

        // Ищем условие `!$status_changed && ($commission_changed || $cart_changed)`
        // ДО SELECT. Допускаем варианты порядка операндов.
        $head     = substr($window, 0, $select_pos);
        $head_500 = substr($head, max(0, strlen($head) - 600), 600);

        $has_scope_check = preg_match(
            '/!\s*\$status_changed[\s\S]{0,200}\$commission_changed[\s\S]{0,200}\$cart_changed/',
            $head_500
        ) === 1
        || preg_match(
            '/\$commission_changed\s*\|\|\s*\$cart_changed[\s\S]{0,200}!\s*\$status_changed/',
            $head_500
        ) === 1;

        $this->assertTrue(
            $has_scope_check,
            'refetch ДОЛЖЕН быть scoped к ветке !$status_changed && '
            . '($commission_changed || $cart_changed) — иначе transient '
            . 'SELECT failure rollback\'ает status-only sync, который даже '
            . 'не использует refetched cashback (Codex round 13 #2).'
        );
    }

    /**
     * Codex round 16 (2026-05-10): `++$updated` ДОЛЖЕН быть после refetch
     * block, а не сразу после `$wpdb->update`. Иначе rollback в refetch'е
     * (или в reversal-логике выше) не отменяет уже инкрементированный
     * counter — sync stats покажут «successful update» хотя UPDATE откачен.
     * Observability bug → retry/alerting не сработает.
     */
    public function test_updated_counter_after_refetch_block(): void
    {
        $src = $this->api_client_src();

        $select_pos = strpos($src, "'SELECT cashback FROM %i WHERE id = %d'");
        $this->assertNotFalse(
            $select_pos,
            'refetch SELECT должен присутствовать (round 8 invariant)'
        );

        // Берём 1500 байт ДО SELECT — туда не должен попасть `++$updated`.
        $head_window_start = max(0, $select_pos - 2500);
        $head_window       = substr($src, $head_window_start, $select_pos - $head_window_start);

        $this->assertDoesNotMatchRegularExpression(
            '/\+\+\$updated\s*;/',
            $head_window,
            '++$updated НЕ должен быть ДО refetch SELECT cashback. Когда ' .
            'refetch failure делает ROLLBACK + ++$update_errors + return, ' .
            '$updated остался бы инкрементированным от ранее → rolled-back ' .
            'UPDATE счётся как successful (Codex round 16 #2 observability).'
        );

        // ++$updated должен присутствовать в файле ПОСЛЕ refetch блока,
        // ДО enqueue. Берём окно от refetch до enqueue.
        $enqueue_pos = strpos($src, '$this->enqueue_notification_on_update');
        $this->assertNotFalse($enqueue_pos);
        $tail_window = substr($src, $select_pos, $enqueue_pos - $select_pos);

        $this->assertMatchesRegularExpression(
            '/\+\+\$updated\s*;/',
            $tail_window,
            '++$updated должен быть ПОСЛЕ refetch блока (когда все ' .
            'rollback-capable работы прошли) и ДО enqueue.'
        );
    }

    public function test_helper_signature_passes_refetched_cashback(): void
    {
        $src = $this->api_client_src();

        $accepts_param = preg_match(
            '/private\s+function\s+enqueue_notification_on_update\([^)]*\$new_cashback[\w_]*[^)]*\)/',
            $src
        ) === 1;

        $body = $this->extract_private_method_body(
            $src,
            'enqueue_notification_on_update'
        );
        $refetches_internally = preg_match(
            '/SELECT\s+cashback\s+FROM/i',
            $body
        ) === 1;

        if ($accepts_param) {
            // Если helper принимает param — caller должен передавать
            // refetched значение. Достаточно проверить, что вызов
            // содержит >= 11 аргументов (10 текущих + новый).
            $call_pos = strpos($src, '$this->enqueue_notification_on_update');
            $this->assertNotFalse($call_pos);

            // Берём 1500 байт — закрывающая ) вызова.
            $tail = substr($src, $call_pos, 1500);
            $end  = strpos($tail, ');');
            $this->assertNotFalse($end);
            $call = substr($tail, 0, $end);

            // Считаем top-level запятые (грубо, без учёта вложенных скобок —
            // но достаточно для PHP-вызова).
            $depth  = 0;
            $commas = 0;
            for ($i = 0; $i < strlen($call); $i++) {
                $c = $call[ $i ];
                if ($c === '(') {
                    ++$depth;
                } elseif ($c === ')') {
                    --$depth;
                } elseif ($c === ',' && $depth === 1) {
                    ++$commas;
                }
            }
            // 10 запятых = 11 аргументов (включая новый new_cashback).
            $this->assertGreaterThanOrEqual(
                10,
                $commas,
                'caller должен передавать новый аргумент new_cashback в '
                . 'enqueue_notification_on_update (>= 11 параметров)'
            );
        } else {
            $this->assertTrue(
                $refetches_internally,
                'Если helper не принимает param — он обязан рефетчить '
                . 'cashback изнутри (SELECT cashback FROM …)'
            );
        }
    }
}
