FROM python:3.12-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg tesseract-ocr tesseract-ocr-rus \
    && rm -rf /var/lib/apt/lists/* \
    && useradd -r -m inference
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY --chown=inference:inference app.py .
COPY --chown=inference:inference kb ./kb
RUN chown inference:inference /app
USER inference
CMD ["sh","-ec","python app.py && exec uvicorn app:app --host 0.0.0.0 --port 8092 --limit-concurrency 24"]
