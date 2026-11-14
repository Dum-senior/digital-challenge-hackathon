<?php
return static function (PDO $pdo): void {
    $pdo->exec("INSERT INTO ticket_types (name)
        SELECT 'Рабочее место' WHERE NOT EXISTS (SELECT 1 FROM ticket_types WHERE name = 'Рабочее место')");
};
