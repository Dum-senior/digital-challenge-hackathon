import json
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from intake_ai.service import evidence_merge
from intake_ai.schema import Fields

CASES = [json.loads(line) for line in (Path(__file__).parents[1] / "data/demo_cases.jsonl").read_text(encoding="utf-8").splitlines()]
CATALOG = json.loads((Path(__file__).parents[1] / "data/demo_catalog.json").read_text(encoding="utf-8"))


@pytest.mark.parametrize("case", CASES[:10], ids=[f"case-{i+1}" for i in range(10)])
def test_required_russian_cases(post, case):
    response = post(case["text"])
    assert response.status_code == 200, response.text
    data = response.json()
    assert data["category"] == case["category"]
    assert data["priority"] == case["priority"]
    assert data["requires_human_review"] is True
    assert data["persisted"] is False and data["draft_id"] is None
    assert data["recommended_engineer_id"] is None
    assert data["analysis_mode"] == "rules_only"


def test_extracts_known_ntp_time_without_inventing_ip(post):
    r = post("На НТП с 09:00 нет данных счётчика").json()
    assert r["fields"]["object"] == "НТП"
    assert r["fields"]["incident_time"] == "09:00"
    assert r["fields"]["ip"] is None
    assert r["fields"]["meter_number"] is None
    assert "номер счётчика" in r["missing_fields"]


def test_extracts_opc_and_ip(post):
    r = post("На КП-12 нет связи с OPC, IP 10.20.5.14").json()
    assert r["fields"]["object"] == "КП-12"
    assert r["fields"]["ip"] == "10.20.5.14"
    assert r["recommended_team_id"] is None


def test_danger_is_never_low_priority(post):
    r = post("Принтер не печатает, из корпуса идёт дым").json()
    assert r["priority"] == "critical"
    assert r["safety_warnings"]
    assert all("перезагруз" not in x for x in r["safety_warnings"])


def test_vague_case_requires_clarification(post):
    r = post("Ничего не работает").json()
    assert r["status"] == "needs_clarification"
    assert r["recommended_engineer_id"] is None
    assert len(r["missing_fields"]) >= 3


def test_skill_and_load_catalog_not_first_engineer(post):
    r = post("На НТП не передаются показания счётчика", context=CATALOG).json()
    assert (r["recommended_team_id"], r["recommended_engineer_id"]) == (11, 102)
    assert "Компетенция" in r["assignment_reason"]


def test_unskilled_or_unavailable_engineer_not_chosen(post):
    catalog = {"teams": CATALOG["teams"], "engineers": [{**x, "available": False} for x in CATALOG["engineers"]]}
    r = post("Принтер не печатает", context=catalog).json()
    assert r["recommended_team_id"] == 12
    assert r["recommended_engineer_id"] is None


def test_rejects_invalid_catalog_id_and_source(post):
    assert post("Принтер не печатает", context={"teams": [{"id": -1, "name": "X", "categories": ["peripherals"]}]}).status_code == 422
    assert post("Принтер не печатает", source="email").status_code == 422


def test_no_invented_assignment_when_malicious_context_id(post):
    bad = dict(CATALOG)
    bad["engineers"] = CATALOG["engineers"] + [{"id": 999, "team_ids": [999], "skills": ["printer"], "open_tickets": 0}]
    assert post("Принтер не печатает", context=bad).status_code == 422


def test_does_not_accept_model_hallucinations():
    got = evidence_merge("Нет связи с OPC", Fields(ip=None, equipment="OPC"), Fields(ip="8.8.8.8", object="Неизвестный КП"))
    assert got.ip is None and got.object is None and got.equipment == "OPC"


def test_sla_warning_breach_unknown(post):
    base = {"teams": [], "engineers": []}
    later = (datetime.now(timezone.utc) + timedelta(minutes=10)).isoformat()
    past = (datetime.now(timezone.utc) - timedelta(seconds=60)).isoformat()
    assert post("Принтер не печатает", context={**base, "sla_due_at": later}).json()["sla_risk"] == "warning"
    assert post("Принтер не печатает", context={**base, "sla_due_at": past}).json()["sla_risk"] == "breached"
    assert post("Принтер не печатает").json()["sla_risk"] == "unknown"
    assert post("Принтер не печатает", context={**base, "sla_due_at": "2026-11-14T10:00:00"}).status_code == 422


def test_voice_and_phone_use_same_text_pipeline(post):
    texts = [post("Не печатает принтер, кабинет 312", source=s).json() for s in ["text", "voice", "phone"]]
    assert len({x["category"] for x in texts}) == 1
    assert len({x["priority"] for x in texts}) == 1
    assert all(x["persisted"] is False for x in texts)


def test_auth_content_type_and_input_limits(client, post):
    url = "/api/hackathon/v1/intake/analyze"
    assert client.post(url, json={"text": "Принтер не печатает", "source": "text"}).status_code == 401
    assert client.post(url, content="bad", headers={"Authorization": "Bearer test-internal-token-not-for-production"}).status_code == 415
    assert post("a" * 4001).status_code == 422
    assert post("a" * 3, context={"teams": [], "engineers": [], "unexpected": 42}).status_code == 422
    assert client.post(url, content=" " * 70000, headers={"Content-Type": "application/json", "Authorization": "Bearer test-internal-token-not-for-production"}).status_code == 413


def test_empty_token_fails_closed(client, monkeypatch):
    monkeypatch.delenv("INTAKE_SERVICE_TOKEN")
    response = client.post("/api/hackathon/v1/intake/analyze", json={"text": "Принтер не печатает", "source": "text"})
    assert response.status_code == 503


@pytest.mark.parametrize("bad", ["not json", "{}", "[]", '{"choices": [{"message": {"content": "not json"}}]}'])
def test_invalid_llm_response_fails_manual(post, monkeypatch, bad):
    import intake_ai.service as module
    from intake_ai.llm import PrivateModelError

    async def failing(_):
        raise PrivateModelError("test without actual LLM")
    monkeypatch.setattr(module, "analyze_private", failing)
    monkeypatch.setenv("ENABLE_LLM", "true")
    r = post("Принтер не печатает", context=CATALOG).json()
    assert r["status"] == "failed"
    assert r["analysis_mode"] == "rules_fallback"
    assert r["recommended_engineer_id"] is None


def test_valid_llm_cannot_downgrade_smoke(post, monkeypatch):
    import intake_ai.service as module
    from intake_ai.schema import ModelSuggestion

    async def safe_fake(_):
        return ModelSuggestion.model_validate({"category": "peripherals", "priority": "low", "priority_reason": "No hazard", "fields": {}, "missing_fields": [], "confidence_notes": []})
    monkeypatch.setattr(module, "analyze_private", safe_fake)
    monkeypatch.setenv("ENABLE_LLM", "true")
    r = post("Принтер дымит").json()
    assert r["priority"] == "critical"
    assert r["analysis_mode"] == "private_llm"


def test_invalid_ip_cannot_be_injected_by_model():
    got = evidence_merge("IP 999.999.999.999", Fields(), Fields(ip="999.999.999.999"))
    assert got.ip is None


def test_invalid_time_cannot_be_injected_by_model():
    got = evidence_merge("В 99:99 упали данные", Fields(), Fields(incident_time="99:99"))
    assert got.incident_time is None


def test_spoken_russian_hour_is_grounded_in_text(post):
    data = post('На НТП с девяти утра нет данных счётчика').json()
    assert data['fields']['incident_time'] == '09:00'
    assert data['fields']['object'] == 'НТП'
    assert data['fields']['meter_number'] is None
    assert data['fields']['ip'] is None


def test_multiple_wells_is_mass_impact_without_faking_emergency(post):
    data = post('Нет связи с КП-12, не обновляется OPC и телеметрия нескольких скважин').json()
    assert data['priority'] in ('high', 'critical')
    assert data['category'] == 'automation'
    assert data['fields']['object'] == 'КП-12'
    assert not data['safety_warnings']


def test_unknown_time_words_do_not_create_arbitrary_timestamp(post):
    data = post('На НТП давно нет данных со счётчика').json()
    assert data['fields']['incident_time'] is None
