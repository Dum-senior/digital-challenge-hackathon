"""HTTP integration smoke test against an isolated release Compose project."""
import concurrent.futures
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request

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

def sign_in(role):
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    form = request(client, "/login")
    nonce = re.search(r'name="_token"\s+value="([^"]+)"', form)[1]
    page = request(client, "/login", {"_token": nonce, "email": role + "@demo.test",
                   "password": os.environ["RELEASE_" + role.upper() + "_PASSWORD"]}, form=True)
    config = json.loads(re.search(r"window.DISPATCH_CONFIG=({.*?});", page)[1])
    assert config["currentUser"]["role"] == role
    assert "INTAKE_SERVICE_TOKEN" not in page
    return client, config["csrfToken"]

with concurrent.futures.ThreadPoolExecutor() as pool:
    (dispatcher, csrf), (engineer, eng_csrf), (admin, _) = pool.map(sign_in, ("dispatcher", "engineer", "admin"))

request(dispatcher, "/workspace/api/engineers", expected=403)
request(engineer, "/workspace/api/engineers", expected=403)
engineers = request(admin, "/workspace/api/engineers")["engineers"]
assert engineers and engineers[0]["competencies"]

text = "В корпусе Б в кабинете 214 из принтера идёт дым."
draft = request(dispatcher, "/intake/analyze", {"text": text}, csrf, expected=201)
assert draft["extracted"]["priority"] == "critical"
assert draft["extracted"]["location"]["name"] == "Корпус Б"
assert draft["approved"]["assigned_to"] == engineers[0]["id"]
endpoint = f"/intake/drafts/{draft['draft_id']}/confirm"
approved = draft["approved"]
args = {"revision": draft["revision"], "approve": True,
        "manual_review_reason": "Интеграционный тест на отдельной базе."}
request(dispatcher, endpoint, {**args, "approved": {**approved, "priority_id": None}}, csrf, expected=422)
request(engineer, endpoint, args, eng_csrf, expected=403)
approved["subject"] = "Проверенная заявка о дыме"
created = request(dispatcher, endpoint, {**args, "approved": approved}, csrf, expected=201)
ticket = created["ticket_id"]
assert request(dispatcher, endpoint, args, csrf)["ticket_id"] == ticket
assert any(t["id"] == ticket and t["subject"] == approved["subject"] for t in request(engineer, "/workspace/api/tickets"))
endpoint = f"/workspace/api/tickets/{ticket}/update"
request(engineer, endpoint, {"status": "resolved"}, eng_csrf, expected=409)
request(engineer, endpoint, {"status": "in_progress"}, eng_csrf)
request(engineer, endpoint, {"status": "resolved"}, eng_csrf)
report = request(admin, "/workspace/api/reports")
assert report["critical"] >= 1 and report["resolved"] >= 1
assert request(admin, "/workspace/api/audit")
assert len(request(admin, "/workspace/api/articles")) >= 78
print("PASS roles, emergency, edited draft, idempotency, engineer workflow, reports, audit and knowledge base")
