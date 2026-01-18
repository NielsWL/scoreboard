<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = db();

$gameId = (int)($_GET['id'] ?? 0);
if ($gameId <= 0) {
    redirect('/index.html');
}

$stmt = $db->prepare('SELECT id, title, control_password_hash FROM games WHERE id = ?');
$stmt->execute([$gameId]);
$game = $stmt->fetch();
if (!$game) {
    redirect('/index.html');
}

if (has_admin()) {
    redirect('/admin/control.php?id=' . $gameId);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim((string)($_POST['password'] ?? ''));
    if ($password === '') {
        $error = 'Bitte Passwort eingeben.';
    } elseif (empty($game['control_password_hash'])) {
        $error = 'Für dieses Spiel ist kein Steuer-Passwort gesetzt.';
    } elseif (!password_verify($password, (string)$game['control_password_hash'])) {
        $error = 'Passwort ist falsch.';
    } else {
        grant_game_access($gameId);
        redirect('/admin/control.php?id=' . $gameId);
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Spiel steuern</title>
    <link rel="stylesheet" href="/public/assets/style.css">
</head>
<body>
    <header class="topbar">
        <h1><?= e($game['title']) ?></h1>
        <nav>
            <a href="/index.html">Start</a>
            <a href="/public/view.php?id=<?= (int)$gameId ?>" target="_blank">Anzeigen</a>
        </nav>
    </header>

    <main class="container narrow">
        <section class="card">
            <h2>Steuern freischalten</h2>
            <?php if ($error !== ''): ?>
                <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <label>
                    Steuer-Passwort
                    <input type="password" name="password" required>
                </label>
                <button type="submit">Weiter</button>
            </form>
        </section>
    </main>
</body>
</html>
