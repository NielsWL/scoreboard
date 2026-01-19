<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();
start_session();

$db = db();
$config = config();
$defaultDate = date('Y-m-d');
$defaultTime = date('H:i');

$error = '';
$createdPassword = $_SESSION['last_game_password'] ?? null;
unset($_SESSION['last_game_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_game') {
        $title = trim((string)($_POST['title'] ?? ''));
        $teamHome = trim((string)($_POST['team_home'] ?? ''));
        $teamAway = trim((string)($_POST['team_away'] ?? ''));
        $gameDate = trim((string)($_POST['game_date'] ?? ''));
        $gameTime = trim((string)($_POST['game_time'] ?? ''));
        $homeNames = $_POST['home_player_name'] ?? [];
        $awayNames = $_POST['away_player_name'] ?? [];
        $homeNumbers = $_POST['home_player_number'] ?? [];
        $awayNumbers = $_POST['away_player_number'] ?? [];

        $errors = [];
        if ($title === '' || mb_strlen($title) > 60) {
            $errors[] = 'Titel ist erforderlich (max 60 Zeichen).';
        }
        if ($teamHome === '' || mb_strlen($teamHome) > 40) {
            $errors[] = 'Heimteam ist erforderlich (max 40 Zeichen).';
        }
        if ($teamAway === '' || mb_strlen($teamAway) > 40) {
            $errors[] = 'Auswärtsteam ist erforderlich (max 40 Zeichen).';
        }
        if ($gameDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gameDate)) {
            $errors[] = 'Datum ist erforderlich (Format YYYY-MM-DD).';
        }
        if ($gameTime === '' || !preg_match('/^\d{2}:\d{2}$/', $gameTime)) {
            $errors[] = 'Uhrzeit ist erforderlich (Format HH:MM).';
        }

        $players = [];
        for ($i = 0; $i < 12; $i++) {
            $homeName = trim((string)($homeNames[$i] ?? ''));
            $awayName = trim((string)($awayNames[$i] ?? ''));
            $homeNumber = trim((string)($homeNumbers[$i] ?? ''));
            $awayNumber = trim((string)($awayNumbers[$i] ?? ''));

            $requiresName = $i < 5;
            if ($requiresName && $homeName === '') {
                $errors[] = 'Die ersten fünf Heimspieler benötigen einen Namen (max 40 Zeichen).';
                break;
            }
            if ($homeName !== '' && mb_strlen($homeName) > 40) {
                $errors[] = 'Heimspielernamen dürfen max 40 Zeichen haben.';
                break;
            }
            if ($requiresName && $awayName === '') {
                $errors[] = 'Die ersten fünf Auswärtsspieler benötigen einen Namen (max 40 Zeichen).';
                break;
            }
            if ($awayName !== '' && mb_strlen($awayName) > 40) {
                $errors[] = 'Auswärtsspielernamen dürfen max 40 Zeichen haben.';
                break;
            }
            if ($homeNumber !== '' && mb_strlen($homeNumber) > 10) {
                $errors[] = 'Heimnummern dürfen max 10 Zeichen haben.';
                break;
            }
            if ($awayNumber !== '' && mb_strlen($awayNumber) > 10) {
                $errors[] = 'Auswärtsnummern dürfen max 10 Zeichen haben.';
                break;
            }

            $players[] = ['team' => 'home', 'name' => $homeName, 'number' => $homeNumber];
            $players[] = ['team' => 'away', 'name' => $awayName, 'number' => $awayNumber];
        }

        if (empty($errors)) {
            $password = generate_game_password(10);
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO games (title, team_home, team_away, clock_seconds, control_password_hash, control_password_plain, team_fouls_home, team_fouls_away, timeouts_home, timeouts_away, game_date, game_time, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?, ?)');
            $now = now_iso();
            $stmt->execute([$title, $teamHome, $teamAway, (int)$config['QUARTER_SECONDS'], $passwordHash, $password, $gameDate, $gameTime, $now, $now]);
            $gameId = (int)$db->lastInsertId();

            $playerStmt = $db->prepare('INSERT INTO players (game_id, team, number, name, fouls, active) VALUES (?, ?, ?, ?, 0, 1)');
            foreach ($players as $player) {
                $playerStmt->execute([$gameId, $player['team'], $player['number'] !== '' ? $player['number'] : null, $player['name']]);
            }
            $db->commit();
            $_SESSION['last_game_password'] = ['id' => $gameId, 'password' => $password];
            redirect('/admin/dashboard.php');
        }

        $error = implode(' ', $errors);
    }

    if ($action === 'reset_game') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        if ($gameId > 0) {
            $stmt = $db->prepare('UPDATE games SET home_score = 0, away_score = 0, period = 1, clock_seconds = ?, quarter_history = "[]", team_fouls_home = 0, team_fouls_away = 0, timeouts_home = 0, timeouts_away = 0, updated_at = ? WHERE id = ?');
            $stmt->execute([(int)$config['QUARTER_SECONDS'], now_iso(), $gameId]);
            $stmt = $db->prepare('UPDATE players SET fouls = 0 WHERE game_id = ?');
            $stmt->execute([$gameId]);
            redirect('/admin/dashboard.php');
        }
    }

    if ($action === 'delete_game') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        if ($gameId > 0) {
            $stmt = $db->prepare('DELETE FROM games WHERE id = ?');
            $stmt->execute([$gameId]);
            redirect('/admin/dashboard.php');
        }
    }
}

$games = $db->query('SELECT * FROM games ORDER BY created_at DESC')->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Dashboard</title>
    <link rel="stylesheet" href="/public/assets/style.css">
</head>
<body>
    <header class="topbar">
        <h1>Basketball Scoreboard</h1>
        <div class="topbar-logo">
            <img src="/public/logo/Logo_UBC_230px.png" alt="UBC Logo">
        </div>
        <nav>
            <a href="/public/index.php" target="_blank">Öffentliche Spiele</a>
            <a href="/admin/logout.php">Logout</a>
        </nav>
    </header>

    <main class="container">
        <section class="card">
            <h2>Neues Spiel anlegen</h2>
            <?php if ($error !== ''): ?>
                <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <?php if (!empty($createdPassword['password'])): ?>
                <div class="alert">Steuer-Passwort (Spiel #<?= (int)$createdPassword['id'] ?>): <strong><?= e($createdPassword['password']) ?></strong></div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_game">
                <div class="grid">
                    <label>
                        Spieltitel
                        <input type="text" name="title" required maxlength="60">
                    </label>
                    <label>
                        Heimteam
                        <input type="text" name="team_home" required maxlength="40">
                    </label>
                    <label>
                        Auswärtsteam
                        <input type="text" name="team_away" required maxlength="40">
                    </label>
                    <label>
                        Datum
                        <input type="date" name="game_date" required value="<?= e($defaultDate) ?>">
                    </label>
                    <label>
                        Uhrzeit
                        <input type="time" name="game_time" required value="<?= e($defaultTime) ?>">
                    </label>
                </div>

                <div class="teams-grid">
                    <div>
                        <h3>Heimspieler (12)</h3>
                        <?php for ($i = 0; $i < 12; $i++): ?>
                            <div class="player-row">
                                <input type="text" name="home_player_number[]" placeholder="#" maxlength="10">
                                <input type="text" name="home_player_name[]" placeholder="Name" <?= $i < 5 ? 'required' : '' ?> maxlength="40" value="UBC Name<?= $i + 1 ?>">
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div>
                        <h3>Auswärtsspieler (12)</h3>
                        <?php for ($i = 0; $i < 12; $i++): ?>
                            <div class="player-row">
                                <input type="text" name="away_player_number[]" placeholder="#" maxlength="10">
                                <input type="text" name="away_player_name[]" placeholder="Name" <?= $i < 5 ? 'required' : '' ?> maxlength="40" value="Gast Name<?= $i + 1 ?>">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <button type="submit">Spiel erstellen</button>
            </form>
        </section>

        <section class="card">
            <h2>Spiele</h2>
            <?php if (empty($games)): ?>
                <p>Noch keine Spiele.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Titel</th>
                            <th>Teams</th>
                            <th>Score</th>
                            <th>Period</th>
                            <th>Status</th>
                            <th>Steuer-Passwort</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($games as $game): ?>
                            <tr>
                                <td><?= e($game['title']) ?></td>
                                <td><?= e($game['team_home']) ?> vs <?= e($game['team_away']) ?></td>
                                <td><?= (int)$game['home_score'] ?> : <?= (int)$game['away_score'] ?></td>
                            <td><?= (int)$game['period'] ?></td>
                            <td><?= e($game['status']) ?></td>
                            <td><?= e((string)($game['control_password_plain'] ?? '')) ?></td>
                            <td class="actions">
                                    <a class="button" href="/admin/control.php?id=<?= (int)$game['id'] ?>">Steuern</a>
                                    <a class="button" href="/public/view.php?id=<?= (int)$game['id'] ?>" target="_blank">View</a>
                                    <form method="post" class="inline" onsubmit="return confirm('Spiel wirklich zurücksetzen?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reset_game">
                                        <input type="hidden" name="game_id" value="<?= (int)$game['id'] ?>">
                                        <button type="submit" class="secondary">Reset</button>
                                    </form>
                                    <form method="post" class="inline" onsubmit="return confirm('Spiel wirklich löschen?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_game">
                                        <input type="hidden" name="game_id" value="<?= (int)$game['id'] ?>">
                                        <button type="submit" class="danger">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
