---
tags: [debugging, phpcs, phpstan, artefacts, static-analysis]
date: 2026-06-01
---

# Static analysis: ignored archival artefacts

## Контекст

После релиза `v4.4.52` full `phpstan` и full `phpcs` падали на файлах в `artefacts/2026-04-30-e2e-fintech-full-h/`.

`/artefacts/` уже указан в `.gitignore`, а `git ls-files artefacts/2026-04-30-e2e-fintech-full-h` не возвращает production-файлов. Эти runner-файлы являются архивными E2E/evidence artefacts и не участвуют в runtime WordPress-плагина.

## Решение

`artefacts/` исключен из full static/lint gates:

- `phpstan.neon`: `excludePaths`
- `phpcs.xml`: `exclude-pattern`

Это не suppression production-кода и не baseline. Цель — не анализировать generated/archival evidence, который уже не коммитится и не поставляется как runtime-код плагина.

## Проверка

Production PHPCS-долг исправляется отдельно только форматированием/диагностическими комментариями в tracked PHP-файлах. Full gates должны проверять production-код, конфиги и документацию, но не ignored archival artefacts.
