<?php
/**
 * Action Scheduler для фоновой синхронизации статусов транзакций
 *
 * Каждые 2 часа запрашивает обновлённые статусы из CPA-сетей
 * и обновляет локальные транзакции. Планирование и concurrency-защита —
 * через Action Scheduler (группа `cashback`). Atomicity sync+accrual
 * и блокировка админских проверок баланса — через Cashback_Lock.
 *
 * @package CashbackPlugin
 * @since   5.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_API_Cron {

    /** @var string Имя хука Action Scheduler */
    const HOOK_NAME = 'cashback_api_sync_statuses';

    /** @var string Группа действий в Action Scheduler (для UI/фильтрации) */
    const AS_GROUP = 'cashback';

    /** @var int Таймаут ожидания глобального lock (секунды). 0 = не ждать для cron */
    const LOCK_WAIT_TIMEOUT = 0;

    /**
     * Инициализация: регистрация хуков и расписания
     */
    public static function init(): void {
        // Регистрация обработчика
        add_action(self::HOOK_NAME, array( self::class, 'run_sync' ));

        // Планирование через Action Scheduler на init (после загрузки WooCommerce).
        add_action('init', array( self::class, 'maybe_schedule' ));
    }

    /**
     * Планирование recurring action в Action Scheduler (вызывается на init).
     *
     * WooCommerce — жёсткая зависимость плагина, AS загружается им автоматически.
     */
    public static function maybe_schedule(): void {
        if (function_exists('as_has_scheduled_action')
            && function_exists('as_schedule_recurring_action')
            && !as_has_scheduled_action(self::HOOK_NAME)
        ) {
            as_schedule_recurring_action(
                time(),
                2 * HOUR_IN_SECONDS,
                self::HOOK_NAME,
                array(),
                self::AS_GROUP
            );
        }
    }

    /**
     * Запуск фоновой синхронизации.
     *
     * АТОМАРНАЯ ОПЕРАЦИЯ: sync + начисление выполняются под глобальным lock.
     * Во время sync все админские проверки баланса блокируются.
     *
     * Вызывается WP Cron или вручную из админки.
     */
    public static function run_sync(): void {
        $start = microtime(true);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('Cashback API Cron: Starting background sync');

        // ═══ ЗАХВАТ ГЛОБАЛЬНОГО LOCK ═══
        // Без lock sync не запускается — это гарантирует:
        // 1) Нет параллельного sync (cron + manual)
        // 2) Нет админских проверок во время sync
        // 3) Начисление атомарно с sync
        if (!Cashback_Lock::acquire(self::LOCK_WAIT_TIMEOUT)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Cron: Could not acquire global lock — another sync or operation is running');
            return;
        }

        // Единый run_id на все 5 этапов — для checkpoint-истории (Group 8 Step 3, F-8-005).
        $run_id = Cashback_Cron_State::begin_run();

        try {
            $client = Cashback_API_Client::get_instance();

            // ─── Этап 1: background_sync (pull API + sync_update_local + decline_stale) ───
            $results  = array();
            $stage_id = Cashback_Cron_State::begin_stage($run_id, 'background_sync');
            try {
                $results = $client->background_sync();

                $elapsed_sync = round(microtime(true) - $start, 2);

                foreach ($results as $network => $result) {
                    if ($result['success']) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            'Cashback API Cron [%s]: total=%d, updated=%d, inserted=%d, skipped=%d, not_found=%d, insert_errors=%d, declined_stale=%d (%.2fs)',
                            $network,
                            $result['total'],
                            $result['updated'],
                            $result['inserted'] ?? 0,
                            $result['skipped'],
                            $result['not_found'],
                            $result['insert_errors'] ?? 0,
                            $result['declined_stale'] ?? 0,
                            $elapsed_sync
                        ));
                    } else {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            'Cashback API Cron [%s]: FAILED — %s',
                            $network,
                            $result['error'] ?? 'Unknown error'
                        ));
                    }
                }

                Cashback_Cron_State::finish_stage(
                    $stage_id,
                    'success',
                    array(
                        'networks'    => array_keys($results),
                        'elapsed_sec' => $elapsed_sync,
                        'summary'     => $results,
                    )
                );
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback API Cron: background_sync exception — ' . $e->getMessage());
                Cashback_Cron_State::finish_stage($stage_id, 'failed', array(), $e->getMessage());
            }

            // ─── Этап 2: auto_transfer — перенос unregistered→registered ───
            $transfer_result = null;
            $stage_id        = Cashback_Cron_State::begin_stage($run_id, 'auto_transfer');
            try {
                $transfer_result = $client->auto_transfer_unregistered(50);
                if ($transfer_result['transferred'] > 0 || $transfer_result['errors'] > 0) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log(sprintf(
                        'Cashback API Cron: auto_transfer: transferred=%d, skipped_duplicate=%d, errors=%d, checked=%d',
                        $transfer_result['transferred'],
                        $transfer_result['skipped_duplicate'],
                        $transfer_result['errors'],
                        $transfer_result['checked']
                    ));
                }
                Cashback_Cron_State::finish_stage(
                    $stage_id,
                    'success',
                    $transfer_result
                );
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback API Cron: auto_transfer exception — ' . $e->getMessage());
                Cashback_Cron_State::finish_stage($stage_id, 'failed', array(), $e->getMessage());
            }

            // ─── Этап 3: process_ready — АТОМАРНОЕ НАЧИСЛЕНИЕ (ВНУТРИ LOCK) ───
            $accrual_result = null;
            $stage_id       = Cashback_Cron_State::begin_stage($run_id, 'process_ready');
            try {
                $accrual_result = Mariadb_Plugin::process_ready_transactions();
                if (!empty($accrual_result['errors'])) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log('Cashback API Cron: accrual errors — ' . implode('; ', $accrual_result['errors']));
                } elseif ($accrual_result['processed'] > 0) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log(sprintf(
                        'Cashback API Cron: accrual processed=%d, ledger_inserted=%d',
                        $accrual_result['processed'],
                        $accrual_result['ledger_inserted']
                    ));
                }
                Cashback_Cron_State::finish_stage(
                    $stage_id,
                    empty($accrual_result['errors']) ? 'success' : 'failed',
                    $accrual_result,
                    ! empty($accrual_result['errors']) ? implode('; ', (array) $accrual_result['errors']) : ''
                );
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback API Cron: process_ready_transactions exception — ' . $e->getMessage());
                Cashback_Cron_State::finish_stage($stage_id, 'failed', array(), $e->getMessage());
            }

            // ─── Этап 4: affiliate_pending — синхронизация pending-начислений ───
            $stage_id = Cashback_Cron_State::begin_stage($run_id, 'affiliate_pending');
            if (class_exists('Cashback_Affiliate_DB')
                && Cashback_Affiliate_DB::is_module_enabled()
                && class_exists('Cashback_Affiliate_Service')
            ) {
                try {
                    $aff_pending = Cashback_Affiliate_Service::sync_pending_accruals();
                    if ($aff_pending['created'] > 0 || $aff_pending['updated'] > 0) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            'Cashback API Cron: affiliate pending sync: created=%d, updated=%d',
                            $aff_pending['created'],
                            $aff_pending['updated']
                        ));
                    }
                    Cashback_Cron_State::finish_stage(
                        $stage_id,
                        'success',
                        $aff_pending
                    );
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log('Cashback API Cron: affiliate pending sync exception — ' . $e->getMessage());
                    Cashback_Cron_State::finish_stage($stage_id, 'failed', array(), $e->getMessage());
                }
            } else {
                Cashback_Cron_State::finish_stage(
                    $stage_id,
                    'success',
                    array( 'skipped' => 'affiliate module disabled or unavailable' )
                );
            }

            // ─── Этап 5: check_campaigns — статусы кампаний + deactivate/reactivate ───
            $campaign_results = null;
            $stage_id         = Cashback_Cron_State::begin_stage($run_id, 'check_campaigns');
            try {
                $campaign_results = $client->check_campaign_statuses();

                foreach ($campaign_results as $network => $cresult) {
                    if ($cresult['success'] ?? false) {
                        if (( $cresult['deactivated'] ?? 0 ) > 0 || ( $cresult['reactivated'] ?? 0 ) > 0) {
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                            error_log(sprintf(
                                'Cashback API Cron [%s] campaigns: total=%d, deactivated=%d, reactivated=%d, skipped=%d',
                                $network,
                                $cresult['total_campaigns'],
                                $cresult['deactivated'],
                                $cresult['reactivated'],
                                $cresult['skipped']
                            ));
                        }
                    } else {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            'Cashback API Cron [%s] campaign check FAILED: %s',
                            $network,
                            $cresult['error'] ?? 'Unknown'
                        ));
                    }
                }
                Cashback_Cron_State::finish_stage(
                    $stage_id,
                    'success',
                    array( 'per_network' => $campaign_results )
                );
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback API Cron: campaign check exception — ' . $e->getMessage());
                Cashback_Cron_State::finish_stage($stage_id, 'failed', array(), $e->getMessage());
            }

            $elapsed = round(microtime(true) - $start, 2);

            // Сохраняем результат последней синхронизации для отображения в админке
            update_option('cashback_last_sync_result', array(
                'timestamp'        => current_time('mysql'),
                'elapsed'          => $elapsed,
                'run_id'           => $run_id,
                'results'          => $results,
                'auto_transferred' => $transfer_result,
                'accrual'          => $accrual_result,
                'campaign_check'   => $campaign_results,
            ));
        } catch (Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Cron: Exception — ' . $e->getMessage());
        } finally {
            // ═══ ОСВОБОЖДЕНИЕ LOCK ═══
            Cashback_Lock::release();
        }
    }

    /**
     * Деактивация: снять запланированные действия при деактивации плагина.
     *
     * Снимает как AS actions (после миграции), так и WP-Cron события
     * (legacy — могут остаться от старых версий плагина).
     */
    public static function deactivate(): void {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK_NAME, array(), self::AS_GROUP);
        }

        $timestamp = wp_next_scheduled(self::HOOK_NAME);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_NAME);
        }
        wp_clear_scheduled_hook(self::HOOK_NAME);
    }

    /**
     * Ручной запуск синхронизации (из админки).
     *
     * Использует глобальный lock — если sync уже идёт (cron), вернёт ошибку.
     *
     * @return array Результаты синхронизации
     */
    public static function manual_sync(): array {
        // Проверяем lock через Cashback_Lock (заменяет transient)
        if (Cashback_Lock::is_lock_active()) {
            return array(
				'locked'  => true,
				'message' => 'Синхронизация уже выполняется',
			);
        }

        // Запоминаем timestamp до sync для проверки что результат свежий
        $before_sync = time();

        // run_sync() сам захватывает и освобождает lock
        self::run_sync();

        $result = get_option('cashback_last_sync_result', array());

        // Проверяем что sync реально отработал (lock мог не захватиться из-за race condition)
        if (empty($result['timestamp']) || strtotime($result['timestamp']) < $before_sync) {
            return array(
				'locked'  => true,
				'message' => 'Не удалось запустить синхронизацию — повторите попытку',
			);
        }

        return $result;
    }
}
