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

function timeout_warning_threshold(int $period): int
{
    if ($period >= 5) {
        return 1;
    }
    if ($period >= 3) {
        return 3;
    }
    return 2;
}

function sum_team_fouls(array $players, string $team): int
{
    $sum = 0;
    foreach ($players as $player) {
        if ($player['team'] === $team) {
            $sum += (int)$player['fouls'];
        }
    }
    return $sum;
}

function upsert_history_entry(array $history, int $period, int $homeScore, int $awayScore, int $homeFouls, int $awayFouls): array
{
    $entry = [
        'period' => $period,
        'home' => $homeScore,
        'away' => $awayScore,
        'fouls_home_total' => $homeFouls,
        'fouls_away_total' => $awayFouls,
    ];
    foreach ($history as $index => $item) {
        if ((int)($item['period'] ?? 0) === $period) {
            $history[$index] = $entry;
            return array_values($history);
        }
    }
    $history[] = $entry;
    return array_values($history);
}

function foul_baseline(array $history, string $team): int
{
    $key = $team === 'home' ? 'fouls_home_total' : 'fouls_away_total';
    for ($i = count($history) - 1; $i >= 0; $i -= 1) {
        if (isset($history[$i][$key])) {
            return (int)$history[$i][$key];
        }
    }
    return 0;
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

    if ($action === 'end_quarter') {
        $history = json_decode($game['quarter_history'], true);
        if (!is_array($history)) {
            $history = [];
        }
        $playerStmt = $db->prepare('SELECT team, fouls FROM players WHERE game_id = ?');
        $playerStmt->execute([$gameId]);
        $playersForTotals = $playerStmt->fetchAll();
        $homeTotal = sum_team_fouls($playersForTotals, 'home');
        $awayTotal = sum_team_fouls($playersForTotals, 'away');
        $history[] = [
            'period' => (int)$game['period'],
            'home' => (int)$game['home_score'],
            'away' => (int)$game['away_score'],
            'fouls_home_total' => $homeTotal,
            'fouls_away_total' => $awayTotal,
        ];
        $period = (int)$game['period'] + 1;
        $resetTimeouts = in_array((int)$game['period'], [2, 4], true);
        $timeoutsHome = $resetTimeouts ? 0 : (int)$game['timeouts_home'];
        $timeoutsAway = $resetTimeouts ? 0 : (int)$game['timeouts_away'];
        $stmt = $db->prepare('UPDATE games SET quarter_history = ?, period = ?, clock_seconds = ?, timeouts_home = ?, timeouts_away = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([json_encode($history), $period, (int)$config['QUARTER_SECONDS'], $timeoutsHome, $timeoutsAway, now_iso(), $gameId]);
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

    if ($action === 'timeout_adjust') {
        $team = $_POST['team'] ?? '';
        $delta = (int)($_POST['delta'] ?? 0);
        if (in_array($team, ['home', 'away'], true)) {
            $field = $team === 'home' ? 'timeouts_home' : 'timeouts_away';
            $current = (int)$game[$field];
            $newValue = clamp_int($current + $delta, 0, (int)$config['MAX_TIMEOUTS']);
            $stmt = $db->prepare("UPDATE games SET {$field} = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$newValue, now_iso(), $gameId]);
        }
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'end_game') {
        $history = json_decode($game['quarter_history'], true);
        if (!is_array($history)) {
            $history = [];
        }
        $playerStmt = $db->prepare('SELECT team, fouls FROM players WHERE game_id = ?');
        $playerStmt->execute([$gameId]);
        $playersForTotals = $playerStmt->fetchAll();
        $homeTotal = sum_team_fouls($playersForTotals, 'home');
        $awayTotal = sum_team_fouls($playersForTotals, 'away');
        $history = upsert_history_entry(
            $history,
            (int)$game['period'],
            (int)$game['home_score'],
            (int)$game['away_score'],
            $homeTotal,
            $awayTotal
        );
        $now = now_iso();
        $stmt = $db->prepare('UPDATE games SET status = ?, ended_at = ?, quarter_history = ?, updated_at = ? WHERE id = ?');
        $stmt->execute(['ended', $now, json_encode($history), $now, $gameId]);
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'reopen_game') {
        $stmt = $db->prepare('UPDATE games SET status = ?, ended_at = NULL, updated_at = ? WHERE id = ?');
        $stmt->execute(['active', now_iso(), $gameId]);
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'update_teams') {
        $teamHome = trim((string)($_POST['team_home'] ?? ''));
        $teamAway = trim((string)($_POST['team_away'] ?? ''));
        $gameDate = trim((string)($_POST['game_date'] ?? ''));
        $gameTime = trim((string)($_POST['game_time'] ?? ''));
        if (
            $teamHome !== '' && $teamAway !== ''
            && mb_strlen($teamHome) <= 40 && mb_strlen($teamAway) <= 40
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $gameDate)
            && preg_match('/^\d{2}:\d{2}$/', $gameTime)
        ) {
            $stmt = $db->prepare('UPDATE games SET team_home = ?, team_away = ?, game_date = ?, game_time = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$teamHome, $teamAway, $gameDate, $gameTime, now_iso(), $gameId]);
        }
        redirect('/admin/control.php?id=' . $gameId);
    }

    if ($action === 'update_player') {
        $playerId = (int)($_POST['player_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $number = trim((string)($_POST['number'] ?? ''));
        if ($playerId > 0 && mb_strlen($name) <= 40 && mb_strlen($number) <= 10) {
            $stmt = $db->prepare('SELECT team, (SELECT COUNT(*) FROM players p2 WHERE p2.game_id = players.game_id AND p2.team = players.team AND p2.id <= players.id) AS team_index FROM players WHERE id = ? AND game_id = ?');
            $stmt->execute([$playerId, $gameId]);
            $playerInfo = $stmt->fetch();
            $teamIndex = (int)($playerInfo['team_index'] ?? 0);
            if ($playerInfo && ($name !== '' || $teamIndex > 5)) {
                $stmt = $db->prepare('UPDATE players SET name = ?, number = ? WHERE id = ? AND game_id = ?');
                $stmt->execute([$name, $number !== '' ? $number : null, $playerId, $gameId]);
            }
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

$entriesByPeriod = [];
$maxPeriod = 4;
foreach ($history as $entry) {
    $period = (int)($entry['period'] ?? 0);
    if ($period > 0) {
        $entriesByPeriod[$period] = [
            'home' => (int)($entry['home'] ?? 0),
            'away' => (int)($entry['away'] ?? 0),
        ];
        $maxPeriod = max($maxPeriod, $period);
    }
}

$homeFoulsTotal = sum_team_fouls($players, 'home');
$awayFoulsTotal = sum_team_fouls($players, 'away');
$homeBaseline = foul_baseline($history, 'home');
$awayBaseline = foul_baseline($history, 'away');
$teamFoulsHome = max(0, $homeFoulsTotal - $homeBaseline);
$teamFoulsAway = max(0, $awayFoulsTotal - $awayBaseline);

$historyRows = [];
$prevHome = 0;
$prevAway = 0;
for ($period = 1; $period <= $maxPeriod; $period++) {
    if (isset($entriesByPeriod[$period])) {
        $homeScore = $entriesByPeriod[$period]['home'];
        $awayScore = $entriesByPeriod[$period]['away'];
        $historyRows[] = [
            'period' => $period,
            'quarter_home' => $homeScore - $prevHome,
            'quarter_away' => $awayScore - $prevAway,
            'home' => $homeScore,
            'away' => $awayScore,
        ];
        $prevHome = $homeScore;
        $prevAway = $awayScore;
    } else {
        $historyRows[] = [
            'period' => $period,
            'quarter_home' => null,
            'quarter_away' => null,
            'home' => null,
            'away' => null,
        ];
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
<body class="control-page">
    <header class="topbar">
        <h1><?= e($game['title']) ?></h1>
        <div class="topbar-logo">
            <img src="/public/logo/Logo_UBC_230px.png" alt="UBC Logo">
        </div>
        <nav>
            <a href="/admin/dashboard.php">Dashboard</a>
            <a href="/public/view.php?id=<?= (int)$gameId ?>" target="_blank">Public View</a>
            <a class="refresh-link" href="" title="Aktualisieren" onclick="window.location.reload(); return false;">↻ Aktualisieren</a>
            <a href="/admin/logout.php">Logout</a>
        </nav>
    </header>

    <main class="container">
        <section class="scoreboard-layout control-scoreboard">
            <section class="card foul-side control-side">
                <h3><?= e($game['team_home']) ?></h3>
                <ul class="foul-control-list">
                    <?php foreach ($homePlayers as $player): ?>
                        <li class="<?= (int)$player['fouls'] >= (int)$config['MAX_FOULS'] ? 'foul-out' : '' ?>">
                            <span class="foul-player"><?= e((string)$player['number']) ?> <?= e($player['name']) ?></span>
                            <form method="post" class="foul-buttons">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="foul_adjust">
                                <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                                <button name="delta" value="1" class="small-button">+</button>
                                <button name="delta" value="-1" class="secondary small-button">➖</button>
                                <span class="foul-count"><?= (int)$player['fouls'] ?></span>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <section class="card scoreboard-center">
                <div class="score-block score-grid">
                    <div>
                        <h2><?= e($game['team_home']) ?></h2>
                        <div class="score"><?= (int)$game['home_score'] ?></div>
                        <form method="post" class="button-grid compact">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="score">
                            <input type="hidden" name="team" value="home">
                            <button name="delta" value="1" class="small-button">+1</button>
                            <button name="delta" value="2" class="small-button">+2</button>
                            <button name="delta" value="3" class="small-button">+3</button>
                            <button name="delta" value="-1" class="secondary small-button">➖1</button>
                        </form>
                    </div>
                    <div class="score-toggle">
                        <button type="button" class="secondary small-button toggle-minus-button" id="toggle-minus">-</button>
                    </div>
                    <div>
                        <h2><?= e($game['team_away']) ?></h2>
                        <div class="score"><?= (int)$game['away_score'] ?></div>
                        <form method="post" class="button-grid compact">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="score">
                            <input type="hidden" name="team" value="away">
                            <button name="delta" value="1" class="small-button">+1</button>
                            <button name="delta" value="2" class="small-button">+2</button>
                            <button name="delta" value="3" class="small-button">+3</button>
                            <button name="delta" value="-1" class="secondary small-button">➖1</button>
                        </form>
                    </div>
                </div>
                <div class="period-block">
                    <div class="period"><?= $game['status'] === 'ended' ? 'Spielende' : e(period_label((int)$game['period'])) ?></div>
                    <div class="button-row">
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="end_quarter">
                            <button type="submit" class="small-button">⏭ Viertel beenden</button>
                        </form>
                        <form method="post" class="inline" onsubmit="return confirm('Letztes Viertel löschen?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_last_quarter">
                            <button type="submit" class="secondary small-button">↩ Letztes Viertel löschen</button>
                        </form>
                    </div>
                </div>
                <div class="team-stats center-stat-list score-stat-list">
                    <div class="center-stat-row">
                        <strong class="center-stat-value <?= $teamFoulsHome >= 5 ? 'team-fouls-warning' : '' ?>"><?= $teamFoulsHome ?></strong>
                        <span class="center-stat-label">Teamfouls</span>
                        <strong class="center-stat-value <?= $teamFoulsAway >= 5 ? 'team-fouls-warning' : '' ?>"><?= $teamFoulsAway ?></strong>
                    </div>
                    <div class="center-stat-row">
                        <div class="center-stat-side">
                            <strong class="center-stat-value <?= (int)$game['timeouts_home'] >= timeout_warning_threshold((int)$game['period']) ? 'timeouts-warning' : '' ?>"><?= (int)$game['timeouts_home'] ?></strong>
                            <form method="post" class="button-grid compact center-stat-buttons">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="timeout_adjust">
                                <input type="hidden" name="team" value="home">
                                <button name="delta" value="1" class="small-button">+</button>
                                <button name="delta" value="-1" class="secondary small-button">➖</button>
                            </form>
                        </div>
                        <span class="center-stat-label">Timeouts</span>
                        <div class="center-stat-side">
                            <strong class="center-stat-value <?= (int)$game['timeouts_away'] >= timeout_warning_threshold((int)$game['period']) ? 'timeouts-warning' : '' ?>"><?= (int)$game['timeouts_away'] ?></strong>
                            <form method="post" class="button-grid compact center-stat-buttons">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="timeout_adjust">
                                <input type="hidden" name="team" value="away">
                                <button name="delta" value="1" class="small-button">+</button>
                                <button name="delta" value="-1" class="secondary small-button">➖</button>
                            </form>
                        </div>
                    </div>
                </div>
                <p class="muted">Teamfouls werden automatisch pro Viertel aus den Spielerfouls berechnet.</p>
            </section>
            <section class="card foul-side control-side">
                <h3><?= e($game['team_away']) ?></h3>
                <ul class="foul-control-list">
                    <?php foreach ($awayPlayers as $player): ?>
                        <li class="<?= (int)$player['fouls'] >= (int)$config['MAX_FOULS'] ? 'foul-out' : '' ?>">
                            <span class="foul-player"><?= e((string)$player['number']) ?> <?= e($player['name']) ?></span>
                            <form method="post" class="foul-buttons">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="foul_adjust">
                                <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                                <button name="delta" value="1" class="small-button">+</button>
                                <button name="delta" value="-1" class="secondary small-button">➖</button>
                                <span class="foul-count"><?= (int)$player['fouls'] ?></span>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </section>

        <section class="card">
            <h2>Viertel-History</h2>
            <?php if (empty($history)): ?>
                <p>Noch keine Einträge.</p>
            <?php else: ?>
                <h3>Viertelwerte</h3>
                <table class="quarter-table">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <?php for ($period = 1; $period <= $maxPeriod; $period++): ?>
                                <th><?= e(period_label($period)) ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= e($game['team_home']) ?></td>
                            <?php foreach ($historyRows as $row): ?>
                                <td><?= $row['quarter_home'] === null ? '-' : (int)$row['quarter_home'] ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><?= e($game['team_away']) ?></td>
                            <?php foreach ($historyRows as $row): ?>
                                <td><?= $row['quarter_away'] === null ? '-' : (int)$row['quarter_away'] ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
                <p class="muted"></p>
                <h3>Kumulativ</h3>
                <table class="quarter-table">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <?php for ($period = 1; $period <= $maxPeriod; $period++): ?>
                                <th><?= e(period_label($period)) ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= e($game['team_home']) ?></td>
                            <?php foreach ($historyRows as $row): ?>
                                <td><?= $row['home'] === null ? '-' : (int)$row['home'] ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><?= e($game['team_away']) ?></td>
                            <?php foreach ($historyRows as $row): ?>
                                <td><?= $row['away'] === null ? '-' : (int)$row['away'] ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
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
                <label>
                    Datum
                    <input type="date" name="game_date" value="<?= e((string)($game['game_date'] ?? '')) ?>" required>
                </label>
                <label>
                    Uhrzeit
                    <input type="time" name="game_time" value="<?= e((string)($game['game_time'] ?? '')) ?>" required>
                </label>
                <button type="submit">Speichern</button>
            </form>
        </section>

        <section class="card">
            <h2>Steuer-Passwort</h2>
            <p><strong><?= e((string)($game['control_password_plain'] ?? '')) ?></strong></p>
            <?php if ($game['status'] === 'ended'): ?>
                <form method="post" class="inline end-game-row" onsubmit="return confirm('Spielende wirklich zurücknehmen?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reopen_game">
                    <button type="submit" class="secondary small-button">↩ Spielende zurücknehmen</button>
                </form>
            <?php else: ?>
                <form method="post" class="inline end-game-row" onsubmit="return confirm('Spiel wirklich beenden?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="end_game">
                    <button type="submit" class="danger small-button">🛑 Spiel beenden</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Spieler bearbeiten</h2>
            <div class="teams-grid">
                <div>
                    <h3><?= e($game['team_home']) ?></h3>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
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
                                    <td>
                                        <form method="post" id="<?= e($formId) ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_player">
                                            <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                                            <button type="submit" class="secondary small-button">💾</button>
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
                                    <td>
                                        <form method="post" id="<?= e($formId) ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_player">
                                            <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                                            <button type="submit" class="secondary small-button">💾</button>
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
    <script>
        const toggleButton = document.getElementById('toggle-minus');
        const storageKey = 'scoreboard.showNegative';
        const setShowNegative = (enabled) => {
            document.body.classList.toggle('show-negative', enabled);
            toggleButton.textContent = '-';
            if (window.localStorage) {
                if (enabled) {
                    window.localStorage.setItem(storageKey, '1');
                } else {
                    window.localStorage.removeItem(storageKey);
                }
            }
        };
        const storedValue = window.localStorage ? window.localStorage.getItem(storageKey) : null;
        setShowNegative(storedValue === '1');
        toggleButton.addEventListener('click', () => {
            const enabled = !document.body.classList.contains('show-negative');
            setShowNegative(enabled);
        });
    </script>
</body>
</html>
