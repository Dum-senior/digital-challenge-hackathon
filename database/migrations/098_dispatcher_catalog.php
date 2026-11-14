<?php
return static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE locations ADD COLUMN IF NOT EXISTS place_type ENUM('ntp','building','office') NULL");
    $pdo->exec("ALTER TABLE user_skill_map ADD COLUMN IF NOT EXISTS proficiency TINYINT UNSIGNED NOT NULL DEFAULT 1");
    foreach ([['Низкий', '#198754', 10], ['Средний', '#a36000', 20], ['Авария', '#b42318', 30]] as [$name, $color, $order]) {
        $pdo->prepare('INSERT INTO ticket_priorities (name,color,sort_order)
            SELECT ?,?,? WHERE NOT EXISTS (SELECT 1 FROM ticket_priorities WHERE name=?)')
            ->execute([$name,$color,$order,$name]);
    }
    foreach (['Печать','Телеметрия','OPC','Сеть','ИБ','Доступ','База данных'] as $tag) {
        $pdo->prepare('INSERT IGNORE INTO ticket_tags (name) VALUES (?)')->execute([$tag]);
        $pdo->prepare('INSERT INTO ticket_types (name)
            SELECT ? WHERE NOT EXISTS (SELECT 1 FROM ticket_types WHERE name=?)')
            ->execute([$tag,$tag]);
    }
};
