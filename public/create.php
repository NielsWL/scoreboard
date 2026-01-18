<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

$title = trim((string)($_GET['title'] ?? 'Spiel UBC vs Gast'));
$teamHome = trim((string)($_GET['team_home'] ?? 'UBC'));
$teamAway = trim((string)($_GET['team_away'] ?? 'Gast'));

if ($title === '') {
    $title = 'Spiel UBC vs Gast';
}
if ($teamHome === '') {
    $teamHome = 'UBC';
}
if ($teamAway === '') {
    $teamAway = 'Gast';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Spiel anlegen</title>
    <link rel="stylesheet" href="/public/assets/style.css">
</head>
<body>
    <header class="topbar">
        <h1>Spiel anlegen</h1>
        <nav>
            <a href="/index.html">Start</a>
            <a href="/admin/login.php">Admin</a>
        </nav>
    </header>

    <main class="container">
        <section class="card">
            <h2>Spieldaten &amp; Spieler</h2>
            <form id="create-game-form">
                <div class="grid">
                    <label>
                        Spieltitel
                        <input type="text" name="title" required maxlength="60" value="<?= e($title) ?>">
                    </label>
                    <label>
                        Heimteam
                        <input type="text" name="team_home" required maxlength="40" value="<?= e($teamHome) ?>">
                    </label>
                    <label>
                        Auswärtsteam
                        <input type="text" name="team_away" required maxlength="40" value="<?= e($teamAway) ?>">
                    </label>
                </div>

                <div class="teams-grid">
                    <div>
                        <h3>Heimspieler (12)</h3>
                        <?php for ($i = 0; $i < 12; $i++): ?>
                            <div class="player-row">
                                <input type="text" name="home_player_number[]" placeholder="#" maxlength="10">
                                <input type="text" name="home_player_name[]" required maxlength="40" value="<?= e($teamHome) ?> Name<?= $i + 1 ?>">
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div>
                        <h3>Auswärtsspieler (12)</h3>
                        <?php for ($i = 0; $i < 12; $i++): ?>
                            <div class="player-row">
                                <input type="text" name="away_player_number[]" placeholder="#" maxlength="10">
                                <input type="text" name="away_player_name[]" required maxlength="40" value="<?= e($teamAway) ?> Name<?= $i + 1 ?>">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <button type="submit">Spiel erstellen</button>
            </form>
            <div id="create-result" class="muted" style="margin-top: 1rem;"></div>
        </section>
    </main>

    <script>
        const formEl = document.getElementById('create-game-form');
        const resultEl = document.getElementById('create-result');

        formEl.addEventListener('submit', async (event) => {
            event.preventDefault();
            resultEl.textContent = 'Spiel wird angelegt...';
            try {
                const response = await fetch('/public/api.php?action=create', {
                    method: 'POST',
                    body: new FormData(formEl),
                });
                const payload = await response.json();
                if (!response.ok) {
                    resultEl.textContent = payload.error || 'Fehler beim Anlegen.';
                    return;
                }
                resultEl.innerHTML = `Steuer-Passwort: <strong>${payload.password}</strong> (Spiel #${payload.game_id})`;
            } catch (error) {
                resultEl.textContent = 'Fehler beim Anlegen.';
            }
        });
    </script>
</body>
</html>
