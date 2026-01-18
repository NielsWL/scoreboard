<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

$db = db();
$config = config();

$gameId = (int)($_GET['id'] ?? 0);
if ($gameId <= 0) {
    redirect('/admin/dashboard.php');
}

require_admin_or_game_access($gameId);

function period_label(int $period): string
{
    if ($period <= 4) {
        return 'Q' . $period;
    }
    return 'OT' . ($period - 4);
}

function load_game(PDO $db, int $gameId): array
{
    $stmt = $db->prepare('SELECT * FROM games WHERE id = ?');
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) {
        redirect('/admin/dashboard.php');
    }
    return $game;
}

$game = load_game($db, $gameId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'score') {
        $team = $_POST['team'] ?? '';
        $delta = (int)($_POST['delta'] ?? 0);
        if (in_array($team, ['home', 'away'], true)) {
            $field = $team === 'home' ? 'home_score' : 'away_score';
            $current = (int)$game[$field];
            $newScore = max(0, $current + $delta);
            $stmt = $db->prepare("UPDATE games SET {$field} = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$newScore, now_iso(), $gameId]);
        }
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'next_period') {
        $period = (int)$game['period'] + 1;
        $stmt = $db->prepare('UPDATE games SET period = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$period, now_iso(), $gameId]);
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'end_quarter') {
        $history = json_decode($game['quarter_history'], true);
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = [
            'period' => (int)$game['period'],
            'home' => (int)$game['home_score'],
            'away' => (int)$game['away_score'],
        ];
        $period = (int)$game['period'] + 1;
        $stmt = $db->prepare('UPDATE games SET quarter_history = ?, period = ?, clock_seconds = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([json_encode($history), $period, (int)$config['QUARTER_SECONDS'], now_iso(), $gameId]);
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'delete_last_quarter') {
        $history = json_decode($game['quarter_history'], true);
        if (!is_array($history)) {
            $history = [];
        }
        array_pop($history);
        $last = end($history);
        if ($last) {
            $homeScore = (int)($last['home'] ?? 0);
            $awayScore = (int)($last['away'] ?? 0);
            $period = (int)($last['period'] ?? 0) + 1;
        } else {
            $homeScore = 0;
            $awayScore = 0;
            $period = 1;
        }
        $stmt = $db->prepare('UPDATE games SET quarter_history = ?, home_score = ?, away_score = ?, period = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([json_encode(array_values($history)), $homeScore, $awayScore, $period, now_iso(), $gameId]);
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'clock_adjust') {
        $delta = (int)($_POST['delta'] ?? 0);
        $clock = clamp_int((int)$game['clock_seconds'] + $delta, 0, (int)$config['QUARTER_SECONDS']);
        $stmt = $db->prepare('UPDATE games SET clock_seconds = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$clock, now_iso(), $gameId]);
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'foul_adjust') {
        $playerId = (int)($_POST['player_id'] ?? 0);
        $delta = (int)($_POST['delta'] ?? 0);
        $stmt = $db->prepare('SELECT fouls FROM players WHERE id = ? AND game_id = ?');
        $stmt->execute([$playerId, $gameId]);
        $player = $stmt->fetch();
        if ($player) {
            $fouls = clamp_int((int)$player['fouls'] + $delta, 0, (int)$config['MAX_FOULS']);
            $update = $db->prepare('UPDATE players SET fouls = ? WHERE id = ?');
            $update->execute([$fouls, $playerId]);
        }
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'update_teams') {
        $teamHome = trim((string)($_POST['team_home'] ?? ''));
        $teamAway = trim((string)($_POST['team_away'] ?? ''));
        if ($teamHome !== '' && $teamAway !== '' && mb_strlen($teamHome) <= 40 && mb_strlen($teamAway) <= 40) {
            $stmt = $db->prepare('UPDATE games SET team_home = ?, team_away = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$teamHome, $teamAway, now_iso(), $gameId]);
        }
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'update_player') {
        $playerId = (int)($_POST['player_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $number = trim((string)($_POST['number'] ?? ''));
        if ($playerId > 0 && $name !== '' && mb_strlen($name) <= 40 && mb_strlen($number) <= 10) {
            $stmt = $db->prepare('UPDATE players SET name = ?, number = ? WHERE id = ? AND game_id = ?');
            $stmt->execute([$name, $number !== '' ? $number : null, $playerId, $gameId]);
        }
        redirect('/admin/control.php?id=' . $gameId);
    }
}

$game = load_game($db, $gameId);

$stmt = $db->prepare('SELECT * FROM players WHERE game_id = ? ORDER BY team, id');
$stmt->execute([$gameId]);
$players = $stmt->fetchAll();

$homePlayers = array_values(array_filter($players, fn($p) => $p['team'] === 'home'));
$awayPlayers = array_values(array_filter($players, fn($p) => $p['team'] === 'away'));

$history = json_decode($game['quarter_history'], true);
if (!is_array($history)) {
    $history = [];
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
            <a href="/admin/dashboard.php">Dashboard</a>
            <a href="/public/view.php?id=<?= (int)$gameId ?>" target="_blank">Public View</a>
            <a href="/admin/logout.php">Logout</a>
        </nav>
    </header>

    <main class="container">
        <section class="card scoreboard">
            <div class="score-block">
                <div>
                    <h2><?= e($game['team_home']) ?></h2>
                    <div class="score"><?= (int)$game['home_score'] ?></div>
                    <form method="post" class="button-grid">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="score">
                        <input type="hidden" name="team" value="home">
                        <button name="delta" value="1">+1</button>
                        <button name="delta" value="2">+2</button>
                        <button name="delta" value="3">+3</button>
                        <button name="delta" value="-1" class="secondary">-1</button>
                    </form>
                </div>
                <div>
                    <h2><?= e($game['team_away']) ?></h2>
                    <div class="score"><?= (int)$game['away_score'] ?></div>
                    <form method="post" class="button-grid">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="score">
                        <input type="hidden" name="team" value="away">
                        <button name="delta" value="1">+1</button>
                        <button name="delta" value="2">+2</button>
                        <button name="delta" value="3">+3</button>
                        <button name="delta" value="-1" class="secondary">-1</button>
                    </form>
                </div>
            </div>
            <div class="period-block">
                <div class="period"><?= e(period_label((int)$game['period'])) ?></div>
                <div class="button-row">
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="next_period">
                        <button type="submit">Nächstes Viertel</button>
                    </form>
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="end_quarter">
                        <button type="submit">Viertel beenden</button>
                    </form>
                    <form method="post" class="inline" onsubmit="return confirm('Letztes Viertel löschen?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_last_quarter">
                        <button type="submit" class="secondary">Letztes Viertel löschen</button>
                    </form>
                </div>
            </div>
        </section>

        <section class="card">
            <h2>Viertel-History (kumulativ)</h2>
            <?php if (empty($history)): ?>
                <p>Noch keine Einträge.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($history as $entry): ?>
                        <li><?= e(period_label((int)$entry['period'])) ?>: <?= (int)$entry['home'] ?> : <?= (int)$entry['away'] ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Teamnamen bearbeiten</h2>
            <form method="post" class="grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_teams">
                <label>
                    Heimteam
                    <input type="text" name="team_home" value="<?= e($game['team_home']) ?>" maxlength="40" required>
                </label>
                <label>
                    Auswärtsteam
                    <input type="text" name="team_away" value="<?= e($game['team_away']) ?>" maxlength="40" required>
                </label>
                <button type="submit">Speichern</button>
            </form>
        </section>

        <section class="card">
            <h2>Spieler & Fouls</h2>
            <div class="teams-grid">
                <div>
                    <h3><?= e($game['team_home']) ?></h3>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Fouls</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($homePlayers as $player): ?>
                                <?php $formId = 'update-player-' . (int)$player['id']; ?>
                                <tr class="<?= (int)$player['fouls'] >= (int)$config['MAX_FOULS'] ? 'foul-out' : '' ?>">
                                    <td>
                                        <input type="text" name="number" value="<?= e((string)$player['number']) ?>" maxlength="10" class="tiny" form="<?= e($formId) ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="name" value="<?= e($player['name']) ?>" maxlength="40" class="wide" form="<?= e($formId) ?>">
                                    </td>
                                    <td><?= (int)$player['fouls'] ?></td>
                                    <td>
                                        <form method="post" id="<?= e($formId) ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_player">
                                            <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                                            <button type="submit" class="secondary">Speichern</button>
                                        </form>
                                        <form method="post" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="foul_adjust">
                                            <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                                            <button name="delta" value="1">+Foul</button>
                                            <button name="delta" value="-1" class="secondary">-Foul</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3><?= e($game['team_away']) ?></h3>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Fouls</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($awayPlayers as $player): ?>
                                <?php $formId = 'update-player-' . (int)$player['id']; ?>
                                <tr class="<?= (int)$player['fouls'] >= (int)$config['MAX_FOULS'] ? 'foul-out' : '' ?>">
                                    <td>
                                        <input type="text" name="number" value="<?= e((string)$player['number']) ?>" maxlength="10" class="tiny" form="<?= e($formId) ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="name" value="<?= e($player['name']) ?>" maxlength="40" class="wide" form="<?= e($formId) ?>">
                                    </td>
                                    <td><?= (int)$player['fouls'] ?></td>
                                    <td>
                                        <form method="post" id="<?= e($formId) ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_player">
                                            <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                                            <button type="submit" class="secondary">Speichern</button>
                                        </form>
                                        <form method="post" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="foul_adjust">
                                            <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                                            <button name="delta" value="1">+Foul</button>
                                            <button name="delta" value="-1" class="secondary">-Foul</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
