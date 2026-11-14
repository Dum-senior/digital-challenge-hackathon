<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $group = $db->query("SELECT m.group_id FROM group_user_map m
        JOIN users u ON u.id=m.user_id JOIN `groups` g ON g.id=m.group_id
        WHERE u.email='engineer@demo.test' AND g.is_confidential=0 ORDER BY m.group_id LIMIT 1")->fetchColumn();
    if (!$group) return;

    $roster = [
        ['Елена', 'Соколова', 'print', ['Принтер и печать', 'Рабочие станции']],
        ['Максим', 'Волков', 'network', ['Сеть и связь', 'Управление доступом', 'Аварийная координация']],
        ['Ирина', 'Крылова', 'telemetry', ['OPC и телеметрия', 'АСУ ТП']],
        ['Олег', 'Громов', 'security', ['Информационная безопасность', 'Управление доступом']],
        ['Анна', 'Морозова', 'database', ['Базы данных SQL', 'Сеть и связь']],
        ['Павел', 'Лебедев', 'reserve', ['Аварийная координация', 'Сеть и связь', 'Принтер и печать']],
    ];
    $user = $db->prepare("SELECT id FROM users WHERE email=? AND role='agent'");
    $insert = $db->prepare("INSERT IGNORE INTO users (first_name,last_name,email,password,role,is_available)
        VALUES (?,?,?,?,'agent',1)");
    $join = $db->prepare('INSERT IGNORE INTO group_user_map (group_id,user_id) VALUES (?,?)');
    $find = $db->prepare('SELECT MIN(id) FROM agent_skills WHERE group_id=? AND name=?');
    $skill = $db->prepare('INSERT INTO agent_skills (name,group_id) VALUES (?,?)');
    $map = $db->prepare('INSERT IGNORE INTO user_skill_map (user_id,skill_id,proficiency) VALUES (?,?,?)');
    $db->beginTransaction();
    try {
        foreach ($roster as [$first, $last, $login, $skills]) {
            $email = $login . '.engineer@demo.test';
            $insert->execute([$first, $last, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
            $user->execute([$email]);
            $id = (int)$user->fetchColumn();
            if (!$id) continue;
            $join->execute([$group, $id]);
            foreach ($skills as $name) {
                $find->execute([$group, $name]);
                $skillId = (int)$find->fetchColumn();
                if (!$skillId) {
                    $skill->execute([$name, $group]);
                    $skillId = (int)$db->lastInsertId();
                }
                $map->execute([$id, $skillId, $login === 'reserve' ? 2 : 3]);
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
};
