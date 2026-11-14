FROM python:3.12-slim-bookworm AS python
FROM release_web
COPY --from=python /usr/local/bin/python* /usr/local/bin/
COPY --from=python /usr/local/lib/libpython* /usr/local/lib/
COPY --from=python /usr/local/lib/python3.12 /usr/local/lib/python3.12
RUN ldconfig && apt-get update && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-rus \
    && rm -rf /var/lib/apt/lists/* && useradd -r -m inference
WORKDIR /inference
COPY requirements.txt .
RUN python3 -m pip install --no-cache-dir -r requirements.txt
COPY --chown=inference:inference app.py .
COPY --chown=inference:inference kb ./kb
RUN chown inference:inference /inference
USER inference
CMD ["sh","-ec","python3 app.py && exec uvicorn app:app --host 0.0.0.0 --port 8092 --limit-concurrency 24"]
