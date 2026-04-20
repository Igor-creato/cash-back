# Check.md — Финтех-аудит плагина `cash-back`

> **Единый прерываемый центр security-аудита.** Claude координирует, Codex (gpt-5.4) ищет баги, Claude применяет фиксы. Состояние сохраняется атомарно — аудит можно остановить на любом шаге и продолжить позже.

---

## 1. Назначение

Провести системный финтех-аудит плагина: баги, уязвимости, бэкдоры. Исправления **не должны менять функционал** (zero-functional-change). Стандарт: деньги, PII, платежи, антифрод, шифрование.

## 2. Как запустить (для пользователя)

Короткая команда:

```
/check
```

Альтернативы:

- `/check status` — сводка прогресса без запуска аудита.
- `/check stop` — сохранить state и выйти.
- `/check reset` — сбросить state (не трогает чеклист, только state-блок).
- `/check plan` — после закрытия всех батчей: собрать из `## 11. Open questions` и всех `⚠️ needs-human` находок сгруппированный план рефакторинга с приоритетами и оценкой влияния. Процедура в `## 14. Финальный план рефакторинга`.

Claude сам прочитает `check.md`, определит — стартовать новый батч или продолжить прерванный — и выполнит следующий шаг.

## 3. Как продолжить (инструкция для Claude — читать в начале каждого вызова)

1. Прочитай `check.md` целиком. Обрати внимание на секции `## State`, `## Чеклист файлов`, `## Лог итераций`.
2. Если `state.phase` ∈ {`awaiting_codex`, `applying_fixes`, `verifying`} и `state.current_batch` непустой — **продолжай с этой фазы**, не начинай новый батч. Подробности в секции «Фазы» ниже.
3. Если `state.phase = done_batch` или `current_batch` пуст — возьми следующие **2–10 файлов** из `## Чеклист файлов` со статусом `⬜ pending` по порядку приоритета (P0 → P1 → P2, внутри приоритета — сверху вниз). Суммарно батч ≤ 3500 строк (чтобы промпт Codex-у не раздулся).
4. Обнови статус этих файлов на `🔄 in-review`, заполни `state.current_batch`, `state.iteration = iteration + 1`, `state.phase = awaiting_codex`, `state.last_update = <ISO-время>`, сохрани `check.md` (атомарно — это важная точка возобновления).
5. Сформируй промпт из `## Промпт для Codex (шаблон)` с подстановкой `{{FILES}}` (список относительных путей) и `{{ITERATION_ID}}` (равно `state.iteration`).
6. Вызови скилл `codex:rescue` с этим промптом. Дождись ответа.
7. Когда Codex вернул JSON — следуй `## Промпт для Claude (обработка отчёта)`.
8. Если пользователь написал `/check stop` — только обнови `state.last_update`, сохрани `check.md`, ничего не откатывай.
9. Если пользователь написал `/check status` — выведи сводку по приоритетам (P0: X/Y ✅, Z ⬜, W ⚠️), текущий батч, фазу.
10. Если пользователь написал `/check reset` — обнули `state.current_batch`, поставь `state.phase = done_batch`, **не** трогай статусы файлов в `🔄`/`✅` — только сбрось state-блок.

### Фазы — как возобновиться с каждой

| Фаза             | Что значит                                                              | Что делать при возобновлении                                                                                                   |
| ---------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `awaiting_codex` | Батч отправлен Codex-у, но ответа ещё нет / сессия прервана до парсинга | Повторно отправить `codex:rescue` с тем же промптом и тем же `iteration_id`. Codex идемпотентен по контексту.                  |
| `applying_fixes` | Codex ответил, часть фиксов применена, но не все                        | Прочитать `state.findings_pending` (список ID находок) и применить только их. Уже применённые ID — в `state.findings_applied`. |
| `verifying`      | Фиксы применены, идёт phpstan/phpcs/тесты                               | Повторно запустить верификацию. Если уже зелёная — перейти в `done_batch`.                                                     |
| `done_batch`     | Батч закрыт, коммит сделан (или пропущен)                               | Взять следующий батч (п.3).                                                                                                    |

## 4. Правила

- **Zero-functional-change.** Если фикс меняет публичное API, порядок операций, формат данных, хуки WP, структуру БД, имена опций — **не применяй**, помечай находку `⚠️ needs-human` и пиши в `open_questions`.
- **Батч = 2–3 файла**, максимум 4500 строк суммарно.
- **Fail-closed.** Любая неуверенность → статус `⚠️ needs-human`, а не `✅`.
- **Автокоммит** после успешной верификации батча. Шаблон сообщения:

  ```
  security(<module>): <N> <severity> fixes — iter <iteration>

  Categories: <cat1>, <cat2>
  Files: <file1>, <file2>
  Codex-iter: <iteration_id>

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  ```

  Если в батче **нет** находок — коммит не создаётся, файлы помечаются `✅` без записи в git.

- **Дизайн по проекту** (UUID v7, BCMath, AES-256-GCM, двухуровневый rate limiting, Redis, MariaDB с триггерами) — это **не баги**, не репортить.
- **Не рефакторить соседний код.** Только точечный патч по строкам `line_start..line_end`.
- **Не трогать вендорные/минифицированные файлы** (`purify.min.js`, `vendor/`, `node_modules/`, `wp-plugin-tests/node_modules/`).
- **Obsidian как контекст.** Codex читает `obsidian/atlas/*` и `obsidian/knowledge/patterns/*` чтобы отличать дизайн от багов.
- **Язык отчётов Codex-а:** JSON только, без сопроводительного текста. Язык description/title: русский.

## 5. Чеклист категорий (что ищет Codex)

**Обязательно для каждого файла:**

- **SQL** — `$wpdb->prepare()` везде; `%i` для имён таблиц/колонок; нет интерполяции в `IN (...)`, `ORDER BY`, `LIKE`.
- **Auth/Access** — `current_user_can()` на админ-действиях; `permission_callback` на всех REST-роутах; `check_admin_referer` / `wp_verify_nonce` на POST/AJAX.
- **Sanitize** — все входы через `sanitize_text_field`/`absint`/`wp_kses`/`sanitize_email`/`wp_unslash`.
- **Escape** — все выходы через `esc_html`/`esc_attr`/`esc_url`/`esc_js`/`wp_kses_post`.
- **Crypto** — AES-256-GCM (authenticated), случайный IV, не переиспользуется; HMAC для integrity; нет `mcrypt`/DES/ECB.
- **Rate limiting** — на публичных endpoint-ах, login, formsubmit, withdrawals.
- **Atomicity** — multi-step операции (балансы, выплаты, claims, реферальные начисления) выполнены через `START TRANSACTION` + `COMMIT`/`ROLLBACK`; при любом промежуточном сбое состояние откатывается целиком; нет частичного apply (списан баланс — не создана запись выплаты; обновлён payout — не обновлён transaction).
- **Data integrity** — консистентность между связанными таблицами (balance ↔ transactions ↔ payout_requests); FK-constraints / CHECK / UNIQUE на критических колонках; триггеры-fallback не заменяют транзакции, а подстраховывают их; состояние не может «разбежаться» между строками при любом порядке ошибок.
- **Race conditions** — `SELECT ... FOR UPDATE` на всех read-modify-write в транзакциях баланса/выплат/claims/referrals; явный `START TRANSACTION` ... `COMMIT`; нет read-then-update без блокировки; нет TOCTOU в проверках статуса перед UPDATE.
- **Idempotency** — UUID v7 на запросах, меняющих состояние (выплаты, заявки, реферальные события); unique constraint в БД; повторный POST с тем же idempotency-key не создаёт дубликат и возвращает тот же результат; retry безопасен на всех уровнях (клиент → REST → CPA-API).
- **Fintech-math** — деньги в копейках/целых, либо BCMath; нет `float +/-/*` для сумм; нет integer overflow.
- **Логика** — IDOR (проверка владельца user_id/ownership); mass-assignment; price/amount tampering; negative amounts; replay attacks.
- **Double-spend** — одна выплата не обрабатывается дважды (lock + strict status transitions).
- **Backdoors** — `eval`, `assert(string)`, `create_function`, подозрительные `base64_decode`/`gzinflate`, unexpected external URLs, hardcoded secrets, cron-хуки создающие админов, callback-и на `wp_roles`.
- **Secrets** — нет API-ключей/паролей/токенов в коде; только через `wp-config.php` constants или env.
- **PII в логах** — ФИО, номера карт/кошельков, телефоны, email, IP — не в `error_log`/`debug.log`/exceptions без маскирования.
- **CSRF** — admin-post.php, admin-ajax.php endpoint-ы защищены nonce + capability.
- **XSS (JS)** — `innerHTML` только через DOMPurify; нет `eval`/`new Function`/`document.write`; нет XSS через `data-*` атрибуты.
- **Open redirect** — `wp_safe_redirect` вместо `wp_redirect` при переходах на внешние/user-controlled URL.
- **File I/O** — нет path traversal (`../`), нет неограниченной загрузки файлов, `realpath()` + whitelist для путей.
- **HTTP (outbound)** — `wp_remote_*` с `sslverify=true`, явные `timeout`, проверка статус-кода.
- **Session/Cookie** — `HttpOnly`, `Secure`, `SameSite`; нет секретов в cookies без HMAC.
- **Capabilities** — используются конкретные cap (`manage_cashback`, `edit_cashback_payouts`, ...), а не универсальный `manage_options`.
- **Error leakage** — нет stack trace / SQL-ошибок в ответе пользователю; `WP_DEBUG_DISPLAY = false` в prod.
- **Dependency** — `composer.lock` не содержит known-CVE пакетов (подсказать, если заметно).
- **Fail-closed defaults** — отсутствие config / опции → deny, не allow.
- **Uninstall** — `uninstall.php` удаляет все свои таблицы, опции, cron, transients; не оставляет прав/ролей.

## 6. Промпт для Codex (шаблон — используется как есть, подставляются `{{...}}`)

````
Ты — senior security-аудитор финтех-плагина WordPress/WooCommerce `cash-back`.
Проведи полный security-review указанных файлов на финтех-уровне (деньги, PII, платежи, антифрод).

ФАЙЛЫ ДЛЯ РЕВЬЮ (относительные пути от корня плагина):
{{FILES}}

КОНТЕКСТ ПРОЕКТА (прочитай перед анализом):
- obsidian/atlas/ядро плагина.md
- obsidian/atlas/архитектура системы.md
- obsidian/atlas/база данных.md
- obsidian/knowledge/patterns/idempotency pattern.md
- obsidian/knowledge/patterns/for-update блокировки.md
- obsidian/knowledge/patterns/шифрование детали.md
- obsidian/knowledge/patterns/антифрод система.md
- CLAUDE.md (принципы плагина)

ДИЗАЙН ПРОЕКТА (НЕ РЕПОРТИТЬ как баги):
- UUID v7 для идемпотентности и timestamps
- BCMath / integer-копейки для денег
- AES-256-GCM для шифрования PII (authenticated encryption)
- Двухуровневый rate limiting (Redis + БД)
- FOR UPDATE блокировки в транзакциях выплат
- MariaDB-триггеры для fallback целостности

ОБЯЗАТЕЛЬНЫЕ КАТЕГОРИИ ПРОВЕРКИ:
SQL, Auth/Access, Sanitize, Escape, Crypto,
Atomicity (транзакции all-or-nothing, rollback на ошибке, нет частичного apply между связанными таблицами),
Data-integrity (консистентность balance ↔ transactions ↔ payouts; FK/CHECK/UNIQUE; state machine статусов),
Race (FOR UPDATE на read-modify-write, отсутствие TOCTOU, явный START TRANSACTION...COMMIT),
Idempotency (UUID v7, unique constraints, retry-safe на всех уровнях),
Rate-limit, Fintech-math,
Logic (IDOR/mass-assign/price-tampering/replay), Double-spend, Backdoors, Secrets,
PII-in-logs, CSRF, XSS, Open-redirect, File-I/O, HTTP-outbound, Session/Cookie,
Capabilities, Error-leakage, Fail-closed-defaults, Uninstall-completeness.

ОСОБЫЙ ФОКУС НА ФИНТЕХ-ИНВАРИАНТАХ (проверять в каждом хендлере, меняющем состояние):
- Атомарность: любая многошаговая операция (например «списать баланс → создать выплату → записать audit») либо проходит целиком, либо откатывается целиком. Нет путей, где только часть шагов применена.
- Целостность данных: после любой операции связанные строки согласованы (сумма списаний с баланса = сумма созданных выплат; статус transaction соответствует статусу payout; отсутствует orphaned state).
- Идемпотентность: повторная доставка одного и того же запроса (сеть упала, клиент ретраит) не создаёт дубликат и возвращает тот же результат. UUID v7 / idempotency-key обязаны проверяться ДО записи.
- Гонки: все read-modify-write защищены `SELECT ... FOR UPDATE` или эквивалентным механизмом (unique constraint + ON DUPLICATE KEY / INSERT IGNORE). Нет «сначала проверили статус, потом обновили» без блокировки.

СТРОГИЕ ПРАВИЛА ОТЧЁТА:
1. НЕ редактируй файлы. Только читай и анализируй.
2. Возвращай ТОЛЬКО валидный JSON в единственном блоке ```json ... ```.
3. Схема ответа:
```json
{
  "iteration_id": "{{ITERATION_ID}}",
  "findings": [
    {
      "id": "F-{{ITERATION_ID}}-001",
      "file": "relative/path.php",
      "line_start": 123,
      "line_end": 130,
      "severity": "critical|high|medium|low|info",
      "category": "sql|auth|crypto|race|idempotency|math|logic|backdoor|secrets|pii-log|csrf|xss|open-redirect|file-io|http|session|capability|error-leak|fail-open|uninstall",
      "title": "Краткое название (русский, до 80 символов)",
      "description": "Что не так и почему. Конкретные строки. Русский язык.",
      "impact": "Финансовый/репутационный/правовой/операционный риск — конкретика",
      "suggested_fix": "Минимальный точечный патч. Код-сниппет или unified diff. Сохраняет функционал.",
      "breaks_functionality": false,
      "confidence": "high|medium|low",
      "references": ["CWE-xxx", "OWASP-A0x", "WP-docs-url"]
    }
  ],
  "clean_categories": ["список категорий без находок по этому батчу"],
  "needs_human_review": [
    {
      "file": "path",
      "concern": "архитектурный вопрос, не фиксится без обсуждения",
      "reason": "почему нельзя применить автоматически"
    }
  ]
}
```
4. Если `breaks_functionality = true` — перенеси в `needs_human_review`, не оставляй в `findings`.
5. При сомнениях — `confidence: low`, а не выдумывать.
6. Не дублируй: один баг в одном файле = одна запись.
7. Никакого текста вне JSON-блока.

ПРИОРИТЕТЫ severity:
- critical: утечка денег / массовый RCE / полный обход auth
- high: приватная data leak / CSRF / SQLi в admin / XSS на чужого юзера
- medium: локальный обход / слабая криптография / отсутствие rate-limit
- low: отсутствие defense-in-depth / mild info leak
- info: improvement suggestion без реальной уязвимости
````

## 7. Промпт для Claude (обработка отчёта Codex)

1. **Парсинг.** Извлеки JSON из fenced-блока. Если parse fail — пометь `state.phase = awaiting_codex`, сохрани raw-ответ в `/tmp/codex-iter-{iteration_id}.txt`, попроси Codex повторить с пометкой «предыдущий ответ невалиден».
2. **Сохрани JSON** в `state.codex_findings` (встроить в check.md в свёрнутый блок под `## Лог итераций` для последующих возобновлений).
3. Переведи `state.phase = applying_fixes`, сохрани check.md.
4. **Применяй фиксы по одному** (обновляя `state.findings_applied` после каждого):
   - Для каждой находки с `breaks_functionality=false` и `confidence ∈ {high, medium}`:
     - Прочитай файл вокруг `line_start..line_end` (с контекстом ±20 строк).
     - Применяй `suggested_fix` через `Edit` — точечно.
     - Добавь `id` в `state.findings_applied`.
   - Находки с `breaks_functionality=true` или `confidence=low` → в `state.findings_needs_human`.
5. После обработки всех находок — `state.phase = verifying`, сохрани check.md.
6. **Верификация** (только по изменённым файлам):
   - `rtk composer phpstan -- --memory-limit=1G <изменённые .php>` — сравни число ошибок до и после. Новые ошибки → откатить эти фиксы, пометить `⚠️ needs-human`.
   - `rtk composer phpcs -- <изменённые .php>` — то же сравнение.
   - Для JS-файлов: `rtk pnpm run lint -- <изменённые .js>` (или eslint напрямую).
   - **Тесты:** найди в `development/test/tests/*.php` файлы, чьё имя коррелирует с изменённым модулем (например `cashback-withdrawal.php` → `WithdrawalConcurrencyTest.php`, `PayoutRequestValidationTest.php`). Запусти их: `rtk composer test -- --filter="<TestClass>"`. Падение — откатить, `⚠️ needs-human`.
7. **Коммит** (только если были применены фиксы):
   - `rtk git add <изменённые файлы> check.md`
   - `rtk git commit` с шаблоном сообщения из раздела «Правила».
8. **Обнови чеклист файлов.** Для каждого файла из `current_batch`:
   - Все находки применены и верификация зелёная → `✅ clean`.
   - Есть `⚠️ needs-human` записи или часть откачена → `⚠️ needs-human`, добавь заметку в колонку «Заметки» (и в `open_questions`).
   - В колонку «Найдено» — число всех findings по severity (`2C/1H/0M/0L`).
   - В колонку «Исправлено» — число applied.
   - В колонку «Коммит» — короткий хеш (`git rev-parse --short HEAD`) или `—` если фиксов не было.
   - В колонку «Итерация» — `state.iteration`.
9. **Лог итерации** — добавь запись в `## Лог итераций` (append-only).
10. **Финализация батча:**
    - `state.current_batch = []`, `state.phase = done_batch`, `state.codex_findings = null`, `state.findings_applied = []`, `state.findings_pending = []`, `state.last_update = <ISO>`.
    - Сохрани check.md.
11. **Человеко-читаемая сводка в чат** (обязательно, после применения правок и коммита, до вопроса пользователю).
    Выведи в чат простым понятным русским языком, без JSON и без технического жаргона, короткое резюме батча. Формат — маркированный список, по одному пункту на каждую находку (и applied, и needs-human). Для каждого пункта укажи:
    - **В чём была ошибка** — 1 фраза на человеческом языке (не копируй `title` из Codex, перефразируй так, чтобы понял не-разработчик).
    - **Что именно исправлено** — 1 фраза: какое поведение кода изменилось. Если находка в `needs-human` — напиши «не исправлено, требуется решение: …» и суть вопроса.
    - **К чему могло привести** — 1 фраза про реальный риск (утечка денег / утечка персональных данных / обход авторизации / возможность дубль-выплаты / и т.п.). Без CWE/OWASP-кодов.
    - Файл и строки — в конце пункта, маленьким: `— file.php:123-130`.

    В конце сводки — 1 строка итога: «Применено X из Y находок, Z требуют решения оператора. Коммит: `<short-hash>` (или «коммита нет»)».
    Если в батче 0 находок — выведи одну строку: «По файлам <list> замечаний нет.»

12. **Спроси пользователя:** «Батч iter-N закрыт. Продолжить следующим батчом? (Y/stop/status)». Не продолжай автоматически — финтех-правки требуют человеческого контроля темпа.

## 8. State (машиночитаемый блок — Claude читает/пишет)

```yaml
iteration: 33
phase: done_batch
current_batch: []
codex_session: adfec0a2cd5dad3c5
codex_findings: null
findings_applied: []
findings_pending: []
findings_needs_human: []
last_update: '2026-04-20T15:40:00Z'
open_questions:
  - 'iter-1 · F-1-001: fail-closed guard в save_payout_settings — ждёт решения оператора'
  - 'iter-2 · F-2-001: разрешены ли non-http deep-links (intent://, market://) для внешних товаров? Без ответа режем всё кроме http/https.'
  - 'iter-2 · F-2-002: rate-limit на transient неатомарен — требуется миграция на Redis INCR или отдельную таблицу с INSERT ... ON DUPLICATE KEY UPDATE.'
  - 'iter-2 · F-2-004: uninstall — нужен version-matrix таблиц/triggers/events для безопасного порядка DROP и fail-closed удаления ключа.'
  - 'iter-4 · F-4-001/F-4-004: деньги как float/BCMath в admin-хендлерах — end-to-end миграция на копейки требует продуктового согласования.'
  - 'iter-4 · F-4-002: FOR UPDATE в ручных repair-хендлерах admin-api-validation — нужно решение по частоте параллельных манипуляций.'
  - 'iter-4 · F-4-005: allowlist/https-enforcement для outbound API endpoints — нужно продуктовое решение по допустимым схемам/хостам в dev.'
  - 'iter-6 · F-6-001: нет UNIQUE-ограничения на daily device-сессии в cashback_fraud_device_ids — schema change + миграция + переход record() на INSERT ... ON DUPLICATE KEY UPDATE. Требует ALTER TABLE для существующих установок.'
  - 'iter-6 · needs_human (collector): запись persistent device_id для guest без явного consent — правовой/продуктовый вопрос по юрисдикциям и cookie-consent UX.'
  - 'iter-7 · F-7-001: captcha_server_key хранится plaintext в wp_options. Шифрование ломает формат данных существующих установок — нужна миграция + совместимость plaintext→encrypted.'
  - 'iter-7 · needs_human (ip-intelligence): GeoLite2-ASN.mmdb в /uploads/cashback-fraud/ — проверить deployment-конфиг веб-сервера на блок прямой загрузки .mmdb.'
  - "iter-8 · F-8-001 (H, race): sync_update_local() без FOR UPDATE/транзакции — lost update при параллельных sync/admin-правках. Требует аккуратного добавления lock'а с проверкой на deadlock в reconciliation-контуре."
  - 'iter-8 · F-8-002 (H, atomicity): decline_stale_missing_transactions() массово переводит в declined без FOR UPDATE; в выборку попадает completed. Требует решения: убирать ли completed из auto-decline + как обернуть массовое обновление.'
  - 'iter-8 · F-8-003 (M, math): деньги как float/%f в insert_missing_transaction(). End-to-end миграция на integer minor units = schema change. Пересекается с iter-4 (F-4-001).'
  - 'iter-8 · F-8-004 (M, logic): parse_api_date() срезает timezone регекспами вместо конверсии через DateTimeImmutable+wp_timezone. Фикс меняет интерпретацию существующих дат — нужен план миграции/backfill.'
  - 'iter-8 · F-8-005 (H, atomicity): cron run_sync декларирует атомарность, но шаги sync+transfer+accrual не обёрнуты в единую транзакцию. Требует архитектурного решения: общий transaction boundary vs. явные checkpoints + компенсационные действия.'
  - "iter-9 · F-9-003 (M, fail-open): Cashback_Rate_Limiter::check(unregistered) → allowed=true по умолчанию. Fail-closed ломает реальные вызовы social_email_prompt/social_unlink. Требуется инвентаризация всех callsite'ов и дополнение ACTION_TIERS перед переключением default=deny."
  - 'iter-9 · F-9-005 (M, race): transient rate-limit count неатомарен (дубликат iter-2 F-2-002). Требует Redis INCR или SQL-таблицу с INSERT ... ON DUPLICATE KEY UPDATE.'
  - 'iter-10 · F-10-001 (M, idempotency): POST /activate каждый раз создаёт новый click_id — повторные ретраи дублируют клики. Требует решение по семантике атрибуции: возвращать существующий клик в окне ACTIVATION_WINDOW или всегда новый.'
  - 'iter-10 · F-10-002 (M, rate-limit): /activate использует transient для rate-limit (неатомарно). Миграция на общий Cashback_Rate_Limiter требует согласования tier/лимитов (пересекается с iter-9 F-9-005, iter-2 F-2-002).'
  - 'iter-10 · F-10-003 (M, secrets): server key SmartCaptcha в URL query string. Перевод на POST body требует проверки поддержки POST в Yandex SmartCaptcha API (docs указывают GET /validate).'
  - 'iter-10 · F-10-004 (M, fail-open): CAPTCHA fail-open при ошибке/HTTP≠200/пустом секрете. Переключение на fail-closed — availability vs security trade-off: при сбое Yandex SmartCaptcha серые IP не смогут выполнять critical/write AJAX.'
  - 'iter-10 · needs_human (bot-protection): полная оценка двухуровневого rate-limit требует чтения Cashback_Rate_Limiter (уже закрыт в iter-9) — но атомарность счётчика уже признана открытой (F-9-005).'
  - 'iter-11 · F-11-002 (M, math): calculate_cashback/recalculate_cashback_on_update на float/round. Миграция на BCMath/integer-копейки = end-to-end money change (пересекается с iter-4 F-4-001, iter-8 F-8-003).'
  - 'iter-11 · F-11-003 (H, data-integrity): bfreeze_balance_on_ban морозит available+pending, unfreeze возвращает всё в available — после цикла ban/unban pending становится available. Fix в PHP fallback согласован с MariaDB trigger `tr_freeze_balance_on_ban` — нужна синхронная миграция trigger+fallback и отдельный bucket для ban-freeze vs payout-freeze в frozen_balance.'
  - 'iter-11 · needs_human (consent): автозапись consent для social-auth без собственного checkbox — legal/продуктовое решение по OAuth-основанию согласия.'
  - "iter-12 · F-12-001 (H, http/SSRF): build_api_url() принимает произвольный URL из network_config. Требуется allowlist host'ов + запрет http/приватных IP (пересекается с iter-4 F-4-005)."
  - 'iter-13 · F-13-001 (H, secrets): EPN refresh_token хранится plaintext в wp_options. Шифрование ломает формат данных существующих установок — требуется миграция plaintext→encrypted (одного класса с iter-7 F-7-001 captcha_server_key).'
  - 'iter-13 · F-13-003 (M, idempotency): consume_pending() в handle_confirm сжигает токен ДО confirm_link_finish/email_verify_finish. Требуется двухфазная схема (lock→finish→mark consumed / release on failure) + новые методы в Cashback_Social_Auth_DB + schema-level status поле.'
  - 'iter-13 · F-13-004 (M, logic): create_pending_user_and_link не откатывает wp_insert_user при сбое save_link/save_pending. Компенсация через wp_delete_user затрагивает user_register/affiliate hooks — требует решения по допустимым side-effect стратегиям.'
  - 'iter-13 · needs_human (social-auth-db): UNIQUE-гарантии атомарности social links (provider, subject) — закрывается чтением class-social-auth-db.php в следующем батче.'
  - 'iter-15 · F-15-002 (L, fail-open): Cashback_Social_Auth_Bootstrap::load_files/init молча пропускают отсутствующие файлы/классы. Codex предлагает throw RuntimeException при missing required file. Политическое решение: fail-closed фатал на деплой с неполной поставкой = site-down; fail-open текущий = тихая деградация защитных хендлеров. Требуется решение оператора по tolerance + admin_notice flow.'
  - 'iter-19 · F-19-003 (M, idempotency): Cashback_Claims_Manager::create() не принимает idempotency_key — ретраи после таймаута дают ошибку вместо повторного возврата существующей заявки. Фикс требует новой колонки `idempotency_key` в `cashback_claims` + UNIQUE(user_id, idempotency_key) + миграцию. Zero-functional-change guard — schema change.'
  - 'iter-19 · F-19-004 (M, fail-open): Codex предлагает добавить Cashback_Captcha::verify_token в ajax_submit_claim. Отклонено как ложное срабатывание: Cashback_Bot_Protection::guard_ajax_requests на admin_init prio=1 уже применяет CAPTCHA для claims_submit (write tier) на серых IP — проектный двухуровневый дизайн (CAPTCHA только для grey IP, не для всех). Запись для памяти: если когда-нибудь отключается bot-protection (cashback_bot_protection_enabled=false), claims_submit теряет CAPTCHA-gate — compensating control отсутствует.'
  - "iter-20 · F-20-001 (M, fail-open): Cashback_Claims_DB::add_constraints() молча пропускает любые ошибки ALTER TABLE (кроме 'Duplicate'). Таблицы могут остаться без FK/CHECK. Codex предлагает throw RuntimeException. Риск: MariaDB version-dependent error strings → throw может закрыть активацию на существующих установках с partial schema. Требует version-matrix error-pattern matcher + план миграции существующих инсталляций."
  - 'iter-20 · F-20-002 (H, logic): Cashback_Claims_Scoring — score_time_factor возвращает 1.0 (максимум) для <1h, antifraud risk score не учитывается как фактор. Codex предлагает WEIGHT_RISK + score_risk_factor + 5-мин штраф. Это продуктовое/антифрод-политическое решение: материально меняет формулу скоринга для всех заявок, затрагивает пороги авто-одобрения. Требует согласования со стороны продукта и антифрод-команды.'
  - 'iter-21 · F-21-002 (M, race): Cashback_Claims_Antifraud::check_order_id_uniqueness() — TOCTOU между SELECT и INSERT, параллельные submits с одним order_id могут пройти. Codex предлагает MySQL GET_LOCK/RELEASE_LOCK в Cashback_Claims_Manager::create() + Antifraud (cross-file, новый lock-примитив). Правильный fix = UNIQUE constraint на order_id (schema change — однороден с iter-19 F-19-003). Требует решения по миграции + политики: глобальный unique или per-network unique.'
  - "iter-22 · F-22-001 (C, idempotency): Cashback_Affiliate_Service::process_affiliate_commissions() накапливает $balance_deltas ДО попытки UPDATE/INSERT в accruals и безусловно применяет их к available_balance, хотя ledger идемпотентен (ON DUPLICATE KEY UPDATE id=id). Повторный запуск batch → двойное начисление доступного баланса рефереру при неизменном ledger. Прямая утечка денег. Fix требует перестройки tracking'а на effective_deltas (только новые ledger-строки) + per-row контроля affected_rows — money-path surgery."
  - "iter-22 · F-22-002 (H, race/atomicity): process_affiliate_commissions() делает multi-step update/insert/balance без START TRANSACTION. Связан с F-22-001 — без совместного fix'а ROLLBACK даёт «atomic double-credit». Требует совместного рефакторинга + FOR UPDATE на cashback_user_balance для рефереров."
  - 'iter-22 · F-22-003 (H, logic): IP-transient fallback в bind_referral_on_registration() позволяет любой регистрации с того же NAT/прокси в пределах TTL присвоить чужую реферальную атрибуцию без подписанной cookie. Удаление fallback = легитимные пользователи без cookie теряют атрибуцию. Требуется продуктовое решение: (1) удалить fallback, (2) добавить browser-token в ключ transient, (3) оставить как есть (с накопленным риском).'
  - 'iter-22 · F-22-005 (H, race): re_freeze_after_unban() выполняет read-modify-write на affiliate profile + user_balance без FOR UPDATE/транзакции; idempotency_key зависит от time() → повтор хендлера создаёт новые freeze-записи. Пересекается с iter-11 F-11-003 (bucket-разделение ban-freeze vs payout-freeze в frozen_balance) — решение должно быть согласованным на уровне модели frozen_balance.'
  - 'iter-23 · F-23-003 (M, logic): handle_bulk_update_affiliate_commission обновляет global_rate через update_option() ПОСЛЕ COMMIT MySQL-транзакции. Существующий комментарий признаёт trade-off. Перенос в TX требует политики инвалидации wp_cache при ROLLBACK (cache set но БД откатилась) — архитектурное решение.'
  - 'iter-23 · F-23-004 (L, idempotency): process_queue() без claim-lock — параллельные воркеры дают дубли писем. Фикс = schema change (колонка processing_token/attempts), т.к. SELECT FOR UPDATE удерживал бы row-lock на время SMTP. Пересекается с общим вопросом retry-policy для notification queue.'
  - 'iter-23 · F-23-005 (L, fail-open): process_queue() помечает items processed=1 без проверки результата send() — тихая потеря писем при сбое SMTP. Фикс = cascading refactor сигнатур on_*() методов + schema columns attempts/last_error для retry с ограничением. Связан с F-23-004 (общая модель retry очереди).'
  - 'iter-26 · F-26-002 (L, race): handle_add_network в partner-management — TOCTOU на проверке slug/name до INSERT. Правильный fix требует UNIQUE KEY на cashback_partner_networks (slug) + обработку duplicate error. Schema change на существующих установках требует data-migration для возможных duplicate rows — zero-functional-change guard.'
  - 'iter-27 · F-27-002 (L, idempotency): handle_admin_reply не принимает request_id / idempotency-key. Повторная доставка POST создаёт дубль сообщения и дублирующее email-уведомление. Fix требует ALTER TABLE cashback_support_messages ADD COLUMN request_id CHAR(36) + UNIQUE KEY — schema change, однороден с iter-19 F-19-003 / iter-21 F-21-002.'
  - 'iter-28 · F-28-001 (H, race): handle_user_unban вызывает Cashback_Affiliate_Service::re_freeze_after_unban ПОСЛЕ COMMIT транзакции, открывая race-окно, в котором affiliate-средства доступны к выводу до повторной заморозки. Тесно связан с iter-22 F-22-005 (re_freeze_after_unban без FOR UPDATE + time()-idempotency). Требует нового атомарного метода re_freeze_after_unban_atomic(), работающего под той же TX, и согласованного bucket-разделения ban-freeze vs payout-freeze в frozen_balance (iter-11 F-11-003).'
  - 'iter-30 · F-30-002 (M, race): cashback_contact_submit использует transient get/set для rate-limit IP — неатомарно, параллельные запросы обходят лимит 3/час. Дубликат iter-2 F-2-002 / iter-9 F-9-005 / iter-10 F-10-002. Fix = общая архитектура rate-limit (Redis INCR или SQL upsert-таблица) вместо transient.'
  - 'iter-32 · F-32-004 (M, idempotency): admin-payouts.js теперь отправляет request_id, но серверный handle_update_payout_request (admin/payouts.php) не делает дедупликацию по нему. JS защищает от двойного клика через disabled btn, для полной retry-safe поведения требуется парный server-side дедуп (transient cb_admin_payout_idem_{user}_{request_id}). admin/payouts.php уже закрыт в iter-3 — отдельный раунд для серверного слоя.'
  - 'iter-33 · F-33-003 (H, xss): admin-claims.js вставляет серверные HTML-фрагменты (res.data.html) через .html() на 4 sink-ах — безопасность зависит от серверного экранирования claim.comment/order_id/merchant-полей в claims-admin/claims-frontend/claims-db render callbacks и AJAX-хэндлерах. JS-обёртка safeHtml() + DOMPurify — только defense-in-depth и работает, если DOMPurify enqueued на странице. Требуется: (1) аудит серверных HTML-рендеров claims (те же уже ✅ в iter-19/20/21 — но конкретно output-экранирование всех полей в шаблонах требует отдельного прохода), (2) явный enqueue DOMPurify/safe-html на admin-claims страницах.'
  - 'iter-33 · F-33-004 (M, idempotency): admin-claims.js теперь отправляет request_id в claims_submit, claims_admin_transition, claims_admin_add_note — но серверный слой (Cashback_Claims_Manager::create, ajax_claims_admin_transition, ajax_claims_admin_add_note в claims-manager.php / claims-admin.php) ещё не делает дедупликацию по request_id. Для полной retry-safe семантики нужна парная server-side проверка (transient/уникальный ключ). Пересекается с iter-19 F-19-003 (schema-level idempotency_key для cashback_claims).'
```

## 9. Лог итераций

> Apppend-only. Claude добавляет запись после каждого закрытого батча.

<!-- ITERATIONS:START -->

### iter-1 · 2026-04-20

- **Батч:** `cashback-plugin.php` (887), `cashback-withdrawal.php` (1817) — 2704 строк
- **Codex session:** `afd7c62b0c89d1d30`
- **Findings:** 1H + 1L (+1 needs_human_review по uninstall-scope)
- **Applied:** F-1-002 (chmod 0600 на файл ключа шифрования) — `cashback-plugin.php:828-842`
- **Needs-human:** F-1-001 (fail-closed guard в `save_payout_settings` при недоступном шифровании) — требует одобрения оператора на материальное изменение user-facing поведения в misconfigured env
- **Верификация:** phpstan `No errors`; phpcs без новых нарушений (CRLF на стр.1 — pre-existing baseline); PHPUnit не запускался (composer-скрипт отсутствует, требуется отдельный WP-test env)
- **Коммит:** `f72694d`

<details><summary>Codex JSON iter-1</summary>

```json
{
  "iteration_id": 1,
  "findings": [
    {
      "id": "F-1-001",
      "file": "cashback-withdrawal.php",
      "line_start": 1618,
      "line_end": 1665,
      "severity": "high",
      "category": "fail-open",
      "title": "Реквизиты сохраняются в plaintext при недоступном шифровании",
      "description": "В update_user_payout_details() при отсутствии $encrypted_details код явно переходит в fallback и записывает payout_account в открытом виде (1662-1665). В save_payout_settings() шифрование выполняется только при class_exists('Cashback_Encryption') && Cashback_Encryption::is_configured() (1324-1338), то есть при сбое конфигурации ключа/класса сохранение не блокируется. Дополнительно в create_withdrawal_request() plaintext payout_account копируется в cashback_payout_requests, если encrypted_details отсутствует (1001-1004).",
      "impact": "Компрометация БД/дампа → утечка банковских реквизитов/телефонов. Регуляторные риски + мошенничество.",
      "suggested_fix": "Fail-closed: при !Cashback_Encryption::is_configured() возвращать wp_send_json_error и не сохранять plaintext.",
      "breaks_functionality": false,
      "confidence": "high",
      "references": ["CWE-311", "OWASP-A02"]
    },
    {
      "id": "F-1-002",
      "file": "cashback-plugin.php",
      "line_start": 799,
      "line_end": 840,
      "severity": "low",
      "category": "secrets",
      "title": "Файл ключа шифрования создается без принудительных прав 0600",
      "description": "Ключ пишется через file_put_contents(LOCK_EX) но права доступа не ограничиваются 0600 — при небезопасном umask файл может быть доступен лишним процессам.",
      "impact": "Чтение файла ключа любым локальным процессом → расшифровка всех PII.",
      "suggested_fix": "chmod($key_file, 0600) сразу после успешной записи.",
      "breaks_functionality": false,
      "confidence": "medium",
      "references": ["CWE-732", "OWASP-A02"]
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "race",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall"
  ],
  "needs_human_review": [
    {
      "file": "cashback-plugin.php",
      "concern": "Поток uninstall не подтвержден в проверенных файлах",
      "reason": "register_uninstall_hook() и cleanup ключа/PII видно только в uninstall.php, который не входил в батч."
    }
  ]
}
```

</details>

### iter-2 · 2026-04-20

- **Батч:** `wc-affiliate-url-params.php` (1923), `uninstall.php` (310) — 2233 строк
- **Codex session:** `a285f167ea65d150e`
- **Findings:** 0C / 0H / 4M / 0L (+ 2 needs_human_review)
- **Applied:** F-2-003 (symlink-safe cleanup support-uploads, containment check относительно realpath support-каталога) — `uninstall.php:138-175`
- **Needs-human:**
  - F-2-001 — open-redirect fallback (non-http deep-links) в `wc-affiliate-url-params.php:771-913`; требуется продуктовое решение по разрешённым схемам
  - F-2-002 — неатомарный transient rate-limit в `wc-affiliate-url-params.php:1478-1495`; требуется архитектурное изменение (Redis INCR или SQL upsert-таблица)
  - F-2-004 — uninstall не fail-closed + возможный неверный порядок `array_reverse($tables)` в `uninstall.php:55-299`; требуется version-matrix таблиц перед патчем
- **Верификация:** phpstan `No errors`; phpcs без нарушений; PHPUnit не запускался (нет composer-скрипта)
- **Коммит:** `674a9cf`

<details><summary>Codex JSON iter-2</summary>

```json
{
  "iteration_id": 2,
  "findings": [
    {
      "id": "F-2-001",
      "file": "wc-affiliate-url-params.php",
      "line_start": 771,
      "line_end": 913,
      "severity": "medium",
      "category": "open-redirect",
      "title": "Fallback redirect обходит проверку допустимых схем URL",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-2-002",
      "file": "wc-affiliate-url-params.php",
      "line_start": 1478,
      "line_end": 1495,
      "severity": "medium",
      "category": "race",
      "title": "Rate-limit на transient неатомарен",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-2-003",
      "file": "uninstall.php",
      "line_start": 141,
      "line_end": 156,
      "severity": "medium",
      "category": "file-io",
      "title": "Uninstall удаляет realpath без проверки symlink/junction",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-2-004",
      "file": "uninstall.php",
      "line_start": 55,
      "line_end": 299,
      "severity": "medium",
      "category": "uninstall",
      "title": "Uninstall не fail-closed и может удалить ключ при частичном фейле",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "needs_human_review": [
    { "file": "uninstall.php", "concern": "Точный порядок удаления по FK и legacy-объектам" },
    {
      "file": "wc-affiliate-url-params.php",
      "concern": "Допустимы ли бизнесом non-http deep links у внешних товаров"
    }
  ]
}
```

</details>

### iter-3 · 2026-04-20

- **Батч:** `mariadb.php` (2230), `admin/payouts.php` (2097) — 4327 строк
- **Codex session:** `ae100c578ef45f473`
- **Findings:** 0C / 1H / 0M / 1L (0 needs_human_review)
- **Applied:**
  - F-3-001 (HIGH, capability/info-leak) — `admin/payouts.php:1521-1527`, `1072-1078`: AJAX-ответы `handle_get_payout_request` и `handle_update_payout_request` теперь unset и `encrypted_details`, и `payout_account`. Доступ к реквизитам остаётся только через защищённый `handle_decrypt_payout_details` (status=processing, отдельный nonce, rate-limit, аудит).
  - F-3-002 (LOW, error-leak) — `mariadb.php:84-89`: wp_die показывает обобщённое сообщение; детальный error_log — только при WP_DEBUG_LOG; wc_get_logger получает структурированный контекст.
- **Needs-human:** —
- **Верификация:** phpstan `No errors`; phpcs — 1 pre-existing baseline warning (CRLF на mariadb.php стр.1, подтверждён до правок через git stash); PHPUnit не запускался (composer-скрипт отсутствует)
- **Коммит:** `0241739`

<details><summary>Codex JSON iter-3</summary>

```json
{
  "iteration_id": "3",
  "findings": [
    {
      "id": "F-3-001",
      "file": "admin/payouts.php",
      "line_start": 1504,
      "line_end": 1527,
      "severity": "high",
      "category": "capability",
      "title": "AJAX отдаёт plaintext реквизиты в обход защищённой расшифровки",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-3-002",
      "file": "mariadb.php",
      "line_start": 82,
      "line_end": 89,
      "severity": "low",
      "category": "error-leak",
      "title": "Активация показывает сырые исключения и пишет путь файла в лог",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "sanitize",
    "escape",
    "crypto",
    "rate-limit",
    "race",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-4 · 2026-04-20

- **Батч:** `admin/bank-management.php` (459), `admin/payout-methods.php` (458), `admin/class-cashback-admin-api-validation.php` (1863) — 2780 строк
- **Codex session:** `a13325f6b00daf076`
- **Findings:** 0C / 0H / 2M / 3L
- **Applied:**
  - F-4-003 (LOW, error-leak) — `admin/class-cashback-admin-api-validation.php`: 5 мест (save network, edit_transaction, add_transaction, overwrite_transaction, check_campaigns_now) теперь показывают admin обобщённое сообщение; сырые `$wpdb->last_error` / exception messages — только в `error_log` при `WP_DEBUG_LOG`. Сохранена ветка «Duplicate» в add_transaction.
- **Needs-human:**
  - F-4-001 (M, math) — `admin/class-cashback-admin-api-validation.php:1116-1415`: floatval/`%f` для денег в ручной сверке (ajax_edit/add/overwrite_transaction). Требует end-to-end замены на целые копейки / BCMath во всех хендлерах — архитектурное изменение.
  - F-4-002 (M, race) — там же: ручные repair-хендлеры выполняют read-modify-write без `START TRANSACTION` + `FOR UPDATE`. Требует обсуждения: насколько часты параллельные манипуляции одной транзакцией из админки, чтобы оправдать добавление локинга в редкие manual-repair пути.
  - F-4-004 (L, math) — `admin/payout-methods.php:215-447`: `cashback_max_withdrawal_amount` как float (step=0.01). Тот же архитектурный вопрос — требует согласованного перевода лимитов в копейки вместе с downstream-сравнениями.
  - F-4-005 (L, http, confidence=low) — `admin/class-cashback-admin-api-validation.php:262-1000`: нет allowlist/https-enforcement на исходящие API-endpoints. Codex confidence=low. Требует продуктового решения: допустимы ли http/приватные адреса в dev-окружениях.
- **Верификация:** phpstan `No errors`; phpcs без нарушений; PHPUnit не запускался (composer-скрипт отсутствует)
- **Коммит:** `94ca719`

<details><summary>Codex JSON iter-4</summary>

```json
{
  "iteration_id": "4",
  "findings": [
    {
      "id": "F-4-001",
      "file": "admin/class-cashback-admin-api-validation.php",
      "line_start": 1116,
      "line_end": 1415,
      "severity": "medium",
      "category": "math",
      "title": "floatval/%f для денег в ручной сверке транзакций",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-4-002",
      "file": "admin/class-cashback-admin-api-validation.php",
      "line_start": 1137,
      "line_end": 1415,
      "severity": "medium",
      "category": "race",
      "title": "Ручные эндпоинты repair без транзакционной блокировки",
      "breaks_functionality": false,
      "confidence": "medium"
    },
    {
      "id": "F-4-003",
      "file": "admin/class-cashback-admin-api-validation.php",
      "line_start": 923,
      "line_end": 1811,
      "severity": "low",
      "category": "error-leak",
      "title": "Admin AJAX возвращают сырые DB/exception тексты",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-4-004",
      "file": "admin/payout-methods.php",
      "line_start": 215,
      "line_end": 447,
      "severity": "low",
      "category": "math",
      "title": "Лимит вывода как float",
      "breaks_functionality": false,
      "confidence": "medium"
    },
    {
      "id": "F-4-005",
      "file": "admin/class-cashback-admin-api-validation.php",
      "line_start": 262,
      "line_end": 1000,
      "severity": "low",
      "category": "http",
      "title": "Нет allowlist/https enforcement на исходящих",
      "breaks_functionality": false,
      "confidence": "low"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "sanitize",
    "escape",
    "crypto",
    "rate-limit",
    "idempotency",
    "logic",
    "double-spend",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "session",
    "capability",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-5 · 2026-04-20

- **Батч:** `antifraud/class-fraud-detector.php` (1116), `antifraud/class-fraud-admin.php` (1753), `antifraud/class-fraud-cluster-detector.php` (784) — 3653 строк
- **Codex session:** `abe285f89f86151ad`
- **Findings:** 0C / 0H / 3M / 0L (0 needs_human_review) — все atomicity/race
- **Applied:**
  - F-5-001 (M, atomicity) — `antifraud/class-fraud-detector.php:979-1000`: `create_alert()` теперь проверяет результат каждого INSERT в `cashback_fraud_signals` и откатывает транзакцию при любом сбое; алерт без доказательной базы больше не остаётся в БД.
  - F-5-002 (M, race) — `antifraud/class-fraud-admin.php:1021-1085`: `handle_review_alert()` обёрнут в `START TRANSACTION` + `SELECT ... FOR UPDATE`; TOCTOU между параллельными ревью и автоподтверждением при бане устранён.
  - F-5-003 (M, atomicity) — `antifraud/class-fraud-admin.php:1341-1446`: `handle_ban_user()` теперь throw'ит при сбое профиля/заявок/алертов, попадая в существующий `catch` → `ROLLBACK`. `freeze_balance_on_ban()` оставлен void (shared helper, триггерный fallback идемпотентен).
- **Needs-human:** —
- **Верификация:** phpstan `No errors`; phpcs без нарушений; PHPUnit не запускался (composer-скрипт отсутствует)
- **Коммит:** `0edcfb9`

<details><summary>Codex JSON iter-5</summary>

```json
{
  "iteration_id": 5,
  "findings": [
    {
      "id": "F-5-001",
      "file": "antifraud/class-fraud-detector.php",
      "line_start": 979,
      "line_end": 994,
      "severity": "medium",
      "category": "atomicity",
      "title": "Алерт коммитится даже при сбое записи fraud_signals",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-5-002",
      "file": "antifraud/class-fraud-admin.php",
      "line_start": 1024,
      "line_end": 1051,
      "severity": "medium",
      "category": "race",
      "title": "Ревью алерта делает TOCTOU без FOR UPDATE",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-5-003",
      "file": "antifraud/class-fraud-admin.php",
      "line_start": 1341,
      "line_end": 1418,
      "severity": "medium",
      "category": "atomicity",
      "title": "Бан пользователя коммитится при частичном выполнении шагов",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "data-integrity",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-6 · 2026-04-20

- **Батч:** `antifraud/class-fraud-collector.php` (326), `antifraud/class-fraud-db.php` (263), `antifraud/class-fraud-device-id.php` (383) — 972 строк
- **Codex session:** `a1380f56689a0330d`
- **Findings:** 0C / 0H / 1M / 0L (+ 1 needs_human_review)
- **Applied:** — (F-6-001 меняет структуру БД → попадает под zero-functional-change guard, переведено в needs-human)
- **Needs-human:**
  - F-6-001 (M, race/idempotency) — `antifraud/class-fraud-db.php:107-128` + `antifraud/class-fraud-device-id.php`: отсутствует UNIQUE-ограничение на (device_id, visitor_id, ip_address, seen_date) в `cashback_fraud_device_ids`. Codex предлагает добавить колонку `seen_date` + `UNIQUE KEY uk_device_daily_session` и перейти `record()` на `INSERT ... ON DUPLICATE KEY UPDATE`. Требует ALTER TABLE на существующих установках (DB-schema change) и согласованного обновления логики upsert.
  - needs_human (collector) — `antifraud/class-fraud-collector.php:203-240`: запись persistent device_id для guest-пользователей без явного consent. Правовой/продуктовый вопрос по юрисдикциям и cookie-consent UX.
- **Верификация:** не запускалась (0 применённых фиксов)
- **Коммит:** — (нет фиксов → нет коммита кода; check.md обновлён без коммита)

<details><summary>Codex JSON iter-6</summary>

```json
{
  "iteration_id": 6,
  "findings": [
    {
      "id": "F-6-001",
      "file": "antifraud/class-fraud-db.php",
      "line_start": 107,
      "line_end": 128,
      "severity": "medium",
      "category": "race",
      "title": "Нет schema-level дедупликации для device_id-сессий",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "sanitize",
    "escape",
    "crypto",
    "atomicity",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall",
    "double-spend",
    "rate-limit"
  ],
  "needs_human_review": [
    {
      "file": "antifraud/class-fraud-collector.php",
      "concern": "Запись persistent device_id для guest без явного consent"
    }
  ]
}
```

</details>

### iter-7 · 2026-04-20

- **Батч:** `antifraud/class-fraud-ip-intelligence.php` (459), `antifraud/class-fraud-settings.php` (355) — 814 строк
- **Codex session:** `af77ad3db1856c928`
- **Findings:** 0C / 0H / 0M / 2L (+ 1 needs_human_review)
- **Applied:**
  - F-7-002 (L, pii-log) — `antifraud/class-fraud-ip-intelligence.php:354-358`: в catch-блоке MaxMind lookup IP заменён на `sha256`, exception message заменён на `get_class($e)`. PII и внутренние детали исключения больше не попадают в `error_log`.
- **Needs-human:**
  - F-7-001 (L, secrets) — `antifraud/class-fraud-settings.php:318-338,174-175`: `captcha_server_key` хранится plaintext в `wp_options`. Патч Codex'а шифрует опцию через `Cashback_Encryption` — **меняет формат данных** существующих установок (plaintext → encrypted), требует миграции + совместимости при read. Попадает под zero-functional-change guard.
  - needs_human (ip-intelligence) — `antifraud/class-fraud-ip-intelligence.php:250`: `.mmdb` в `/uploads/cashback-fraud/` — проверить deployment (nginx/Apache) на блок прямой загрузки файла.
- **Верификация:** phpstan — 1 pre-existing error `WP_CONTENT_DIR not found` на стр.250 (подтверждён до правок через git stash); phpcs — 1 pre-existing baseline CRLF на стр.1; PHPUnit не запускался (composer-скрипт отсутствует).
- **Коммит:** `1854650`

<details><summary>Codex JSON iter-7</summary>

```json
{
  "iteration_id": 7,
  "findings": [
    {
      "id": "F-7-001",
      "file": "antifraud/class-fraud-settings.php",
      "line_start": 318,
      "line_end": 338,
      "severity": "low",
      "category": "secrets",
      "title": "Server Key SmartCaptcha сохраняется plaintext в wp_options",
      "breaks_functionality": false,
      "confidence": "medium"
    },
    {
      "id": "F-7-002",
      "file": "antifraud/class-fraud-ip-intelligence.php",
      "line_start": 354,
      "line_end": 356,
      "severity": "low",
      "category": "pii-log",
      "title": "В error_log пишется полный IP + текст исключения",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "sanitize",
    "escape",
    "crypto",
    "atomicity",
    "data-integrity",
    "race",
    "idempotency",
    "rate-limit",
    "math",
    "logic",
    "backdoor",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": [
    {
      "file": "antifraud/class-fraud-ip-intelligence.php",
      "concern": "Публичная доступность .mmdb в /uploads/cashback-fraud/"
    }
  ]
}
```

</details>

### iter-8 · 2026-04-20

- **Батч:** `includes/class-cashback-api-client.php` (3393), `includes/class-cashback-api-cron.php` (284) — 3677 строк
- **Codex session:** `aab1e922bceb08f26`
- **Findings:** 0C / 3H / 2M / 0L (0 needs_human_review от Codex)
- **Applied:** — (все 5 находок переведены в `⚠️ needs-human` по политике fail-closed: критичная денежная логика reconciliation, большой файл, риск deadlock/миграции при точечном патче)
- **Needs-human:**
  - F-8-001 (H, race) — `includes/class-cashback-api-client.php:1994-2097`: `sync_update_local()` делает read-modify-write без `SELECT ... FOR UPDATE`/транзакции. Lost update между параллельными sync/admin-правками.
  - F-8-002 (H, atomicity) — `includes/class-cashback-api-client.php:2418-2598`: `decline_stale_missing_transactions()` массово переводит в `declined` без `FOR UPDATE`; в выборку включён статус `completed`. Отдельный бизнес-вопрос: должен ли auto-decline вообще затрагивать `completed`.
  - F-8-003 (M, math) — `includes/class-cashback-api-client.php:2228-2311`: деньги как `float` + `%f`. End-to-end миграция на integer minor units = schema change (пересекается с iter-4 F-4-001).
  - F-8-004 (M, logic) — `includes/class-cashback-api-client.php:689-695`: `parse_api_date()` срезает timezone регекспами вместо нормальной конверсии через `DateTimeImmutable` + `wp_timezone()`. Фикс меняет интерпретацию дат — нужен план миграции/backfill существующих записей.
  - F-8-005 (H, atomicity) — `includes/class-cashback-api-cron.php:87-157`: `run_sync()` декларирует атомарность, но sync+transfer+accrual не обёрнуты в единую БД-транзакцию. Требует архитектурного решения (общий transaction boundary vs. durable checkpoints + компенсационные действия).
- **Верификация:** не запускалась (0 применённых фиксов)
- **Коммит:** — (нет фиксов → нет коммита кода; check.md обновлён без коммита)

<details><summary>Codex JSON iter-8</summary>

```json
{
  "iteration_id": 8,
  "findings": [
    {
      "id": "F-8-001",
      "file": "includes/class-cashback-api-client.php",
      "line_start": 1994,
      "line_end": 2097,
      "severity": "high",
      "category": "race",
      "title": "Обновление транзакции идёт без row-lock и защиты от lost update",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-8-002",
      "file": "includes/class-cashback-api-client.php",
      "line_start": 2418,
      "line_end": 2598,
      "severity": "high",
      "category": "atomicity",
      "title": "Auto-decline меняет денежные статусы массово и неатомарно",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-8-003",
      "file": "includes/class-cashback-api-client.php",
      "line_start": 2228,
      "line_end": 2311,
      "severity": "medium",
      "category": "math",
      "title": "Денежные суммы сохраняются через float и %f",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-8-004",
      "file": "includes/class-cashback-api-client.php",
      "line_start": 689,
      "line_end": 695,
      "severity": "medium",
      "category": "logic",
      "title": "Парсер дат отбрасывает timezone вместо корректной конверсии",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-8-005",
      "file": "includes/class-cashback-api-cron.php",
      "line_start": 87,
      "line_end": 157,
      "severity": "high",
      "category": "atomicity",
      "title": "Cron декларирует atomic sync+accrual, но не откатывает частичный state",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "sanitize",
    "escape",
    "crypto",
    "idempotency",
    "rate-limit",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-9 · 2026-04-20

- **Батч:** `includes/class-cashback-encryption.php` (387), `includes/class-cashback-lock.php` (228), `includes/class-cashback-rate-limiter.php` (278) — 893 строк
- **Codex session:** `a88c8c92f16ad52f9`
- **Findings:** 0C / 2H / 3M / 0L (0 needs_human_review от Codex)
- **Applied:**
  - F-9-001 (M, pii-log) — `includes/class-cashback-encryption.php:332-351`: `write_audit_log()` теперь пропускает `$extra_details` через helper `redact_audit_details()`, который реплейсит чувствительные ключи (`account`, `payout_account`, `card`, `pan`, `cvv`, `token`, `secret`, `password`, `api_key`, `encrypted_details`, `auth_tag`, `iv`, `nonce` и др.) на `[REDACTED]` рекурсивно.
  - F-9-002 (H, fail-open) — `includes/class-cashback-lock.php:110-137`: `is_lock_active()` теперь делает `IS_FREE_LOCK()` первичной проверкой (источник правды), а `wp_options` читается только для метаданных cleanup'а. Пустая/потерянная wp_options-запись при занятом MySQL-lock больше не даёт fail-open.
  - F-9-004 (H, rate-limit) — `includes/class-cashback-rate-limiter.php:151-192`: в `check()` добавлен вызов `is_blocked_ip($ip)` после валидации tier — заблокированный IP (grey score >= блок-порог) отклоняется до истечения GREY_TTL, как задекларировано в комментарии класса.
- **Needs-human:**
  - F-9-003 (M, fail-open) — `includes/class-cashback-rate-limiter.php:151-160`: unregistered action → allow. Fail-closed (default=deny) ломает существующие вызовы `social_email_prompt` и `social_unlink` в `social-auth-router.php` — они не зарегистрированы в `ACTION_TIERS`. Требуется инвентаризация всех call-сайтов `Cashback_Rate_Limiter::check()` и дополнение реестра перед переключением.
  - F-9-005 (M, race) — `includes/class-cashback-rate-limiter.php:169-185`: транзиент-счётчик read-modify-write неатомарен. Дубликат iter-2 F-2-002; требует Redis INCR или SQL-upsert.
- **Верификация:** phpstan `No errors`; phpcs без нарушений (после правки формата массива); PHPUnit не запускался (composer-скрипт отсутствует).
- **Коммит:** `816381b`

<details><summary>Codex JSON iter-9</summary>

```json
{
  "iteration_id": 9,
  "findings": [
    {
      "id": "F-9-001",
      "file": "includes/class-cashback-encryption.php",
      "line_start": 332,
      "line_end": 347,
      "severity": "medium",
      "category": "pii-log",
      "title": "Аудит-лог пишет произвольные чувствительные поля без редактирования",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-9-002",
      "file": "includes/class-cashback-lock.php",
      "line_start": 61,
      "line_end": 126,
      "severity": "high",
      "category": "fail-open",
      "title": "Сигнал видимости lock fail-open при сбое записи wp_options",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-9-003",
      "file": "includes/class-cashback-rate-limiter.php",
      "line_start": 151,
      "line_end": 160,
      "severity": "medium",
      "category": "fail-open",
      "title": "Незарегистрированные AJAX проходят без лимита",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-9-004",
      "file": "includes/class-cashback-rate-limiter.php",
      "line_start": 162,
      "line_end": 191,
      "severity": "high",
      "category": "rate-limit",
      "title": "Заблокированный IP не отклоняется в check()",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-9-005",
      "file": "includes/class-cashback-rate-limiter.php",
      "line_start": 169,
      "line_end": 185,
      "severity": "medium",
      "category": "race",
      "title": "Счётчик rate-limit обновляется неатомарно",
      "breaks_functionality": false,
      "confidence": "medium"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall",
    "atomicity",
    "data-integrity"
  ],
  "needs_human_review": []
}
```

</details>

### iter-10 · 2026-04-20

- **Батч:** `includes/class-cashback-rest-api.php` (1176), `includes/class-cashback-captcha.php` (192), `includes/class-cashback-bot-protection.php` (274) — 1642 строк
- **Codex session:** `a0a9f7da6a47dcf86`
- **Findings:** 0C / 0H / 4M / 0L (+ 1 needs_human_review, закрывается iter-9)
- **Applied:** — (все 4 находки переведены в `⚠️ needs-human` по fail-closed политике: существенные изменения семантики/availability/интеграций)
- **Needs-human:**
  - F-10-001 (M, idempotency) — `includes/class-cashback-rest-api.php:471-555`: `activate_cashback()` при каждом POST создаёт новый `click_id`. Codex предлагает возвращать существующий клик в окне `ACTIVATION_WINDOW`. Затрагивает семантику атрибуции — нужно бизнес-решение.
  - F-10-002 (M, rate-limit) — `includes/class-cashback-rest-api.php:978-1007`: transient-счётчик неатомарен (read-modify-write). Codex предлагает миграцию на `Cashback_Rate_Limiter::check()` — пересекается с iter-9 F-9-005 и iter-2 F-2-002; требует согласованной rate-limit архитектуры.
  - F-10-003 (M, secrets) — `includes/class-cashback-captcha.php:118-126`: серверный ключ SmartCaptcha в URL query (попадает в HTTP-логи прокси/APM). Перевод на POST body требует проверки Yandex SmartCaptcha API (docs описывают GET `/validate`).
  - F-10-004 (M, fail-open) — `includes/class-cashback-captcha.php:112-151`: `verify_token()` возвращает `true` при пустом секрете, `is_wp_error`, HTTP≠200 и невалидном JSON. Fail-closed — существенное user-facing изменение: при сбое Yandex серые IP не смогут выполнять critical/write.
- **Верификация:** не запускалась (0 применённых фиксов)
- **Коммит:** — (нет фиксов → нет коммита)

<details><summary>Codex JSON iter-10</summary>

```json
{
  "iteration_id": 10,
  "findings": [
    {
      "id": "F-10-001",
      "file": "includes/class-cashback-rest-api.php",
      "line_start": 471,
      "line_end": 555,
      "severity": "medium",
      "category": "idempotency",
      "title": "POST /activate неидемпотентен и создаёт дубли кликов",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-10-002",
      "file": "includes/class-cashback-rest-api.php",
      "line_start": 978,
      "line_end": 1007,
      "severity": "medium",
      "category": "rate-limit",
      "title": "Rate limit на transient неатомарен",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-10-003",
      "file": "includes/class-cashback-captcha.php",
      "line_start": 118,
      "line_end": 126,
      "severity": "medium",
      "category": "secrets",
      "title": "Server key CAPTCHA в URL query string",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-10-004",
      "file": "includes/class-cashback-captcha.php",
      "line_start": 112,
      "line_end": 151,
      "severity": "medium",
      "category": "fail-open",
      "title": "Проверка CAPTCHA открывается при любой ошибке upstream",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "sanitize",
    "escape",
    "crypto",
    "atomicity",
    "data-integrity",
    "race",
    "fintech-math",
    "logic",
    "double-spend",
    "backdoors",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall"
  ],
  "needs_human_review": [
    {
      "file": "includes/class-cashback-bot-protection.php",
      "concern": "Атомарность двухуровневого rate-limit требует чтения Cashback_Rate_Limiter",
      "reason": "Закрыт iter-9 F-9-005."
    }
  ]
}
```

</details>

### iter-11 · 2026-04-20

- **Батч:** `includes/class-cashback-fraud-consent.php` (320), `includes/class-cashback-trigger-fallbacks.php` (219), `includes/class-cashback-user-status.php` (76) — 615 строк
- **Codex session:** `ab67cd16fcb3f9f73`
- **Findings:** 0C / 1H / 2M / 0L (+ 2 needs_human_review)
- **Applied:**
  - F-11-001 (M, fail-open) — `includes/class-cashback-fraud-consent.php:60-91,111-124,129-140`: добавлен `META_KEY_CONSENT_WITHDRAWN_AT`. `withdraw_consent()` фиксирует явный маркер отзыва, `has_consent()` проверяет его до legacy-fallback'а по дате регистрации, `record_consent()` снимает маркер при повторной выдаче. Отзыв согласия теперь корректно работает для пользователей, зарегистрированных до `cashback_fraud_consent_required_after`.
- **Needs-human:**
  - F-11-002 (M, math) — `includes/class-cashback-trigger-fallbacks.php:21-84`: float/`round(…, 2)` в `calculate_cashback` и `recalculate_cashback_on_update`. Пересекается с iter-4 F-4-001 и iter-8 F-8-003 — общая end-to-end миграция денег на BCMath / integer-копейки.
  - F-11-003 (H, data-integrity) — `includes/class-cashback-trigger-fallbacks.php:175-202`: `freeze_balance_on_ban` морозит `available + pending`, `unfreeze_balance_on_unban` возвращает весь `frozen_balance` в `available` → после ban/unban `pending` «проскакивает» в `available`. Fallback согласован с MariaDB trigger `tr_freeze_balance_on_ban` (`mariadb.php:1083-1099`) — одностороннее исправление создаст несогласованность DB-path vs PHP-path. Требуется синхронная миграция `trigger+fallback` и архитектурное решение по bucket'ам (ban-freeze vs payout-freeze) внутри `frozen_balance`.
  - needs_human (consent, social-auth) — `includes/class-cashback-fraud-consent.php:243-262`: автозапись consent для OAuth-регистраций без собственного checkbox. Правовое/продуктовое решение по допустимости OAuth-основания как информированного согласия.
- **Верификация:** phpstan — 3 pre-existing errors (PHPDoc narrowing), подтверждены через git stash; phpcs — 1 pre-existing CRLF на стр.1; PHPUnit не запускался.
- **Коммит:** `be5edec`

<details><summary>Codex JSON iter-11</summary>

```json
{
  "iteration_id": 11,
  "findings": [
    {
      "id": "F-11-001",
      "file": "includes/class-cashback-fraud-consent.php",
      "line_start": 80,
      "line_end": 135,
      "severity": "medium",
      "category": "fail-open",
      "title": "Отзыв consent не отключает legacy-подразумеваемое согласие",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-11-002",
      "file": "includes/class-cashback-trigger-fallbacks.php",
      "line_start": 21,
      "line_end": 84,
      "severity": "medium",
      "category": "math",
      "title": "Денежный расчёт cashback на float/round",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-11-003",
      "file": "includes/class-cashback-trigger-fallbacks.php",
      "line_start": 175,
      "line_end": 202,
      "severity": "high",
      "category": "data-integrity",
      "title": "Бан переносит pending в frozen, разбан — в available",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "race",
    "idempotency",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall",
    "atomicity",
    "rate-limit"
  ],
  "needs_human_review": [
    {
      "file": "includes/class-cashback-trigger-fallbacks.php",
      "concern": "Bucket-разделение ban-freeze vs payout-freeze"
    },
    {
      "file": "includes/class-cashback-fraud-consent.php",
      "concern": "Автозапись consent для social-auth требует legal-решения"
    }
  ]
}
```

</details>

### iter-12 · 2026-04-20

- **Батч:** `includes/adapters/interface-cashback-network-adapter.php` (105), `includes/adapters/abstract-cashback-network-adapter.php` (141), `includes/adapters/class-admitad-adapter.php` (409) — 655 строк
- **Codex session:** `ab2910f6e5b63b538`
- **Findings:** 0C / 2H / 2M / 0L (0 needs_human_review)
- **Applied:**
  - F-12-002 (M, fail-open) — `includes/adapters/abstract-cashback-network-adapter.php:93-135`: в `http_get()` и `http_post()` добавлены явные `sslverify => true` и `reject_unsafe_urls => true`. Transport-слой не полагается на глобальные WP-фильтры, которые могут быть ослаблены сторонними плагинами/dev-override.
  - F-12-003 (H, idempotency) — `includes/adapters/class-admitad-adapter.php:251-270`: `fetch_all_actions()` дедуплицирует результаты по стабильному `action.id` (ключ в `$all_actions[ $action_id ]`) перед возвратом. Offset-пагинация на изменяемой выборке больше не возвращает дубли.
  - F-12-004 (M, error-leak) — `includes/adapters/class-admitad-adapter.php:211-213,355-361,419-450`: сырые тела non-200 ответов заменены на `safe_error_summary()` — private helper, который пропускает только allowlist полей (`code`, `error`, `error_description`, `detail`, `status`, `status_code`). `order_id`, `subid`, `email`, `results` больше не уходят в error-строку.
- **Needs-human:**
  - F-12-001 (H, http/SSRF) — `includes/adapters/abstract-cashback-network-adapter.php:70-82`: `build_api_url()` принимает произвольный endpoint (http/https) из `network_config`. SSRF во внутреннюю сеть / утечка Admitad Basic Auth credentials на контролируемый сервер. Требует allowlist хостов для каждой сети + запрет `http://` + резолв и блок private/reserved IP. Пересекается с iter-4 F-4-005.
- **Верификация:** phpstan `No errors`; phpcs — исходно 3 warnings по alignment в новом foreach, исправлено через `phpcbf`, финальный прогон без нарушений; PHPUnit не запускался.
- **Коммит:** `510a7ef`

<details><summary>Codex JSON iter-12</summary>

```json
{
  "iteration_id": "12",
  "findings": [
    {
      "id": "F-12-001",
      "file": "includes/adapters/abstract-cashback-network-adapter.php",
      "line_start": 70,
      "line_end": 123,
      "severity": "high",
      "category": "http",
      "title": "Конфиг сети позволяет SSRF и отправку секретов на произвольный URL",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-12-002",
      "file": "includes/adapters/abstract-cashback-network-adapter.php",
      "line_start": 93,
      "line_end": 123,
      "severity": "medium",
      "category": "fail-open",
      "title": "Исходящие HTTP-запросы не фиксируют безопасные transport-флаги",
      "breaks_functionality": false,
      "confidence": "medium"
    },
    {
      "id": "F-12-003",
      "file": "includes/adapters/class-admitad-adapter.php",
      "line_start": 229,
      "line_end": 263,
      "severity": "high",
      "category": "idempotency",
      "title": "Пагинация offset/limit не защищена от дублей",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-12-004",
      "file": "includes/adapters/class-admitad-adapter.php",
      "line_start": 211,
      "line_end": 360,
      "severity": "medium",
      "category": "error-leak",
      "title": "Сырые тела ответов Admitad в строках ошибок",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "sanitize",
    "escape",
    "crypto",
    "atomicity",
    "race",
    "fintech-math",
    "logic",
    "double-spend",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "session",
    "capability",
    "uninstall",
    "rate-limit"
  ],
  "needs_human_review": []
}
```

</details>

### iter-13 · 2026-04-20

- **Батч:** `includes/adapters/class-epn-adapter.php` (694), `includes/social-auth/class-social-auth-router.php` (811), `includes/social-auth/class-social-auth-account-manager.php` (1069) — 2574 строк
- **Codex session:** `a0421ca5378306f08`
- **Findings:** 0C / 1H / 4M / 0L (+ 1 needs_human_review)
- **Applied:**
  - F-13-002 (M, pii-log) — `includes/adapters/class-epn-adapter.php:108-114, 376-393`: сырые тела EPN ответов больше не пишутся в `error_log`. SSID-ошибка логирует только `Code` + `error_description`; `fetch_actions` — структурированный контекст `{http_code, error}`. В 403-сообщении убрана подстановка `Raw: mb_substr($raw_body, 0, 200)`.
  - F-13-005 (M, logic) — `includes/social-auth/class-social-auth-router.php:742-755`: unlink теперь сначала удаляет связку `delete_link($link_id)`, и только после успеха — токены через `Token_Store::delete()`. Исчезает состояние «токены удалены, связка осталась», ломавшее последующие refresh-сценарии.
- **Needs-human:**
  - F-13-001 (H, secrets) — `includes/adapters/class-epn-adapter.php:163-165, 213-215`: EPN `refresh_token` хранится plaintext в `wp_options`. Патч Codex'а шифрует через `Cashback_Encryption` — **меняет формат данных** существующих установок (plaintext → encrypted), требует миграции + совместимости plaintext→encrypted на чтении. Тот же класс проблемы, что iter-7 F-7-001.
  - F-13-003 (M, idempotency) — `includes/social-auth/class-social-auth-account-manager.php:413-429`: `consume_pending($token)` сжигает pending до `confirm_link_finish` / `email_verify_finish`. При падении state-changing шагов ссылка уже истрачена. Codex предлагает двухфазную схему `lock_pending_for_processing` / `release_pending` / `mark_pending_consumed` — требует новых методов в `Cashback_Social_Auth_DB` и соответствующего schema-level поля `status`.
  - F-13-004 (M, logic) — `includes/social-auth/class-social-auth-account-manager.php:618-687`: `create_pending_user_and_link()` создаёт WP-user → meta → link → pending verify token без полного отката при сбое. Компенсация через `wp_delete_user()` затрагивает `user_register`/affiliate hooks — требует обсуждения допустимых side-effect стратегий.
  - Codex needs_human (`class-social-auth-account-manager.php`) — подтвердить, что DB-layer гарантирует атомарную уникальность social links (UNIQUE-индекс на `(provider, subject)` + `INSERT IGNORE`/`ON DUPLICATE KEY`). Требует чтения `class-social-auth-db.php` в отдельном батче — уже в P0-чеклисте.
- **Верификация:** phpstan `No errors`; phpcs — clean (2/2, без новых нарушений); PHPUnit не запускался (composer-скрипт отсутствует)
- **Коммит:** `e3b43e6`

<details><summary>Codex JSON iter-13</summary>

```json
{
  "iteration_id": 13,
  "findings": [
    {
      "id": "F-13-001",
      "file": "includes/adapters/class-epn-adapter.php",
      "line_start": 163,
      "line_end": 165,
      "severity": "high",
      "category": "secrets",
      "title": "Refresh token EPN сохраняется в wp_options в открытом виде",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-13-002",
      "file": "includes/adapters/class-epn-adapter.php",
      "line_start": 389,
      "line_end": 390,
      "severity": "medium",
      "category": "pii-log",
      "title": "Сырой ответ EPN логируется без маскирования",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-13-003",
      "file": "includes/social-auth/class-social-auth-account-manager.php",
      "line_start": 413,
      "line_end": 429,
      "severity": "medium",
      "category": "idempotency",
      "title": "Pending-токен сжигается до завершения confirm-flow",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-13-004",
      "file": "includes/social-auth/class-social-auth-account-manager.php",
      "line_start": 618,
      "line_end": 687,
      "severity": "medium",
      "category": "logic",
      "title": "Создание пользователя и связки не атомарно",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-13-005",
      "file": "includes/social-auth/class-social-auth-router.php",
      "line_start": 742,
      "line_end": 746,
      "severity": "medium",
      "category": "logic",
      "title": "Отвязка соцсети выполняется с частичным применением",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "race",
    "math",
    "backdoor",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": [
    {
      "file": "includes/social-auth/class-social-auth-account-manager.php",
      "concern": "UNIQUE-гарантии social links",
      "reason": "требуется проверка DB-layer в отдельном батче"
    }
  ]
}
```

</details>

### iter-14 · 2026-04-20

- **Батч:** `includes/social-auth/class-social-auth-db.php` (435), `includes/social-auth/class-social-auth-session.php` (243), `includes/social-auth/class-social-auth-token-store.php` (120) — 798 строк
- **Codex session:** `a6c86746df3acad38`
- **Findings:** 0C / 0H / 2M / 1L (0 needs_human_review)
- **Applied:**
  - F-14-001 (M, fail-open) — `includes/social-auth/class-social-auth-session.php:220-240, 70-110, 125-140`: удалён hardcoded fallback `'cashback-social-fallback-secret'` в `sign()`. При отсутствии `CB_ENCRYPTION_KEY` и `AUTH_KEY` возвращается пустая строка; `store()` отклоняет создание cookie, `load_and_verify()` трактует пустой `expected` как невалидную подпись. Predictable-signature путь для OAuth state-cookie закрыт.
  - F-14-002 (L, race) — `includes/social-auth/class-social-auth-db.php:374-395`: в атомарный UPDATE pending-токена добавлено `AND expires_at >= %s` с UTC-временем (соответствует формату insert через `gmdate`). TOCTOU между SELECT-проверкой истечения и UPDATE устранён: токен, истёкший в race-окне, не попадает в `consumed_at`.
  - F-14-003 (M, secrets) — `includes/social-auth/class-social-auth-db.php:267-310`: `delete_link()` теперь выполняет каскадное удаление refresh_token через `Token_Store::delete()` в единой `START TRANSACTION`/`COMMIT`. При сбое — `ROLLBACK`. Orphaned зашифрованные refresh_token после unlink больше не остаются. Router-вызов `Token_Store::delete()` после `delete_link()` стал избыточным, но остаётся как defense-in-depth.
- **Needs-human:** —
- **Верификация:** phpstan `No errors`; phpcs — clean (3/3); PHPUnit не запускался (composer-скрипт отсутствует)
- **Коммит:** `889e046`

<details><summary>Codex JSON iter-14</summary>

```json
{
  "iteration_id": 14,
  "findings": [
    {
      "id": "F-14-001",
      "file": "includes/social-auth/class-social-auth-session.php",
      "line_start": 225,
      "line_end": 233,
      "severity": "medium",
      "category": "fail-open",
      "title": "Предсказуемый fallback-secret для подписи OAuth state-cookie",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-14-002",
      "file": "includes/social-auth/class-social-auth-db.php",
      "line_start": 370,
      "line_end": 385,
      "severity": "low",
      "category": "race",
      "title": "Истечение pending-токена проверяется вне атомарного UPDATE",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-14-003",
      "file": "includes/social-auth/class-social-auth-db.php",
      "line_start": 267,
      "line_end": 280,
      "severity": "medium",
      "category": "secrets",
      "title": "Удаление соц-связки не гарантирует удаление refresh_token",
      "breaks_functionality": false,
      "confidence": "medium"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-15 · 2026-04-20

- **Батч:** `includes/social-auth/class-social-auth-bootstrap.php` (213), `includes/social-auth/class-social-auth-audit.php` (59), `includes/social-auth/class-social-auth-emails.php` (302) — 574 строк
- **Codex session:** `a0b59959826fe7a79`
- **Findings:** 0C / 0H / 1M / 1L (0 needs_human_review)
- **Applied:**
  - F-15-001 (M, pii-log) — `includes/social-auth/class-social-auth-audit.php:40-100`: `log()` теперь пропускает `$context` через `redact_context()` перед сериализацией. Чувствительные ключи (`code`, `state`, `cookie`, `token`/`access_token`/`refresh_token`/`id_token`, `email`, `recipient`, `user_email`, `phone`, `user_agent`, `password`, `secret`, `api_key`, `authorization`, `auth_tag`, `iv`, `nonce`) заменяются на `[redacted]` рекурсивно. Аудит сохраняет `event`, `ts`, `provider`, `user_id`, `ip` и прочие не-чувствительные поля.
- **Needs-human:**
  - F-15-002 (L, fail-open) — `includes/social-auth/class-social-auth-bootstrap.php:58-107`: `load_files()` и `init()` молча пропускают отсутствующие файлы / классы. Codex предлагает `throw RuntimeException` при missing required file. Политическое решение: fail-closed фатал на неполном деплое = site-down; fail-open текущий = тихая деградация. Требуется решение оператора по deployment tolerance + admin_notice flow.
- **Верификация:** phpstan `No errors`; phpcs — clean после точечного `phpcbf` (форматирование нового array sensitive_keys); PHPUnit не запускался
- **Коммит:** `8f9ba73`

<details><summary>Codex JSON iter-15</summary>

```json
{
  "iteration_id": 15,
  "findings": [
    {
      "id": "F-15-001",
      "file": "includes/social-auth/class-social-auth-audit.php",
      "line_start": 45,
      "line_end": 57,
      "severity": "medium",
      "category": "pii-log",
      "title": "Аудит пишет произвольный контекст в error_log без маскирования",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-15-002",
      "file": "includes/social-auth/class-social-auth-bootstrap.php",
      "line_start": 58,
      "line_end": 107,
      "severity": "low",
      "category": "fail-open",
      "title": "Критичные security-компоненты подключаются в fail-open режиме",
      "breaks_functionality": false,
      "confidence": "medium"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "race",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-16 · 2026-04-20

- **Батч:** `includes/social-auth/class-social-auth-my-account.php` (256), `includes/social-auth/class-social-auth-providers.php` (68), `includes/social-auth/class-social-auth-renderer.php` (403) — 727 строк
- **Codex session:** `a580abe18220fac39`
- **Findings:** 0C / 0H / 0M / 0L (0 needs_human_review)
- **Applied:** —
- **Needs-human:** —
- **Верификация:** не запускалась (0 применённых фиксов)
- **Коммит:** — (нет фиксов)

<details><summary>Codex JSON iter-16</summary>

```json
{
  "iteration_id": 16,
  "findings": [],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "race",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-17 · 2026-04-20

- **Батч:** `includes/social-auth/providers/interface-social-provider.php` (82), `includes/social-auth/providers/abstract-social-provider.php` (160), `includes/social-auth/providers/class-social-provider-vkid.php` (322) — 564 строк
- **Codex session:** `a4881cf5c6b4fd15e`
- **Findings:** 0C / 0H / 0M / 1L (0 needs_human_review)
- **Applied:**
  - F-17-001 (L, error-leak) — `includes/social-auth/providers/class-social-provider-vkid.php:194-218, 271-295`: `error` пропускается через `sanitize_key()`, `error_description` — через `wp_strip_all_tags()` + truncate до 120 символов. Сырое `error_description` больше не попадает в `RuntimeException::getMessage()` (в аудит-лог идёт только безопасный вариант). Закрывает путь утечки внутренних деталей VK ID через `WP_DEBUG`/`debug_profile=1` REST-ответ и через `error_log`.
- **Needs-human:** —
- **Верификация:** phpstan `No errors`; phpcs — clean (1/1, без новых нарушений); PHPUnit не запускался (composer-скрипт отсутствует)
- **Коммит:** `c033fa8`

<details><summary>Codex JSON iter-17</summary>

```json
{
  "iteration_id": 17,
  "findings": [
    {
      "id": "F-17-001",
      "file": "includes/social-auth/providers/class-social-provider-vkid.php",
      "line_start": 194,
      "line_end": 290,
      "severity": "low",
      "category": "error-leak",
      "title": "Сырые ошибки VK ID попадают в исключения и отладочный ответ",
      "breaks_functionality": false,
      "confidence": "medium"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "race",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-18 · 2026-04-20

- **Батч:** `includes/social-auth/providers/class-social-provider-yandex.php` (268), `includes/social-auth/templates/email-prompt.php` (98), `admin/class-cashback-social-admin.php` (363) — 729 строк
- **Codex session:** `a033ed7a8d4568d92`
- **Findings:** 0C / 0H / 0M / 0L (0 needs_human_review)
- **Applied:** —
- **Needs-human:** —
- **Верификация:** не запускалась (0 применённых фиксов)
- **Коммит:** — (нет фиксов)

<details><summary>Codex JSON iter-18</summary>

```json
{
  "iteration_id": "18",
  "findings": [],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "race",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-19 · 2026-04-20

- **Батч:** `claims/class-claims-manager.php` (528), `claims/class-claims-frontend.php` (678), `claims/class-claims-admin.php` (566) — 1772 строк
- **Codex session:** `a41f4228ea914a2fd`
- **Findings:** 0C / 1H / 3M / 1L (0 needs_human_review от Codex)
- **Applied:**
  - F-19-001 (H, race) — `claims/class-claims-manager.php:175-246`: `transition_status()` обёрнут в `START TRANSACTION`; `SELECT` с `FOR UPDATE`; `UPDATE` переведён на `$wpdb->query()` с CAS-условием `WHERE claim_id = %d AND status = %s`; при `updated !== 1` — `ROLLBACK` + сообщение «статус изменён другим действием». Параллельные ревью и повторные AJAX больше не могут оба зафиксировать конкурирующие финальные статусы.
  - F-19-002 (M, atomicity) — `claims/class-claims-manager.php:113-161`: INSERT заявки + первичный `log_event('submitted')` обёрнуты в единую транзакцию. `log_event()` изменил сигнатуру с `void` на `bool` (возвращает результат `$wpdb->insert`). При сбое `log_event` — `ROLLBACK` + возврат ошибки. «Немых» заявок без обязательного аудит-события больше не появляется. Post-submit антифрод-лог остаётся вне транзакции (не критичен для state).
  - F-19-005 (L, error-leak) — `claims/class-claims-manager.php:229-240`: сырой `$wpdb->last_error` убран из ответа `transition_status()`. Теперь в HTTP-ответ идёт только обобщённое сообщение, детали — через `error_log`.
- **Needs-human:**
  - F-19-003 (M, idempotency) — `claims/class-claims-manager.php:113-129`: отсутствует `idempotency_key` на входе `create()`. Codex предлагает колонку + UNIQUE + проверку существующей заявки до INSERT. Требует ALTER TABLE на существующих установках (schema change) — zero-functional-change guard.
  - F-19-004 (M, fail-open) — отклонено как ложное срабатывание: `Cashback_Bot_Protection::guard_ajax_requests` на `admin_init` prio=1 уже применяет CAPTCHA для `claims_submit` (write tier, зарегистрирован в `Cashback_Rate_Limiter::ACTION_TIERS`) на серых IP через `Cashback_Captcha::should_require()` — это проектный двухуровневый дизайн. Записано в open questions как напоминание про compensating control при отключении bot-protection.
- **Верификация:** phpstan `No errors` (claims-manager + frontend + admin); phpcs — clean (3/3); PHPUnit не запускался (composer-скрипт отсутствует)
- **Коммит:** `c9acb64`

<details><summary>Codex JSON iter-19</summary>

```json
{
  "iteration_id": 19,
  "findings": [
    {
      "id": "F-19-001",
      "file": "claims/class-claims-manager.php",
      "line_start": 175,
      "line_end": 221,
      "severity": "high",
      "category": "race",
      "title": "Гонка при смене статуса заявки без блокировки строки",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-19-002",
      "file": "claims/class-claims-manager.php",
      "line_start": 113,
      "line_end": 139,
      "severity": "medium",
      "category": "logic",
      "title": "Создание заявки и аудит не атомарны",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-19-003",
      "file": "claims/class-claims-manager.php",
      "line_start": 113,
      "line_end": 129,
      "severity": "medium",
      "category": "idempotency",
      "title": "Идемпотентность создания заявки реализована постфактум",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-19-004",
      "file": "claims/class-claims-frontend.php",
      "line_start": 173,
      "line_end": 177,
      "severity": "medium",
      "category": "fail-open",
      "title": "Captcha отображается, но серверная проверка сабмита отсутствует",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-19-005",
      "file": "claims/class-claims-manager.php",
      "line_start": 212,
      "line_end": 216,
      "severity": "low",
      "category": "error-leak",
      "title": "Во внешний ответ уходит сырой SQL error",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "math",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "uninstall"
  ],
  "needs_human_review": [
    {
      "file": "claims/class-claims-manager.php",
      "concern": "Проверить обработчики cashback_claim_status_changed для approved",
      "reason": "Подтвердить, что downstream-обработчик атомарно создаёт финансовую транзакцию/начисление без рассинхрона между claim, transaction и audit."
    }
  ]
}
```

</details>

### iter-20 · 2026-04-20

- **Батч:** `claims/class-claims-db.php` (250), `claims/class-claims-eligibility.php` (499), `claims/class-claims-scoring.php` (269) — 1018 строк
- **Codex session:** `ad492aba174671b5a`
- **Findings:** 0C / 1H / 1M / 0L (+ 1 needs_human_review от Codex — закрывается iter-19)
- **Applied:** — (обе находки переведены в `⚠️ needs-human` по zero-functional-change guard)
- **Needs-human:**
  - F-20-001 (M, fail-open) — `claims/class-claims-db.php:117-126`: `add_constraints()` молча проглатывает ошибки ALTER TABLE. FK/CHECK могут не установиться, активация декларирует success. Codex предлагает throw RuntimeException на любой не-Duplicate ошибке. Риск: MariaDB/MySQL возвращают разные строки ошибок по версиям, throw может заблокировать активацию на существующих установках с частичной схемой. Требует version-matrix pattern matcher для `Duplicate key name`/`Duplicate foreign key` + план миграции.
  - F-20-002 (H, logic) — `claims/class-claims-scoring.php:12-118`: `score_time_factor()` возвращает `1.0` для интервала `<= 1h`, что стимулирует сверхбыстрые (подозрительные) заявки; antifraud risk score не участвует как фактор. Codex предлагает: drop `WEIGHT_MERCHANT`, add `WEIGHT_RISK` + `score_risk_factor()`, + штраф 0.25 для `< 5 min`. Это материально меняет формулу скоринга для всех заявок — продуктовое/антифрод-политическое решение, требует согласования со стороны продукта.
- **Верификация:** не запускалась (0 применённых фиксов)
- **Коммит:** — (нет фиксов)

<details><summary>Codex JSON iter-20</summary>

```json
{
  "iteration_id": "20",
  "findings": [
    {
      "id": "F-20-001",
      "file": "claims/class-claims-db.php",
      "line_start": 117,
      "line_end": 126,
      "severity": "medium",
      "category": "fail-open",
      "title": "Сбой установки ограничений БД переводится в предупреждение",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-20-002",
      "file": "claims/class-claims-scoring.php",
      "line_start": 12,
      "line_end": 118,
      "severity": "high",
      "category": "logic",
      "title": "Скоринг завышает рискованные заявки и игнорирует антифрод",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "race",
    "idempotency",
    "math",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall"
  ],
  "needs_human_review": [
    {
      "file": "claims/class-claims-eligibility.php",
      "concern": "Pre-check чтения без FOR UPDATE — допустимо для UI, но атомарная де-дупликация должна быть в state-changing handler"
    }
  ]
}
```

</details>

### iter-21 · 2026-04-20

- **Батч:** `claims/class-claims-antifraud.php` (262), `claims/class-claims-notifications.php` (182) — 444 строк
- **Codex session:** `a37be2043ca49f7e5`
- **Findings:** 0C / 0H / 2M / 0L (0 needs_human_review)
- **Applied:**
  - F-21-001 (M, fail-open) — `claims/class-claims-antifraud.php:85-119`: `check_rate_limit()` переведён с хардкод-констант `MAX_CLAIMS_PER_DAY/WEEK` на существующие геттеры `get_max_claims_per_day()`/`get_max_claims_per_week()`. Настройки `cashback_claims_max_per_day` и `cashback_claims_max_per_week` в `wp_options` теперь реально применяются; администратор может ужесточить лимиты без правок кода.
- **Needs-human:**
  - F-21-002 (M, race) — `claims/class-claims-antifraud.php:131-155`: `check_order_id_uniqueness()` — обычный SELECT без блокировки. Параллельные submits с одинаковым `order_id` из разных аккаунтов могут оба пройти антифрод. Codex предлагает MySQL `GET_LOCK`/`RELEASE_LOCK` в `Cashback_Claims_Manager::create()` + антифроде (cross-file, новый lock-примитив для кодбазы). Правильный fix = `UNIQUE (order_id)` на `cashback_claims` (schema change — тот же класс проблемы, что iter-19 F-19-003). Требует решения по миграции + политики (глобальный unique vs per-network unique с учётом CPA-сети).
- **Верификация:** phpstan `No errors`; phpcs — clean (2/2); PHPUnit не запускался (composer-скрипт отсутствует)
- **Коммит:** `3638b7b`

<details><summary>Codex JSON iter-21</summary>

```json
{
  "iteration_id": 21,
  "findings": [
    {
      "id": "F-21-001",
      "file": "claims/class-claims-antifraud.php",
      "line_start": 88,
      "line_end": 110,
      "severity": "medium",
      "category": "fail-open",
      "title": "Настраиваемые лимиты заявок не применяются в антифроде",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-21-002",
      "file": "claims/class-claims-antifraud.php",
      "line_start": 131,
      "line_end": 155,
      "severity": "medium",
      "category": "race",
      "title": "Проверка уникальности order_id уязвима к гонке при параллельной подаче",
      "breaks_functionality": false,
      "confidence": "medium"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-22 · 2026-04-20

- **Батч:** `affiliate/class-affiliate-service.php` (1331), `affiliate/class-affiliate-db.php` (274), `affiliate/class-affiliate-antifraud.php` (247) — 1852 строк
- **Codex session:** `ac5ddcfce24699809`
- **Findings:** 1C / 3H / 1M / 0L (0 needs_human_review от Codex)
- **Applied:**
  - F-22-004 (M, fail-open) — `affiliate/class-affiliate-service.php:118-147, 165-168, 293-299`: `compute_cookie_hmac_static()` при отсутствии `CB_ENCRYPTION_KEY` возвращает пустую строку (вместо hardcoded `'fallback-not-secure'`). `set_referral_cookie()` отклоняет постановку cookie при пустой подписи; `read_referral_cookie()` трактует пустой expected как невалидную подпись (`hash_equals('', anything)=false` + явная проверка). Применён паттерн iter-14 F-14-001 (social-auth session HMAC). Predictable-signature path для реферальных cookie закрыт.
- **Needs-human:** (4 находки — всё money-path surgery)
  - F-22-001 (C, idempotency) — `affiliate/class-affiliate-service.php:516-680`: `process_affiliate_commissions()` накапливает `$balance_deltas` ДО попытки UPDATE/INSERT accruals и безусловно применяет их к `available_balance`, хотя ledger идемпотентен (`ON DUPLICATE KEY UPDATE id = id`). Повторный прогон batch → двойной кредит доступного баланса реферера при неизменном ledger. **Прямая утечка денег.** Требует перестройки на tracking `effective_deltas` (только новые ledger-строки) + per-row контроля `affected_rows`.
  - F-22-002 (H, race/atomicity) — `affiliate/class-affiliate-service.php:577-680`: multi-step batch без `START TRANSACTION`, при частичном сбое balance может разойтись с ledger. Связан с F-22-001 — без совместного fix'а ROLLBACK даёт «atomic double-credit».
  - F-22-003 (H, logic) — `affiliate/class-affiliate-service.php:231-279, 346-353`: IP-transient fallback позволяет любой регистрации с того же IP в пределах TTL присвоить чужую реферальную атрибуцию без подписанной cookie. Продуктовое решение: удалить fallback / добавить browser-token / оставить.
  - F-22-005 (H, race) — `affiliate/class-affiliate-service.php:1159-1221`: `re_freeze_after_unban()` — read-modify-write без FOR UPDATE, `idempotency_key = 'aff_refreeze_' . $user_id . '_' . time()` (не стабилен). Пересекается с iter-11 F-11-003 bucket-вопросом `frozen_balance`.
- **Верификация:** phpstan `No errors` (3 файла); phpcs — 3 pre-existing alignment warnings фиксанулись через ручное выравнивание после `phpcbf --standard=./phpcs-safe.xml` (168 auto-fixes в class-affiliate-service.php = trailing whitespace/формат); PHPUnit не запускался
- **Коммит:** `bb092a6`

<details><summary>Codex JSON iter-22</summary>

```json
{
  "iteration_id": "22",
  "findings": [
    {
      "id": "F-22-001",
      "file": "affiliate/class-affiliate-service.php",
      "line_start": 516,
      "line_end": 674,
      "severity": "critical",
      "category": "idempotency",
      "title": "Повторный batch повторно увеличивает available_balance",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-22-002",
      "file": "affiliate/class-affiliate-service.php",
      "line_start": 577,
      "line_end": 679,
      "severity": "high",
      "category": "race",
      "title": "Начисление комиссий не атомарно и допускает partial apply",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-22-003",
      "file": "affiliate/class-affiliate-service.php",
      "line_start": 231,
      "line_end": 353,
      "severity": "high",
      "category": "logic",
      "title": "Fallback по IP позволяет чужую реферальную атрибуцию",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-22-004",
      "file": "affiliate/class-affiliate-service.php",
      "line_start": 289,
      "line_end": 291,
      "severity": "medium",
      "category": "fail-open",
      "title": "HMAC cookie подписывается предсказуемым ключом при сбое конфигурации",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-22-005",
      "file": "affiliate/class-affiliate-service.php",
      "line_start": 1159,
      "line_end": 1221,
      "severity": "high",
      "category": "race",
      "title": "re_freeze_after_unban выполняет повторную заморозку без lock",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "math",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-23 · 2026-04-20

- **Батч:** `affiliate/class-affiliate-admin.php` (1066), `affiliate/class-affiliate-frontend.php` (480), `notifications/class-cashback-notifications.php` (841) — 2387 строк
- **Codex session:** `a7ebbec12968c6725`
- **Findings:** 0C / 0H / 2M / 3L (0 needs_human_review от Codex)
- **Applied:**
  - F-23-001 (M, race) — `affiliate/class-affiliate-admin.php:959-1065` (`handle_edit_accrual`): POST-валидация перенесена до транзакции, read-modify-write обёрнут в `START TRANSACTION` + `SELECT ... FOR UPDATE`. Все ранние-exit ветки (`not_found`, `available`, `invalid_transition`, `empty_update_data`, `update_failed`) делают `ROLLBACK` до `wp_send_json_error`. TOCTOU между чтением `$accrual['status']` и UPDATE устранён.
  - F-23-002 (L, error-leak) — `affiliate/class-affiliate-admin.php:1040-1048`: сырой `$wpdb->last_error` убран из AJAX-ответа; клиенту отдаётся обобщённое «Ошибка обновления.», детали идут в `error_log` только при `WP_DEBUG_LOG`.
- **Needs-human:**
  - F-23-003 (M, logic) — `affiliate/class-affiliate-admin.php:861-941` (`handle_bulk_update_affiliate_commission`): глобальная ставка обновляется через `Cashback_Affiliate_DB::set_global_rate()` (т.е. `update_option`) **после** `COMMIT` транзакции профилей. Существующий комментарий в коде явно признаёт trade-off: при сбое `update_option` профили уже изменены, а default-ставка для новых партнёров останется старой. Перенос `set_global_rate()` внутрь TX требует политики инвалидации `wp_cache` при `ROLLBACK` и решения, как обрабатывать неконсистентность DB/cache; архитектурный вопрос.
  - F-23-004 (L, idempotency) — `notifications/class-cashback-notifications.php:763-832` (`process_queue`): SELECT `WHERE processed=0 LIMIT 50` без claim-lock; параллельные воркеры Action Scheduler могут захватить тот же batch и отправить дубли. Фикс требует schema change — колонка `processing_token` (либо промежуточный статус) для атомарного claim. Без схемы — альтернатива с `SELECT ... FOR UPDATE` удерживает row-lock на время SMTP, это неприемлемо. Needs-human по zero-functional-change guard.
  - F-23-005 (L, fail-open) — `notifications/class-cashback-notifications.php:786-832`: записи помечаются `processed=1` безусловно, без проверки результата `send()` / `on_*()`. Тихая потеря уведомлений при сбое SMTP. Фикс требует (а) cascading изменения сигнатур `on_transaction_created/status_changed/data_changed` на возврат bool; (б) schema-колонок `attempts` + `last_error` для retry-policy с ограничением. Без них failed-items либо теряются, либо retry-ятся бесконечно. Needs-human.
- **Верификация:** phpstan — `No errors` (affiliate/class-affiliate-admin.php); phpcs — clean (1/1, без новых нарушений); PHPUnit не запускался (composer-скрипт отсутствует). Файлы без изменений (affiliate-frontend, notifications) в верификации не участвовали.
- **Коммит:** 6225113

<details><summary>Codex JSON iter-23</summary>

```json
{
  "iteration_id": "23",
  "findings": [
    {
      "id": "F-23-001",
      "file": "affiliate/class-affiliate-admin.php",
      "line_start": 976,
      "line_end": 1043,
      "severity": "medium",
      "category": "race",
      "title": "Редактирование начисления без блокировки допускает TOCTOU",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-23-002",
      "file": "affiliate/class-affiliate-admin.php",
      "line_start": 1045,
      "line_end": 1046,
      "severity": "low",
      "category": "error-leak",
      "title": "SQL-ошибка БД возвращается в AJAX-ответ без маскировки",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-23-003",
      "file": "affiliate/class-affiliate-admin.php",
      "line_start": 861,
      "line_end": 940,
      "severity": "medium",
      "category": "logic",
      "title": "Массовое изменение ставки коммитит БД до обновления global rate",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-23-004",
      "file": "notifications/class-cashback-notifications.php",
      "line_start": 769,
      "line_end": 832,
      "severity": "low",
      "category": "idempotency",
      "title": "Очередь уведомлений обрабатывается без claim/lock и даёт дубли",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-23-005",
      "file": "notifications/class-cashback-notifications.php",
      "line_start": 786,
      "line_end": 832,
      "severity": "low",
      "category": "fail-open",
      "title": "Записи очереди помечаются обработанными без проверки результата send()",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "math",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-24 · 2026-04-20

- **Батч:** `notifications/class-cashback-broadcast.php` (980), `notifications/class-cashback-notifications-admin.php` (362), `notifications/class-cashback-notifications-frontend.php` (220) — 1562 строк
- **Codex session:** `a59811c12d7a61205`
- **Findings:** 1C / 3H / 1M / 0L (0 needs_human_review от Codex)
- **Applied:** 5/5 — все в `class-cashback-broadcast.php`
  - F-24-001 (H, race) — `handle_ajax_create_campaign` (`:276-412`): проверка «одна активная кампания» перенесена под `START TRANSACTION` + `SELECT ... FOR UPDATE`. Повторная проверка `campaign_uuid` под блокировкой закрывает race между первичной идемпотентностью и INSERT.
  - F-24-002 (H, logic/atomicity) — `handle_ajax_create_campaign` + `insert_queue_batch` (`:410-441`): INSERT кампании и batch-insert очереди обёрнуты в единую транзакцию. `insert_queue_batch` изменил сигнатуру `void → int|false`; вызывающий проверяет `=== false` и делает `ROLLBACK` при сбое любого батча. Кампания с неполной очередью больше не создаётся.
  - F-24-003 (C, idempotency) — `process_queue` (`:585-790`): переработка на двухфазный claim-lock. (1) Recovery: строки `status='processing'` с `processed_at < NOW - 10min` возвращаются в `pending` (восстановление после краша воркера). (2) Claim phase: короткая TX `SELECT ... FOR UPDATE LIMIT batch_size` + bulk `UPDATE status='processing', attempts=attempts+1, processed_at=NOW` + `COMMIT`. (3) Send phase (вне TX): per-row CAS `WHERE id=X AND status='processing'`. Параллельные воркеры Action Scheduler не могут оба отправить одно и то же письмо. `still_pending` теперь учитывает и `processing` для корректной финализации кампании.
  - F-24-004 (H, race) — `process_queue` (`:679-702`): перед каждым `$sender->send()` повторная выборка `campaign.status`; если не `sending` — строка помечается `failed/cancelled` с CAS по `processing` и send пропускается. Отмена из админки становится fail-closed для уже захваченного батча.
  - F-24-005 (M, pii-log) — `process_queue` (`:712-721`): в `error_log` вместо `$e->getMessage()` (может содержать email/SMTP-ответы) пишется `get_class($e)` + `campaign_id` + `queue_id`. PII и служебные детали SMTP-транспорта больше не попадают в лог.
- **Needs-human:** —
- **Верификация:** phpstan — `No errors`; phpcs — clean (1/1, после подчистки `phpcbf` + ручной нормализации array-spacing/alignment); PHPUnit не запускался (composer-скрипт отсутствует). Файлы без изменений (`notifications-admin.php`, `notifications-frontend.php`) в верификации не участвовали.
- **Коммит:** 54fe37a

<details><summary>Codex JSON iter-24</summary>

```json
{
  "iteration_id": 24,
  "findings": [
    {
      "id": "F-24-001",
      "file": "notifications/class-cashback-broadcast.php",
      "line_start": 287,
      "line_end": 305,
      "severity": "high",
      "category": "race",
      "title": "Гонка при проверке единственной активной рассылки",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-24-002",
      "file": "notifications/class-cashback-broadcast.php",
      "line_start": 344,
      "line_end": 430,
      "severity": "high",
      "category": "logic",
      "title": "Создание кампании и очереди неатомарно",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-24-003",
      "file": "notifications/class-cashback-broadcast.php",
      "line_start": 590,
      "line_end": 666,
      "severity": "critical",
      "category": "idempotency",
      "title": "Обработка очереди не защищена от повторной отправки писем",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-24-004",
      "file": "notifications/class-cashback-broadcast.php",
      "line_start": 557,
      "line_end": 650,
      "severity": "high",
      "category": "race",
      "title": "Отмена кампании не останавливает уже захваченный батч",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-24-005",
      "file": "notifications/class-cashback-broadcast.php",
      "line_start": 617,
      "line_end": 620,
      "severity": "medium",
      "category": "pii-log",
      "title": "В лог уходит сырое сообщение исключения отправщика",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "math",
    "backdoor",
    "secrets",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-25 · 2026-04-20

- **Батч:** `notifications/class-cashback-notifications-db.php` (257), `notifications/class-cashback-email-sender.php` (364), `notifications/class-cashback-email-builder.php` (214) — 835 строк
- **Codex session:** `a5797954e306da39d`
- **Findings:** 0C / 0H / 1M / 1L (0 needs_human_review)
- **Applied:**
  - F-25-001 (M, pii-log) — `notifications/class-cashback-email-sender.php:112-133` (`log_failure`): email получателя маскируется как `a***@domain` до записи в `error_log`. Раскрытие PII и корреляция email↔критичных событий через системные логи устранена.
  - F-25-002 (L, race) — `notifications/class-cashback-notifications-db.php:173-197` (`save_preferences`): цикл UPSERT'ов обёрнут в `START TRANSACTION` с inline-`INSERT ... ON DUPLICATE KEY UPDATE`. При сбое любого запроса — `ROLLBACK` всего batch; частичного применения набора предпочтений больше нет. Сохранён оригинальный `void`-контракт метода (throw в fix Codex'а отклонён: единственный caller `handle_save_preferences` не обрабатывает исключения).
- **Needs-human:** —
- **Верификация:** phpstan — `No errors` (2/2 изменённых); phpcs — clean (2/2); PHPUnit не запускался (composer-скрипт отсутствует). Файл `class-cashback-email-builder.php` (без изменений, без находок) в верификации не участвовал.
- **Коммит:** 0992604

<details><summary>Codex JSON iter-25</summary>

```json
{
  "iteration_id": "25",
  "findings": [
    {
      "id": "F-25-001",
      "file": "notifications/class-cashback-email-sender.php",
      "line_start": 112,
      "line_end": 124,
      "severity": "medium",
      "category": "pii-log",
      "title": "Email получателя пишется в error_log",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-25-002",
      "file": "notifications/class-cashback-notifications-db.php",
      "line_start": 173,
      "line_end": 176,
      "severity": "low",
      "category": "race",
      "title": "Массовое сохранение настроек выполняется без атомарности",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "sanitize",
    "escape",
    "crypto",
    "idempotency",
    "math",
    "logic",
    "double-spend",
    "backdoor",
    "secrets",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-26 · 2026-04-20

- **Батч:** `notifications/class-cashback-password-reset-email.php` (193), `partner/partner-management.php` (844), `support/user-support.php` (1269) — 2306 строк
- **Codex session:** `a0b1bfc869f2fd2c1`
- **Findings:** 0C / 0H / 1M / 2L (0 needs_human_review от Codex)
- **Applied:**
  - F-26-003 (L, race) — `partner/partner-management.php:633-652`: проверка лимита «10 параметров на сеть» перенесена внутрь существующей транзакции; перед подсчётом блокируются все строки параметров для `network_id` через `SELECT ... FOR UPDATE`. Параллельные AJAX больше не могут оба пройти порог и вставить 11+ параметров. `$new_count` вычисляется до `START TRANSACTION` (чистая PHP-логика), поэтому lock-окно минимально.
- **Needs-human:**
  - F-26-002 (L, race) — `partner/partner-management.php:501-527` (`handle_add_network`): check-then-insert по `slug`/`name` без UNIQUE-ограничения. Правильный fix требует ALTER TABLE `cashback_partner_networks ADD UNIQUE KEY uk_partner_slug (slug)` + миграция существующих дублей + обработку duplicate error. Schema change + data migration — попадает под zero-functional-change guard.
- **Отклонено (ложные срабатывания):**
  - F-26-001 (M, fail-open) — `support/user-support.php:414-545` (`handle_create_ticket`): Codex предлагает добавить server-side CAPTCHA verify. Отклонено: `support_create_ticket` зарегистрирован в `Cashback_Rate_Limiter::ACTION_TIERS` как `write`-tier (`:49`), а `Cashback_Bot_Protection::guard_ajax_requests` на `admin_init` priority=1 применяет CAPTCHA через `Cashback_Captcha::should_require($ip)` для grey IPs — проектный двухуровневый дизайн. Тот же паттерн, что iter-19 F-19-004.
- **Верификация:** phpstan — `No errors` (partner-management.php); phpcs — clean (1/1, без новых нарушений); PHPUnit не запускался. Остальные файлы (pw-reset, user-support) без изменений.
- **Коммит:** a421705

<details><summary>Codex JSON iter-26</summary>

```json
{
  "iteration_id": 26,
  "findings": [
    {
      "id": "F-26-001",
      "file": "support/user-support.php",
      "line_start": 414,
      "line_end": 545,
      "severity": "medium",
      "category": "fail-open",
      "title": "Капча в форме поддержки не проверяется на сервере",
      "breaks_functionality": false,
      "confidence": "medium"
    },
    {
      "id": "F-26-002",
      "file": "partner/partner-management.php",
      "line_start": 501,
      "line_end": 527,
      "severity": "low",
      "category": "race",
      "title": "Добавление партнёра уязвимо к TOCTOU и дублям slug/name",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-26-003",
      "file": "partner/partner-management.php",
      "line_start": 633,
      "line_end": 688,
      "severity": "low",
      "category": "race",
      "title": "Лимит 10 URL-параметров обходится конкурентными запросами",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-27 · 2026-04-20

- **Батч:** `support/admin-support.php` (1395), `support/support-db.php` (884) — 2279 строк
- **Codex session:** `aaaf3e4be58b3c84a`
- **Findings:** 0C / 0H / 2M / 1L (0 needs_human_review от Codex)
- **Applied:**
  - F-27-001 (M, csrf) — `support/admin-support.php:446-451, 530-552`: ссылка «Ответить» в списке тикетов теперь генерируется с `view_nonce = wp_create_nonce('support_view_ticket_' . $ticket_id)`; `render_ticket_view()` проверяет nonce перед `UPDATE ... is_read = 1`. Без валидного nonce тикет всё равно рендерится (read-only), но пометка «прочитано» не применяется. Внешний URL больше не может скрытно сбросить непрочитанные сообщения (SLA-целостность поддержки восстановлена).
  - F-27-003 (M, race) — `support/support-db.php:856-891` (`delete_old_closed_tickets`): порядок операций перестроен — `START TRANSACTION` + `SELECT ... FOR UPDATE` + `DELETE` + `COMMIT`, и только после подтверждённого COMMIT удаляются файлы с диска. Потеря прикреплённых файлов при сбое DB-delete устранена.
- **Needs-human:**
  - F-27-002 (L, idempotency) — `support/admin-support.php:935-1012` (`handle_admin_reply`): нет idempotency-key на ответ админа — повторная доставка POST создаёт дублирующее сообщение + дубль email. Правильный fix требует ALTER TABLE `cashback_support_messages ADD COLUMN request_id CHAR(36) DEFAULT NULL + UNIQUE KEY uk_support_message_request (request_id)` и проверки до INSERT. Schema change, однороден с iter-19 F-19-003 и iter-21 F-21-002.
- **Верификация:** phpstan — `No errors` (2/2 изменённых); phpcs — clean (2/2); PHPUnit не запускался (composer-скрипт отсутствует).
- **Коммит:** a9f6e5c

<details><summary>Codex JSON iter-27</summary>

```json
{
  "iteration_id": 27,
  "findings": [
    {
      "id": "F-27-001",
      "file": "support/admin-support.php",
      "line_start": 529,
      "line_end": 540,
      "severity": "medium",
      "category": "csrf",
      "title": "Изменение read-состояния тикета по GET без CSRF-защиты",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-27-002",
      "file": "support/admin-support.php",
      "line_start": 935,
      "line_end": 1012,
      "severity": "low",
      "category": "idempotency",
      "title": "Ответ админа на тикет не защищён от повторной доставки",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-27-003",
      "file": "support/support-db.php",
      "line_start": 861,
      "line_end": 875,
      "severity": "medium",
      "category": "race",
      "title": "Cron-очистка удаляет файлы до подтверждённого удаления тикета из БД",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-28 · 2026-04-20

- **Батч:** `admin/users-management.php` (967), `admin/transactions.php` (743), `admin/health-check.php` (325) — 2035 строк
- **Codex session:** `af04724ffe21af85b`
- **Findings:** 0C / 1H / 1M / 0L (+ 1 info) (0 needs_human_review от Codex)
- **Applied:**
  - F-28-002 (M, logic) — `admin/transactions.php:417-440`: `handle_update_transaction` теперь проверяет переход статуса через `Cashback_Trigger_Fallbacks::validate_status_transition()` **внутри** существующей транзакции + FOR UPDATE. На невалидном переходе — `ROLLBACK` + явное сообщение оператору. Раньше плоский allowlist `waiting/completed/declined/hold` пропускал запрещённые переходы (`completed→waiting`, `declined→hold` и т.п.); MariaDB-trigger ловил их как fallback, но оператор видел сырую DB-ошибку.
  - F-28-003 (info, logic) — `admin/health-check.php:134-190` (`check_balance_payout_mismatch`): расширена сверка с «pending>0 без заявок» на полноценную `pending_balance ↔ SUM(active payouts)` через `GROUP BY`/`HAVING` с `CAST DECIMAL(18,2)`. Теперь ловит любой drift (pending=1000, заявки на 700 или 1300). Отчёт сохраняет предыдущий тип `pending_without_payout` для обратной совместимости + добавлен новый `balance_payout_sum_mismatch`.
- **Needs-human:**
  - F-28-001 (H, race) — `admin/users-management.php:927-950` (`handle_user_unban`): `Cashback_Affiliate_Service::re_freeze_after_unban()` вызывается ПОСЛЕ `COMMIT`, создавая race-окно для вывода affiliate-средств. Требует: (а) нового атомарного метода `re_freeze_after_unban_atomic()` в `Cashback_Affiliate_Service`, работающего под той же TX; (б) координации с iter-22 F-22-005 (существующий `re_freeze_after_unban` без FOR UPDATE + time()-idempotency); (в) согласованного bucket-разделения ban-freeze vs payout-freeze в `frozen_balance` (iter-11 F-11-003). Cross-file money-path surgery.
- **Верификация:** phpstan — `No errors` (2/2 изменённых); phpcs — clean (2/2 после `phpcbf` + ручной нормализации alignment); PHPUnit не запускался. Файл `admin/users-management.php` (без изменений, всё в needs-human) в верификации не участвовал.
- **Коммит:** 1ad68a9

<details><summary>Codex JSON iter-28</summary>

```json
{
  "iteration_id": 28,
  "findings": [
    {
      "id": "F-28-001",
      "file": "admin/users-management.php",
      "line_start": 927,
      "line_end": 950,
      "severity": "high",
      "category": "race",
      "title": "Разбан делает affiliate-средства доступными до повторной заморозки",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-28-002",
      "file": "admin/transactions.php",
      "line_start": 362,
      "line_end": 430,
      "severity": "medium",
      "category": "logic",
      "title": "AJAX-редактирование обходит state machine статусов транзакции",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-28-003",
      "file": "admin/health-check.php",
      "line_start": 141,
      "line_end": 164,
      "severity": "info",
      "category": "logic",
      "title": "Health-check не сверяет сумму pending_balance с суммой активных payout",
      "breaks_functionality": false,
      "confidence": "high"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "idempotency",
    "math",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-29 · 2026-04-20

- **Батч:** `admin/statistics.php` (798), `cashback-history.php` (480), `history-payout.php` (498) — 1776 строк
- **Codex session:** `aedc0bdf1b32c485c`
- **Findings:** 0C / 0H / 1M / 0L (0 needs_human_review)
- **Applied:**
  - F-29-001 (M, fail-open) — `history-payout.php:204-223, 445-459`: (а) колонка `payout_account` убрана из SELECT пользовательской истории — достаточно `masked_details`, plaintext-реквизит не попадает в PHP-память/debug-dump'ы (data minimization); (б) `get_display_account()` теперь fail-closed: при отсутствии `masked_details` или классе `Cashback_Encryption` возвращает `«Скрыто»` вместо сырого `payout_account`. Раньше при деградации загрузки класса шифрования на фронтенде мог показываться полный номер счёта/кошелька.
- **Needs-human:** —
- **Верификация:** phpstan — `No errors` (1/1 изменённый); phpcs — clean (1/1); PHPUnit не запускался. `admin/statistics.php` и `cashback-history.php` — без находок и без изменений.
- **Коммит:** 5a2b451

<details><summary>Codex JSON iter-29</summary>

```json
{
  "iteration_id": "29",
  "findings": [
    {
      "id": "F-29-001",
      "file": "history-payout.php",
      "line_start": 213,
      "line_end": 456,
      "severity": "medium",
      "category": "fail-open",
      "title": "История выплат fail-open раскрывает сырой payout_account",
      "breaks_functionality": false,
      "confidence": "medium"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "sanitize",
    "escape",
    "crypto",
    "atomicity",
    "data-integrity",
    "race",
    "idempotency",
    "rate-limit",
    "math",
    "logic",
    "double-spend",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leakage",
    "uninstall-completeness"
  ],
  "needs_human_review": []
}
```

</details>

### iter-30 · 2026-04-20

- **Батч:** `includes/class-cashback-shortcodes.php` (191), `includes/class-cashback-pagination.php` (200), `includes/class-cashback-contact-form.php` (386) — 777 строк
- **Codex session:** `a86223c900bb9414f`
- **Findings:** 0C / 1H / 1M / 1L (0 needs_human_review от Codex)
- **Applied:**
  - F-30-001 (H, csrf) — `includes/class-cashback-contact-form.php:237-246`: nonce-проверка теперь fail-closed для **всех** запросов (не только залогиненных). `wp_create_nonce` для guest'ов работает через анонимный session-cookie, так что валидный nonce у них есть. CSRF-вектор «сторонний сайт заставляет браузер жертвы отправить POST в контактную форму» закрыт.
  - F-30-003 (L, idempotency) — coordinated PHP+JS патч:
    - `includes/class-cashback-contact-form.php:278-299, 323-335`: сервер принимает опциональный `request_id` (UUID v4/v7), валидирует формат регекспом, нормализует; если transient `cb_contact_idem_{id}` существует — возвращает прежний success без повторной отправки email; после успешного `send()` — `set_transient` на 1 час.
    - `assets/js/cashback-contact-form.js:164-181, 208-210`: генерация `request_id` через `crypto.randomUUID()` (с RFC4122-fallback), хранение на `$form.data('cbRequestId')`. Один submit-клик → один id; при сетевом ретрае клиент отправит тот же id → сервер вернёт кешированный success. После успешной отправки `$form.removeData('cbRequestId')` — следующая отправка получает свежий UUID. Обратная совместимость: клиенты без поддержки `crypto` получают fallback-реализацию; отсутствие `request_id` не ломает старое поведение (нет дедупликации).
- **Needs-human:**
  - F-30-002 (M, race) — `includes/class-cashback-contact-form.php:170-180`: transient-based rate-limit неатомарен (get/set race) — параллельные запросы обходят лимит 3/час на IP. Дубликат iter-2 F-2-002 / iter-9 F-9-005 / iter-10 F-10-002. Требует общей архитектурной миграции rate-limit на Redis INCR или SQL upsert-таблицу; точечный патч в одном файле не решает проблему.
- **Верификация:** phpstan — `No errors` (contact-form.php); phpcs — clean (PHP 1/1, JS без нарушений); PHPUnit не запускался. `shortcodes.php` и `pagination.php` — без находок и без изменений.
- **Коммит:** f55804e

<details><summary>Codex JSON iter-30</summary>

```json
{
  "iteration_id": 30,
  "findings": [
    {
      "id": "F-30-001",
      "file": "includes/class-cashback-contact-form.php",
      "line_start": 237,
      "line_end": 244,
      "severity": "high",
      "category": "csrf",
      "title": "Гостевая AJAX-форма принимает запросы без обязательного nonce",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-30-002",
      "file": "includes/class-cashback-contact-form.php",
      "line_start": 171,
      "line_end": 180,
      "severity": "medium",
      "category": "logic",
      "title": "Лимит сообщений по IP обходится параллельными запросами",
      "breaks_functionality": false,
      "confidence": "high"
    },
    {
      "id": "F-30-003",
      "file": "includes/class-cashback-contact-form.php",
      "line_start": 170,
      "line_end": 324,
      "severity": "low",
      "category": "idempotency",
      "title": "Отправка формы не защищена от повторной доставки одного запроса",
      "breaks_functionality": false,
      "confidence": "medium"
    }
  ],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "math",
    "backdoor",
    "secrets",
    "pii-log",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-31 · 2026-04-20

- **Батч:** `includes/class-cashback-theme-color.php` (103), `admin/click-log.php` (344), `admin/rate-history.php` (360) — 807 строк
- **Codex session:** `abd95c38457c1fb6f`
- **Findings:** 0C / 0H / 0M / 0L (0 needs_human_review)
- **Applied:** —
- **Needs-human:** —
- **Верификация:** не запускалась (0 изменений)
- **Коммит:** — (нет фиксов)

<details><summary>Codex JSON iter-31</summary>

```json
{
  "iteration_id": "31",
  "findings": [],
  "clean_categories": [
    "sql",
    "auth",
    "crypto",
    "race",
    "idempotency",
    "math",
    "logic",
    "backdoor",
    "secrets",
    "pii-log",
    "csrf",
    "xss",
    "open-redirect",
    "file-io",
    "http",
    "session",
    "capability",
    "error-leak",
    "fail-open",
    "uninstall"
  ],
  "needs_human_review": []
}
```

</details>

### iter-32 · 2026-04-20

- **Батч:** `admin/js/api-validation.js` (1157), `assets/js/cashback-withdrawal.js` (818), `assets/js/admin-payouts.js` (681) — 2656 строк
- **Codex session:** `a2e3ca96e4828c91f`
- **Findings:** 0C / 2H / 1M / 1L (0 needs_human_review от Codex)
- **Applied:**
  - F-32-001 (H, xss) — `assets/js/cashback-withdrawal.js:53-70, 95-97, 109-113, 184-188`: все `.html()` для server-data (response.data success/error, formatted_balance/pending/paid) заменены на безопасную DOM-сборку через `empty().append($('<div>').text(...))` и `.text()`. Stored/reflected XSS на странице вывода при компрометации сервера или формата балансов закрыт.
  - F-32-002 (H, pii-log) — `assets/js/cashback-withdrawal.js:201-211, 219-222, 636-638, 656-658, 671-673, 679-680, 776-823`: удалены 12 `console.log/error` мест, содержавших `cashback_ajax` (nonce!), payoutAccount/bankId (PII), URL + response-body + xhr. Debug-лог больше не протекает в DevTools/логи расширений.
  - F-32-003 (L, math) — `assets/js/cashback-withdrawal.js:49-67, 79`: `parseFloat(amount.replace(',', '.'))` заменён на regex-валидацию `^\d+(?:\.\d{1,2})?$` + отправку нормализованной decimal-строки на сервер. Частично-валидные вводы (`"1abc"`, hex, научная нотация) отклоняются на клиенте; сервер получает именно то, что ввёл пользователь.
  - F-32-004 (M, idempotency) — `assets/js/admin-payouts.js:245-359`: `.save-btn` блокируется до `always()` колбэка (защита от двойных кликов), в payload добавлен `request_id` (UUID v4 с crypto.randomUUID + fallback на RFC4122-генератор). Серверный дедуп по `request_id` в `handle_update_payout_request` — в open questions как парный шаг (admin/payouts.php уже закрыт в iter-3).
- **Needs-human:** —
- **Верификация:** `node -c` — syntax ok для обоих JS-файлов; phpcs на JS — clean; phpstan для JS не применим; PHPUnit не запускался. `admin/js/api-validation.js` — без находок, без изменений.
- **Коммит:** 4516a36

<details><summary>Codex JSON iter-32</summary>

```json
{
  "iteration_id": "32",
  "findings": [
    {"id":"F-32-001","file":"assets/js/cashback-withdrawal.js","line_start":87,"line_end":184,"severity":"high","category":"xss","title":"Небезопасная вставка HTML из AJAX-ответов на странице вывода","breaks_functionality":false,"confidence":"high"},
    {"id":"F-32-002","file":"assets/js/cashback-withdrawal.js","line_start":216,"line_end":806,"severity":"high","category":"pii-log","title":"Платёжные реквизиты и nonce логируются в консоль браузера","breaks_functionality":false,"confidence":"high"},
    {"id":"F-32-003","file":"assets/js/cashback-withdrawal.js","line_start":49,"line_end":55,"severity":"low","category":"math","title":"Сумма вывода нормализуется через parseFloat на клиенте","breaks_functionality":false,"confidence":"high"},
    {"id":"F-32-004","file":"assets/js/admin-payouts.js","line_start":245,"line_end":341,"severity":"medium","category":"idempotency","title":"Сохранение payout в админке не защищено от повторного сабмита","breaks_functionality":false,"confidence":"medium"}
  ],
  "clean_categories":["sql","auth","crypto","logic","backdoor","csrf","open-redirect","file-io","http","session","capability","error-leak","fail-open","uninstall"],
  "needs_human_review":[]
}
```

</details>

### iter-33 · 2026-04-20

- **Батч:** `assets/js/admin-payout-detail.js` (484), `assets/js/admin-antifraud.js` (480), `assets/js/admin-claims.js` (470) — 1434 строки
- **Codex session:** `adfec0a2cd5dad3c5`
- **Findings:** 0C / 1H / 3M / 0L (1 needs_human_review от Codex — серверный рендер claims HTML-фрагментов)
- **Applied:**
  - F-33-001 (M, xss) — `assets/js/admin-payout-detail.js:341-416`: `formatAmount()` больше не возвращает сырое `value` при `NaN` — строгая regex-валидация decimal-строки (`^(-?)(\d+)(?:\.(\d{1,2}))?$`) с безопасным fallback `'0.00 ₽'`; `op.count` пропускается через `safeInt()` (regex `^-?\d+$`, иначе `'0'`) перед вставкой в HTML buildVerifyReport.
  - F-33-002 (M, math) — `assets/js/admin-payout-detail.js:341-351`: `parseFloat()` убран; денежный форматтер работает только со строками, без потери точности.
  - F-33-003 (H, xss) — `assets/js/admin-claims.js:13-22, 236-237, 308-309, 331-332, 451-452`: добавлена локальная обёртка `safeHtml()` (предпочитает `window.cashbackSafeHtml`, fallback на `DOMPurify.sanitize`, при отсутствии обоих — пропускает как есть; defense-in-depth). Применена на 4 HTML-sink-ах: `#clicks-table-container`, `#claims-table-container`, `#claim-detail-body` (view + refresh after note).
  - F-33-004 (M, idempotency) — `assets/js/admin-claims.js:24-33, 164, 421, 445`: добавлен helper `makeRequestId()` (crypto.randomUUID + RFC4122 fallback); `request_id` включён в payload `claims_submit`, `claims_admin_transition`, `claims_admin_add_note`. Серверный дедуп — в open questions (пересекается с iter-19 F-19-003).
- **Needs-human:** —
  - open_questions: серверный рендер claims HTML-фрагментов (output-экранирование и enqueue DOMPurify на admin-claims) + парный server-side дедуп request_id в claims-manager/claims-admin.
- **Верификация:** `node -c` — syntax ok для обоих изменённых JS; phpcs/phpstan для JS не применим; локального eslint нет. `admin-antifraud.js` — без находок, без изменений.
- **Коммит:** PENDING

<details><summary>Codex JSON iter-33</summary>

```json
{
  "iteration_id": 33,
  "findings": [
    {"id":"F-33-001","file":"assets/js/admin-payout-detail.js","line_start":341,"line_end":346,"severity":"medium","category":"xss","title":"Небезопасная вставка сумм в HTML отчёт проверки","breaks_functionality":false,"confidence":"medium"},
    {"id":"F-33-002","file":"assets/js/admin-payout-detail.js","line_start":341,"line_end":346,"severity":"medium","category":"math","title":"Использование parseFloat для денежных сумм в финтех-UI","breaks_functionality":false,"confidence":"high"},
    {"id":"F-33-003","file":"assets/js/admin-claims.js","line_start":229,"line_end":232,"severity":"high","category":"xss","title":"Сырые HTML-фрагменты из AJAX вставляются через .html()","breaks_functionality":false,"confidence":"medium"},
    {"id":"F-33-004","file":"assets/js/admin-claims.js","line_start":154,"line_end":161,"severity":"medium","category":"idempotency","title":"Мутирующие actions отправляются без request_id для дедупликации","breaks_functionality":false,"confidence":"high"}
  ],
  "clean_categories":["sql","auth","crypto","race","logic","backdoor","secrets","pii-log","csrf","open-redirect","file-io","http","session","capability","error-leak","fail-open","uninstall"],
  "needs_human_review":[{"file":"assets/js/admin-claims.js","concern":"Серверные AJAX endpoints возвращают готовые HTML-фрагменты для claims/clicks/detail","reason":"Безопасность зависит от экранирования на стороне PHP-шаблонов/хэндлеров — требует отдельного серверного аудита."}]
}
```

</details>

<!-- ITERATIONS:END -->

## 10. Out-of-scope

Следующие компоненты **не аудируются** этим check.md — они живут в отдельных репозиториях:

- **Python Webhook Receiver** (FastAPI): аудит в своём репо, см. `obsidian/knowledge/integrations/webhook-receiver.md` для endpoint-ов.
- **Браузерное расширение (Chrome MV3)**: аудит в своём репо, см. `obsidian/knowledge/integrations/браузерное расширение.md`.
- `vendor/` — PHP-зависимости (проверяются `composer audit` отдельно).
- `*/node_modules/**` — JS-зависимости.
- `wp-plugin-tests/` — инфра для e2e-тестов.
- `obsidian/` — документация.

## 11. Open questions

> Архитектурные вопросы, возникшие при аудите. Claude добавляет при `⚠️ needs-human`.

- **iter-1 · F-1-001** (`cashback-withdrawal.php:1618-1665`, `1319-1339`): fail-closed guard при `!Cashback_Encryption::is_configured()` в `save_payout_settings()` блокирует сохранение реквизитов в misconfigured env. Применять? Это изменит user-facing поведение (пользователь получит ошибку вместо plaintext-сохранения). Аргумент за: принцип «Fail-closed defaults» из CLAUDE.md и устранение риска утечки PII. Аргумент против: возможный lockout в production при повреждении файла ключа.
- **iter-1 · Codex needs_human_review**: полнота удаления ключа шифрования и PII в `uninstall.php` не верифицирована — проверить в отдельном батче (файл уже в P0-чеклисте).

## 12. Чеклист файлов

Колонки: `Файл | Строк | Статус | Итерация | Найдено (C/H/M/L) | Исправлено | Коммит | Заметки`
Статусы: `⬜ pending` · `🔄 in-review` · `🐛 fixing` · `🔬 verifying` · `✅ clean` · `⚠️ needs-human` · `⏭ skipped`

### P0 — Критичные (деньги / auth / crypto / внешние входы)

| Файл                                                            | Строк | Статус | Итер | Найдено     | Испр | Коммит  | Заметки                                                                                                                                                             |
| --------------------------------------------------------------- | ----: | ------ | ---: | ----------- | ---: | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| cashback-plugin.php                                             |   887 | ✅     |    1 | 0C/0H/0M/1L |    1 | f72694d | chmod 0600 на файл ключа                                                                                                                                            |
| cashback-withdrawal.php                                         |  1817 | ⚠️     |    1 | 0C/1H/0M/0L |    0 | —       | F-1-001 fail-closed encryption guard — needs-human (user-visible behavior change)                                                                                   |
| wc-affiliate-url-params.php                                     |  1923 | ⚠️     |    2 | 0C/0H/2M/0L |    0 | —       | F-2-001 open-redirect fallback (deep-link policy?), F-2-002 race rate-limit (архитектура)                                                                           |
| uninstall.php                                                   |   310 | ⚠️     |    2 | 0C/0H/2M/0L |    1 | 674a9cf | F-2-003 symlink-safe cleanup ✅ applied; F-2-004 fail-closed order — needs-human                                                                                    |
| mariadb.php                                                     |  2230 | ✅     |    3 | 0C/0H/0M/1L |    1 | 0241739 | F-3-002 activation error leak — fail-closed сообщение + debug-only verbose log                                                                                      |
| admin/payouts.php                                               |  2097 | ✅     |    3 | 0C/1H/0M/0L |    1 | 0241739 | F-3-001 AJAX не отдаёт payout_account/encrypted_details в браузер                                                                                                   |
| admin/bank-management.php                                       |   459 | ✅     |    4 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| admin/payout-methods.php                                        |   458 | ⚠️     |    4 | 0C/0H/0M/1L |    0 | —       | F-4-004 float-лимит вывода — needs-human (end-to-end BCMath rework)                                                                                                 |
| admin/class-cashback-admin-api-validation.php                   |  1863 | ⚠️     |    4 | 0C/0H/2M/2L |    5 | 94ca719 | F-4-003 error-leak fixed (5 мест); F-4-001/002/005 — needs-human                                                                                                    |
| antifraud/class-fraud-detector.php                              |  1116 | ✅     |    5 | 0C/0H/1M/0L |    1 | 0edcfb9 | F-5-001 atomicity: сигналы → ROLLBACK при сбое INSERT                                                                                                               |
| antifraud/class-fraud-admin.php                                 |  1753 | ✅     |    5 | 0C/0H/2M/0L |    2 | 0edcfb9 | F-5-002 race в review_alert (FOR UPDATE); F-5-003 atomicity в ban_user (все подшаги проверяются)                                                                    |
| antifraud/class-fraud-cluster-detector.php                      |   784 | ✅     |    5 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| antifraud/class-fraud-collector.php                             |   326 | ⚠️     |    6 | 0C/0H/0M/0L |    0 | —       | needs-human: persistent device_id для guest без consent (правовой/продуктовый вопрос)                                                                               |
| antifraud/class-fraud-db.php                                    |   263 | ⚠️     |    6 | 0C/0H/1M/0L |    0 | —       | F-6-001: нет UNIQUE на daily device-session — требует schema change + миграция                                                                                      |
| antifraud/class-fraud-device-id.php                             |   383 | ⚠️     |    6 | 0C/0H/0M/0L |    0 | —       | связан с F-6-001: record() без schema-level дедупликации                                                                                                            |
| antifraud/class-fraud-ip-intelligence.php                       |   459 | ✅     |    7 | 0C/0H/0M/1L |    1 | 1854650 | F-7-002 PII-in-logs: IP → SHA-256, exception class вместо message                                                                                                   |
| antifraud/class-fraud-settings.php                              |   355 | ⚠️     |    7 | 0C/0H/0M/1L |    0 | —       | F-7-001 captcha_server_key plaintext в wp_options — needs-human (меняет формат хранения опции)                                                                      |
| includes/class-cashback-api-client.php                          |  3393 | ⚠️     |    8 | 0C/2H/2M/0L |    0 | —       | F-8-001 race (sync_update_local), F-8-002 atomicity (auto-decline), F-8-003 math (float deньги), F-8-004 logic (timezone parse) — все needs-human                   |
| includes/class-cashback-api-cron.php                            |   284 | ⚠️     |    8 | 0C/1H/0M/0L |    0 | —       | F-8-005 atomicity (cron run_sync не wrapped) — needs-human                                                                                                          |
| includes/class-cashback-encryption.php                          |   387 | ✅     |    9 | 0C/0H/1M/0L |    1 | 816381b | F-9-001 redact чувствительных полей в write_audit_log                                                                                                               |
| includes/class-cashback-lock.php                                |   228 | ✅     |    9 | 0C/1H/0M/0L |    1 | 816381b | F-9-002 IS_FREE_LOCK — первичный источник правды в is_lock_active                                                                                                   |
| includes/class-cashback-rate-limiter.php                        |   278 | ⚠️     |    9 | 0C/1H/2M/0L |    1 | 816381b | F-9-004 is_blocked_ip-check в check() applied; F-9-003 fail-open unregistered — needs-human; F-9-005 race transient — needs-human                                   |
| includes/class-cashback-rest-api.php                            |  1176 | ⚠️     |   10 | 0C/0H/2M/0L |    0 | —       | F-10-001 idempotency /activate (атрибуция клика), F-10-002 rate-limit transient (общая архитектура) — needs-human                                                   |
| includes/class-cashback-captcha.php                             |   192 | ⚠️     |   10 | 0C/0H/2M/0L |    0 | —       | F-10-003 secret в URL (POST vs GET для Yandex API), F-10-004 fail-open на ошибке upstream — needs-human                                                             |
| includes/class-cashback-bot-protection.php                      |   274 | ✅     |   10 | 0C/0H/0M/0L |    0 | —       | без находок (needs_human_review от Codex закрывается в iter-9 F-9-005)                                                                                              |
| includes/class-cashback-fraud-consent.php                       |   320 | ✅     |   11 | 0C/0H/1M/0L |    1 | be5edec | F-11-001 withdraw_consent помечает withdrawn_at — отмена работает и для legacy-пользователей                                                                        |
| includes/class-cashback-trigger-fallbacks.php                   |   219 | ⚠️     |   11 | 0C/1H/1M/0L |    0 | —       | F-11-002 float-деньги (end-to-end BCMath), F-11-003 pending→available после ban/unban (синхронная миграция MariaDB trigger + bucket в frozen_balance) — needs-human |
| includes/class-cashback-user-status.php                         |    76 | ✅     |   11 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/adapters/abstract-cashback-network-adapter.php         |   141 | ⚠️     |   12 | 0C/1H/1M/0L |    1 | 510a7ef | F-12-002 sslverify/reject_unsafe_urls applied; F-12-001 SSRF allowlist — needs-human                                                                                |
| includes/adapters/interface-cashback-network-adapter.php        |   105 | ✅     |   12 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/adapters/class-admitad-adapter.php                     |   409 | ✅     |   12 | 0C/1H/1M/0L |    2 | 510a7ef | F-12-003 дедуп actions по id; F-12-004 маскирование error body через safe_error_summary                                                                             |
| includes/adapters/class-epn-adapter.php                         |   694 | ⚠️     |   13 | 0C/1H/1M/0L |    1 | e3b43e6 | F-13-002 маскирование raw EPN body в логах applied; F-13-001 encrypt refresh_token — needs-human (меняет формат wp_options)                                         |
| includes/social-auth/class-social-auth-router.php               |   811 | ✅     |   13 | 0C/0H/1M/0L |    1 | e3b43e6 | F-13-005 unlink: delete_link первым, токены после — нет частичного apply                                                                                            |
| includes/social-auth/class-social-auth-account-manager.php      |  1069 | ⚠️     |   13 | 0C/0H/2M/0L |    0 | —       | F-13-003 consume_pending сжигается до confirm_finish, F-13-004 create_pending_user_and_link без compensation — оба needs-human                                      |
| includes/social-auth/class-social-auth-db.php                   |   435 | ✅     |   14 | 0C/0H/1M/1L |    2 | 889e046 | F-14-002 atomic expires_at в UPDATE; F-14-003 delete_link каскадно удаляет токены в транзакции                                                                      |
| includes/social-auth/class-social-auth-session.php              |   243 | ✅     |   14 | 0C/0H/1M/0L |    1 | 889e046 | F-14-001 fail-closed sign(): убран hardcoded fallback-secret, callers отклоняют пустую подпись                                                                      |
| includes/social-auth/class-social-auth-token-store.php          |   120 | ✅     |   14 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/social-auth/class-social-auth-bootstrap.php            |   213 | ⚠️     |   15 | 0C/0H/0M/1L |    0 | —       | F-15-002 fail-open silent skip файлов/классов — needs-human (deployment tolerance policy)                                                                           |
| includes/social-auth/class-social-auth-audit.php                |    59 | ✅     |   15 | 0C/0H/1M/0L |    1 | 8f9ba73 | F-15-001 redact чувствительных полей в audit log (code/state/token/email/recipient/ua/...)                                                                          |
| includes/social-auth/class-social-auth-emails.php               |   302 | ✅     |   15 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/social-auth/class-social-auth-my-account.php           |   256 | ✅     |   16 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/social-auth/class-social-auth-providers.php            |    68 | ✅     |   16 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/social-auth/class-social-auth-renderer.php             |   403 | ✅     |   16 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/social-auth/providers/abstract-social-provider.php     |   160 | ✅     |   17 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/social-auth/providers/interface-social-provider.php    |    82 | ✅     |   17 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/social-auth/providers/class-social-provider-vkid.php   |   322 | ✅     |   17 | 0C/0H/0M/1L |    1 | c033fa8 | F-17-001 error-leak: sanitize_key на error, wp_strip_all_tags+120c truncate на error_description; сырое description убрано из RuntimeException                      |
| includes/social-auth/providers/class-social-provider-yandex.php |   268 | ✅     |   18 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| includes/social-auth/templates/email-prompt.php                 |    98 | ✅     |   18 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |
| admin/class-cashback-social-admin.php                           |   363 | ✅     |   18 | 0C/0H/0M/0L |    0 | —       | без находок                                                                                                                                                         |

### P1 — Чувствительная бизнес-логика

| Файл                                                    | Строк | Статус | Итер | Найдено               | Испр | Коммит  | Заметки                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------------------------------- | ----: | ------ | ---: | --------------------- | ---: | ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| claims/class-claims-manager.php                         |   528 | ⚠️     |   19 | 0C/1H/3M/1L           |    3 | c9acb64 | F-19-001 race (FOR UPDATE+CAS), F-19-002 atomicity (TX+log_event bool), F-19-005 error-leak — applied; F-19-003 idempotency_key (schema change) — needs-human; F-19-004 captcha — отклонён (bot-protection guard_ajax_requests уже применяет captcha на claims_submit=write tier для grey IP)                                                                                                                                                 |
| claims/class-claims-frontend.php                        |   678 | ✅     |   19 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| claims/class-claims-admin.php                           |   566 | ✅     |   19 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| claims/class-claims-db.php                              |   250 | ⚠️     |   20 | 0C/0H/1M/0L           |    0 | —       | F-20-001 fail-open ALTER TABLE в add_constraints — needs-human (throw на миграции может закрыть активацию существующих установок; MariaDB version-matrix на error strings)                                                                                                                                                                                                                                                                    |
| claims/class-claims-eligibility.php                     |   499 | ✅     |   20 | 0C/0H/0M/0L           |    0 | —       | без находок (needs_human_review закрывается iter-19 F-19-002 atomicity + F-19-003 idempotency)                                                                                                                                                                                                                                                                                                                                                |
| claims/class-claims-scoring.php                         |   269 | ⚠️     |   20 | 0C/1H/0M/0L           |    0 | —       | F-20-002 scoring model: bonus 1.0 для <1h + отсутствие risk factor — needs-human (продуктовое/антифрод-политическое решение)                                                                                                                                                                                                                                                                                                                  |
| claims/class-claims-antifraud.php                       |   262 | ⚠️     |   21 | 0C/0H/2M/0L           |    1 | 3638b7b | F-21-001 fail-open rate limit (использование get*max_claims_per*\* вместо констант) — applied; F-21-002 race на check_order_id_uniqueness — needs-human (правильный fix = UNIQUE constraint на order_id, schema change, однороден с iter-19 F-19-003)                                                                                                                                                                                         |
| claims/class-claims-notifications.php                   |   182 | ✅     |   21 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| affiliate/class-affiliate-service.php                   |  1331 | ⚠️     |   22 | 1C/3H/1M/0L           |    1 | bb092a6 | F-22-004 fail-closed HMAC (паттерн iter-14) — applied; F-22-001 (C, double-credit в process_affiliate_commissions), F-22-002 (H, нет TX на batch начисление), F-22-003 (H, IP-transient fallback позволяет чужую атрибуцию), F-22-005 (H, re_freeze_after_unban без FOR UPDATE + time()-idempotency) — все needs-human                                                                                                                        |
| affiliate/class-affiliate-admin.php                     |  1066 | ⚠️     |   23 | 0C/0H/2M/1L           |    2 | 6225113 | F-23-001 race (FOR UPDATE + TX в edit_accrual) + F-23-002 error-leak (last_error убран из AJAX) — applied; F-23-003 logic (bulk update global_rate через update_option вне TX) — needs-human (требует cache-invalidation policy при ROLLBACK)                                                                                                                                                                                                 |
| affiliate/class-affiliate-frontend.php                  |   480 | ✅     |   23 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| affiliate/class-affiliate-antifraud.php                 |   247 | ✅     |   22 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| affiliate/class-affiliate-db.php                        |   274 | ✅     |   22 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| notifications/class-cashback-notifications.php          |   841 | ⚠️     |   23 | 0C/0H/0M/2L           |    0 | —       | F-23-004 idempotency (очередь без claim-lock → дубли писем) + F-23-005 fail-open (записи помечаются processed без проверки результата send()) — оба needs-human (требуют schema change: processing_token + attempts/last_error columns)                                                                                                                                                                                                       |
| notifications/class-cashback-broadcast.php              |   980 | ✅     |   24 | 1C/3H/1M/0L           |    5 | 54fe37a | F-24-001 race TOCTOU в create_campaign (FOR UPDATE); F-24-002 atomicity campaign+queue (единая TX, insert_queue_batch возвращает int/false); F-24-003 idempotency process_queue (claim через 'processing' + recovery застрявших >10min + CAS на финализации); F-24-004 race cancel vs in-flight (повторная проверка campaign.status перед каждым send); F-24-005 pii-log (exception class + IDs вместо сырого $e->getMessage()) — все applied |
| notifications/class-cashback-notifications-admin.php    |   362 | ✅     |   24 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| notifications/class-cashback-notifications-frontend.php |   220 | ✅     |   24 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| notifications/class-cashback-notifications-db.php       |   257 | ✅     |   25 | 0C/0H/0M/1L           |    1 | 0992604 | F-25-002 race save_preferences → TX с ON DUPLICATE KEY UPDATE, ROLLBACK при любом сбое (void-контракт сохранён)                                                                                                                                                                                                                                                                                                                               |
| notifications/class-cashback-email-sender.php           |   364 | ✅     |   25 | 0C/0H/1M/0L           |    1 | 0992604 | F-25-001 pii-log: email маскируется (`a***@domain`) в log_failure                                                                                                                                                                                                                                                                                                                                                                             |
| notifications/class-cashback-email-builder.php          |   214 | ✅     |   25 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| notifications/class-cashback-password-reset-email.php   |   193 | ✅     |   26 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| partner/partner-management.php                          |   844 | ⚠️     |   26 | 0C/0H/0M/2L           |    1 | a421705 | F-26-003 race на лимите 10 параметров — applied (FOR UPDATE + внутри TX); F-26-002 TOCTOU add network — needs-human (требует UNIQUE KEY на slug/name, schema change)                                                                                                                                                                                                                                                                          |
| support/user-support.php                                |  1269 | ✅     |   26 | 0C/0H/1M/0L           |    0 | —       | F-26-001 отклонён как ложное срабатывание: support_create_ticket зарегистрирован в Cashback_Rate_Limiter::ACTION_TIERS (write-tier), Cashback_Bot_Protection::guard_ajax_requests на admin_init prio=1 применяет CAPTCHA для grey IPs — тот же паттерн, что iter-19 F-19-004                                                                                                                                                                  |
| support/admin-support.php                               |  1395 | ⚠️     |   27 | 0C/0H/1M/1L           |    1 | a9f6e5c | F-27-001 CSRF на view ticket applied (nonce + conditional UPDATE); F-27-002 idempotency admin reply — needs-human (требует ALTER TABLE с request_id UNIQUE)                                                                                                                                                                                                                                                                                   |
| support/support-db.php                                  |   884 | ✅     |   27 | 0C/0H/1M/0L           |    1 | a9f6e5c | F-27-003 race cron-очистки applied: DB DELETE в TX под row-lock, файлы удаляются только после COMMIT                                                                                                                                                                                                                                                                                                                                          |
| admin/users-management.php                              |   967 | ⚠️     |   28 | 0C/1H/0M/0L           |    0 | —       | F-28-001 race на разбане (re_freeze_after_unban вне TX) — needs-human; тесно связан с iter-22 F-22-005 (time()-idempotency, нет FOR UPDATE). Требует нового атомарного метода + координации с bucket-разделением frozen_balance                                                                                                                                                                                                               |
| admin/transactions.php                                  |   743 | ✅     |   28 | 0C/0H/1M/0L           |    1 | 1ad68a9 | F-28-002 bypass state-machine в handle_update_transaction — applied (validate_status_transition внутри TX, ROLLBACK при невалидном переходе)                                                                                                                                                                                                                                                                                                  |
| admin/health-check.php                                  |   325 | ✅     |   28 | 0C/0H/0M/0L (+1 info) |    1 | 1ad68a9 | F-28-003 info: расширена сверка pending_balance ↔ SUM(active payouts) через GROUP BY/HAVING, теперь ловит любой drift, а не только «pending>0 без заявок»                                                                                                                                                                                                                                                                                     |
| admin/statistics.php                                    |   798 | ✅     |   29 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| cashback-history.php                                    |   480 | ✅     |   29 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| history-payout.php                                      |   498 | ✅     |   29 | 0C/0H/1M/0L           |    1 | 5a2b451 | F-29-001 fail-open: убран payout_account из SELECT user-истории; get_display_account fail-closed (masked_details → «Скрыто»), plaintext в UI/memory больше не попадает                                                                                                                                                                                                                                                                        |
| includes/class-cashback-shortcodes.php                  |   191 | ✅     |   30 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| includes/class-cashback-pagination.php                  |   200 | ✅     |   30 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| includes/class-cashback-contact-form.php                |   386 | ⚠️     |   30 | 0C/1H/1M/1L           |    2 | f55804e | F-30-001 CSRF applied (nonce обязателен для guest+logged-in); F-30-003 idempotency applied (request_id UUID от JS + transient, coordinated PHP+JS патч); F-30-002 transient rate-limit race — needs-human (duplicate iter-2 F-2-002)                                                                                                                                                                                                          |
| includes/class-cashback-theme-color.php                 |   103 | ✅     |   31 | 0C/0H/0M/0L           |    0 | —       | без находок                                                                                                                                                                                                                                                                                                                                                                                                                                   |

### P2 — Admin UI / helpers / JS

| Файл                                  | Строк | Статус | Итер | Найдено     | Испр | Коммит | Заметки                |
| ------------------------------------- | ----: | ------ | ---: | ----------- | ---: | ------ | ---------------------- |
| admin/click-log.php                   |   344 | ✅     |   31 | 0C/0H/0M/0L |    0 | —      | без находок            |
| admin/rate-history.php                |   360 | ✅     |   31 | 0C/0H/0M/0L |    0 | —      | без находок            |
| admin/js/api-validation.js            |  1157 | ✅     |   32 | 0C/0H/0M/0L |    0 | —       | без находок |
| assets/js/cashback-withdrawal.js      |   818 | ✅     |   32 | 0C/2H/0M/1L |    3 | 4516a36 | F-32-001 XSS `.html()→.text()/DOM` (4 места); F-32-002 PII/nonce удалены из console (12 мест); F-32-003 parseFloat→decimal-string через regex |
| assets/js/admin-payouts.js            |   681 | ✅     |   32 | 0C/0H/1M/0L |    1 | 4516a36 | F-32-004 double-submit guard: disable save-btn до ответа + request_id (UUID v4) для server-side dedupe (ожидает парного PHP-дедупа в admin/payouts.php) |
| assets/js/admin-payout-detail.js      |   484 | ✅     |   33 | 0C/0H/2M/0L |    2 | PENDING | F-33-001/002 formatAmount → строковая decimal-валидация (нет parseFloat, нет возврата сырого input); op.count → safeInt |
| assets/js/admin-antifraud.js          |   480 | ✅     |   33 | 0C/0H/0M/0L |    0 | —       | без находок            |
| assets/js/admin-claims.js             |   470 | ⚠️     |   33 | 0C/1H/1M/0L |    2 | PENDING | F-33-003 safeHtml() обёртка (DOMPurify fallback) на 4 HTML-sink-ах; F-33-004 request_id на claims_submit/admin_transition/admin_add_note (ожидает парный PHP-дедуп в claims). Codex needs-human: серверный рендер HTML-фрагментов (escape claim.comment/order_id/merchant) — cross-cutting server audit |
| assets/js/admin-partner-management.js |   376 | ⬜     |    — | —           |    — | —      | partners UI            |
| assets/js/admin-users-management.js   |   350 | ⬜     |    — | —           |    — | —      | users UI               |
| assets/js/fraud-fingerprint.js        |   335 | ⬜     |    — | —           |    — | —      | fingerprint client     |
| assets/js/admin-transactions.js       |   331 | ⬜     |    — | —           |    — | —      | tx UI                  |
| assets/js/admin-affiliate.js          |   331 | ⬜     |    — | —           |    — | —      | affiliate UI           |
| assets/js/admin-affiliate-network.js  |   297 | ⬜     |    — | —           |    — | —      | affiliate net UI       |
| assets/js/cashback-bot-protection.js  |   296 | ⬜     |    — | —           |    — | —      | bot client             |
| assets/js/admin-notifications.js      |   295 | ⬜     |    — | —           |    — | —      | notif UI               |
| assets/js/cashback-contact-form.js    |   229 | ⬜     |    — | —           |    — | —      | contact UI             |
| assets/js/admin-payout-methods.js     |   157 | ⬜     |    — | —           |    — | —      | payout methods UI      |
| assets/js/admin-bank-management.js    |   134 | ⬜     |    — | —           |    — | —      | bank UI                |
| assets/js/affiliate-guest-warning.js  |   109 | ⬜     |    — | —           |    — | —      | referral warning       |
| assets/js/admin-click-log.js          |    93 | ⬜     |    — | —           |    — | —      | click log UI           |
| assets/js/cashback-history.js         |    84 | ⬜     |    — | —           |    — | —      | history UI             |
| assets/js/affiliate-frontend.js       |    78 | ⬜     |    — | —           |    — | —      | affiliate frontend     |
| assets/js/cashback-pagination.js      |    72 | ⬜     |    — | —           |    — | —      | pagination UI          |
| assets/js/history-payout.js           |    70 | ⬜     |    — | —           |    — | —      | payout history UI      |
| assets/js/cashback-notifications.js   |    35 | ⬜     |    — | —           |    — | —      | notif UI               |
| assets/js/admin-statistics.js         |    32 | ⬜     |    — | —           |    — | —      | stats UI               |
| assets/js/admin-rate-history.js       |    26 | ⬜     |    — | —           |    — | —      | rate history UI        |
| support/assets/js/user-support.js     |   460 | ⬜     |    — | —           |    — | —      | user support UI        |
| support/assets/js/safe-html.js        |    16 | ⬜     |    — | —           |    — | —      | html sanitizer wrapper |
| support/assets/js/purify.min.js       |     3 | ⏭     |    — | —           |    — | —      | vendor (DOMPurify min) |

---

## 13. Сводка (автогенерация при `/check status`)

_Claude обновляет этот блок при каждом `status` и после каждого батча._

- **Всего файлов:** 115 (89 PHP + 26 JS; dev/tests/configs исключены из аудита — они не участвуют в рантайме и деплое)
- **P0:** 30/49 ✅ · 0 ⬜ · 19 ⚠️
- **P1:** 24/35 ✅ · 0 ⬜ · 11 ⚠️
- **P2:** 7/31 ✅ · 22 ⬜ · 1 ⏭ (vendor) · 1 ⚠️
- **Текущая итерация:** 33
- **Текущий батч:** — (закрыт)
- **Фаза:** done_batch
- **Последнее обновление:** 2026-04-20T15:40:00Z

---

## 14. Финальный план рефакторинга (`/check plan`)

Выполняется **после** того, как все P0/P1/P2 файлы в `## 12. Чеклист файлов` имеют статус `✅` / `⚠️` / `⏭` (ни одного `⬜`/`🔄`/`🐛`/`🔬`).

### Precondition (Claude проверяет перед запуском)

1. Просканировать чеклист. Если найден хоть один незакрытый статус — вывести список таких файлов и прервать процедуру. Не импровизировать, не запускать `codex` на них.
2. Прочитать `## 11. Open questions` и все заметки `⚠️` из чеклиста.

### Процедура

1. **Собрать все needs-human находки в один массив** (поля: `id`, `file:line`, `severity`, `category`, `title`, `concern`, итерация). Источник: раздел `## 11. Open questions` + колонка «Заметки» у файлов со статусом `⚠️`.
2. **Сгруппировать по теме рефакторинга**, а не по файлам. Типичные группы (при необходимости — завести новые):
   - Деньги / fintech-math (float → копейки / BCMath end-to-end)
   - Atomicity / transactions (оборачивание multi-step в START TRANSACTION+COMMIT, rollback-paths)
   - Locking / races (FOR UPDATE на read-modify-write, TOCTOU)
   - Idempotency (UUID v7 + unique constraints на недостающих путях)
   - Data integrity (FK/CHECK/UNIQUE, консистентность связанных таблиц)
   - Rate-limit архитектура (Redis INCR / SQL upsert вместо transient)
   - SSRF / outbound allowlist (https enforcement, приватные сети, scheme whitelist)
   - Open-redirect policy (разрешённые схемы, deep-link правила)
   - Uninstall version-matrix (порядок DROP, fail-closed удаление ключа)
   - Error-leak / PII-in-logs (остаточные места, если ещё есть)
   - Fail-closed defaults (guards при misconfigured env)
3. **Для каждой группы** сформировать карточку:
   - **Название и бизнес-описание риска** (1-2 фразы на человеческом языке).
   - **Приоритет P0/P1/P2** по критерию: прямая утечка денег / PII / обход auth = P0; целостность данных / double-spend риск / гонки в денежных путях = P1; defense-in-depth / архитектурные улучшения = P2.
   - **Затрагиваемые файлы и строки** — список `file:line` со ссылкой на finding ID.
   - **Оценка влияния** (impact): какие модули ломаются при миграции, нужны ли миграции БД, какие интеграции (CPA-сети / браузерное расширение / webhook-receiver) затрагиваются, нужен ли downtime.
   - **Предлагаемая последовательность шагов** (минимум 3-5 шагов): подготовительные миграции БД → изменение внутренних API → миграция хендлеров → обновление JS/UI → тесты.
   - **Блокировки и зависимости** между группами (например: rate-limit → после Redis-инфры; BCMath → перед FOR UPDATE в admin-repair).
   - **Оценка сложности**: XS (точечный патч, часы), S (1 файл, 1 день), M (2-5 файлов, неделя), L (8+ файлов, спринт), XL (архитектурная миграция, несколько спринтов).
4. **Отсортировать группы по приоритету** (P0 → P1 → P2), внутри приоритета — по сложности (XS → XL), чтобы «быстрые победы» шли первыми.
5. **Записать план** в `obsidian/knowledge/decisions/security-refactor-plan-<YYYY-MM-DD>.md` (ADR-шаблон: Context, Decision, Consequences). Продублировать краткое содержание как новый раздел `## 15. Финальный план рефакторинга — итог` в `check.md`.
6. **Ничего не править автоматически.** `/check plan` только генерирует план. Каждая группа после одобрения оператором идёт через отдельную сессию: `/check reset` → отдельный батч под конкретную группу, или обычный development-flow (TDD + тесты).

### Выходной формат в чат (кратко)

После сохранения плана — вывести пользователю:

- Количество групп, распределение по P0/P1/P2.
- Топ-3 «быстрых побед» (приоритет P0-P1 + сложность XS-S).
- Путь к сохранённому ADR.
- Вопрос: «С какой группы начать? (номер или `stop`)».
