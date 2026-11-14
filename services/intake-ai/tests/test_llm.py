import pytest
import asyncio

from intake_ai.llm import PrivateModelError, read_config


def test_only_allowlisted_private_model_host(monkeypatch):
    monkeypatch.setenv("LLM_API_KEY", "unit-test-llm-token")
    monkeypatch.setenv("LLM_ALLOWED_HOSTS", "localhost,127.0.0.1,vllm")
    monkeypatch.setenv("LLM_BASE_URL", "https://api.openai.com/v1")
    with pytest.raises(PrivateModelError):
        read_config()


def test_no_token_is_not_usable(monkeypatch):
    monkeypatch.setenv("LLM_BASE_URL", "http://127.0.0.1:8000/v1")
    monkeypatch.delenv("LLM_API_KEY", raising=False)
    with pytest.raises(PrivateModelError):
        read_config()


def test_private_local_server_config(monkeypatch):
    monkeypatch.setenv("LLM_API_KEY", "unit-test-llm-token")
    monkeypatch.setenv("LLM_BASE_URL", "http://127.0.0.1:8000/v1")
    assert read_config()[0] == "http://127.0.0.1:8000/v1"

def test_invalid_model_json_is_rejected(monkeypatch):
    import httpx
    from intake_ai.llm import analyze_private

    monkeypatch.setenv("LLM_API_KEY", "unit-test-llm-token")
    monkeypatch.setenv("LLM_BASE_URL", "http://127.0.0.1:8000/v1")
    factory = httpx.AsyncClient

    def mock_client(*args, **kwargs):
        return factory(transport=httpx.MockTransport(lambda req: httpx.Response(200, json={"choices": [{"message": {"content": "not valid JSON"}}]})))

    monkeypatch.setattr(httpx, "AsyncClient", mock_client)
    with pytest.raises(PrivateModelError):
        asyncio.run(analyze_private("На НТП нет данных"))
