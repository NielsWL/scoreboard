<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

$db = db();

$action = $_GET['action'] ?? '';
if ($action === 'get') {
    $gameId = (int)($_GET['id'] ?? 0);
    if ($gameId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing game id']);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM games WHERE id = ?');
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) {
        http_response_code(404);
        echo json_encode(['error' => 'Game not found']);
        exit;
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
    $homeTotal = array_sum(array_map(fn($player) => (int)$player['fouls'], $homePlayers));
    $awayTotal = array_sum(array_map(fn($player) => (int)$player['fouls'], $awayPlayers));
    $teamFoulsHome = max(0, $homeTotal - $homeBaseline);
    $teamFoulsAway = max(0, $awayTotal - $awayBaseline);

    header('Content-Type: application/json');
    echo json_encode([
        'game' => [
            'id' => (int)$game['id'],
            'title' => $game['title'],
            'team_home' => $game['team_home'],
            'team_away' => $game['team_away'],
            'home_score' => (int)$game['home_score'],
            'away_score' => (int)$game['away_score'],
            'period' => (int)$game['period'],
            'clock_seconds' => (int)$game['clock_seconds'],
            'team_fouls_home' => $teamFoulsHome,
            'team_fouls_away' => $teamFoulsAway,
            'timeouts_home' => (int)$game['timeouts_home'],
            'timeouts_away' => (int)$game['timeouts_away'],
            'status' => $game['status'],
            'quarter_history' => $game['quarter_history'],
            'updated_at' => $game['updated_at'],
        ],
        'players' => [
            'home' => $homePlayers,
            'away' => $awayPlayers,
        ],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf();
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported action']);
    exit;
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid request']);
