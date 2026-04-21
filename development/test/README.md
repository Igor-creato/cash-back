# Тесты плагина Cashback

## Запуск тестов

```bash
# Windows — все тесты
run-tests.bat

# Windows — конкретная группа
run-tests.bat encryption      # шифрование
run-tests.bat calculation     # расчёты кэшбэка
run-tests.bat integrity       # целостность данных
run-tests.bat fraud           # антифрод
run-tests.bat reference_id    # генерация Reference ID

# напрямую через PHP
f:\wamp64\bin\php\php8.3.28\php.exe vendor/bin/phpunit --configuration phpunit.xml.dist --testdox
```

## Структура тестов

| Файл                                | Группа         | Что тестирует                            |
| ----------------------------------- | -------------- | ---------------------------------------- |
| `tests/EncryptionTest.php`          | `encryption`   | AES-256-GCM шифрование, маскировка, хеши |
| `tests/CashbackCalculationTest.php` | `calculation`  | Расчёт кэшбэка, переходы статусов        |
| `tests/ReferenceIdTest.php`         | `reference_id` | Генерация ID заявок WD-XXXXXXXX          |
| `tests/FraudDetectorTest.php`       | `fraud`        | Антифрод, риск-скоры                     |
| `tests/DataIntegrityTest.php`       | `integrity`    | Целостность данных, бизнес-правила       |

## Найденные баги (исправлены)

### BUG-001: decrypt_gcm() отклоняла пустую строку

**Файл**: `includes/class-cashback-encryption.php`, метод `decrypt_gcm()`  
**Проблема**: `$min_length = GCM_IV_LENGTH + GCM_TAG_LENGTH + 1` — лишний +1 означал что шифрование/дешифрование пустой строки невозможно  
**Исправление**: Убрали +1: `$min_length = self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH`

### BUG-002: charset_len=30 при алфавите из 31 символа

**Файл**: `mariadb.php`, метод `generate_reference_id()`  
**Проблема**: `$charset = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'` содержит 31 символ (8 цифр + 23 буквы), но `$charset_len = 30` — символ 'Z' никогда не использовался  
**Исправление**: `$charset_len = 31`

## Статистика покрытия

- **159 тестов**, 2272+ assertions
- Скорость: ~130ms
- Пропущен: 1 тест (требует runtime-константу `CASHBACK_TRUSTED_PROXIES`)

## Зависимости

Тесты не требуют:

- ❌ WordPress установку
- ❌ MySQL/MariaDB базу данных
- ❌ WooCommerce

Тесты используют моки WordPress функций из `bootstrap.php`.
