# 02_ДЕН — доработка ядра ИИ-диспетчерской

Пакет содержит **накладываемые изменения на `feature/engineer-ai` OpenHelpDesk**, не сам полный репозиторий. Исходники и тесты PHP/Python, миграция, веб-страница, OpenAPI-дополнения и установка через `uv` готовы к интеграции.

## Применение (без GitHub push)

1. Иметь локальный clone с кодом `feature/engineer-ai` проекта `Dum-senior/digital-challenge-hackathon`. Не запускать против пустой ветки `хуй`, в которой нет OpenHelpDesk.
2. Запустить `python apply_to_openhelpdesk.py /путь/к/локальному/репозиторию`.
3. Просмотреть `git diff`, запустить штатную отдельную тестовую MariaDB и миграции существующего приложения; **не запускать `database/seed.php` на рабочей БД**.
4. Для Python-сервиса запускать только `uv sync --group dev` и `uv run ...`. Открыть в браузере `/intake`.

Скрипт не создаёт ветки, не коммитит и не пушит. Не использует ваши реальные токены.

См. `overlay/docs/team/dispatcher-ai.md`, `TEST_RESULTS_02_DEN.md`, `overlay/services/intake-ai/README.md`.
