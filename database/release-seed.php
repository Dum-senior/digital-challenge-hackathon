<?php
declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR.'/src/helpers.php';
require ROOT_DIR.'/src/Database.php';
$db=Database::connect();
if ($db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn()) exit(0);

$roles=[
    ['admin','Администратор','admin','RELEASE_ADMIN_PASSWORD'],
    ['dispatcher','Диспетчер','dispatcher','RELEASE_DISPATCHER_PASSWORD'],
    ['agent','Инженер','engineer','RELEASE_ENGINEER_PASSWORD'],
];
foreach ($roles as $role) {
    if (strlen(env($role[3]))<12) throw new RuntimeException($role[3].' requires at least 12 characters');
}
$db->beginTransaction();
try {
    $db->exec("INSERT IGNORE INTO roles (slug,name,is_system,is_staff,landing,sort_order)
        VALUES ('dispatcher','Диспетчер',1,1,'agent',25)");
    $insert=$db->prepare('INSERT INTO users (first_name,last_name,email,password,role,is_available) VALUES (?,?,?,?,?,?)');
    $member=$db->prepare('INSERT INTO group_user_map (group_id,user_id) VALUES (?,?)');
    $group=$db->prepare('INSERT INTO `groups` (name,description) VALUES (?,?)');
    $group->execute(['Инженерная служба','OPC, телеметрия, принтер, печать, сеть, доступ, ИБ, база данных']);
    $groupId=(int)$db->lastInsertId();
    foreach ($roles as [$role,$name,$login,$variable]) {
        $insert->execute([$name,'Демо',$login.'@demo.test',password_hash(env($variable),PASSWORD_DEFAULT),$role,(int)($role==='agent')]);
        if ($role==='agent') {
            $engineerId=(int)$db->lastInsertId();
            $member->execute([$groupId,$engineerId]);
            foreach (['OPC и телеметрия','Принтер и печать','Сеть и доступ'] as $name) {
                $db->prepare('INSERT INTO agent_skills (name,group_id) VALUES (?,?)')->execute([$name,$groupId]);
                $skillId=(int)$db->lastInsertId();
                $db->prepare('INSERT INTO user_skill_map (user_id,skill_id,proficiency) VALUES (?,?,?)')->execute([$engineerId,$skillId,2]);
            }
        }
    }
    $location=$db->prepare('INSERT INTO locations (name,address,place_type) VALUES (?,?,?)');
    foreach ([['НТП №3','Демо-площадка Северная, узел 3','ntp'],['Корпус Б','Демо-площадка Северная, корпус Б','building'],
        ['Офис №2','Демо-площадка Центральная, офис 2','office']] as $place) $location->execute($place);
    $policy=$db->prepare('INSERT IGNORE INTO sla_policies (priority_id,first_response_minutes,resolution_minutes)
        SELECT id,?,? FROM ticket_priorities WHERE name=?');
    foreach ([[120,480,'Низкий'],[30,240,'Средний'],[10,60,'Авария']] as $row) $policy->execute($row);
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
