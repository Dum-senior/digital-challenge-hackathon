<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ИИ-диспетчерская</title>
    <link rel="stylesheet" href="/assets/css/dispatcher.css">
    <script src="/assets/js/intake-dispatcher.js" defer></script>
</head>
<body>
<header class="top">
    <nav aria-label="Основная навигация">
        <a class="brand" href="/intake"><span aria-hidden="true">◆</span> ИИ-диспетчерская</a>
        <?php if ($showNavigation ?? true): ?>
        <a href="#tickets">Заявки</a>
        <a href="#intakeQueue">Черновики</a>
        <a class="logout" href="/logout">Выйти</a>
        <?php endif; ?>
    </nav>
</header>
<?= $content ?>
</body>
</html>
