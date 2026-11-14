import os
import pytest
from fastapi.testclient import TestClient
from intake_ai.service import app


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setenv("INTAKE_SERVICE_TOKEN", "test-internal-token-not-for-production")
    monkeypatch.setenv("ENABLE_LLM", "false")
    with TestClient(app) as c:
        yield c


@pytest.fixture
def post(client):
    def run(text, source="text", context=None, headers=None):
        body = {"text": text, "source": source}
        if context is not None:
            body["context"] = context
        return client.post("/api/hackathon/v1/intake/analyze", json=body,
                           headers=headers or {"Authorization": "Bearer test-internal-token-not-for-production"})
    return run
