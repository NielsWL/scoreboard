<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

start_session();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = trim((string)($_POST['password'] ?? ''));
    $hash = config()['ADMIN_PASSWORD_HASH'] ?? '';

    if ($password !== '' && is_string($hash) && password_verify($password, $hash)) {
        $_SESSION['admin'] = true;
        redirect('/admin/dashboard.php');
    }

    $error = 'Ungültiges Passwort.';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Admin Login</title>
    <link rel="stylesheet" href="/public/assets/style.css">
</head>
<body>
    <main class="container narrow">
        <h1>Admin Login</h1>
        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <label>
                Passwort
                <input type="password" name="password" required>
            </label>
            <button type="submit">Einloggen</button>
        </form>
    </main>
</body>
</html>
