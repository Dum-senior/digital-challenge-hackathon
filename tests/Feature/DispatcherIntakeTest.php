<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database;
use IntakeDispatcher;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\DatabaseSeeder;

require_once dirname(__DIR__, 2) . '/src/IntakeDispatcher.php';

final class DispatcherIntakeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::connect();
        self::assertSame('engineer_test', $this->db->query('SELECT DATABASE()')->fetchColumn(),
            'These database tests must NEVER run against a working database');
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) $this->db->rollBack();
    }

    public function test_rejects_control_chars_unknown_source_and_extra_input(): void
    {
        foreach ([
            ['text' => 'Printer smoke', 'source' => 'sip'],
            ['text' => 'a', 'source' => 'text'],
            ['text' => "Bad\0 request", 'source' => 'text'],
            ['text' => 'Printer smoke', 'source' => 'text', 'assigned_to' => 9],
        ] as $input) {
            try {
                IntakeDispatcher::validateInput($input);
                self::fail('Invalid input accepted: ' . json_encode($input));
            } catch (\InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
        self::assertSame('voice', IntakeDispatcher::validateInput(['text' => 'Текст обращения', 'source' => 'voice'])['source']);
    }

    public function test_portal_cannot_confirm_regardless_of_requested_role(): void
    {
        self::assertFalse(IntakeDispatcher::canConfirm(['id' => DatabaseSeeder::$portalId, 'role' => 'user']));
        self::assertTrue(IntakeDispatcher::canConfirm(['id' => DatabaseSeeder::$adminId, 'role' => 'admin']));
    }

    public function test_unknown_type_priority_group_and_assignee_are_rejected(): void
    {
        $base = ['subject' => 'Принтер не печатает', 'description' => 'Принтер не печатает, кабинет 312',
            'type_id' => null, 'priority_id' => null, 'location_id' => null, 'group_id' => null,
            'assigned_to' => null, 'tag_ids' => []];
        $saved = IntakeDispatcher::validateApproved($this->db, $base, DatabaseSeeder::$portalId, true);
        self::assertNull($saved['assigned_to']);
        foreach ([['group_id' => 2147483640], ['priority_id' => 2147483640],
            ['assigned_to' => 2147483640], ['location_id' => -1], ['unknown' => 1]] as $change) {
            $this->expectInvalid($base + [], $change);
        }
    }

    private function expectInvalid(array $base, array $change): void
    {
        try {
            IntakeDispatcher::validateApproved($this->db, array_replace($base, $change), DatabaseSeeder::$portalId, true);
            self::fail('Invalid confirmed fields were accepted');
        } catch (\InvalidArgumentException $expected) {
            self::assertNotSame('', $expected->getMessage());
        }
    }

    public function test_live_group_skill_and_load_change_recommended_engineer(): void
    {
        // Test rows are *real* users/groups/skills in the isolated DB, never hard-coded IDs.
        $db = $this->db;
        $db->prepare("INSERT INTO `groups` (name, description, assign_strategy) VALUES (?, '', 'manual')")
            ->execute(['Принтер [02DEN TEST]']);
        $group = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO agent_skills (name, group_id) VALUES (?, ?)')
            ->execute(['printer test ' . bin2hex(random_bytes(5)), $group]);
        $skill = (int) $db->lastInsertId();
        $users = [];
        foreach ([1, 2] as $i) {
            $db->prepare('INSERT INTO users (first_name, last_name, email, password, role, is_available) VALUES (?, ?, ?, ?, ?, 1)')
                ->execute(['AI', 'Test-' . $i, 'den-skill-' . bin2hex(random_bytes(6)) . '@example.invalid', 'disabled', 'agent']);
            $id = (int) $db->lastInsertId();
            $users[] = $id;
            $db->prepare('INSERT INTO group_user_map (group_id, user_id) VALUES (?, ?)')->execute([$group, $id]);
            $db->prepare('INSERT INTO user_skill_map (user_id, skill_id) VALUES (?, ?)')->execute([$id, $skill]);
        }
        $first = IntakeDispatcher::suggest($db, 'peripherals');
        // If isolated DB has another printer-named group, fail visibly rather than invent a match.
        self::assertSame($group, $first['group_id'], $first['reason']);
        self::assertSame($users[0], $first['assignee_id']);
        $db->prepare('INSERT INTO tickets (subject, description, created_by, assigned_to, group_id, status) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['[TEST] Printer', 'fixture', DatabaseSeeder::$adminId, $users[0], $group, ticketDefaultNewStatusSlug()]);
        $second = IntakeDispatcher::suggest($db, 'peripherals');
        self::assertSame($users[1], $second['assignee_id'], 'Recommender must adapt to LIVE ticket load');
    }

    public function test_analyze_never_returns_real_ticket_or_persists_it(): void
    {
        // LLM may be down: still return a human-only draft proposal without INSERT.
        $before = (int) $this->db->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
        $analysis = IntakeDispatcher::analyze($this->db, 'Принтер не печатает, из корпуса идёт дым', 'text');
        $after = (int) $this->db->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
        self::assertSame($before, $after);
        self::assertTrue($analysis['is_potential_emergency']);
        self::assertSame('critical', $analysis['priority']);
        self::assertTrue($analysis['requires_human_review']);
        self::assertArrayNotHasKey('ticket_id', $analysis);
    }
}
