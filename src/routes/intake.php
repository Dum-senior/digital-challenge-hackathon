<?php

declare(strict_types=1);

/** 02_ДЕН — routes appended AFTER the existing src/routes/api.php. */
require_once ROOT_DIR . '/src/IntakeDispatcher.php';

/** API bearer tokens or same-origin session/CSRF; never accept a browser-supplied AI service token. */
function _intakeActor(): array
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (str_starts_with($path, '/api/v1/')) {
        $actor = _apiAuth();
    } else {
        Auth::requireAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') requireJsonCsrf();
        $actor = Auth::user();
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // Shared extra budget for both API and browser; cannot multiply calls by switching channel.
        $db = Database::connect();
        $window = time() - (time() % 60);
        $bucket = 'intake:' . (int) $actor['id'];
        try {
            $db->prepare('INSERT INTO api_rate_limits (bucket, window_start, count) VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE count = count + 1')->execute([$bucket, $window]);
            $s = $db->prepare('SELECT count FROM api_rate_limits WHERE bucket = ? AND window_start = ?');
            $s->execute([$bucket, $window]);
            if ((int) $s->fetchColumn() > 25) {
                header('Retry-After: ' . (60 - (time() % 60)));
                _intakeReply(['error' => 'Intake rate limit exceeded'], 429);
            }
        } catch (Throwable $e) {
            error_log('intake rate limiter unavailable');
            _intakeReply(['error' => 'Rate limiter temporarily unavailable'], 503);
        }
    }
    return $actor;
}
function _intakeReply(array $result, int $code = 200): never
{
    header('Cache-Control: private, no-store');
    _apiJson($result, $code);
}

function _intakeBody(): array
{
    if (!str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        _intakeReply(['error' => 'Content-Type: application/json required'], 415);
    }
    $stream = fopen('php://input', 'rb');
    $raw = stream_get_contents($stream, 16385);
    fclose($stream);
    if ($raw === false || strlen($raw) > 16384) _intakeReply(['error' => 'Payload too large'], 413);
    try {
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        _intakeReply(['error' => 'Invalid JSON'], 422);
    }
    if (!is_array($value) || array_is_list($value)) _intakeReply(['error' => 'JSON object required'], 422);
    return $value;
}

function _intakeDefaultEdited(array $analysis, bool $hasStaff): array
{
    return [
        'subject' => $analysis['subject'], 'description' => $analysis['description'],
        'type_id' => $analysis['type_id'], 'priority_id' => $analysis['priority_id'],
        'location_id' => $analysis['location_id'],
        'group_id' => $hasStaff ? $analysis['recommended_group_id'] : null,
        'assigned_to' => $hasStaff ? $analysis['recommended_assignee_id'] : null,
        'tag_ids' => $analysis['tag_ids'] ?? [],
        'fields' => array_intersect_key($analysis, array_flip(['object','floor','room','equipment',
            'serial_number','incident_time','affected_scope','symptoms','ip_addresses','error_codes','meter_number','started_at','useful_notes'])),
    ];
}

function _intakeStatus(array $analysis): string
{
    if (($analysis['analysis_mode'] ?? '') === 'failed') return 'failed';
    return !empty($analysis['questions']) ? 'needs_clarification' : 'awaiting_review';
}

function _intakeFetch(PDO $db, int $draftId, array $actor, bool $lock = false): array
{
    $sql = 'SELECT * FROM ai_intake_drafts WHERE id = ?' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->execute([$draftId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ((int) $row['user_id'] !== (int) $actor['id'] && !IntakeDispatcher::canConfirm($actor))) {
        _intakeReply(['error' => 'Draft not found'], 404);
    }
    return $row;
}

$intakeAnalyze = static function (): void {
    $user = _intakeActor();
    $input = _intakeBody();
    try {
        $validated = IntakeDispatcher::validateInput($input);
    } catch (InvalidArgumentException $e) {
        _intakeReply(['error' => $e->getMessage()], 422);
    }
    $db = Database::connect();
    $source = $validated['source'];
    $text = $validated['text'];
    $key = $validated['request_id'] !== null ? hash('sha256', $validated['request_id']) : null;
    $fingerprint = hash('sha256', $source . "\0" . $text);
    if ($key !== null) {
        $stmt = $db->prepare('SELECT * FROM ai_intake_drafts WHERE user_id = ? AND request_key = ?');
        $stmt->execute([(int) $user['id'], $key]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!hash_equals($row['request_fingerprint'], $fingerprint)) {
                _intakeReply(['error' => 'request_id already used for another request'], 409);
            }
            _intakeReply(IntakeDispatcher::publicResponse($row, $user));
        }
    }
    try {
        $analysis = IntakeDispatcher::analyze($db, $text, $source);
        $status = _intakeStatus($analysis);
        $edited = _intakeDefaultEdited($analysis, roleIsStaff($user['role'] ?? null));
        $stmt = $db->prepare('INSERT INTO ai_intake_drafts
            (user_id, source, request_key, request_fingerprint, raw_text, analysis_json, original_analysis_json,
             edited_json, conversation_json, status, revision)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
        $encoded = IntakeDispatcher::json($analysis);
        $stmt->execute([(int) $user['id'], $source, $key, $fingerprint, $text, $encoded, $encoded,
            IntakeDispatcher::json($edited), '[]', $status]);
        $id = (int) $db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM ai_intake_drafts WHERE id = ?');
        $stmt->execute([$id]);
        _intakeReply(IntakeDispatcher::publicResponse($stmt->fetch(PDO::FETCH_ASSOC), $user), 201);
    } catch (PDOException $e) {
        // Parallel delivery of the same request_id can race before INSERT.
        if ($key !== null && $e->getCode() === '23000') {
            $stmt = $db->prepare('SELECT * FROM ai_intake_drafts WHERE user_id = ? AND request_key = ?');
            $stmt->execute([(int) $user['id'], $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && hash_equals($row['request_fingerprint'], $fingerprint)) {
                _intakeReply(IntakeDispatcher::publicResponse($row, $user));
            }
            _intakeReply(['error' => 'request_id collision'], 409);
        }
        error_log('intake analyze persistence failed');
        _intakeReply(['error' => 'Draft storage unavailable; please use normal ticket form'], 503);
    } catch (Throwable $e) {
        error_log('intake analyze failed: ' . get_class($e));
        _intakeReply(['error' => 'Intake unavailable; please use normal ticket form'], 503);
    }
};
$router->post('/api/v1/intake/analyze', $intakeAnalyze);
$router->post('/intake/analyze', $intakeAnalyze);

$intakeList = static function (): void {
    $actor = _intakeActor();
    $db = Database::connect();
    if (IntakeDispatcher::canConfirm($actor)) {
        // Dispatcher sees pending drafts without texts; fetch a specific draft to review.
        $sql = "SELECT id, user_id, source, status, revision, created_at, updated_at FROM ai_intake_drafts
                WHERE status IN ('needs_clarification','awaiting_review','failed') ORDER BY updated_at DESC LIMIT 100";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare('SELECT id, source, status, revision, created_at, updated_at FROM ai_intake_drafts WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100');
        $stmt->execute([(int) $actor['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    _intakeReply(['data' => $rows]);
};
$router->get('/api/v1/intake/drafts', $intakeList);
$router->get('/intake/drafts', $intakeList);

$intakeGet = static function (array $p): void {
    $actor = _intakeActor();
    if (!ctype_digit((string) $p['draft_id'])) _intakeReply(['error' => 'Invalid draft_id'], 422);
    $row = _intakeFetch(Database::connect(), (int) $p['draft_id'], $actor);
    _intakeReply(IntakeDispatcher::publicResponse($row, $actor));
};
$router->get('/api/v1/intake/drafts/{draft_id}', $intakeGet);
$router->get('/intake/drafts/{draft_id}', $intakeGet);

$intakeClarify = static function (array $p): void {
    $actor = _intakeActor();
    if (!ctype_digit((string) $p['draft_id'])) _intakeReply(['error' => 'Invalid draft_id'], 422);
    $input = _intakeBody();
    if (!isset($input['revision']) || !is_int($input['revision']) || $input['revision'] < 1
        || array_diff(array_keys($input), ['revision', 'answer', 'edits']) !== []) _intakeReply(['error' => 'Invalid clarification'], 422);
    $answer = $input['answer'] ?? '';
    if (!is_string($answer) || mb_strlen($answer) > 2000) _intakeReply(['error' => 'Invalid answer'], 422);
    $edits = $input['edits'] ?? [];
    if (!is_array($edits) || ($edits !== [] && array_is_list($edits)) || array_diff(array_keys($edits), IntakeDispatcher::EDITABLE) !== []) {
        _intakeReply(['error' => 'Unknown edited fields'], 422);
    }
    if (!IntakeDispatcher::canConfirm($actor)) {
        if ($edits !== []) _intakeReply(['error' => 'Only a dispatcher can edit ticket fields'], 403);
    }
    if (trim($answer) === '' && $edits === []) _intakeReply(['error' => 'Nothing to update'], 422);
    $db = Database::connect();
    try {
        $db->beginTransaction();
        $row = _intakeFetch($db, (int) $p['draft_id'], $actor, true);
        if ($row['status'] === 'created') { $db->rollBack(); _intakeReply(['error' => 'Draft already confirmed'], 409); }
        if ((int) $row['revision'] !== $input['revision']) { $db->rollBack(); _intakeReply(['error' => 'Stale draft revision'], 409); }
        $edited = json_decode($row['edited_json'], true, 32, JSON_THROW_ON_ERROR);
        $history = json_decode($row['conversation_json'], true, 32, JSON_THROW_ON_ERROR);
        if (trim($answer) !== '' || array_key_exists('description', $edits)) {
            if (trim($answer) !== '' && count($history) >= 24) { $db->rollBack(); _intakeReply(['error' => 'Clarification history is full'], 422); }
            if (trim($answer) !== '') {
                $history[] = ['by' => (int) $actor['id'], 'answer' => trim($answer), 'at' => gmdate('c')];
            }
            $fullText = is_string($edits['description'] ?? null) ? $edits['description'] : $row['raw_text'];
            foreach ($history as $h) $fullText .= "\nУточнение: " . $h['answer'];
            if (mb_strlen($fullText) > 4000) { $db->rollBack(); _intakeReply(['error' => 'Combined text exceeds 4000 characters'], 422); }
            $previousAnalysis = json_decode($row['analysis_json'], true, 32, JSON_THROW_ON_ERROR);
            $analysis = IntakeDispatcher::analyze($db, $fullText, $row['source']);
            $oldDefault = _intakeDefaultEdited($previousAnalysis, IntakeDispatcher::canConfirm($actor));
            $newDefault = _intakeDefaultEdited($analysis, IntakeDispatcher::canConfirm($actor));
            foreach ($newDefault as $field => $newValue) {
                if (!array_key_exists($field, $edited) || $edited[$field] === $oldDefault[$field]) {
                    $edited[$field] = $newValue;
                }
            }
        } else {
            $analysis = json_decode($row['analysis_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        $edited = array_replace($edited, $edits);
        if ($edits !== []) IntakeDispatcher::validateApproved($db, $edited, (int) $row['user_id'], IntakeDispatcher::canConfirm($actor));
        $status = _intakeStatus($analysis);
        $stmt = $db->prepare('UPDATE ai_intake_drafts SET analysis_json = ?, edited_json = ?, conversation_json = ?,
                              status = ?, revision = revision + 1, updated_at = NOW() WHERE id = ?');
        $stmt->execute([IntakeDispatcher::json($analysis), IntakeDispatcher::json($edited), IntakeDispatcher::json($history), $status, (int) $row['id']]);
        $db->commit();
        $stmt = $db->prepare('SELECT * FROM ai_intake_drafts WHERE id = ?');
        $stmt->execute([(int) $row['id']]);
        _intakeReply(IntakeDispatcher::publicResponse($stmt->fetch(PDO::FETCH_ASSOC), $actor));
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) $db->rollBack();
        _intakeReply(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('intake clarify failed: ' . get_class($e));
        _intakeReply(['error' => 'Unable to save clarification'], 503);
    }
};
$router->post('/api/v1/intake/drafts/{draft_id}/clarify', $intakeClarify);
$router->post('/intake/drafts/{draft_id}/clarify', $intakeClarify);

$intakeConfirm = static function (array $p): void {
    $actor = _intakeActor();
    if (!IntakeDispatcher::canConfirm($actor)) _intakeReply(['error' => 'Dispatcher permission required'], 403);
    if (!ctype_digit((string) $p['draft_id'])) _intakeReply(['error' => 'Invalid draft_id'], 422);
    $input = _intakeBody();
    if (!isset($input['revision']) || !is_int($input['revision']) || $input['revision'] < 1
        || ($input['approve'] ?? null) !== true || array_diff(array_keys($input), ['revision', 'approve', 'approved', 'manual_review_reason', 'sla']) !== []) {
        _intakeReply(['error' => 'Explicit approval and revision required'], 422);
    }
    $manualReason = $input['manual_review_reason'] ?? '';
    if (!is_string($manualReason) || mb_strlen($manualReason) > 500) _intakeReply(['error' => 'Invalid review reason'], 422);
    $db = Database::connect();
    $ticketId = null;
    $assignedTo = null;
    try {
        $db->beginTransaction();
        $row = _intakeFetch($db, (int) $p['draft_id'], $actor, true);
        if ($row['status'] === 'created' && (int) $row['confirmed_ticket_id'] === 0) {
            $db->commit();
            _intakeReply(['error' => 'Confirmed ticket was removed; cannot recreate from same draft'], 410);
        }
        if ($row['status'] === 'created' && (int) $row['confirmed_ticket_id'] > 0) {
            $db->commit();
            _intakeReply(['status' => 'created', 'ticket_id' => (int) $row['confirmed_ticket_id'], 'requires_human_review' => false, 'idempotent_replay' => true]);
        }
        if ((int) $row['revision'] !== $input['revision']) { $db->rollBack(); _intakeReply(['error' => 'Stale draft revision'], 409); }
        $analysis = json_decode($row['analysis_json'], true, 32, JSON_THROW_ON_ERROR);
        $previous = json_decode($row['edited_json'], true, 32, JSON_THROW_ON_ERROR);
        $newApproved = $input['approved'] ?? [];
        if (!is_array($newApproved) || (array_is_list($newApproved) && $newApproved !== [])) {
            $db->rollBack(); _intakeReply(['error' => 'approved must be an object'], 422);
        }
        $approved = IntakeDispatcher::validateApproved($db, array_replace($previous, $newApproved), (int) $row['user_id'], true);
        if ($row['status'] !== 'awaiting_review' && mb_strlen(trim($manualReason)) < 20) {
            $db->rollBack(); _intakeReply(['error' => 'Clarification/AI failure requires documented manual review reason (20+ characters)'], 422);
        }
        $priorityIds = array_map(static fn($level) => IntakeDispatcher::matchPriority($db, $level), ['low','medium','critical']);
        if ($approved['priority_id'] === null || !in_array($approved['priority_id'], $priorityIds, true)) {
            $db->rollBack(); _intakeReply(['error' => 'Выберите приоритет заявки'], 422);
        }
        if (($analysis['is_potential_emergency'] ?? false) && ($analysis['priority_id'] ?? null) !== null
            && $analysis['priority_id'] !== $approved['priority_id'] && mb_strlen(trim($manualReason)) < 20) {
            $db->rollBack(); _intakeReply(['error' => 'Emergency priority override requires reason'], 422);
        }
        $groupId = resolveTicketGroup($db, $approved['group_id'], $approved['type_id']);
        $assignedTo = $approved['assigned_to'];
        $stmt = $db->prepare('INSERT INTO tickets
            (subject, description, created_by, submitted_by, status, type_id, priority_id, location_id, group_id, assigned_to)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$approved['subject'], $approved['description'], (int) $row['user_id'], (int) $actor['id'],
            ticketDefaultNewStatusSlug(), $approved['type_id'], $approved['priority_id'], $approved['location_id'], $groupId, $assignedTo]);
        $ticketId = (int) $db->lastInsertId();
        $detail = 'AI intake draft #' . $row['id'] . ' reviewed by staff user #' . $actor['id'];
        $db->prepare('INSERT INTO ticket_timeline (ticket_id, user_id, action, details, is_internal) VALUES (?, ?, ?, ?, 1)')
            ->execute([$ticketId, (int) $actor['id'], 'created', $detail]);
        foreach ($approved['tag_ids'] as $tagId) {
            $db->prepare('INSERT IGNORE INTO ticket_tag_map (ticket_id, tag_id) VALUES (?, ?)')->execute([$ticketId, $tagId]);
        }
        $emergency = ($analysis['is_potential_emergency'] ?? false) || $approved['priority_id'] === $priorityIds[2];
        $profile = $emergency ? 'critical'
            : ($approved['priority_id'] === $priorityIds[0] ? 'low'
                : (($analysis['sla_profile'] ?? '') === 'mass' ? 'mass' : 'medium'));
        if (!$emergency && ($analysis['sla_profile'] ?? '') === 'mass'
            && $profile === 'low' && mb_strlen(trim($manualReason)) < 20) {
            throw new InvalidArgumentException('Укажите причину снижения приоритета массового сбоя (от 20 символов).');
        }
        $sla = IntakeDispatcher::approvedSla(IntakeDispatcher::slaProfiles()[$profile], $input['sla'] ?? null, $emergency, $manualReason);
        Sla::initializeForIntake($db, $ticketId, $sla);
        $delta = [];
        foreach ($approved as $k => $v) if (($previous[$k] ?? null) !== $v) $delta[] = $k;
        $db->prepare('UPDATE ai_intake_drafts SET status = ?, confirmed_ticket_id = ?, confirmed_by = ?,
           edited_json = ?, revision = revision + 1, updated_at = NOW() WHERE id = ?')
           ->execute(['created', $ticketId, (int) $actor['id'], IntakeDispatcher::json($approved), (int) $row['id']]);
        $db->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, detail, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)')->execute([(int) $actor['id'], 'ai_intake_confirm', 'ticket', $ticketId,
            'draft_id=' . $row['id'] . '; changed_fields=' . implode(',', $delta) . '; sla_profile=' . $profile . '; sla_minutes=' . IntakeDispatcher::json($sla) . '; reason=' . mb_substr($manualReason, 0, 180),
            $_SERVER['REMOTE_ADDR'] ?? null]);
        $db->commit();
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) $db->rollBack();
        _intakeReply(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('intake confirm failed: ' . get_class($e));
        _intakeReply(['error' => 'Ticket confirmation failed; retry the same draft, do not create a new one'], 503);
    }
    // Only reached for the FIRST successful confirmation. The row lock above
    // prevents duplicate tickets, SLA initialisation, and notification attempts.
    try {
        // The confirmed engineer assignment is authoritative for an intake ticket.
        $last = $db->prepare('SELECT assigned_to FROM tickets WHERE id = ?');
        $last->execute([$ticketId]);
        $assignee = $last->fetchColumn();
        $assignee = $assignee !== false && $assignee !== null ? (int) $assignee : null;
        notifyRequesterTicketCreated($db, $ticketId);
        if ($assignee !== null) {
            notifyAssignedAgent($db, $ticketId, $assignee);
            notifyRequesterTicketAssigned($db, $ticketId, $assignee);
        }
        $db->prepare('UPDATE ai_intake_drafts SET postprocess_state = ? WHERE id = ?')->execute(['done', (int) $p['draft_id']]);
    } catch (Throwable $e) {
        error_log('intake ticket post-processing needs staff review: ticket #' . $ticketId);
        try {
            $db->prepare('UPDATE ai_intake_drafts SET postprocess_state = ? WHERE id = ?')->execute(['failed', (int) $p['draft_id']]);
        } catch (Throwable $ignored) { error_log('intake postprocess flag update failed'); }
    }
    _intakeReply(['status' => 'created', 'ticket_id' => $ticketId, 'requires_human_review' => false,
        'idempotent_replay' => false, 'engineer_assist' => '/api/v1/tickets/' . $ticketId . '/engineer-assist'], 201);
};
$router->post('/api/v1/intake/drafts/{draft_id}/confirm', $intakeConfirm);
$router->post('/intake/drafts/{draft_id}/confirm', $intakeConfirm);

/** The session UI uses the same shared controller and current logged-in user. */
$router->get('/intake', static function (): void {
    Auth::requireAuth();
    $db = Database::connect();
    $actor = Auth::user();
    $staff = roleIsStaff($actor['role'] ?? null);
    $visibility = $staff
        ? ticketStaffVisibilitySql($db, (int) $actor['id'], $actor['role'])
        : ['sql' => 't.created_by = ?', 'params' => [(int) $actor['id']]];
    if (Auth::isAdmin()) {
        $groupIds = userGroupIds($db, (int) $actor['id']);
        $groupFilter = $groupIds ? implode(',', array_fill(0, count($groupIds), '?')) : 'NULL';
        $visibility = [
            'sql' => "NOT EXISTS (
                SELECT 1 FROM ticket_types tt WHERE tt.id = t.type_id AND tt.is_confidential = 1
                AND tt.group_id IS NOT NULL AND (tt.group_id IN ($groupFilter)) IS NOT TRUE
            )",
            'params' => $groupIds,
        ];
    }
    $stmt = $db->prepare("SELECT t.id, t.subject, t.status, t.sla_state,
        COALESCE(t.due_date, t.resolution_due_at) AS due_date,
        CONCAT_WS(' ', u.first_name, u.last_name) AS assignee
        FROM tickets t LEFT JOIN users u ON u.id = t.assigned_to
        WHERE {$visibility['sql']} ORDER BY t.updated_at DESC, t.id DESC LIMIT 12");
    $stmt->execute($visibility['params']);
    $statusLabels = [
        'open' => 'Открыта', 'new' => 'Новая', 'in_progress' => 'В работе',
        'pending' => 'Ожидает ответа', 'resolved' => 'Решена', 'closed' => 'Закрыта',
        'on_hold' => 'Приостановлена', 'cancelled' => 'Отменена',
    ] + ticketStatusLabelMap();
    $ticketPrefix = Auth::isAdmin() ? '/admin/tickets/' : ($staff ? '/agent/tickets/' : '/portal/tickets/');
    header('Cache-Control: private, no-store');
    render('agent/ai-intake', [
        'tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'statusLabels' => $statusLabels,
        'ticketPrefix' => $ticketPrefix,
    ]);
});

$router->get('/intake/meta', static function (): void {
    $actor = _intakeActor();
    $db = Database::connect();
    $staff = IntakeDispatcher::canConfirm($actor);
    $types = array_values(array_filter(
        $db->query('SELECT id, name, restrict_create_to_group, group_id FROM ticket_types ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $x) => userCanCreateTicketType($db, (int) $x['id'], (int) $actor['id'])
    ));
    $groups = $staff ? $db->query('SELECT id, name FROM `groups` WHERE is_confidential = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : [];
    $agents = $staff ? $db->query('SELECT u.id, u.first_name, u.last_name, m.group_id
                  FROM users u JOIN group_user_map m ON m.user_id = u.id
                  WHERE ' . staffRoleSqlIn('u.role') . ' ORDER BY u.first_name, u.last_name')->fetchAll(PDO::FETCH_ASSOC) : [];
    _intakeReply(['can_confirm' => $staff, 'types' => array_map(static fn($x) => ['id' => $x['id'], 'name' => $x['name']], $types),
        'priorities' => array_values(array_filter(array_map(static fn($level, $name) =>
            ['id' => IntakeDispatcher::matchPriority($db, $level), 'name' => $name, 'level' => $level],
            ['low','medium','critical'], ['Низкий','Средний','Авария']), static fn($priority) => $priority['id'] !== null)),
        'locations' => $db->query('SELECT id, name, address FROM locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
        'tags' => $staff ? $db->query('SELECT id, name FROM ticket_tags ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : [],
        'groups' => $groups, 'agents' => $agents,
        'sla_profiles' => $staff ? IntakeDispatcher::slaProfiles() : [],
        'ticket_url_prefix' => roleIsAdmin($actor['role'] ?? null) ? '/admin/tickets/' : (roleIsStaff($actor['role'] ?? null) ? '/agent/tickets/' : '/portal/tickets/')]);
});

$router->post('/intake/resolve', static function (): void {
    $actor = _intakeActor();
    $input = _intakeBody();
    $draftId = $input['draft_id'] ?? null;
    $text = $input['text'] ?? null;
    if (!is_int($draftId) || !is_string($text) || mb_strlen(trim($text)) < 3 || mb_strlen($text) > 6000) {
        _intakeReply(['error' => 'Проверьте текст обращения'], 422);
    }
    _intakeFetch(Database::connect(), $draftId, $actor);
    $token = env('INTAKE_SERVICE_TOKEN', '');
    if ($token === '' || env('INTAKE_API_MODE', '') !== 'dispatcher') {
        _intakeReply(['error' => 'Поиск временно недоступен'], 503);
    }
    session_write_close();
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 105, 'follow_location' => 0,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\nConnection: close\r\n",
        'content' => IntakeDispatcher::json(['text' => $text]),
    ]]);
    $response = @file_get_contents(rtrim(env('DISPATCHER_INFERENCE_URL','http://127.0.0.1:8092'),'/').'/resolve', false, $context, 0, 65536);
    $result = $response === false ? null : json_decode($response, true);
    if (!is_array($result)) _intakeReply(['error' => 'Поиск временно недоступен'], 503);
    _intakeReply($result);
});
