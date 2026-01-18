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

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title><?= e($game['title']) ?></title>
    <link rel="stylesheet" href="/public/assets/style.css">
</head>
<body data-game-id="<?= (int)$gameId ?>">
    <header class="topbar">
        <h1><?= e($game['title']) ?></h1>
        <nav>
            <a href="/index.html">Alle Spiele</a>
        </nav>
    </header>

    <main class="container">
        <section class="card view-scoreboard">
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
                <div class="period" data-period><?= e(period_label((int)$game['period'])) ?></div>
            </div>
        </section>

        <section class="card">
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
        </section>

        <section class="card">
            <h2>Spielerfouls</h2>
            <div class="teams-grid">
                <div>
                    <h3><?= e($game['team_home']) ?></h3>
                    <ul class="player-list">
                        <?php foreach ($homePlayers as $player): ?>
                            <li class="<?= (int)$player['fouls'] >= (int)$config['MAX_FOULS'] ? 'foul-out' : '' ?>">
                                <span><?= e((string)$player['number']) ?> <?= e($player['name']) ?></span>
                                <strong><?= (int)$player['fouls'] ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <h3><?= e($game['team_away']) ?></h3>
                    <ul class="player-list">
                        <?php foreach ($awayPlayers as $player): ?>
                            <li class="<?= (int)$player['fouls'] >= (int)$config['MAX_FOULS'] ? 'foul-out' : '' ?>">
                                <span><?= e((string)$player['number']) ?> <?= e($player['name']) ?></span>
                                <strong><?= (int)$player['fouls'] ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </section>
    </main>

    <script src="/public/assets/app.js"></script>
</body>
</html>
