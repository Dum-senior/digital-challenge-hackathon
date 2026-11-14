# 02_ДЕН — проверка изменений (14.11.2026)

## Фактически выполнено в среде подготовки

| Проверка | Результат | Команда |
|---|---|---|
| Русскоязычный классификатор, источники text/voice/phone, нормализация времени, опасные сценарии, ошибки модели | **37 passed** | `cd overlay/services/intake-ai && PYTHONPATH=. python -m pytest -q` |
| Чистая PHP-валидация без БД: источники, роли, некорректные поля, недостоверные IP/время | **10 passed** | `php overlay/tests/smoke/dispatcher_intake_smoke.php` |
| PHP syntax (`IntakeDispatcher.php`, `routes/intake.php`, миграция, шаблон, 2 PHPUnit файла, smoke) | **7 файлов, без ошибок** | `php -l <file>` |
| JS syntax для веб-страницы | **pass** | `node --check overlay/public/assets/js/intake-dispatcher.js` |
| Инсталлятор на синтетическом OpenHelpDesk-дереве; два последовательных применения; сохранность чужих файлов, вставка 5 путей OpenAPI | **pass** | `python integration_selftest.py` |

Фактически использованы Python 3.13.5 и PHP 8.4.24. Набор Python-пакетов уже был установлен в тестовой среде; `uv run --offline --no-sync` не смог импортировать зависимости в пустую `.venv`, поэтому тесты выполнены через `python -m pytest`, а не с созданным `uv.lock`.

## Подготовлено, но НЕ проверено здесь

- **MariaDB/PDO/E2E:** PHPUnit `tests/Feature/DispatcherIntakeTest.php` и `DispatcherIntakeHttpTest.php` не запускались: в контейнере нет Docker, `pdo_mysql` и тестовой MariaDB. Это включает реальный подбор по двум сотрудникам с изменением загрузки, конкурентное `confirm`, фактическую запись аудита, сохранность после перезапуска, проверку прав через браузер, совместимость с настоящим `EngineerAssistant`.
- **Реальная LLM:** локальный vLLM/llama.cpp не был развернут, тестов качества модели на GPU нет. Python unit-тесты выполнялись в режиме правил или подмены LLM, не подтверждают точность модели на новом русском аудио.
- **Браузерный UX и интеграция с STT/телефонией:** страницы не открывались против запущенного OpenHelpDesk; сценарии voice/phone проверены лишь как входная строка с соответствующим `source`.
- **Lock-файл:** `uv.lock` не создан; для воспроизводимого развёртывания требуется `uv sync --group dev` с разрешённым доступом к пакетному индексу и сохранение полученного lock в командную ветку.
- **Замер ручного и ИИ времени:** не выполнялся, числа не выдуманы. Протокол `overlay/docs/team/dispatcher-measurement.md`.
- **Merge/PR/GitHub push:** намеренно не выполнялись по последней просьбе пользователя.

## Что запустить на стенде команды

```sh
# Только в копии ветки, где уже есть OpenHelpDesk и docs/team/engineer-ai-design.md
python apply_to_openhelpdesk.py /path/to/openhelpdesk

# НЕ в production; только изолированная engineer_test и MAIL_ENABLED=false
cd /path/to/openhelpdesk
docker compose -f compose.test.yaml up -d db app
docker compose -f compose.test.yaml exec app vendor/bin/phpunit --filter DispatcherIntake

# Сервис Python отдельным процессом / приватным Docker-контейнером
cd services/intake-ai
uv sync --group dev
uv run pytest -q
uv run python -m intake_ai
```

Следом вручную проверить `GET /intake`, `POST analyze`, `clarify`, `confirm` с обычным и аварийным обращением, повтор `confirm`, чужой draft, неверный ID сотрудника, `MAIL_ENABLED=false`, неотправку уведомлений до подтверждения, `GET /api/v1/tickets/{id}`, `POST .../engineer-assist` токеном инженера. До этого нельзя утверждать, что *вся* интеграция прошла E2E.
