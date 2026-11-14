# Распознавание речи

Для PHP нужны cURL и Fileinfo, для проверки длительности нужен FFmpeg. Настройте
`upload_max_filesize=20M` и `post_max_size=21M`, а также разрешите эти размеры в прокси.
В окружении приложения задайте `STT_WHISPER_URL=http://127.0.0.1:8090/inference`, если
Whisper работает на том же хосте. Для контейнеров укажите адрес во внутренней сети.
На стенде используется whisper.cpp с моделью large-v3-turbo и опцией `--convert`.
Whisper должен быть доступен только из доверенной сети приложения.

API использует обычный Bearer-токен HelpDesk. Пример для локальной тестовой копии:

```sh
curl -H "Authorization: Bearer ${HELPDESK_TOKEN}" \
  -F audio=@recording.webm -F source=browser \
  http://127.0.0.1:8085/api/v1/intake/transcriptions
```

Поддерживаемые контейнеры: WAV, MP3, MP4/M4A, Ogg и WebM с аудиодорожкой.
Максимум 20 МиБ и 120 секунд. PHP удаляет временный файл HTTP-загрузки после запроса.
Для записи звонка доверенный обработчик может вызвать
`TranscriptionService::transcribe($path, 'phone')` и удалить собственный файл после вызова.

Проверка сервиса с подменённым распознавателем и затем с реальным Whisper:

```sh
php scripts/test-transcription.php recording.webm
STT_WHISPER_URL=http://127.0.0.1:8090/inference php scripts/test-transcription.php recording.webm --real
```
