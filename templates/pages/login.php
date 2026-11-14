<?php
$layout = 'dispatcher';
$showNavigation = false;
?>
<main class="sign-in">
    <section class="panel">
        <h1>Вход</h1>
        <?php if (!empty($error)): ?>
            <p class="alert" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <?php if ($success = getFlash('success')): ?>
            <p role="status"><?= e($success) ?></p>
        <?php endif; ?>
        <form action="/login" method="post">
            <?= csrfField() ?>
            <label for="email">Электронная почта</label>
            <input id="email" type="email" name="email" value="<?= e($email ?? '') ?>" autocomplete="email" required>
            <label for="password">Пароль</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
            <div class="section-heading">
                <button class="primary" type="submit">Войти</button>
                <a href="/forgot">Забыли пароль?</a>
            </div>
        </form>
    </section>
</main>
