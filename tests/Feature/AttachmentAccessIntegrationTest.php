<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database;
use GuzzleHttp\Client;
use Tests\Support\DatabaseSeeder;
use Tests\Support\TestCase;

final class AttachmentAccessIntegrationTest extends TestCase
{
    private const CONTENT = 'Synthetic attachment for access control tests.';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jJd8AAAAASUVORK5CYII=';
    private array $files = [];
    private array $attachmentIds = [];
    private array $timelineIds = [];
    private array $tokens = [];

    public function test_requester_can_only_download_public_attachments_on_their_ticket(): void
    {
        $id = $this->attachment(DatabaseSeeder::$ticketId, null);
        $otherTicket = $this->ticketOwnedByAdmin();

        try {
            $otherId = $this->attachment($otherTicket, null);
            $path = '/api/v1/attachments/' . $id;
            self::assertSame(401, $this->guestClient()->get($path)->getStatusCode());

            $requester = $this->bearerClient(DatabaseSeeder::$portalId);
            $response = $requester->get($path);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(self::CONTENT, (string) $response->getBody());
            self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame(403, $requester->get('/api/v1/attachments/' . $otherId)->getStatusCode());
            self::assertSame(403, $this->bearerClient(DatabaseSeeder::$agentId)
                ->get('/api/v1/attachments/' . $otherId)->getStatusCode());
        } finally {
            Database::connect()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$otherTicket]);
        }
    }

    public function test_internal_note_attachments_require_staff_access(): void
    {
        $note = $this->internalNote();
        $path = '/api/v1/attachments/' . $this->attachment(DatabaseSeeder::$ticketId, $note);
        $requester = $this->bearerClient(DatabaseSeeder::$portalId);
        $denied = $requester->get($path);
        self::assertSame(403, $denied->getStatusCode());
        self::assertStringNotContainsString(self::CONTENT, (string) $denied->getBody());

        $response = $this->bearerClient(DatabaseSeeder::$agentId)->get($path);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::CONTENT, (string) $response->getBody());
    }

    public function test_requester_cannot_upload_to_an_internal_note(): void
    {
        $note = $this->internalNote();
        $response = $this->bearerClient(DatabaseSeeder::$portalId)->post(
            '/api/v1/tickets/' . DatabaseSeeder::$ticketId . '/attachments',
            ['multipart' => [
                ['name' => 'timeline_id', 'contents' => (string) $note],
                ['name' => 'attachments', 'contents' => self::CONTENT, 'filename' => 'synthetic.txt'],
            ]],
        );
        self::assertSame(403, $response->getStatusCode());
        $stmt = Database::connect()->prepare('SELECT COUNT(*) FROM ticket_attachments WHERE timeline_id = ?');
        $stmt->execute([$note]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function test_upload_rejects_unsupported_content_without_leaving_partial_files(): void
    {
        $before = glob(ATTACHMENT_STORAGE_PATH . '*');
        $client = $this->bearerClient(DatabaseSeeder::$portalId);
        $png = base64_decode(self::PNG);
        $response = $client->post('/api/v1/tickets/' . DatabaseSeeder::$ticketId . '/attachments', [
            'multipart' => [
                ['name' => 'attachments[]', 'contents' => $png, 'filename' => 'valid.png'],
                ['name' => 'attachments[]', 'contents' => '<html><body>HTML fixture</body></html>', 'filename' => 'fake.txt'],
            ],
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('fake.txt', (string) $response->getBody());
        self::assertSame($before, glob(ATTACHMENT_STORAGE_PATH . '*'));
    }

    public function test_valid_upload_can_be_downloaded_as_an_attachment(): void
    {
        $png = base64_decode(self::PNG);
        $client = $this->bearerClient(DatabaseSeeder::$portalId);
        $response = $client->post('/api/v1/tickets/' . DatabaseSeeder::$ticketId . '/attachments', [
            'multipart' => [['name' => 'attachments', 'contents' => $png, 'filename' => 'synthetic.png']],
        ]);
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $id = $data['data'][0]['id'];
        $this->attachmentIds[] = $id;
        $stmt = Database::connect()->prepare('SELECT stored_name FROM ticket_attachments WHERE id = ?');
        $stmt->execute([$id]);
        $this->files[] = ATTACHMENT_STORAGE_PATH . $stmt->fetchColumn();
        $response = $client->get('/api/v1/attachments/' . $id);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($png, (string) $response->getBody());
    }

    private function bearerClient(int $userId): Client
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $db = Database::connect();
        $db->prepare("INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at)
            VALUES (?, ?, 'attachment-test', DATE_ADD(NOW(), INTERVAL 1 HOUR))")->execute([$userId, $hash]);
        $this->tokens[] = $hash;
        return new Client([
            'base_uri' => self::$base,
            'http_errors' => false,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
    }

    private function ticketOwnedByAdmin(): int
    {
        $db = Database::connect();
        $db->prepare("INSERT INTO tickets (subject, description, created_by, status) VALUES (?, '', ?, 'open')")
            ->execute(['[TEST] Private attachment fixture', DatabaseSeeder::$adminId]);
        return (int) $db->lastInsertId();
    }

    private function internalNote(): int
    {
        $db = Database::connect();
        $db->prepare("INSERT INTO ticket_timeline (ticket_id, user_id, action, details, is_internal)
            VALUES (?, ?, 'comment', '[TEST] Internal attachment note', 1)")
            ->execute([DatabaseSeeder::$ticketId, DatabaseSeeder::$agentId]);
        $id = (int) $db->lastInsertId();
        $this->timelineIds[] = $id;
        return $id;
    }

    private function attachment(int $ticket, ?int $note): int
    {
        $file = 'test-attachment-' . bin2hex(random_bytes(12)) . '.txt';
        $path = ATTACHMENT_STORAGE_PATH . $file;
        if (!is_dir(ATTACHMENT_STORAGE_PATH)) {
            mkdir(ATTACHMENT_STORAGE_PATH, 0755, true);
        }
        self::assertSame(strlen(self::CONTENT), file_put_contents($path, self::CONTENT));
        $this->files[] = $path;

        $db = Database::connect();
        $db->prepare("INSERT INTO ticket_attachments
            (ticket_id, timeline_id, uploaded_by, original_name, stored_name, mime_type, file_size)
            VALUES (?, ?, ?, 'synthetic.txt', ?, 'text/plain', ?)")
            ->execute([$ticket, $note, DatabaseSeeder::$agentId, $file, strlen(self::CONTENT)]);
        $id = (int) $db->lastInsertId();
        $this->attachmentIds[] = $id;
        return $id;
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        foreach ($this->attachmentIds as $id) {
            $db->prepare('DELETE FROM ticket_attachments WHERE id = ?')->execute([$id]);
        }
        foreach ($this->timelineIds as $id) {
            $db->prepare('DELETE FROM ticket_timeline WHERE id = ?')->execute([$id]);
        }
        foreach ($this->tokens as $hash) {
            $db->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([$hash]);
        }
        foreach ($this->files as $path) {
            unlink($path);
        }
        parent::tearDown();
    }
}
