# Интеграция 02_ДЕН с OpenHelpDesk (согласовать перед merge)

## Проверенное дерево соседней ветки

В `feature/engineer-ai` (на дату проверки 2026-11-14) есть PHP `src/AI.php` с `AIClassifier`, `src/routes/api.php` с `_apiAuth()`, `src/routes/drafts.php`, существующая таблица `ticket_drafts` (migration 064), классификация/назначение (migrations 027/035) и полноценная схема SLA. Поэтому мы НЕ пишем вторую БД, вторую систему пользователей, второй STT или финальное назначение.

## Рекомендуемая граница доверия

```text
Браузер/voice/phone -> OpenHelpDesk PHP (его текущая сессия/токен + CSRF + права)
    -> PHP получает допустимые ID команд/инженеров, навыки, количество активных заявок, SLA
    -> loopback HTTP POST на intake-ai с server-side INTAKE_SERVICE_TOKEN
    -> intake-ai (rules + optional local LLM)
    -> PHP валидирует рекомендованные ID на актуальном снимке БД
    -> PHP сохраняет/обновляет реальный черновик OpenHelpDesk, назначает draft_id
    -> диспетчер видит AI-поля, редактирует и подтверждает через штатную авторизацию
    -> только после этого OpenHelpDesk создаёт/назначает итоговую заявку
```

**ВАЖНО**: `context` на входе сервиса предназначен ТОЛЬКО для собранных PHP сервером данных. Нельзя пробрасывать `context` из JSON браузера. Токен сервиса никогда не отдавать фронтенду. PHP обязан ограничивать запросы, проверять CSRF, владение черновиком и полномочия диспетчера. `requires_human_review: true` не является самостоятельной реализацией авторизации.

## Контракт

`POST /api/hackathon/v1/intake/analyze`: `{ "text": "...", "source": "text|voice|phone" }`; опционально только серверный `context`:

```json
{
  "teams": [{"id": 11, "name": "Учебная команда", "categories": ["telemetry", "automation"]}],
  "engineers": [{"id": 101, "team_ids": [11], "skills": ["telemetry", "opc"], "open_tickets": 2, "available": true}],
  "sla_due_at": "2026-11-14T15:00:00+05:00"
}
```

Все реальные ID — **числовые** и должны существовать в БД. `skills` — нормализованные категории (`printer`, `telemetry`, `opc`, `network`, `security`, `identity`, `database`), которые должен сопоставлять PHP-адаптер с реальными справочниками навыков. Если сопоставления нет, рекомендация ID не появляется. Приоритетные правила демонстрационные; политику утверждает заказчик.

Пример успешного ответа со структурой (разные `analysis_id` на каждом вызове):

```json
{
  "status": "awaiting_review", "analysis_id": "<uuid>", "draft_id": null, "persisted": false,
  "source": "text", "analysis_mode": "rules_only", "category": "telemetry", "priority": "high",
  "priority_reason": "Прерван поток промышленных данных; демонстрационное правило, не норматив предприятия",
  "fields": {"object": "НТП", "site": null, "building": null, "floor": null,
             "room": null, "ip": null, "equipment": "счётчика", "meter_number": null,
             "serial_number": null, "incident_time": "09:00", "error_code": null,
             "symptoms": "На НТП с 09:00 нет данных счётчика", "scope": null},
  "missing_fields": ["номер счётчика"],
  "recommended_team_id": null, "recommended_engineer_id": null,
  "assignment_reason": "Каталог инженеров из OpenHelpDesk не предоставлен; назначение не предлагается",
  "sla_risk": "unknown", "safety_warnings": [],
  "confidence_notes": ["Приоритет определяется демонстрационной политикой, не регламентом заказчика", "Нет живого каталога команд и инженеров; тестовые ID не подставляются"],
  "requires_human_review": true
}
```

## Спорные изменения — только согласованный интеграционный PR

- `src/routes/api.php`: использовать `_apiAuth()` / существующие лимиты, не писать второй login.
- `src/routes/drafts.php`: безопасно расширить существующий контекст или создать логический серверный workflow, но НЕ использовать client-side произвольный `draft_id`. Назначение только после диспетчерского подтверждения.
- Миграции/полномочия/route handling: интегратор меняет отдельно после выбора реального OpenHelpDesk UI/contract.
- В существующем `src/AI.php` есть свои API-ключи сторонних провайдеров: приватный сервис Дена не должен включать их или случайно отправлять данные туда.
- Поскольку `main` пока без импортированного OpenHelpDesk, прямого PHP-patch в этой ветке нет. Ветка сервиса должна объединяться лишь после merge baseline и согласования общего API.
