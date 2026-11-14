<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database;
use GuzzleHttp\Client;
use Tests\Support\DatabaseSeeder;
use Tests\Support\TestCase;

final class ApiSecurityIntegrationTest extends TestCase
{
    public function test_invalid_update_preserves_status_and_timeline(): void
    {
        $db = Database::connect();
        $db->prepare("INSERT INTO tickets (subject, description, created_by) VALUES ('[TEST] Atomic validation', '', ?)")
            ->execute([DatabaseSeeder::$portalId]);
        $id = (int) $db->lastInsertId();
        $ticket = $db->prepare('SELECT status, assigned_to FROM tickets WHERE id = ?');
        $timeline = $db->prepare('SELECT COUNT(*) FROM ticket_timeline WHERE ticket_id = ?');
        try {
            $client = $this->adminApi();
            $path = "/api/v1/tickets/{$id}/update";
            $ticket->execute([$id]);
            $before = $ticket->fetch();
            $status = $before['status'] === 'closed' ? 'open' : 'closed';
            foreach ([['assigned_to' => DatabaseSeeder::$portalId], ['group_id' => 4294967295], ['priority_id' => []]] as $invalid) {
                $response = $client->post($path, ['json' => ['status' => $status] + $invalid]);
                self::assertSame(422, $response->getStatusCode());
                $ticket->execute([$id]);
                self::assertSame($before, $ticket->fetch());
                $timeline->execute([$id]);
                self::assertSame(0, (int) $timeline->fetchColumn());
            }
            self::assertSame(200, $client->post($path, ['json' => ['assigned_to' => DatabaseSeeder::$agentId]])->getStatusCode());
            $ticket->execute([$id]);
            self::assertSame(DatabaseSeeder::$agentId, (int) $ticket->fetch()['assigned_to']);
        } finally {
            $db->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }

    public function test_confidential_ticket_surfaces_use_the_same_access_rule(): void
    {
        $db = Database::connect();
        $group = (int) $db->query('SELECT id FROM `groups` ORDER BY id LIMIT 1')->fetchColumn();
        $db->prepare("INSERT INTO ticket_types (name, group_id, is_confidential) VALUES ('[TEST] Restricted timeline', ?, 1)")
            ->execute([$group]);
        $type = (int) $db->lastInsertId();
        try {
            $db->prepare("INSERT INTO tickets (subject, description, created_by, type_id) VALUES ('[TEST] Restricted ticket', '', ?, ?)")
                ->execute([DatabaseSeeder::$portalId, $type]);
            $id = (int) $db->lastInsertId();
            try {
                $client = $this->adminApi();
                self::assertSame(403, $client->get("/api/v1/tickets/{$id}")->getStatusCode());
                self::assertSame(403, $client->get("/api/v1/tickets/{$id}/timeline")->getStatusCode());
                $list = $client->get('/api/v1/tickets', ['query' => ['type_id' => $type]]);
                self::assertSame(200, $list->getStatusCode());
                $data = json_decode((string) $list->getBody(), true, 16, JSON_THROW_ON_ERROR);
                self::assertSame(0, $data['total']);
                $dashboard = $client->get('/api/v1/dashboard');
                self::assertSame(200, $dashboard->getStatusCode());
                self::assertStringNotContainsString('[TEST] Restricted ticket', (string) $dashboard->getBody());

                $db->prepare('UPDATE ticket_types SET group_id = NULL WHERE id = ?')->execute([$type]);
                self::assertSame(403, $client->get("/api/v1/tickets/{$id}")->getStatusCode());
            } finally {
                $db->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
            }
        } finally {
            $db->prepare('DELETE FROM ticket_types WHERE id = ?')->execute([$type]);
        }
    }

    public function test_invalid_json_and_typed_fields_have_controlled_errors(): void
    {
        $login = '/api/v1/auth/login';
        $client = $this->guestClient();
        foreach (['{', '[]', 'null'] as $body) {
            self::assertSame(400, $client->post($login, ['body' => $body])->getStatusCode());
        }
        self::assertSame(422, $client->post($login, ['json' => ['email' => []]])->getStatusCode());
        self::assertFalse(\verifyCsrf(['invalid']));
    }

    public function test_web_login_rejects_non_string_fields(): void
    {
        $client = $this->guestClient();
        $page = (string) $client->get('/login')->getBody();
        self::assertSame(1, preg_match('/name="_token"\s+value="([^"]+)"/', $page, $matches));
        $response = $client->post('/login', ['form_params' => [
            '_token' => $matches[1],
            'email' => ['synthetic'],
        ]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Invalid request', (string) $response->getBody());
    }

    public function test_sso_callback_rejects_non_string_state_without_calling_provider(): void
    {
        $response = $this->guestClient()->get('/auth/microsoft/callback', [
            'query' => ['state' => ['synthetic']],
            'allow_redirects' => false,
        ]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?sso_error=state', $response->getHeaderLine('Location'));
    }

    private function adminApi(): Client
    {
        $login = $this->guestClient()->post('/api/v1/auth/login', ['json' => [
            'email' => DatabaseSeeder::ADMIN_EMAIL,
            'password' => DatabaseSeeder::password(),
        ]]);
        self::assertSame(200, $login->getStatusCode());
        $data = json_decode((string) $login->getBody(), true, 16, JSON_THROW_ON_ERROR);
        return new Client([
            'base_uri' => self::$base,
            'http_errors' => false,
            'headers' => ['Authorization' => 'Bearer ' . $data['token']],
        ]);
    }
}
