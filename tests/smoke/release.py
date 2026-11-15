"""HTTP integration smoke test against an isolated release Compose project."""
import concurrent.futures
from datetime import datetime
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

BASE = os.environ.get("TEST_BASE_URL", "http://127.0.0.1:8080")

def request(client, path, body=None, csrf=None, expected=200, form=False):
    headers = {"Accept": "application/json"}
    if csrf:
        headers["X-CSRF-Token"] = csrf
    data = None
    if body is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded" if form else "application/json"
        data = urllib.parse.urlencode(body).encode() if form else json.dumps(body).encode()
    try:
        response = client.open(urllib.request.Request(BASE + path, data=data, headers=headers), timeout=115)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read().decode()
        assert response.status == expected, (path, response.status, raw[:400])
        return json.loads(raw) if "application/json" in response.headers.get("Content-Type", "") else raw

def upload(client, path, field, fixture, csrf):
    boundary = "dispatcher-release-smoke"
    file = Path(fixture)
    data = (
        f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{file.name}"\r\n\r\n'.encode()
        + file.read_bytes() + f"\r\n--{boundary}--\r\n".encode()
    )
    headers = {"Content-Type": f"multipart/form-data; boundary={boundary}", "X-CSRF-Token": csrf}
    with client.open(urllib.request.Request(BASE + path, data=data, headers=headers), timeout=115) as response:
        assert response.status == 200
        return json.load(response)

def sign_in(role):
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    if os.environ.get("TEST_DEMO_ROLES") == "1":
        page = request(client, "/workspace")
        config = json.loads(re.search(r"window.DISPATCH_CONFIG=({.*?});", page)[1])
        if config["currentUser"]["role"] != role:
            request(client, "/workspace/api/demo-role", {"role": role}, config["csrfToken"])
            page = request(client, "/workspace")
            config = json.loads(re.search(r"window.DISPATCH_CONFIG=({.*?});", page)[1])
        assert config["currentUser"]["role"] == role
        return client, config["csrfToken"]
    form = request(client, "/login?next=/workspace")
    nonce = re.search(r'name="_token"\s+value="([^"]+)"', form)[1]
    page = request(client, "/login", {"_token": nonce, "email": role + "@demo.test",
                   "password": os.environ["RELEASE_" + role.upper() + "_PASSWORD"]}, form=True)
    config = json.loads(re.search(r"window.DISPATCH_CONFIG=({.*?});", page)[1])
    assert config["currentUser"]["role"] == role
    assert "INTAKE_SERVICE_TOKEN" not in page
    return client, config["csrfToken"]

with concurrent.futures.ThreadPoolExecutor() as pool:
    (dispatcher, csrf), (engineer, eng_csrf), (admin, admin_csrf) = pool.map(sign_in, ("dispatcher", "engineer", "admin"))

request(dispatcher, "/workspace/api/engineers", expected=403)
request(engineer, "/workspace/api/engineers", expected=403)
engineers = request(admin, "/workspace/api/engineers")["engineers"]
assert len(engineers) >= 7 and any(e["competencies"] for e in engineers)

for variable, path, field in (
    ("TEST_AUDIO", "/intake/transcriptions", "audio"),
    ("TEST_IMAGE", "/workspace/api/ocr", "file"),
):
    if os.environ.get(variable):
        assert upload(dispatcher, path, field, os.environ[variable], csrf)["text"].strip()
        print("PASS", path, flush=True)

for message, profile, elapsed, equipment in (
    ("В корпусе Б в кабинете 214 кончилась краска в принтере.", "low", (120, 1440), "картридж"),
    ("В корпусе Б 100 принтеров одновременно не печатают.", "mass", (15, 240), "ноутбук"),
):
    case = request(dispatcher, "/intake/analyze", {"text": message}, csrf, expected=201)
    assert case["extracted"]["sla_profile"] == profile, case
    assert (case["sla"]["response_minutes"], case["sla"]["resolution_minutes"]) == elapsed, case
    procedure = request(dispatcher, f"/workspace/api/drafts/{case['draft_id']}/assist", {}, csrf)
    assert procedure["steps"] and equipment in str(procedure["take"]).lower(), procedure
    assert "sources" not in procedure

text = "В корпусе Б в кабинете 214 из принтера идёт дым."
draft = request(dispatcher, "/intake/analyze", {"text": text}, csrf, expected=201)
assert draft["extracted"]["analysis_mode"] == "private_llm", draft["extracted"].get("model_error")
assert draft["extracted"]["priority"] == "critical"
assert draft["sla"]["response_minutes"] == 5
assert draft["extracted"]["location"]["name"] == "Корпус Б"
assert draft["approved"]["assigned_to"] in {e["id"] for e in engineers}
endpoint = f"/intake/drafts/{draft['draft_id']}/confirm"
approved = draft["approved"]
args = {"revision": draft["revision"], "approve": True,
        "manual_review_reason": "Интеграционный тест на отдельной базе."}
request(dispatcher, endpoint, {**args, "approved": {**approved, "priority_id": None}}, csrf, expected=422)
request(engineer, endpoint, args, eng_csrf, expected=403)
request(dispatcher, endpoint, {**args, "sla": {"response_minutes": 30, "resolution_minutes": 60}}, csrf, expected=422)
approved["subject"] = "Проверенная заявка о дыме"
created = request(dispatcher, endpoint, {**args, "approved": approved,
                  "sla": {"response_minutes": 3, "resolution_minutes": 45}}, csrf, expected=201)
ticket = created["ticket_id"]
assert request(dispatcher, endpoint, args, csrf)["ticket_id"] == ticket
before = next(t for t in request(dispatcher, "/workspace/api/tickets") if t["id"] == ticket)
assign_path = f"/workspace/api/tickets/{ticket}/assignment"
assignment = request(dispatcher, assign_path)
second = request(dispatcher, "/intake/analyze", {"text": "В офисе №2 идёт дым из оборудования."}, csrf, expected=201)
assert second["approved"]["assigned_to"] not in (None, assignment["assigned_to"]), second
assert "очередь других специалистов превышает SLA" in second["recommendation"]["reason"]

base_engineer = json.loads(re.search(r"window.DISPATCH_CONFIG=({.*?});", request(engineer, "/workspace"))[1])["currentUser"]["id"]
replacement = next(e for e in assignment["options"] if e["id"] == base_engineer)
assert replacement["available"] and assignment["assigned_to"] != base_engineer
move = {"assignee_id": base_engineer, "group_id": replacement["group_id"],
        "previous_assignee_id": assignment["assigned_to"], "reason": "Передача свободному инженеру"}
request(engineer, assign_path, move, eng_csrf, expected=403)
request(dispatcher, assign_path, move, csrf)
request(dispatcher, assign_path, move, csrf, expected=409)
for client in (dispatcher, engineer):
    record = next(t for t in request(client, "/workspace/api/tickets") if t["id"] == ticket)
    assert record["subject"] == approved["subject"]
    assert record["responseDueAt"] == before["responseDueAt"]
    assert record["resolutionDueAt"] == before["resolutionDueAt"]
    assert record["slaStartedAt"]
    response = datetime.fromisoformat(record["responseDueAt"])
    resolution = datetime.fromisoformat(record["resolutionDueAt"])
    assert (resolution - response).total_seconds() == 42 * 60, record
endpoint = f"/workspace/api/tickets/{ticket}/update"
request(engineer, endpoint, {"status": "resolved"}, eng_csrf, expected=409)
request(engineer, endpoint, {"status": "in_progress"}, eng_csrf)
request(engineer, endpoint, {"status": "resolved"}, eng_csrf)
solution = request(engineer, f"/workspace/api/tickets/{ticket}/assist", {}, eng_csrf)
assert solution.get("status") == "suggested" and solution.get("emergency"), solution
assert solution["steps"] and not solution["take"] and "sources" not in solution, solution
report = request(admin, "/workspace/api/reports")
assert report["critical"] >= 1 and report["resolved"] >= 1
assert next(t for t in report["incidents"] if t["id"] == ticket)["resolved"]
assert report["byCategory"] and report["byEngineer"] and report["byLocation"]
assert report["timing"]["response_count"] >= 1
assert request(admin, "/workspace/api/audit")
assert len(request(admin, "/workspace/api/articles")) >= 78
kb_path = "/workspace/api/articles"
sample = {"title": "Проверка редактора", "category": "Демо", "content": "**Проверки.** Проверить тестовую очередь."}
request(dispatcher, kb_path, sample, csrf, expected=403)
new_article = request(admin, kb_path, sample, admin_csrf)
new_path = kb_path + "/" + new_article["id"]
assert request(admin, new_path)["content"] == sample["content"]
sample["content"] = "**Решение и эскалация.** Передать тестовую заявку."
request(admin, kb_path, {**sample, "id": new_article["id"]}, admin_csrf)
assert request(admin, new_path)["content"] == sample["content"]
request(admin, new_path + "/remove", {}, admin_csrf)
request(admin, new_path, expected=404)
assert all(row["id"] != new_article["id"] for row in request(admin, kb_path))
print("PASS knowledge create, update, delete and role access")
print("PASS roles, emergency, edited draft, idempotency, engineer workflow, reports, audit and knowledge base")
