# API диспетчерской

## Авторизация

Веб-клиент открывает `/login`, затем `/workspace`. PHP передаёт конфигурацию с ролью и CSRF из сессии. Все записи требуют cookie сессии и `X-CSRF-Token`. JSON отправляется с `Content-Type: application/json`. Роли инженера и администратора проверяет сервер.

ИИ-контейнер использует другой контракт и слушает `http://inference:8092` внутри Compose. Каждый запрос требует `Authorization: Bearer <DISPATCHER_API_TOKEN>`. Браузер получает результаты через PHP. Токен хранится в окружении сервера.

## Черновики и распознавание

| Маршрут | Тело | Результат |
|---|---|---|
| GET /intake/meta | — | Справочники `types`, `priorities`, `locations`, `groups`, `agents`, `tags` |
| POST /intake/transcriptions | Multipart `audio`, `source=browser\|upload`, 20 МиБ, 120 с | `text`, `language`, `duration_ms`, `processing_ms` |
| POST /workspace/api/ocr | Multipart `file`, PNG/JPEG/WebP до 5 МиБ | `text`, `engine`, `languages` |
| POST /intake/analyze | `text` (3–4000 символов), `source`, опциональный `request_id` | 201 с черновиком. Повтор того же `request_id` возвращает существующий черновик |
| GET /intake/drafts | — | `data` со списком черновиков |
| GET /intake/drafts/{id} | — | `draft_id`, `revision`, `status`, `raw_text`, `extracted`, `approved`, `recommendation`, `questions` |
| POST /intake/drafts/{id}/clarify | `revision`, `answer` | Новая ревизия |
| POST /intake/drafts/{id}/confirm | `revision`, `approve: true`, `approved`, при необходимости `manual_review_reason` | 201 с `ticket_id`. Повтор возвращает тот же ID |

Пример запроса подтверждения (идентификаторы брать из фактического ответа анализа и `/intake/meta`):

```json
{
  "revision": 1,
  "approve": true,
  "approved": {
    "subject": "Пропали показания счётчика",
    "description": "На НТП №3 с 09:00 нет показаний счётчика №417.",
    "priority_id": 2,
    "fields": {"object": "НТП №3", "meter_number": "417", "started_at": "09:00"}
  },
  "manual_review_reason": "Данные проверены по сообщению заявителя."
}
```

Уровни приоритета: низкий, средний, авария. Приоритет обязателен. Если черновик требует уточнений, анализ завершился ошибкой либо диспетчер меняет аварийный приоритет, обоснование должно быть не короче 20 символов. Устаревшая ревизия возвращает 409. Создание и назначение происходят после подтверждения.

## Рабочие экраны

| Маршрут | Роли | Ответ или действие |
|---|---|---|
| GET /workspace/api/tickets | Диспетчер, инженер | Массив заявок. Инженер получает назначенные ему. Поля включают `id`, `subject`, `description`, `priority`, `status`, `assigneeId`, `responseDueAt` |
| GET /workspace/api/users | Диспетчер | Список инженеров |
| POST /workspace/api/tickets/{id}/update | Диспетчер, назначенный инженер | Диспетчер передаёт `subject` и `description`. Инженер передаёт `status`, переходы `open → in_progress → resolved` |
| POST /workspace/api/tickets/{id}/assist | Диспетчер, назначенный инженер | `status`, `steps`, `sources`. При `insufficient_evidence` блок решения скрыт |
| GET /workspace/api/engineers | Администратор | `engineers` с именами, группами, компетенциями и открытыми заявками |
| GET /workspace/api/reports?from=YYYY-MM-DD&to=YYYY-MM-DD | Администратор | `total`, `open`, `resolved`, `critical`, `byPriority`, `byStatus`, `sla` |
| GET /workspace/api/audit | Администратор | Последние 100 записей журнала |
| GET /workspace/api/articles | Администратор | Разделы Markdown-базы |
| GET /workspace/api/articles/{id} | Сотрудники | Полный текст раздела по ID из ответа поиска |

## ИИ-контейнер

| Маршрут | Формат | Ответ |
|---|---|---|
| GET /health | — | `status`, `index_ready` |
| POST /transcribe | Multipart `file`, до 20 МиБ и 120 с | `text`, `language`, `duration_seconds` |
| POST /ocr | Multipart `file`, PNG/JPEG/WebP до 10 МиБ | `text`, `engine`, `languages` |
| POST /analyze | JSON с `text` (3–6000 символов) | `summary`, `facts`, `questions`, `category`, `object`, `meter_number`, `started_at`, `room` |
| POST /retrieve | Тот же JSON | До четырёх `sources` с разделами и оценкой сходства |
| POST /resolve | Тот же JSON | `status` (`suggested` или `insufficient_evidence`), `steps`, `sources` |

Пример для вызова из сети Compose:

```sh
curl -fsS -H "Authorization: Bearer $DISPATCHER_API_TOKEN" \
  -H 'Content-Type: application/json' \
  --data '{"text":"Клиент OPC подключён, но теги показывают Bad Quality"}' \
  http://inference:8092/resolve
```

PHP сообщает об ошибках в `error`, ИИ-контейнер — в `detail`. Основные коды: 401/403 для доступа и CSRF, 404 для отсутствующих или недоступных заявок, 409 для конфликтов состояния, 413/415 для размера и формата, 422 для ошибок входных полей, 429 для ограничения частоты, 502/503 для недоступности модели.
