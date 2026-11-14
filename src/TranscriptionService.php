<?php

declare(strict_types=1);

final class TranscriptionService
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_SECONDS = 120;
    private const FORMATS = [
        'audio/wav', 'audio/x-wav', 'audio/mpeg', 'audio/mp4',
        'audio/ogg', 'application/ogg', 'audio/webm', 'video/webm', 'video/mp4',
    ];

    public static function transcribe(string $path, string $source, ?callable $worker = null): array
    {
        if (!in_array($source, ['browser', 'upload', 'phone'], true)) {
            throw new RuntimeException('Invalid audio source', 400);
        }
        $size = filesize($path);
        if (!$size) {
            throw new RuntimeException('Empty audio recording', 400);
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Audio must be at most 20 MiB', 413);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, self::FORMATS, true)) {
            throw new RuntimeException('Unsupported audio format', 415);
        }

        // Decode a bounded amount so recordings without container duration work too.
        $process = @proc_open(
            ['ffmpeg', '-nostdin', '-v', 'error', '-xerror', '-protocol_whitelist', 'file',
                '-i', $path, '-map', '0:a:0', '-t', (string) (self::MAX_SECONDS + 1),
                '-ar', '8000', '-ac', '1', '-f', 's16le', 'pipe:1'],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Audio inspection unavailable', 503);
        }
        $pcm = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit === 127) {
            throw new RuntimeException('Audio inspection unavailable', 503);
        }
        if ($exit !== 0 || !$pcm) {
            throw new RuntimeException('Audio is invalid or empty', 400);
        }
        $duration = strlen($pcm) / 16000;
        if ($duration > self::MAX_SECONDS) {
            throw new RuntimeException('Audio must be at most 120 seconds', 413);
        }

        $started = hrtime(true);
        $text = ($worker ?? self::whisper(...))($path, $mime);
        if (trim($text) === '') {
            throw new RuntimeException('No speech was recognized', 400);
        }
        return [
            'status' => 'transcribed',
            'text' => trim($text),
            'language' => 'ru',
            'duration_ms' => (int) round($duration * 1000),
            'processing_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'source' => $source,
        ];
    }

    private static function whisper(string $path, string $mime): string
    {
        $url = env('STT_WHISPER_URL', '');
        if ($url === '' || !function_exists('curl_init')) {
            throw new RuntimeException('Transcription service unavailable', 503);
        }
        $request = curl_init($url);
        curl_setopt_array($request, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($path, $mime, 'recording'),
                'language' => 'ru',
                'response_format' => 'json',
                'prompt' => 'Термины: КП-12, OPC-сервер, НТП, телеметрия, счётчик, IP-адрес, АСУ ТП.',
            ],
        ]);
        $body = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        $text = is_string($body) ? (json_decode($body, true)['text'] ?? null) : null;
        if ($status !== 200 || !is_string($text)) {
            throw new RuntimeException('Transcription service unavailable', 503);
        }
        return $text;
    }
}
