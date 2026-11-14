import uvicorn

# Never bind to 0.0.0.0 directly: PHP proxy/gateway is responsible for external access.
uvicorn.run("intake_ai.service:app", host="127.0.0.1", port=8091)
