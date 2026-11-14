<?php
return static function (PDO $pdo): void {
    $pdo->exec("INSERT INTO ticket_types (name)
        SELECT 'Пожар' WHERE NOT EXISTS (SELECT 1 FROM ticket_types WHERE name = 'Пожар')");
};
