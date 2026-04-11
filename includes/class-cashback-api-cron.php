<?php
/**
 * WP Cron для фоновой синхронизации статусов транзакций
 *
 * Каждые 2 часа запрашивает обновлённые статусы из CPA-сетей
 * и обновляет локальные транзакции.
 *
 * @package CashbackPlugin
 * @since   5.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_API_Cron
{
    /** @var string Имя cron-хука */
    const HOOK_NAME = 'cashback_api_sync_statuses';

    /** @var string Интервал */
    const INTERVAL_NAME = 'cashback_every_2_hours';

    /** @var int Таймаут ожидания глобального lock (секунды). 0 = не ждать для cron */
    const LOCK_WAIT_TIMEOUT = 0;

    /**
     * Инициализация: регистрация хуков и расписания
     */
    public static function init(): void
    {
        // Регистрация кастомного интервала
        add_filter('cron_schedules', [self::class, 'add_cron_interval']);

        // Регистрация обработчика
        add_action(self::HOOK_NAME, [self::class, 'run_sync']);

        // Планирование если не запланировано
        if (!wp_next_scheduled(self::HOOK_NAME)) {
            wp_schedule_event(time(), self::INTERVAL_NAME, self::HOOK_NAME);
        }
    }

    /**
     * Добавить кастомный интервал 2 часа
     *
     * @param array $schedules
     * @return array
     */
    public static function add_cron_interval(array $schedules): array
    {
        $schedules[self::INTERVAL_NAME] = [
            'interval' => 2 * HOUR_IN_SECONDS,
            'display'  => __('Каждые 2 часа (кэшбэк синхронизация)', 'cashback-plugin'),
        ];
        return $schedules;
    }

    /**
     * Запуск фоновой синхронизации.
     *
     * АТОМАРНАЯ ОПЕРАЦИЯ: sync + начисление выполняются под глобальным lock.
     * Во время sync все админские проверки баланса блокируются.
     *
     * Вызывается WP Cron или вручную из админки.
     */
    public static function run_sync(): void
    {
        $start = microtime(true);

        error_log('Cashback API Cron: Starting background sync');

        // ═══ ЗАХВАТ ГЛОБАЛЬНОГО LOCK ═══
        // Без lock sync не запускается — это гарантирует:
        // 1) Нет параллельного sync (cron + manual)
        // 2) Нет админских проверок во время sync
        // 3) Начисление атомарно с sync
        if (!Cashback_Lock::acquire(self::LOCK_WAIT_TIMEOUT)) {
            error_log('Cashback API Cron: Could not acquire global lock — another sync or operation is running');
            return;
        }

        try {
            $client  = Cashback_API_Client::get_instance();
            $results = $client->background_sync();

            $elapsed_sync = round(microtime(true) - $start, 2);

            foreach ($results as $network => $result) {
                if ($result['success']) {
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
                    error_log(sprintf(
                        'Cashback API Cron [%s]: FAILED — %s',
                        $network,
                        $result['error'] ?? 'Unknown error'
                    ));
                }
            }

            // Автоматический перенос незарегистрированных транзакций к реальным пользователям
            $transfer_result = null;
            try {
                $transfer_result = $client->auto_transfer_unregistered(50);
                if ($transfer_result['transferred'] > 0 || $transfer_result['errors'] > 0) {
                    error_log(sprintf(
                        'Cashback API Cron: auto_transfer: transferred=%d, skipped_duplicate=%d, errors=%d, checked=%d',
                        $transfer_result['transferred'],
                        $transfer_result['skipped_duplicate'],
                        $transfer_result['errors'],
                        $transfer_result['checked']
                    ));
                }
            } catch (Exception $e) {
                error_log('Cashback API Cron: auto_transfer exception — ' . $e->getMessage());
            }

            // ═══ АТОМАРНОЕ НАЧИСЛЕНИЕ (ВНУТРИ LOCK) ═══
            // Начисление НЕРАЗРЫВНО с sync — одна операция.
            // process_ready_transactions проверяет наличие lock.
            $accrual_result = null;
            try {
                $accrual_result = Mariadb_Plugin::process_ready_transactions();
                if (!empty($accrual_result['errors'])) {
                    error_log('Cashback API Cron: accrual errors — ' . implode('; ', $accrual_result['errors']));
                } elseif ($accrual_result['processed'] > 0) {
                    error_log(sprintf(
                        'Cashback API Cron: accrual processed=%d, ledger_inserted=%d',
                        $accrual_result['processed'],
                        $accrual_result['ledger_inserted']
                    ));
                }
            } catch (Exception $e) {
                error_log('Cashback API Cron: process_ready_transactions exception — ' . $e->getMessage());
            }

            // Синхронизация pending-начислений партнёрской программы
            if (class_exists('Cashback_Affiliate_DB')
                && Cashback_Affiliate_DB::is_module_enabled()
                && class_exists('Cashback_Affiliate_Service')
            ) {
                try {
                    $aff_pending = Cashback_Affiliate_Service::sync_pending_accruals();
                    if ($aff_pending['created'] > 0 || $aff_pending['updated'] > 0) {
                        error_log(sprintf(
                            'Cashback API Cron: affiliate pending sync: created=%d, updated=%d',
                            $aff_pending['created'],
                            $aff_pending['updated']
                        ));
                    }
                } catch (Exception $e) {
                    error_log('Cashback API Cron: affiliate pending sync exception — ' . $e->getMessage());
                }
            }

            // Проверка статусов кампаний и авто-деактивация/реактивация магазинов
            $campaign_results = null;
            try {
                $campaign_results = $client->check_campaign_statuses();

                foreach ($campaign_results as $network => $cresult) {
                    if ($cresult['success'] ?? false) {
                        if (($cresult['deactivated'] ?? 0) > 0 || ($cresult['reactivated'] ?? 0) > 0) {
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
                        error_log(sprintf(
                            'Cashback API Cron [%s] campaign check FAILED: %s',
                            $network,
                            $cresult['error'] ?? 'Unknown'
                        ));
                    }
                }
            } catch (Exception $e) {
                error_log('Cashback API Cron: campaign check exception — ' . $e->getMessage());
            }

            $elapsed = round(microtime(true) - $start, 2);

            // Сохраняем результат последней синхронизации для отображения в админке
            update_option('cashback_last_sync_result', [
                'timestamp'        => current_time('mysql'),
                'elapsed'          => $elapsed,
                'results'          => $results,
                'auto_transferred' => $transfer_result,
                'accrual'          => $accrual_result,
                'campaign_check'   => $campaign_results,
            ]);
        } catch (Exception $e) {
            error_log('Cashback API Cron: Exception — ' . $e->getMessage());
        } finally {
            // ═══ ОСВОБОЖДЕНИЕ LOCK ═══
            Cashback_Lock::release();
        }
    }

    /**
     * Деактивация: снять cron при деактивации плагина
     */
    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK_NAME);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_NAME);
        }
    }

    /**
     * Ручной запуск синхронизации (из админки).
     *
     * Использует глобальный lock — если sync уже идёт (cron), вернёт ошибку.
     *
     * @return array Результаты синхронизации
     */
    public static function manual_sync(): array
    {
        // Проверяем lock через Cashback_Lock (заменяет transient)
        if (Cashback_Lock::is_lock_active()) {
            return ['locked' => true, 'message' => 'Синхронизация уже выполняется'];
        }

        // Запоминаем timestamp до sync для проверки что результат свежий
        $before_sync = time();

        // run_sync() сам захватывает и освобождает lock
        self::run_sync();

        $result = get_option('cashback_last_sync_result', []);

        // Проверяем что sync реально отработал (lock мог не захватиться из-за race condition)
        if (empty($result['timestamp']) || strtotime($result['timestamp']) < $before_sync) {
            return ['locked' => true, 'message' => 'Не удалось запустить синхронизацию — повторите попытку'];
        }

        return $result;
    }
}
