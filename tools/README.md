# `tools/` — one-shot maintenance scripts

Скрипты в этой директории НЕ подключаются при обычной загрузке плагина.
Запуск — только через WP-CLI `wp eval-file <path>`:

```bash
wp eval-file wp-content/plugins/cash-back/tools/dedup-rows-fraud-device-ids.php
wp eval-file wp-content/plugins/cash-back/tools/dedup-rows-claims.php
wp eval-file wp-content/plugins/cash-back/tools/dedup-rows-affiliate-networks.php
```

По умолчанию все скрипты работают в **dry-run**: читают БД, печатают отчёт о
группах дублей, не изменяют данные. Для реального прогона передайте
positional-аргумент `--confirm=yes` (WP-CLI пробрасывает positional args как `$args[]`):

```bash
wp eval-file .../dedup-rows-fraud-device-ids.php --confirm=yes --limit=500
```

Флаги (parseable из `$args`):

- `--confirm=yes` — разрешить UPDATE/DELETE. Без него — dry-run.
- `--limit=N` — обработать максимум N групп дублей за один запуск. 0 = без лимита.

## Назначение (Группа 6 ADR)

Скрипты подготавливают БД перед наложением UNIQUE-ключей (`ALTER TABLE … ADD UNIQUE KEY …`)
на existing installations. Без дедупликации старых дубликатов ALTER TABLE упадёт.

| Скрипт | Таблица | Ключ дедупа | Финальный UNIQUE (шаг 2 Группы 6) |
|---|---|---|---|
| `dedup-rows-fraud-device-ids.php` | `cashback_fraud_device_ids` | `(user_id, DATE(first_seen), device_id)` | `UNIQUE(user_id, session_date, device_id)` |
| `dedup-rows-claims.php` | `cashback_claims` | `(merchant_id, order_id)` | `UNIQUE(merchant_id, order_id)` |
| `dedup-rows-affiliate-networks.php` | `cashback_affiliate_networks` | `slug` | UNIQUE уже существует; скрипт — legacy safety net |

## Безопасность

- Все destructive операции выполняются под `START TRANSACTION` + `SELECT … FOR UPDATE`.
- При любой SQL-ошибке — `ROLLBACK`, скрипт прерывается с exit-кодом ≠ 0.
- Перед запуском на prod: снять бэкап БД, прогнать на dev-копии в dry-run + confirm-режимах.
