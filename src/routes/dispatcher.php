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
function dispatcherDemoSelect(string $role): void
{
    $accounts = ['dispatcher'=>'dispatcher@demo.test','engineer'=>'engineer@demo.test','admin'=>'admin@demo.test'];
    if (!isset($accounts[$role])) _apiJson(['error'=>'Неизвестная роль'],422);
    $stmt=Database::connect()->prepare('SELECT id,first_name,last_name,email,role,avatar FROM users WHERE email=?');
    $stmt->execute([$accounts[$role]]);
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
    dispatcherDemoSelect($input['role']);
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
    $stmt = $db->prepare("SELECT t.id,t.subject,t.description,t.status,t.assigned_to AS assigneeId,
        CONCAT_WS(' ',u.first_name,u.last_name) AS assigneeName,t.created_at,t.first_response_due_at,
        t.first_responded_at,t.resolution_due_at,t.sla_state,t.sla_paused_at,t.sla_exempt,
        p.name AS priority,c.name AS category,l.name AS location
        FROM tickets t LEFT JOIN users u ON u.id=t.assigned_to LEFT JOIN ticket_types c ON c.id=t.type_id
        LEFT JOIN ticket_priorities p ON p.id=t.priority_id LEFT JOIN locations l ON l.id=t.location_id
        WHERE (c.id IS NULL OR c.is_confidential=0)" . ($engineer === null ? '' : ' AND t.assigned_to=?')
        . ($activeOnly ? ' AND t.assigned_to IS NOT NULL AND '.ticketStatusSqlIn(ticketClosedBucketSlugs(),'t.status',true) : '')
        . ' ORDER BY t.id DESC');
    $stmt->execute($engineer === null ? [] : [$engineer]);
    $zone = Sla::storageTimezone();
    return array_map(static function (array $t) use ($zone): array {
        $t['priority'] = ['Низкий'=>'low','Средний'=>'medium','Авария'=>'critical'][$t['priority']] ?? null;
        $t['subject']=html_entity_decode($t['subject'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t['description']=html_entity_decode($t['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t['createdAt'] = (new DateTimeImmutable($t['created_at'], $zone))->format(DATE_ATOM);
        $t['responseDueAt'] = $t['first_response_due_at'] ? (new DateTimeImmutable($t['first_response_due_at'], $zone))->format(DATE_ATOM) : null;
        $t['resolutionDueAt'] = $t['resolution_due_at'] ? (new DateTimeImmutable($t['resolution_due_at'], $zone))->format(DATE_ATOM) : null;
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
    $config=json_encode(['demoRoles'=>dispatcherDemoEnabled(),'mode'=>'api','apiBase'=>'/workspace/api','csrfToken'=>csrfToken(),
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
    // Match the SLA engine's 80% warning threshold for running clocks.
    $active=ticketStatusSqlIn(ticketClosedBucketSlugs(),'t.status',true);
    $response="(t.first_responded_at IS NULL AND t.first_response_due_at IS NOT NULL)";
    $baseline="COALESCE(t.sla_started_at,t.created_at)";
    $clock="CASE
        WHEN t.sla_exempt=1 OR NOT ($active) OR t.sla_paused_at IS NOT NULL THEN t.sla_state
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
