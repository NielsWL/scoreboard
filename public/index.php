<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = db();
$games = $db->query("SELECT * FROM games WHERE status = 'active' ORDER BY created_at DESC")->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Aktive Spiele</title>
    <link rel="stylesheet" href="/public/assets/style.css">
</head>
<body>
    <header class="topbar">
        <h1>Aktive Spiele</h1>
        <div class="topbar-logo">
            <img src="/public/logo/Logo_UBC_230px.png" alt="UBC Logo">
        </div>
        <nav>
            <a href="/index.html">Start</a>
            <a href="/admin/login.php">Admin</a>
        </nav>
    </header>

    <main class="container">
        <?php if (empty($games)): ?>
            <p>Keine aktiven Spiele.</p>
        <?php else: ?>
            <div class="game-list">
                <?php foreach ($games as $game): ?>
                    <div class="card">
                        <h2><?= e($game['title']) ?></h2>
                        <p><?= e($game['team_home']) ?> vs <?= e($game['team_away']) ?></p>
                        <p><?= (int)$game['home_score'] ?> : <?= (int)$game['away_score'] ?></p>
                        <div class="actions">
                            <a class="button secondary" href="/public/view.php?id=<?= (int)$game['id'] ?>" target="_blank">Anzeigen</a>
                            <form method="post" action="/public/control.php?id=<?= (int)$game['id'] ?>" class="inline">
                                <input type="password" name="password" placeholder="Steuer-Passwort" required>
                                <button type="submit">Steuern</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
