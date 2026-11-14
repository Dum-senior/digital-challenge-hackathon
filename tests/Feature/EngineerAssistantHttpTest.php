<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database;
use GuzzleHttp\Client;
use Tests\Support\DatabaseSeeder;
use Tests\Support\TestCase;

final class EngineerAssistantHttpTest extends TestCase
{
    private static int $ticket;
    private array $tokens = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $db = Database::connect();
        $db->prepare("INSERT INTO tickets (subject, description, created_by, status) VALUES (?, '', ?, 'open')")
            ->execute(['[TEST] xyzzypqqnvv', DatabaseSeeder::$agentId]);
        self::$ticket = (int) $db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        $db = Database::connect();
        $db->prepare("DELETE FROM audit_log WHERE target_type = 'ticket' AND target_id = ?")->execute([self::$ticket]);
        $db->prepare('DELETE FROM tickets WHERE id = ?')->execute([self::$ticket]);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([DatabaseSeeder::$adminId, DatabaseSeeder::$agentId] as $id) {
            Database::connect()->prepare('DELETE FROM api_rate_limits WHERE bucket = ?')->execute(['engineer-assist:' . $id]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tokens as $hash) {
            Database::connect()->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([$hash]);
        }
        parent::tearDown();
    }

    private function api(?int $id = null): Client
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        Database::connect()->prepare("INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at) VALUES (?, ?, 'engineer-test', DATE_ADD(NOW(), INTERVAL 1 HOUR))")
            ->execute([$id ?? DatabaseSeeder::$adminId, $hash]);
        $this->tokens[] = $hash;
        return new Client(['base_uri' => self::$base, 'http_errors' => false,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']]);
    }

    private function path(): string
    {
        return '/api/v1/tickets/' . self::$ticket . '/engineer-assist';
    }

    public function test_api_authentication_roles_id_validation_and_result_contract(): void
    {
        self::assertSame(401, $this->guestClient()->post($this->path())->getStatusCode());
        self::assertSame(403, $this->api(DatabaseSeeder::$portalId)->post($this->path())->getStatusCode());
        $client = $this->api();
        self::assertSame(400, $client->post('/api/v1/tickets/abc/engineer-assist')->getStatusCode());
        self::assertSame(404, $client->post('/api/v1/tickets/4294967295/engineer-assist')->getStatusCode());
        $response = $client->post($this->path());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(self::$ticket, $data['ticket_id']);
        self::assertSame('no_sources', $data['status']);
        self::assertSame([], $data['steps']);
        self::assertTrue($data['requires_human_review']);
    }

    public function test_session_requires_login_and_csrf(): void
    {
        $path = '/agent/tickets/' . self::$ticket . '/engineer-assist';
        self::assertSame(401, $this->guestClient()->post($path)->getStatusCode());
        $portalResponse = $this->portalClient()->post($path);
        self::assertSame(403, $portalResponse->getStatusCode());
        self::assertStringContainsString('application/json', $portalResponse->getHeaderLine('Content-Type'));
        self::assertSame(403, $this->agentClient()->post($path)->getStatusCode());
        self::assertSame(403, $this->agentClient()->post($path, ['form_params' => ['_token' => ['invalid']]])->getStatusCode());
        self::assertSame(200, $this->post($this->agentClient(), $path, [])->getStatusCode());
    }

    public function test_api_and_session_share_the_same_budget(): void
    {
        $client = $this->api();
        for ($i = 0; $i < 4; ++$i) {
            self::assertSame(200, $client->post($this->path())->getStatusCode());
        }
        self::assertSame(200, $this->post($this->adminClient(), '/agent/tickets/' . self::$ticket . '/engineer-assist', [])->getStatusCode());
        $response = $client->post($this->path());
        self::assertSame(429, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));
    }

    public function test_staff_pages_include_shared_assistant_controls(): void
    {
        foreach ([['admin', $this->adminClient()], ['agent', $this->agentClient()]] as [$panel, $client]) {
            $response = $this->get($client, '/' . $panel . '/tickets/' . self::$ticket);
            self::assertSame(200, $response->getStatusCode());
            $body = (string) $response->getBody();
            self::assertStringContainsString('id="engineerAssistantRun"', $body);
            self::assertStringContainsString('/assets/js/engineer-assistant.js', $body);
        }
    }
}
