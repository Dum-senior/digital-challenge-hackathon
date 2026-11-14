# API ИИ-диспетчерской

База знаний в `kb/` содержит русскоязычные инструкции для диагностики. Обновлённые разделы содержат ссылки на открытые первоисточники. Действия на производственном оборудовании выполняются по регламенту заказчика.

Все вызовы требуют `Authorization: Bearer <токен>`. В примерах `BASE` — адрес API без завершающего слеша.

| Метод | Путь | Тело |
|---|---|---|
| GET | /health | Без тела |
| POST | /ocr | multipart, file (PNG, JPEG, WebP до 10 МиБ) |
| POST | /transcribe | multipart, file (до 20 МиБ, 120 секунд) |
| POST | /analyze | JSON, text |
| POST | /retrieve | JSON, text |
| POST | /resolve | JSON, text |

```sh
curl -fsS -H "Authorization: Bearer $DISPATCHER_API_TOKEN" -F file=@photo.png "$BASE/ocr"
curl -fsS -H "Authorization: Bearer $DISPATCHER_API_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"text":"Клиент подключён, но теги OPC показывают Bad Quality"}' "$BASE/resolve"
```

OCR одновременно ищет русский и английский текст. После OCR или STT можно проверить поле `text` и передать его в `/analyze` и `/resolve`. `/retrieve` возвращает выдержки и оценки сходства. `/resolve` возвращает рекомендации со ссылками на разделы либо `insufficient_evidence`.

Модели запускаются локально. Требуются Ollama на 11435 с `qwen2.5:1.5b-instruct` и `bge-m3`, Whisper на 8090, FFmpeg и Tesseract с `rus+eng`. Установите зависимости в venv через `pip install -r requirements.txt`. Пересоберите векторы после правок базы командой `python app.py`, затем запустите `uvicorn app:app --host 127.0.0.1 --port 8092`.

На стенде используются процессы llama.cpp (чат на 11436, эмбеддинги на 11437). Задайте `DISPATCHER_LLAMA=1` для API и сборки индекса при этом варианте.
