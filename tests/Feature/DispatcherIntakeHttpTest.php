<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database;
use GuzzleHttp\Client;
use Tests\Support\DatabaseSeeder;
use Tests\Support\TestCase;

/** HTTP integration: run ONLY with isolated engineer_test DB and MAIL_ENABLED=false. */
final class DispatcherIntakeHttpTest extends TestCase
{
    private array $tokens = [];
    private array $drafts = [];
    private array $tickets = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('engineer_test', Database::connect()->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame('false', strtolower((string) env('MAIL_ENABLED', 'true')),
            'Never run these integration tests with outbound email enabled');
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        foreach ($this->drafts as $id) $db->prepare('DELETE FROM ai_intake_drafts WHERE id = ?')->execute([$id]);
        foreach ($this->tickets as $id) {
            $db->prepare("DELETE FROM audit_log WHERE target_type = 'ticket' AND target_id = ?")->execute([$id]);
            $db->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        foreach ($this->tokens as $hash) $db->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([$hash]);
        parent::tearDown();
    }

    private function client(int $userId): Client
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        Database::connect()->prepare("INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at) VALUES (?, ?, 'intake-test', DATE_ADD(NOW(), INTERVAL 1 HOUR))")
            ->execute([$userId, $hash]);
        $this->tokens[] = $hash;
        return new Client(['base_uri' => self::$base, 'http_errors' => false,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']]);
    }

    private function analyze(Client $client, string $text, string $req): array
    {
        $res = $client->post('/api/v1/intake/analyze', ['json' => ['text' => $text, 'source' => 'text', 'request_id' => $req]]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true, 32, JSON_THROW_ON_ERROR);
        $this->drafts[] = (int) $data['draft_id'];
        self::assertNull($data['ticket_id']);
        return $data;
    }

    public function test_auth_private_draft_no_ticket_until_confirm_and_idempotency(): void
    {
        $admin = $this->client(DatabaseSeeder::$adminId);
        $portal = $this->client(DatabaseSeeder::$portalId);
        $agent = $this->client(DatabaseSeeder::$agentId);
        self::assertSame(401, $this->guestClient()->post('/api/v1/intake/analyze', ['json' => ['text' => 'Printer does not work', 'source' => 'text']])->getStatusCode());
        $before = (int) Database::connect()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
        $key = 'intake-test-' . bin2hex(random_bytes(5));
        $draft = $this->analyze($portal, 'В кабинете 312 принтер не печатает', $key);
        self::assertSame($before, (int) Database::connect()->query('SELECT COUNT(*) FROM tickets')->fetchColumn());
        $path = '/api/v1/intake/drafts/' . $draft['draft_id'];
        self::assertSame(404, $agent->get($path)->getStatusCode(), 'Foreign draft should not be visible to unrelated staff');
        self::assertSame(403, $portal->post($path . '/confirm', ['json' => ['revision' => $draft['revision'], 'approve' => true]])->getStatusCode());
        self::assertSame(200, $portal->post('/api/v1/intake/analyze', ['json' => [
            'text' => 'В кабинете 312 принтер не печатает', 'source' => 'text', 'request_id' => $key
        ]])->getStatusCode());
        self::assertSame(409, $portal->post('/api/v1/intake/analyze', ['json' => [
            'text' => 'Совсем другой случай', 'source' => 'text', 'request_id' => $key
        ]])->getStatusCode());
        $revision = $draft['revision'];
        $res = $portal->post($path . '/clarify', ['json' => ['revision' => $revision, 'answer' => 'У одного пользователя, кабинет 312']]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $updated = json_decode((string) $res->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($revision + 1, $updated['revision']);
        self::assertSame(409, $portal->post($path . '/clarify', ['json' => ['revision' => $revision, 'answer' => 'Старая версия']])->getStatusCode());
        $response = $admin->post($path . '/confirm', ['json' => ['revision' => $updated['revision'], 'approve' => true,
            'manual_review_reason' => 'Диспетчер проверил локализацию и приоритет вручную',
            'approved' => ['subject' => 'Печать не работает в кабинете 312', 'description' => 'Принтер не печатает, кабинет 312; проверено оператором.',
                'type_id' => null, 'priority_id' => null, 'location_id' => null, 'group_id' => null, 'assigned_to' => null]]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $created = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        $this->tickets[] = $created['ticket_id'];
        self::assertSame($before + 1, (int) Database::connect()->query('SELECT COUNT(*) FROM tickets')->fetchColumn());
        $repeat = $admin->post($path . '/confirm', ['json' => ['revision' => $updated['revision'], 'approve' => true]]);
        self::assertSame(200, $repeat->getStatusCode());
        self::assertSame($created['ticket_id'], json_decode((string) $repeat->getBody(), true)['ticket_id']);
        self::assertSame($before + 1, (int) Database::connect()->query('SELECT COUNT(*) FROM tickets')->fetchColumn());
    }
}
