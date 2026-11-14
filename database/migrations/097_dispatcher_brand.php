<?php
return static function (PDO $pdo): void {
    $pdo->exec("UPDATE settings SET setting_value = 'Диспетчер ИИ'
        WHERE setting_key = 'branding_app_name' AND setting_value = 'OpenHelpDesk'");
};
