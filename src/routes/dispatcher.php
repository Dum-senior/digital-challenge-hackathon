<?php
declare(strict_types=1);

function dispatcherRole(array $user): string
{
    return roleIsAdmin($user['role']) ? 'admin' : ($user['role'] === 'dispatcher' ? 'dispatcher' : 'engineer');
}


/** Explicit demo deployment only: select existing seeded accounts, never arbitrary users. */
function dispatcherDemoEnabled(): bool
{
    return env('DISPATCHER_DEMO_ROLES') === '1';
}
function dispatcherDemoSelect(string $role, ?int $engineerId = null): void
{
    $accounts = ['dispatcher'=>'dispatcher@demo.test','engineer'=>'engineer@demo.test','admin'=>'admin@demo.test'];
    if (!isset($accounts[$role])) _apiJson(['error'=>'Неизвестная роль'],422);
    $db = Database::connect();
    if ($role === 'engineer' && $engineerId !== null) {
        $stmt = $db->prepare("SELECT id,first_name,last_name,email,role,avatar FROM users
            WHERE id=? AND role='agent' AND (email='engineer@demo.test' OR email LIKE '%.engineer@demo.test')");
        $stmt->execute([$engineerId]);
    } else {
        $stmt = $db->prepare('SELECT id,first_name,last_name,email,role,avatar FROM users WHERE email=?');
        $stmt->execute([$accounts[$role]]);
    }
    $user=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || dispatcherRole($user)!==$role) _apiJson(['error'=>'Демо-аккаунт не подготовлен'],503);
    session_regenerate_id(true);
    $_SESSION = ['user' => $user, 'dispatcher_demo_role' => $role];
    $_SESSION['user']['id']=(int)$user['id'];
}
$router->post('/workspace/api/demo-role', static function (): void {
    if (!dispatcherDemoEnabled()) _apiJson(['error'=>'Демо-режим выключен'],404);
    dispatcherActor(['dispatcher','engineer','admin']);
    $input=_intakeBody();
    if (!is_string($input['role']??null)) _apiJson(['error'=>'Укажите роль'],422);
    $engineerId = $input['engineer_id'] ?? null;
    if ($engineerId !== null && ($input['role'] !== 'engineer' || !is_int($engineerId) || $engineerId < 1)) {
        _apiJson(['error'=>'Некорректный демо-инженер'],422);
    }
    dispatcherDemoSelect($input['role'], $engineerId);
    _apiJson(['ok'=>true]);
});

/** Authorize the session and validate CSRF for every write. */
function dispatcherActor(array $roles): array
{
    Auth::requireStaff();
    $actor = Auth::user();
    if (!in_array(dispatcherRole($actor), $roles, true)) _apiJson(['error'=>'Недостаточно прав'], 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') requireJsonCsrf();
    return $actor;
}

function dispatcherTickets(PDO $db, ?int $engineer = null, bool $activeOnly = false): array
{
    $active = ticketStatusSqlIn(ticketClosedBucketSlugs(), 'work.status', true);
    $stmt = $db->prepare("SELECT t.id,t.subject,t.description,t.status,t.assigned_to AS assigneeId,
        CONCAT_WS(' ',u.first_name,u.last_name) AS assigneeName,t.created_at,t.first_response_due_at,
        t.first_responded_at,t.resolution_due_at,t.sla_started_at,t.sla_state,t.sla_paused_at,t.sla_exempt,
        p.name AS priority,c.name AS category,l.name AS location,
        COALESCE(q.wait_minutes,0) AS engineer_queue_minutes,
        CASE WHEN u.is_available=1 AND COALESCE(o.is_oof,0)=0 THEN 1 ELSE 0 END AS engineer_available
        FROM tickets t LEFT JOIN users u ON u.id=t.assigned_to LEFT JOIN ticket_types c ON c.id=t.type_id
        LEFT JOIN ticket_priorities p ON p.id=t.priority_id LEFT JOIN locations l ON l.id=t.location_id
        LEFT JOIN agent_oof_status o ON o.user_id=u.id
        LEFT JOIN (
            SELECT work.assigned_to, SUM(CASE WHEN work.status='in_progress' THEN 20 ELSE 10 END) AS wait_minutes
            FROM tickets work WHERE $active GROUP BY work.assigned_to
        ) q ON q.assigned_to=u.id
        WHERE (c.id IS NULL OR c.is_confidential=0)" . ($engineer === null ? '' : ' AND t.assigned_to=?')
        . ($activeOnly ? ' AND t.assigned_to IS NOT NULL AND '.ticketStatusSqlIn(ticketClosedBucketSlugs(),'t.status',true) : '')
        . ' ORDER BY t.id DESC');
    $stmt->execute($engineer === null ? [] : [$engineer]);
    $zone = Sla::storageTimezone();
    $closed = ticketClosedBucketSlugs();
    return array_map(static function (array $t) use ($zone, $closed): array {
        $t['priority'] = ['Низкий'=>'low','Средний'=>'medium','Авария'=>'critical'][$t['priority']] ?? null;
        $t['subject']=html_entity_decode($t['subject'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t['description']=html_entity_decode($t['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t['createdAt'] = (new DateTimeImmutable($t['created_at'], $zone))->format(DATE_ATOM);
        $t['responseDueAt'] = $t['first_response_due_at'] ? (new DateTimeImmutable($t['first_response_due_at'], $zone))->format(DATE_ATOM) : null;
        $t['resolutionDueAt'] = $t['resolution_due_at'] ? (new DateTimeImmutable($t['resolution_due_at'], $zone))->format(DATE_ATOM) : null;
        $t['slaStartedAt'] = (new DateTimeImmutable($t['sla_started_at'] ?: $t['created_at'], $zone))->format(DATE_ATOM);
        $t['slaPausedAt'] = $t['sla_paused_at'] ? (new DateTimeImmutable($t['sla_paused_at'], $zone))->format(DATE_ATOM) : null;
        $t['isClosed'] = in_array($t['status'], $closed, true);
        $t['engineerQueueMinutes'] = max(0, (int)$t['engineer_queue_minutes']
            - ($t['isClosed'] ? 0 : ($t['status'] === 'in_progress' ? 20 : 10)));
        $t['engineerAvailable'] = (bool)$t['engineer_available'];
        unset($t['engineer_queue_minutes'], $t['engineer_available']);
        $t['notes'] = [];
        return $t;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function dispatcherEngineers(PDO $db): array
{
    $sql = "SELECT u.id, CONCAT_WS(' ',u.first_name,u.last_name) AS name,
        MIN(g.name) AS department, GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR '\n') AS skills
        FROM users u LEFT JOIN group_user_map m ON m.user_id=u.id
        LEFT JOIN `groups` g ON g.id=m.group_id AND g.is_confidential=0
        LEFT JOIN user_skill_map us ON us.user_id=u.id LEFT JOIN agent_skills s ON s.id=us.skill_id
        WHERE u.role='agent' GROUP BY u.id,u.first_name,u.last_name ORDER BY name";
    return array_map(static function (array $row): array {
        $row['role']='engineer';
        $row['competencies']=$row['skills'] ? explode("\n",$row['skills']) : [];
        unset($row['skills']);
        return $row;
    }, $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
}

$router->get('/workspace', static function (): void {
    if (dispatcherDemoEnabled() && (!Auth::check() || !isset($_SESSION['dispatcher_demo_role']))) dispatcherDemoSelect('dispatcher');
    Auth::requireStaff();
    header('Cache-Control: private, no-store');
    $actor=Auth::user();
    $page=file_get_contents(ROOT_DIR . '/templates/dispatcher.html');
    $demoEngineers = dispatcherDemoEnabled() && dispatcherRole($actor)==='engineer'
        ? Database::connect()->query("SELECT id,CONCAT_WS(' ',first_name,last_name) AS name FROM users
            WHERE role='agent' AND (email='engineer@demo.test' OR email LIKE '%.engineer@demo.test') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)
        : [];
    $config=json_encode(['demoRoles'=>dispatcherDemoEnabled(),'demoEngineers'=>$demoEngineers,'mode'=>'api','apiBase'=>'/workspace/api','csrfToken'=>csrfToken(),
        'currentUser'=>['id'=>(int)$actor['id'],'role'=>dispatcherRole($actor),
            'name'=>trim($actor['first_name'].' '.$actor['last_name'])]], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    echo str_replace('<!--CONFIG-->', '<script>window.DISPATCH_CONFIG='.$config.';</script>', $page);
});

$router->get('/workspace/api/tickets', static function (): void {
    $actor=dispatcherActor(['dispatcher','engineer']);
    _apiJson(dispatcherTickets(Database::connect(), dispatcherRole($actor)==='engineer' ? (int)$actor['id'] : null));
});
$router->get('/workspace/api/users', static function (): void {
    dispatcherActor(['dispatcher']);
    _apiJson(dispatcherEngineers(Database::connect()));
});
$router->get('/workspace/api/engineers', static function (): void {
    dispatcherActor(['admin']);
    $db=Database::connect();
    $tickets=dispatcherTickets($db, activeOnly: true);
    $engineers=dispatcherEngineers($db);
    $byEngineer=[];
    foreach ($tickets as $ticket) {
        $byEngineer[$ticket['assigneeId']][]=$ticket;
    }
    foreach ($engineers as &$e) $e['tickets']=$byEngineer[$e['id']]??[];
    unset($e);
    _apiJson(['engineers'=>$engineers]);
});

$router->get('/workspace/api/tickets/{id}/assignment', static function (array $p): void {
    dispatcherActor(['dispatcher']);
    $db = Database::connect();
    $stmt = $db->prepare('SELECT t.*, c.name AS category FROM tickets t
        LEFT JOIN ticket_types c ON c.id=t.type_id WHERE t.id=? AND COALESCE(c.is_confidential,0)=0');
    $stmt->execute([(int)$p['id']]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) _apiJson(['error'=>'Заявка не найдена'],404);
    if (in_array($ticket['status'], ticketClosedBucketSlugs(), true)) _apiJson(['error'=>'Заявка завершена'],409);

    $due = empty($ticket['first_responded_at']) && $ticket['first_response_due_at']
        ? $ticket['first_response_due_at'] : $ticket['resolution_due_at'];
    $minutes = null;
    if ($due && !$ticket['sla_exempt'] && !$ticket['sla_paused_at']) {
        $seconds = (new DateTimeImmutable($due, Sla::storageTimezone()))->getTimestamp() - time();
        $minutes = max(0, (int)ceil($seconds / 60));
    }
    $options = IntakeDispatcher::assignmentOptions($db,
        IntakeDispatcher::categoryForType($ticket['category']), $minutes, (int)$ticket['id']);
    $current = null;
    $alternative = null;
    foreach ($options as $option) {
        if ($option['id'] === (int)$ticket['assigned_to'] && $option['group_id'] === (int)$ticket['group_id']) {
            $current = $option;
        } elseif ($alternative === null && $option['id'] !== (int)$ticket['assigned_to']
            && $option['available'] && $option['match'] > 0 && $option['fits_sla'] !== false) {
            $alternative = $option;
        }
    }
    $risk = $current === null || !$current['available'] || $current['fits_sla'] === false;
    $warning = $risk ? ($alternative
        ? 'Рекомендуем передать заявку: ' . $alternative['name'] . '. Подходящий навык и меньшая очередь.'
        : 'Срок под угрозой или исполнитель недоступен. Подходящего свободного резерва нет — проверьте смену.')
        : null;
    _apiJson(['options'=>$options,'current'=>$current,'alternative'=>$risk ? $alternative : null,
        'warning'=>$warning,'minutes_left'=>$minutes,'assigned_to'=>$ticket['assigned_to'] === null ? null : (int)$ticket['assigned_to']]);
});

$router->post('/workspace/api/tickets/{id}/assignment', static function (array $p): void {
    $actor = dispatcherActor(['dispatcher']);
    $input = _intakeBody();
    $id = $input['assignee_id'] ?? null;
    $group = $input['group_id'] ?? null;
    $previous = $input['previous_assignee_id'] ?? null;
    $reason = $input['reason'] ?? null;
    if (!is_int($id) || $id < 1 || !is_int($group) || $group < 1
        || ($previous !== null && !is_int($previous)) || !array_key_exists('previous_assignee_id', $input)
        || !is_string($reason) || mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 250) {
        _apiJson(['error'=>'Выберите инженера и укажите причину переназначения'],422);
    }
    $db = Database::connect();
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT t.id,t.status,t.assigned_to,t.group_id FROM tickets t
            LEFT JOIN ticket_types c ON c.id=t.type_id WHERE t.id=? AND COALESCE(c.is_confidential,0)=0 FOR UPDATE');
        $lock->execute([(int)$p['id']]);
        $ticket = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) { $db->rollBack(); _apiJson(['error'=>'Заявка не найдена'],404); }
        $assigned = $ticket['assigned_to'] === null ? null : (int)$ticket['assigned_to'];
        if ($assigned !== $previous || in_array($ticket['status'], ticketClosedBucketSlugs(), true)) {
            $db->rollBack();
            _apiJson(['error'=>'Заявка изменилась. Обновите список исполнителей.'],409);
        }
        $check = $db->prepare("SELECT u.id FROM users u JOIN group_user_map m ON m.user_id=u.id
            JOIN `groups` g ON g.id=m.group_id AND g.is_confidential=0
            LEFT JOIN agent_oof_status o ON o.user_id=u.id
            WHERE u.id=? AND m.group_id=? AND u.role='agent' AND u.is_available=1 AND COALESCE(o.is_oof,0)=0");
        $check->execute([$id,$group]);
        if (!$check->fetchColumn() || $id === $assigned) {
            $db->rollBack();
            _apiJson(['error'=>'Выберите другого доступного инженера'],422);
        }
        $db->prepare('UPDATE tickets SET assigned_to=?, group_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$id,$group,$ticket['id']]);
        $detail = sprintf('Исполнитель %s → %d. %s', $assigned ?? 'не назначен', $id, trim($reason));
        $db->prepare("INSERT INTO audit_log (user_id,action,target_type,target_id,detail) VALUES (?,?,'ticket',?,?)")
            ->execute([$actor['id'],'workspace.reassign',$ticket['id'],$detail]);
        $db->prepare('INSERT INTO ticket_timeline (ticket_id,user_id,action,details,is_internal) VALUES (?,?,?,?,1)')
            ->execute([$ticket['id'],$actor['id'],'assigned',$detail]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    try {
        notifyAssignedAgent($db, (int)$ticket['id'], $id);
    } catch (Throwable $e) {
        error_log('Workspace reassignment notification failed: ' . get_class($e));
    }
    _apiJson(['ok'=>true]);
});

/** Lock the ticket so concurrent requests cannot skip or repeat a workflow transition. */
$router->post('/workspace/api/tickets/{id}/update', static function (array $p): void {
    $actor=dispatcherActor(['engineer','dispatcher']);
    $input=_intakeBody();
    $db=Database::connect();
    $db->beginTransaction();
    try {
        $stmt=$db->prepare('SELECT t.* FROM tickets t LEFT JOIN ticket_types c ON c.id=t.type_id
            WHERE t.id=? AND (c.id IS NULL OR c.is_confidential=0) FOR UPDATE');
        $stmt->execute([(int)$p['id']]);
        $ticket=$stmt->fetch(PDO::FETCH_ASSOC);
        $engineer=dispatcherRole($actor)==='engineer';
        if (!$ticket || ($engineer && (int)$ticket['assigned_to']!==(int)$actor['id'])) {
            $db->rollBack(); _apiJson(['error'=>'Заявка не найдена'],404);
        }
        if ($engineer) {
            $next=match($ticket['status']) { 'new','open'=>'in_progress', 'in_progress'=>ticketDefaultResolvedStatusSlug(), default=>null };
            if ($next===null || $input!==['status'=>$next]) {
                $db->rollBack(); _apiJson(['error'=>'Недопустимый переход статуса'],409);
            }
            $db->prepare("UPDATE tickets SET status=?,first_responded_at=COALESCE(first_responded_at,UTC_TIMESTAMP()),
                updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$next,$ticket['id']]);
            if ($next !== 'in_progress') Sla::markClosed($db, (int)$ticket['id']);
            else {
                $ticket['first_responded_at'] ??= gmdate('Y-m-d H:i:s');
                $db->prepare('UPDATE tickets SET sla_state=? WHERE id=?')->execute([Sla::computeSlaState($ticket),$ticket['id']]);
            }
        } else {
            $subject=$input['subject']??null;
            $description=$input['description']??null;
            if (!is_string($subject)||!is_string($description)||trim($subject)===''||trim($description)===''||
                mb_strlen($subject)>255||mb_strlen($description)>10000) {
                $db->rollBack(); _apiJson(['error'=>'Проверьте тему и описание'],422);
            }
            $db->prepare('UPDATE tickets SET subject=?,description=? WHERE id=?')
                ->execute([htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),$ticket['id']]);
        }
        $db->prepare('INSERT INTO audit_log (user_id,action,target_type,target_id,detail) VALUES (?,?,\'ticket\',?,?)')
            ->execute([$actor['id'],'workspace.ticket_update',$ticket['id'],$next??'Описание обновлено']);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    _apiJson(['ok'=>true]);
});

$router->get('/workspace/api/reports', static function (): void {
    dispatcherActor(['admin']);
    $db=Database::connect();
    $where=['(c.id IS NULL OR c.is_confidential=0)']; $params=[];
    foreach (['from'=>'>=', 'to'=>'<'] as $key=>$op) {
        $day=requestScalar($key);
        if ($day==='') continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day) || !checkdate((int)substr($day,5,2),(int)substr($day,8,2),(int)substr($day,0,4))) {
            _apiJson(['error'=>'Некорректная дата'],422);
        }
        $where[]="t.created_at $op ?";
        $params[]=$key==='to' ? (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d') : $day;
    }
    if (count($params) === 2 && $params[0] >= $params[1]) {
        _apiJson(['error'=>'Начальная дата позже конечной'],422);
    }
    // Match the SLA engine's 80% warning threshold for running clocks.
    $active=ticketStatusSqlIn(ticketClosedBucketSlugs(),'t.status',true);
    $response="(t.first_responded_at IS NULL AND t.first_response_due_at IS NOT NULL)";
    $baseline="COALESCE(t.sla_started_at,t.created_at)";
    $clock="CASE
        WHEN t.sla_exempt=1 THEN NULL
        WHEN (t.first_response_due_at IS NOT NULL AND t.first_responded_at>t.first_response_due_at)
            OR (t.resolution_due_at IS NOT NULL AND t.sla_closed_at>t.resolution_due_at) THEN 'breached'
        WHEN NOT ($active) OR t.sla_paused_at IS NOT NULL THEN t.sla_state
        WHEN ($response AND UTC_TIMESTAMP()>t.first_response_due_at) OR UTC_TIMESTAMP()>t.resolution_due_at THEN 'breached'
        WHEN ($response AND TIMESTAMPDIFF(SECOND,$baseline,t.first_response_due_at)>0
            AND TIMESTAMPDIFF(SECOND,$baseline,UTC_TIMESTAMP())>=0.8*TIMESTAMPDIFF(SECOND,$baseline,t.first_response_due_at))
            OR (TIMESTAMPDIFF(SECOND,$baseline,t.resolution_due_at)>0
            AND TIMESTAMPDIFF(SECOND,$baseline,UTC_TIMESTAMP())>=0.8*TIMESTAMPDIFF(SECOND,$baseline,t.resolution_due_at)) THEN 'warning'
        ELSE t.sla_state END";
    $stmt=$db->prepare("SELECT t.status,p.name AS priority,COUNT(*) AS total,
        SUM(($clock)='warning') AS atRisk,SUM(($clock)='breached') AS breached
        FROM tickets t LEFT JOIN ticket_types c ON c.id=t.type_id LEFT JOIN ticket_priorities p ON p.id=t.priority_id
        WHERE ".implode(' AND ',$where).' GROUP BY t.status,p.name');
    $stmt->execute($params);
    $closed=ticketClosedBucketSlugs();
    $out=['total'=>0,'open'=>0,'resolved'=>0,'critical'=>0,'byPriority'=>[],'byStatus'=>[],'sla'=>['atRisk'=>0,'breached'=>0]];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $count=(int)$row['total'];
        $out['total']+=$count;
        $out[in_array($row['status'],$closed,true)?'resolved':'open']+=$count;
        $out['byStatus'][$row['status']]=($out['byStatus'][$row['status']]??0)+$count;
        $priority=['Низкий'=>'low','Средний'=>'medium','Авария'=>'critical'][$row['priority']]??null;
        if ($priority) $out['byPriority'][$priority]=($out['byPriority'][$priority]??0)+$count;
        if ($priority==='critical') $out['critical']+=$count;
        foreach (['atRisk','breached'] as $key) $out['sla'][$key]+=(int)$row[$key];
    }
    $from = ' FROM tickets t LEFT JOIN ticket_types c ON c.id=t.type_id
        LEFT JOIN users u ON u.id=t.assigned_to LEFT JOIN locations l ON l.id=t.location_id
        WHERE ' . implode(' AND ', $where);
    $closedSql = ticketStatusSqlIn($closed, 't.status');
    $responseMinutes = "TIMESTAMPDIFF(SECOND,$baseline,t.first_responded_at)/60";
    $resolutionMinutes = "CASE WHEN $closedSql THEN TIMESTAMPDIFF(SECOND,$baseline,t.sla_closed_at)/60 END";
    foreach ([
        'byCategory'=>"COALESCE(c.name,'Без категории')",
        'byLocation'=>"COALESCE(l.name,'Место не указано')",
        'byEngineer'=>"COALESCE(NULLIF(CONCAT_WS(' ',u.first_name,u.last_name),''),'Не назначен')",
    ] as $key=>$label) {
        $grouping = $key === 'byEngineer' ? "t.assigned_to, $label" : $label;
        $group = $db->prepare("SELECT $label AS name, COUNT(*) AS total, SUM($closedSql) AS resolved,
            SUM($active) AS open, SUM(($clock)='breached') AS breached $from
            GROUP BY $grouping ORDER BY total DESC,name");
        $group->execute($params);
        $out[$key] = $group->fetchAll(PDO::FETCH_ASSOC);
    }
    $timing = $db->prepare("SELECT AVG($responseMinutes) AS response_minutes, COUNT($responseMinutes) AS response_count,
        AVG($resolutionMinutes) AS resolution_minutes, COUNT($resolutionMinutes) AS resolution_count $from");
    $timing->execute($params);
    $out['timing'] = $timing->fetch(PDO::FETCH_ASSOC);
    $recent = $db->prepare("SELECT t.id,t.subject,t.status,t.created_at,$closedSql AS resolved,
        COALESCE(c.name,'Без категории') AS category,l.name AS location,
        NULLIF(CONCAT_WS(' ',u.first_name,u.last_name),'') AS engineer,($clock) AS sla $from ORDER BY t.id DESC LIMIT 100");
    $recent->execute($params);
    $out['incidents'] = array_map(static function (array $row): array {
        $row['subject'] = html_entity_decode($row['subject'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $row['createdAt'] = (new DateTimeImmutable($row['created_at'], Sla::storageTimezone()))->format(DATE_ATOM);
        return $row;
    }, $recent->fetchAll(PDO::FETCH_ASSOC));
    _apiJson($out);
});

$router->get('/workspace/api/audit', static function (): void {
    dispatcherActor(['admin']);
    _apiJson(Database::connect()->query("SELECT a.created_at AS `when`, CONCAT_WS(' ',u.first_name,u.last_name) AS actor,
        a.action, CONCAT('#',a.target_id) AS target FROM audit_log a LEFT JOIN users u ON u.id=a.user_id
        ORDER BY a.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC));
});

/** Forward only known inference operations to the private container. */
function dispatcherInference(string $operation, array $payload): array
{
    $token=env('INTAKE_SERVICE_TOKEN','');
    $url=rtrim(env('DISPATCHER_INFERENCE_URL','http://127.0.0.1:8092'),'/');
    $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>105,'follow_location'=>0,
        'header'=>"Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'content'=>IntakeDispatcher::json($payload)]]);
    $response=@file_get_contents($url.'/'.$operation,false,$context,0,65536);
    $result=$response===false ? null : json_decode($response,true);
    if (!is_array($result)) _apiJson(['error'=>'ИИ-сервис временно недоступен'],503);
    return $result;
}

$router->post('/workspace/api/tickets/{id}/assist', static function (array $p): void {
    $actor=dispatcherActor(['engineer','dispatcher']);
    $stmt=Database::connect()->prepare('SELECT COALESCE(d.raw_text,t.description) AS description,t.assigned_to FROM tickets t
        LEFT JOIN ai_intake_drafts d ON d.confirmed_ticket_id=t.id LEFT JOIN ticket_types c
        ON c.id=t.type_id WHERE t.id=? AND (c.id IS NULL OR c.is_confidential=0)');
    $stmt->execute([(int)$p['id']]);
    $t=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t || (dispatcherRole($actor)==='engineer' && (int)$t['assigned_to']!==(int)$actor['id'])) _apiJson(['error'=>'Заявка не найдена'],404);
    dispatcherResolve(html_entity_decode(strip_tags($t['description'])));
});

$router->post('/workspace/api/drafts/{id}/assist', static function (array $p): void {
    $actor=dispatcherActor(['dispatcher']);
    $draft=_intakeFetch(Database::connect(), (int)$p['id'], $actor);
    dispatcherResolve($draft['raw_text']);
});

function dispatcherResolve(string $text): never
{
    session_write_close();
    $result=dispatcherInference('resolve',['text'=>mb_substr($text,0,6000)]);
    unset($result['sources']);
    _apiJson($result);
}

/** Serve the same Markdown sections used to build the embedding index. */
function dispatcherArticles(): array
{
    $articles=[];
    foreach (glob(ROOT_DIR.'/services/dispatcher-api/kb/*.md') as $file) {
        $sections=preg_split('/^## /m',file_get_contents($file));
        $category=trim(ltrim(array_shift($sections),'# '));
        foreach ($sections as $i=>$section) {
            [$name,$content]=explode("\n",$section,2);
            $id=basename($file,'.md').'-'.($i+1);
            $articles[$id]=['id'=>$id,'title'=>trim($name),'category'=>$category,'content'=>trim($content)];
        }
    }
    return $articles;
}
$router->get('/workspace/api/articles', static function (): void {
    dispatcherActor(['admin']);
    _apiJson(array_values(dispatcherArticles()));
});
$router->get('/workspace/api/articles/{id}', static function (array $p): void {
    dispatcherActor(['admin','dispatcher','engineer']);
    $article=dispatcherArticles()[$p['id']]??null;
    if (!$article) _apiJson(['error'=>'Источник не найден'],404);
    $article['content_html']=renderMarkdown($article['content']);
    _apiJson($article);
});

$router->post('/workspace/api/ocr', static function (): void {
    dispatcherActor(['dispatcher']);
    $file=$_FILES['file']??[];
    if (($file['error']??null)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']??'') || $file['size']>5*1024*1024) {
        _apiJson(['error'=>'Нужен PNG, JPEG или WebP до 5 МиБ'],422);
    }
    session_write_close();
    $url=rtrim(env('DISPATCHER_INFERENCE_URL','http://127.0.0.1:8092'),'/');
    $request=curl_init($url.'/ocr');
    curl_setopt_array($request,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.env('INTAKE_SERVICE_TOKEN')],
        CURLOPT_POSTFIELDS=>['file'=>new CURLFile($file['tmp_name'],'application/octet-stream','image')]]);
    $body=curl_exec($request);
    $status=curl_getinfo($request,CURLINFO_RESPONSE_CODE);
    curl_close($request);
    $result=is_string($body)?json_decode($body,true):null;
    if (!is_array($result)) _apiJson(['error'=>'OCR временно недоступен'],503);
    _apiJson($result,$status);
});
