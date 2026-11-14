<?php

declare(strict_types=1);

require __DIR__ . '/../src/TranscriptionService.php';

function env(string $name, string $default): string
{
    return getenv($name) ?: $default;
}

$path = $argv[1] ?? '';
$worker = isset($argv[2]) && $argv[2] === '--real' ? null : static fn () => 'Тест';
$result = TranscriptionService::transcribe($path, 'upload', $worker);
if ($result['status'] !== 'transcribed' || $result['duration_ms'] <= 0
    || ($worker !== null && $result['text'] !== 'Тест')) {
    throw new RuntimeException('Invalid transcription result');
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
