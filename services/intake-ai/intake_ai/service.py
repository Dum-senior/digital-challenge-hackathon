"""One analyzer for text, voice transcript and phone transcript."""
import os
import ipaddress
import re
import secrets
import uuid

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
from pydantic import ValidationError

from .llm import PrivateModelError, analyze_private
from .rules import classify, extract_fields, sla_risk, suggest_assignment
from .schema import Fields, IntakeRequest, IntakeResponse

app = FastAPI(title="02_ДЕН — local dispatcher AI intake", version="0.1.0", docs_url=None, redoc_url=None, openapi_url=None)


@app.get("/healthz")
async def healthz():
    # Liveness only; does not imply the LLM or the DB is ready.
    return {"status": "alive"}


def check_token(request: Request) -> None:
    expected = os.environ.get("INTAKE_SERVICE_TOKEN", "")
    auth = request.headers.get("authorization", "")
    if not expected or expected.startswith("REPLACE_"):
        raise HTTPException(status_code=503, detail="Intake service is not configured")
    if not auth.startswith("Bearer ") or not secrets.compare_digest(auth[7:], expected):
        raise HTTPException(status_code=401, detail="Unauthorized")


def evidence_merge(source: str, original: Fields, proposed: Fields) -> Fields:
    """Model fields only accepted if the value is a literal substring in the request."""
    data = original.model_dump()
    for field, value in proposed.model_dump().items():
        if data[field] is not None or not isinstance(value, str) or len(value) > 120:
            continue
        if value.casefold() not in source.casefold():
            continue
        if field == "ip":
            try:
                ipaddress.ip_address(value)
            except ValueError:
                continue
        if field == "incident_time" and not re.fullmatch(r"(?:[01]\d|2[0-3]):[0-5]\d", value):
            continue
        data[field] = value
    return Fields.model_validate(data)


@app.post("/api/hackathon/v1/intake/analyze", response_model=IntakeResponse)
async def intake_analyze(request: Request):
    check_token(request)
    if "application/json" not in request.headers.get("content-type", ""):
        raise HTTPException(status_code=415, detail="JSON required")
    # Bound memory while reading, not after buffering an attacker-sized request.
    chunks = []
    total = 0
    async for chunk in request.stream():
        total += len(chunk)
        if total > 65536:
            raise HTTPException(status_code=413, detail="Request too large")
        chunks.append(chunk)
    body = b"".join(chunks)
    try:
        import json
        data = IntakeRequest.model_validate(json.loads(body))
    except (ValueError, ValidationError, TypeError):
        raise HTTPException(status_code=422, detail="Invalid intake request") from None

    category, priority, reason, missing, warnings = classify(data.text)
    fields = extract_fields(data.text)
    mode = "rules_only"
    status = "needs_clarification" if category == "unknown" else "awaiting_review"
    notes = ["Приоритет определяется демонстрационной политикой, не регламентом заказчика"]
    if os.environ.get("ENABLE_LLM", "false").lower() == "true":
        try:
            suggestion = await analyze_private(data.text)
            mode = "private_llm"
            # Rule-based incident safety cannot be downgraded by a model.
            if category == "unknown" and suggestion.category != "unknown":
                category = suggestion.category
            order = ["low", "medium", "high", "critical"]
            if order.index(suggestion.priority) > order.index(priority):
                priority = suggestion.priority
                reason = "Модель повысила рекомендуемую срочность: " + suggestion.priority_reason[:180]
            fields = evidence_merge(data.text, fields, suggestion.fields)
            notes.extend(suggestion.confidence_notes[:6])
            if category != "unknown":
                status = "awaiting_review"
        except (PrivateModelError, ValidationError):
            # No fake success. Do not permit automatic assignment on model failure.
            mode = "rules_fallback"
            status = "failed"
            notes.append("Локальная LLM недоступна/некорректна; результаты правил — только для ручной обработки")
    team_id, engineer_id, assignment_reason = suggest_assignment(category, data.context)
    if status == "failed":
        engineer_id = None
        assignment_reason = "Автоматическая рекомендация инженера отключена при отказе LLM"
    if status == "needs_clarification":
        engineer_id = None
        assignment_reason = "Необходимо уточнить обращение до подбора исполнителя"
    if not fields.object and category in ("telemetry", "automation"):
        if "уточнить объект" not in missing:
            missing.append("уточнить объект")
    if data.context is None:
        notes.append("Нет живого каталога команд и инженеров; тестовые ID не подставляются")
    risk = sla_risk(data.context)
    if risk in ("warning", "breached"):
        notes.append("Риск SLA рассчитан по переданному сроку; эскалация требует подтверждения")
    return IntakeResponse(status=status, analysis_id=str(uuid.uuid4()), source=data.source,
        analysis_mode=mode, category=category, priority=priority, priority_reason=reason,
        fields=fields, missing_fields=missing,
        recommended_team_id=team_id, recommended_engineer_id=engineer_id,
        assignment_reason=assignment_reason, sla_risk=risk, safety_warnings=warnings,
        confidence_notes=notes)
