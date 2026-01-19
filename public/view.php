<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = db();
$config = config();
$gameId = (int)($_GET['id'] ?? 0);
if ($gameId <= 0) {
    redirect('/public/index.php');
}

$stmt = $db->prepare('SELECT * FROM games WHERE id = ?');
$stmt->execute([$gameId]);
$game = $stmt->fetch();
if (!$game) {
    redirect('/public/index.php');
}

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

$quarterHome = [];
$quarterAway = [];
$prevHome = 0;
$prevAway = 0;
for ($period = 1; $period <= $maxPeriod; $period++) {
    if (isset($entriesByPeriod[$period])) {
        $current = $entriesByPeriod[$period];
        $quarterHome[$period] = $current['home'] - $prevHome;
        $quarterAway[$period] = $current['away'] - $prevAway;
        $prevHome = $current['home'];
        $prevAway = $current['away'];
    } else {
        $quarterHome[$period] = null;
        $quarterAway[$period] = null;
    }
}

function period_label(int $period): string
{
    if ($period <= 4) {
        return 'Q' . $period;
    }
    return 'OT' . ($period - 4);
}

$homeFoulsTotal = array_sum(array_map(fn($player) => (int)$player['fouls'], $homePlayers));
$awayFoulsTotal = array_sum(array_map(fn($player) => (int)$player['fouls'], $awayPlayers));
$homeBaseline = 0;
$awayBaseline = 0;
for ($i = count($history) - 1; $i >= 0; $i -= 1) {
    if (isset($history[$i]['fouls_home_total'])) {
        $homeBaseline = (int)$history[$i]['fouls_home_total'];
    }
    if (isset($history[$i]['fouls_away_total'])) {
        $awayBaseline = (int)$history[$i]['fouls_away_total'];
    }
    if ($homeBaseline !== 0 || $awayBaseline !== 0) {
        break;
    }
}
$teamFoulsHome = max(0, $homeFoulsTotal - $homeBaseline);
$teamFoulsAway = max(0, $awayFoulsTotal - $awayBaseline);
$halftimeScore = $entriesByPeriod[2] ?? null;
$fulltimeScore = $entriesByPeriod[4] ?? null;
$showSecondHalf = $maxPeriod > 4 || (int)$game['period'] > 4;

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title><?= e($game['title']) ?></title>
    <link rel="stylesheet" href="/public/assets/style.css">
</head>
<body class="view-page" data-game-id="<?= (int)$gameId ?>">
    <header class="topbar">
        <h1><?= e($game['title']) ?></h1>
        <nav>
            <a href="/index.html">Alle Spiele</a>
        </nav>
    </header>

    <main class="view-shell">
        <div class="view-grid">
            <section class="card view-side view-side-home">
                <h2><?= e($game['team_home']) ?> Fouls</h2>
                <ul class="player-list">
                    <?php foreach ($homePlayers as $player): ?>
                        <li class="<?= (int)$player['fouls'] >= (int)$config['MAX_FOULS'] ? 'foul-out' : '' ?>">
                            <span><?= e((string)$player['number']) ?> <?= e($player['name']) ?></span>
                            <div class="foul-lamps" aria-label="Fouls: <?= (int)$player['fouls'] ?>">
                                <?php for ($lamp = 1; $lamp <= (int)$config['MAX_FOULS']; $lamp++): ?>
                                    <span class="<?= (int)$player['fouls'] >= $lamp ? 'active' : '' ?>"></span>
                                <?php endfor; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="card view-center">
                <div class="view-scoreboard">
                    <div class="score-block">
                        <div>
                            <h2><?= e($game['team_home']) ?></h2>
                            <div class="score" data-home-score><?= (int)$game['home_score'] ?></div>
                        </div>
                        <div>
                            <h2><?= e($game['team_away']) ?></h2>
                            <div class="score" data-away-score><?= (int)$game['away_score'] ?></div>
                        </div>
                    </div>
                    <div class="period-block">
                        <div class="period" data-period><?= $game['status'] === 'ended' ? 'Spielende' : e(period_label((int)$game['period'])) ?></div>
                    </div>
                    <div class="team-stats center-stat-list">
                        <div class="center-stat-row">
                            <strong class="center-stat-value" data-team-fouls-home><?= $teamFoulsHome ?></strong>
                            <span class="center-stat-label">Teamfouls</span>
                            <strong class="center-stat-value" data-team-fouls-away><?= $teamFoulsAway ?></strong>
                        </div>
                        <div class="center-stat-row">
                            <strong class="center-stat-value" data-timeouts-home><?= (int)$game['timeouts_home'] ?></strong>
                            <span class="center-stat-label">Timeouts</span>
                            <strong class="center-stat-value" data-timeouts-away><?= (int)$game['timeouts_away'] ?></strong>
                        </div>
                    </div>
                    <div class="half-summary">
                        <div class="half-row">
                            <span>Stand nach 1. Halbzeit</span>
                            <div class="half-score">
                                <strong data-halftime-home><?= $halftimeScore ? (int)$halftimeScore['home'] : '-' ?></strong>
                                <span>:</span>
                                <strong data-halftime-away><?= $halftimeScore ? (int)$halftimeScore['away'] : '-' ?></strong>
                            </div>
                        </div>
                        <div class="half-row<?= $showSecondHalf ? '' : ' hidden' ?>" data-second-half-block>
                            <span>Stand nach 2. Halbzeit</span>
                            <div class="half-score">
                                <strong data-secondhalf-home><?= $fulltimeScore ? (int)$fulltimeScore['home'] : '-' ?></strong>
                                <span>:</span>
                                <strong data-secondhalf-away><?= $fulltimeScore ? (int)$fulltimeScore['away'] : '-' ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="view-history">
                    <h2>Viertel-History</h2>
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
                                <?php for ($period = 1; $period <= $maxPeriod; $period++): ?>
                                    <td><?= $quarterHome[$period] === null ? '-' : (int)$quarterHome[$period] ?></td>
                                <?php endfor; ?>
                            </tr>
                            <tr>
                                <td><?= e($game['team_away']) ?></td>
                                <?php for ($period = 1; $period <= $maxPeriod; $period++): ?>
                                    <td><?= $quarterAway[$period] === null ? '-' : (int)$quarterAway[$period] ?></td>
                                <?php endfor; ?>
                            </tr>
                        </tbody>
                    </table>
                    <p class="muted">Hinweis: Viertelwerte werden aus kumulativ gespeicherten Endständen berechnet.</p>
                </div>
            </section>

            <section class="card view-side view-side-away">
                <h2><?= e($game['team_away']) ?> Fouls</h2>
                <ul class="player-list">
                    <?php foreach ($awayPlayers as $player): ?>
                        <li class="<?= (int)$player['fouls'] >= (int)$config['MAX_FOULS'] ? 'foul-out' : '' ?>">
                            <span><?= e((string)$player['number']) ?> <?= e($player['name']) ?></span>
                            <div class="foul-lamps" aria-label="Fouls: <?= (int)$player['fouls'] ?>">
                                <?php for ($lamp = 1; $lamp <= (int)$config['MAX_FOULS']; $lamp++): ?>
                                    <span class="<?= (int)$player['fouls'] >= $lamp ? 'active' : '' ?>"></span>
                                <?php endfor; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>
    </main>

    <script src="/public/assets/app.js"></script>
</body>
</html>
