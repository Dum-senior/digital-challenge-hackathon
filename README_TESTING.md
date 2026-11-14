# Тестирование OpenHelpDesk

Команды выполняются из корня репозитория. Нужны Docker и Docker Compose.
Используйте отдельное имя проекта Compose `hackathon-qa`. Оно отделяет базу от
других стендов. Внутри контейнера подключается `docker/test.env`, почта и внешняя
генерация помощника выключены. Стенд доступен на `http://127.0.0.1:8085`.

```sh
export COMPOSE_FILE=compose.test.yaml
export COMPOSE_PROJECT_NAME=hackathon-qa
touch .env
docker compose up -d db
docker compose run --rm app composer install --no-interaction --prefer-dist
docker compose run --rm app php database/seed.php
docker compose up -d app
curl --fail http://127.0.0.1:8085/health
docker compose exec app vendor/bin/phpunit --filter 'AttachmentAccessIntegrationTest|ApiSecurityIntegrationTest|EngineerAssistant|FormBuilderCsrfTest|CsrfKbFeedbackTest|AttachmentImagePreviewTest|AuthTest'
```

`database/seed.php` сбрасывает данные. Запускайте его только при первом создании
этого тестового стенда. Для трёх демонстрационных учётных записей он попросит
отдельные пароли. PHPUnit создаёт собственные синтетические данные.

```sh
docker compose exec app php -d memory_limit=512M vendor/bin/phpunit
docker compose exec app php database/migrate.php
docker compose restart app
curl --fail http://127.0.0.1:8085/health
docker compose exec app vendor/bin/phpunit --filter 'AttachmentAccessIntegrationTest|ApiSecurityIntegrationTest|EngineerAssistantHttpTest'
docker compose down
```

`down` останавливает стенд и сохраняет том. При проблемах смотрите
`docker compose logs app db`. Workflow `qa.yml` дополнительно проверяет синтаксис
PHP, OpenAPI и известные уязвимости зависимостей из `composer.lock`.

После объединения модулей проверьте вручную пять сценариев из командного ТЗ
и запишите результат в `TEST_RESULTS.md`. Для голосового smoke-теста нужны рабочий
STT и синтетическое русское аудио. Тесты помощника с подменой провайдера не
считаются проверкой настоящего STT или облачного LLM.
