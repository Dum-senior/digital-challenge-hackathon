# Диспетчер ИИ 1.0.0

Ветка release/v1 объединяет помощника инженера, голосовой API, диспетчерский приём заявок и проверки безопасности. Для локального развёртывания используйте отдельный том MariaDB.

```sh
git clone --branch release/v1 https://github.com/Dum-senior/digital-challenge-hackathon.git
cd digital-challenge-hackathon
touch .env
docker compose -f compose.test.yaml build app
docker compose -f compose.test.yaml up -d db
docker compose -f compose.test.yaml run --rm app composer install --no-interaction --prefer-dist
docker compose -f compose.test.yaml run --rm app php database/seed.php
docker compose -f compose.test.yaml up -d app
```

Команда seed.php применяется только для новой пустой тестовой базы. Интерфейс доступен на http://localhost:8085. Для обновления существующей базы сначала проверьте резервную копию, затем запустите database/migrate.php. Не удаляйте том MariaDB.

Подключите локальную Qwen3 0.6B и учебные материалы по [инструкции помощника](offline-demo.md). Сервис разбора текста настраивается по [инструкции Дена](dispatcher-ai.md), распознавание по [инструкции Вани](voice-ai.md). Маршрут /intake принимает текст. Голосовой API принимает аудиофайл с Bearer-токеном, требуется отдельное подключение записи в браузере.

Для показа введите три обращения из [контракта принтеров](PRINTER_SCENARIOS_CONTRACT.md), проверьте предложенный приоритет, подтвердите заявку и откройте источники помощника. При недоступной Ollama покажите поиск инструкций и решений закрытых заявок.

На сервере используйте [инструкцию командного стенда](preview-deploy.md). Перед обновлением запишите SHA развёрнутой версии и проверьте резервную копию MariaDB. При откате верните предыдущий SHA и конфигурацию и проверьте совместимость схемы перед восстановлением базы.
