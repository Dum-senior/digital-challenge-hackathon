<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('ENGINEER_DEMO_SEED') !== 'yes') {
    exit("Set ENGINEER_DEMO_SEED=yes explicitly in the demo environment.\n");
}
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Database.php';
loadEnv(__DIR__ . '/../.env');
if (env('DB_NAME') !== 'engineer_test') {
    throw new RuntimeException('Demo seed requires engineer_test');
}
$db = Database::connect();
if ((int) $db->query("SELECT GET_LOCK('engineer-demo-seed', 10)")->fetchColumn() !== 1) {
    throw new RuntimeException('Could not lock demo seed');
}
try {
    $author = (int) $db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $closed = ticketClosedBucketSlugs()[0] ?? null;
    if (!$author || !$closed) {
        throw new RuntimeException('Initialize test users and statuses first');
    }
    $rows = file(__DIR__ . '/engineer-demo.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($rows) !== 20) {
        throw new RuntimeException('Expected 20 demo articles');
    }
    $db->beginTransaction();
    $db->exec("INSERT INTO kb_categories (name, slug) VALUES ('Учебная база инженера', 'engineer-demo')
        ON DUPLICATE KEY UPDATE id = id");
    $category = $db->query("SELECT id FROM kb_categories WHERE slug = 'engineer-demo'")->fetchColumn();
    $folder = $db->prepare('INSERT INTO kb_folders (category_id,name,slug) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=id');
    $findFolder = $db->prepare('SELECT id FROM kb_folders WHERE category_id=? AND slug=?');
    $article = $db->prepare("INSERT INTO kb_articles (folder_id,title,slug,body_markdown,status,published_at,created_by)
        VALUES (?,?,?,?,'published',NOW(),?) ON DUPLICATE KEY UPDATE id=id");
    $findTicket = $db->prepare('SELECT id FROM tickets WHERE subject=? AND created_by=?');
    $ticket = $db->prepare('INSERT INTO tickets (subject,description,created_by,status) VALUES (?,?,?,?)');
    $solution = $db->prepare("INSERT INTO ticket_timeline (ticket_id,user_id,action,details,is_internal) VALUES (?,?,'comment',?,1)");
    $mark = $db->prepare('UPDATE tickets SET solution_timeline_id=? WHERE id=?');
    foreach ($rows as $i => $row) {
        [$section,$title,$symptoms,$causes,$steps,$escalation,$keywords] = explode('|', $row);
        $slug = $section === 'ИТ' ? 'engineer-demo-it' : 'engineer-demo-ot';
        $folder->execute([$category,$section,$slug]);
        $findFolder->execute([$category,$slug]);
        $body = "Учебный пример. При работе действуйте по регламенту предприятия.\n\n"
            . "Симптомы: $symptoms\n\nВозможные причины: $causes\n\n- "
            . implode("\n- ", explode(';', $steps))
            . "\n\nЭскалация: $escalation\n\nКлючевые слова: $keywords";
        $article->execute([$findFolder->fetchColumn(),$title,'engineer-demo-' . ($i + 1),$body,$author]);
        if (!in_array($i, [0,2,5,6,10,11,13,17], true)) {
            continue;
        }
        $subject = '[Демо] ' . $title;
        $findTicket->execute([$subject,$author]);
        if ($findTicket->fetchColumn()) {
            continue;
        }
        $ticket->execute([$subject,$symptoms . ' ' . $keywords,$author,$closed]);
        $id = (int) $db->lastInsertId();
        $solution->execute([$id,$author,"Учебный случай: специалист проверил симптомы по инструкции «{$title}». Подтверждена причина: $causes После устранения ответственным специалистом восстановление проверено."]);
        $mark->execute([$db->lastInsertId(),$id]);
    }
    $db->commit();
    echo "Demo seed complete: 20 articles, 8 closed cases.\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $db->query("SELECT RELEASE_LOCK('engineer-demo-seed')");
}
