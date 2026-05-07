<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тест админ-настройки окна API-синхронизации в днях
 * (опция `cashback_api_sync_window_days`).
 *
 * Состоит из трёх частей:
 *
 * 1. Behavioral. `Cashback_API_Client::default_lookback_date_dmy()` читает
 *    значение из опции, применяет clamp [1, 365], дефолт 180. Этот метод
 *    вызывается из `validate_user`, `validate_unregistered` и
 *    `do_background_sync` — следовательно, настройка глобально влияет на
 *    все CPA-сети, текущие и будущие.
 *
 * 2. Functional. AJAX-хендлер `Cashback_Admin_API_Validation::ajax_save_sync_window`
 *    проверяет capability, валидирует диапазон, сохраняет значение в опцию.
 *
 * 3. Structural. UI-блок (`<input id="cashback-sync-window-days">` + кнопка
 *    `#cashback-save-sync-window`) присутствует в `render_sync_tab()`, и AJAX
 *    action `wp_ajax_cashback_save_sync_window` зарегистрирован в конструкторе
 *    (защита от регрессии — кто-нибудь может удалить хук при рефакторинге).
 *
 * @group api-client
 * @group admin
 * @group sync-window
 */
#[Group('api-client')]
#[Group('admin')]
#[Group('sync-window')]
final class SyncWindowSettingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        if (!class_exists('Cashback_API_Client')) {
            require_once $plugin_root . '/includes/class-cashback-api-client.php';
        }
        if (!class_exists('Cashback_Admin_API_Validation')) {
            require_once $plugin_root . '/admin/class-cashback-admin-api-validation.php';
        }
    }

    protected function setUp(): void
    {
        // Чистим состояние, чтобы тесты были изолированы.
        $GLOBALS['_cb_test_options']           = array();
        $GLOBALS['_cb_test_current_user_can']  = true;
        $GLOBALS['_cb_test_last_json_response'] = null;
        unset($_POST['days'], $_POST['nonce']);
    }

    // =====================================================================
    // 1. Behavioral: default_lookback_date_dmy() reads option with clamp
    // =====================================================================

    private function invoke_default_lookback(): string
    {
        $class  = new ReflectionClass('Cashback_API_Client');
        $client = $class->newInstanceWithoutConstructor();
        $method = new ReflectionMethod('Cashback_API_Client', 'default_lookback_date_dmy');
        $method->setAccessible(true);
        return (string) $method->invoke($client);
    }

    private function expected_dmy_for_days(int $days): string
    {
        return ( new DateTimeImmutable('now', new DateTimeZone('UTC')) )
            ->modify('-' . $days . ' days')
            ->format('d.m.Y');
    }

    public function test_default_lookback_uses_180_when_option_missing(): void
    {
        // Опция не задана — должно быть 180 (дефолт).
        $this->assertSame(
            $this->expected_dmy_for_days(180),
            $this->invoke_default_lookback(),
            'Без опции метод должен возвращать today − 180 дней — это дефолт, рекомендованный для Admitad.'
        );
    }

    public function test_default_lookback_reads_admin_configured_value(): void
    {
        update_option('cashback_api_sync_window_days', 30);
        $this->assertSame(
            $this->expected_dmy_for_days(30),
            $this->invoke_default_lookback(),
            'Метод должен читать значение из опции cashback_api_sync_window_days, а не использовать хардкод.'
        );
    }

    public function test_default_lookback_clamps_below_one_to_180_default(): void
    {
        update_option('cashback_api_sync_window_days', 0);
        $this->assertSame(
            $this->expected_dmy_for_days(180),
            $this->invoke_default_lookback(),
            'days < 1 — некорректное значение (нет смысла «синхронизировать за 0 дней»); должен быть fallback на дефолт 180.'
        );
    }

    public function test_default_lookback_clamps_above_365(): void
    {
        update_option('cashback_api_sync_window_days', 9999);
        $this->assertSame(
            $this->expected_dmy_for_days(365),
            $this->invoke_default_lookback(),
            'days > 365 должен быть clamp\'нут до 365 — защита от случайного возврата к 6-летнему окну, которое ронит /statistics/actions/ Admitad.'
        );
    }

    public function test_default_lookback_handles_garbage_string_as_default(): void
    {
        // Если опция случайно сохранена как строка, (int)'abc' = 0 → fallback 180.
        update_option('cashback_api_sync_window_days', 'abc');
        $this->assertSame(
            $this->expected_dmy_for_days(180),
            $this->invoke_default_lookback(),
            'Не-числовое значение опции должно безопасно деградировать в дефолт 180, а не ронять API-клиент.'
        );
    }

    // =====================================================================
    // 2. Functional: ajax_save_sync_window
    // =====================================================================

    private function invoke_ajax_save_sync_window(): array
    {
        $admin = Cashback_Admin_API_Validation::get_instance();
        try {
            $admin->ajax_save_sync_window();
        } catch (Cashback_Test_Halt_Signal $e) {
            // wp_send_json_success/error прерывают выполнение в проде через wp_die;
            // в тестах bootstrap превращает их в Halt-сигнал.
        }
        return (array) ($GLOBALS['_cb_test_last_json_response'] ?? array());
    }

    public function test_ajax_save_sync_window_persists_valid_value(): void
    {
        $_POST['nonce'] = 'test_nonce_cashback_api_validation';
        $_POST['days']  = 30;

        $resp = $this->invoke_ajax_save_sync_window();

        $this->assertTrue((bool) ($resp['success'] ?? false), 'days=30 должно дать success.');
        $this->assertSame(30, $resp['data']['days'] ?? null, 'Ответ должен возвращать сохранённое значение.');
        $this->assertSame(
            30,
            (int) get_option('cashback_api_sync_window_days', 0),
            'AJAX-хендлер должен записать days в опцию cashback_api_sync_window_days.'
        );
    }

    public function test_ajax_save_sync_window_rejects_zero(): void
    {
        $_POST['nonce'] = 'test_nonce_cashback_api_validation';
        $_POST['days']  = 0;

        $resp = $this->invoke_ajax_save_sync_window();

        $this->assertFalse((bool) ($resp['success'] ?? true), 'days=0 должно дать error.');
        $this->assertSame('invalid_range', $resp['data']['code'] ?? null);
        $this->assertArrayNotHasKey('cashback_api_sync_window_days', $GLOBALS['_cb_test_options']);
    }

    public function test_ajax_save_sync_window_rejects_above_365(): void
    {
        $_POST['nonce'] = 'test_nonce_cashback_api_validation';
        $_POST['days']  = 400;

        $resp = $this->invoke_ajax_save_sync_window();

        $this->assertFalse((bool) ($resp['success'] ?? true), 'days=400 должно дать error.');
        $this->assertSame('invalid_range', $resp['data']['code'] ?? null);
        $this->assertArrayNotHasKey('cashback_api_sync_window_days', $GLOBALS['_cb_test_options']);
    }

    public function test_ajax_save_sync_window_requires_manage_options_capability(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = false;

        $_POST['nonce'] = 'test_nonce_cashback_api_validation';
        $_POST['days']  = 30;

        $resp = $this->invoke_ajax_save_sync_window();

        $this->assertFalse((bool) ($resp['success'] ?? true), 'Без manage_options должно быть error.');
        $this->assertArrayNotHasKey('cashback_api_sync_window_days', $GLOBALS['_cb_test_options']);
    }

    // =====================================================================
    // 3. Structural: UI block + AJAX action registered
    // =====================================================================

    public function test_admin_constructor_registers_ajax_action(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $source      = (string) file_get_contents(
            $plugin_root . '/admin/class-cashback-admin-api-validation.php'
        );

        $this->assertStringContainsString(
            "wp_ajax_cashback_save_sync_window",
            $source,
            'Конструктор Cashback_Admin_API_Validation должен регистрировать action wp_ajax_cashback_save_sync_window — '
            . 'без него UI-форма «Окно синхронизации» не сможет сохранить значение.'
        );
    }

    public function test_render_sync_tab_contains_settings_block(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $source      = (string) file_get_contents(
            $plugin_root . '/admin/class-cashback-admin-api-validation.php'
        );

        $this->assertStringContainsString(
            'id="cashback-sync-window-days"',
            $source,
            'Во вкладке «Синхронизация» должно быть поле ввода #cashback-sync-window-days.'
        );
        $this->assertStringContainsString(
            'id="cashback-save-sync-window"',
            $source,
            'Во вкладке «Синхронизация» должна быть кнопка #cashback-save-sync-window.'
        );
    }
}
