<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$db = db();
$config = config();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
if ($action === 'list') {
    $games = $db->query("SELECT id, title, team_home, team_away, home_score, away_score, status FROM games WHERE status = 'active' OR (status = 'ended' AND ended_at IS NOT NULL AND julianday(ended_at) >= julianday('now', '-2 days')) ORDER BY created_at DESC")->fetchAll();
    echo json_encode(['games' => $games]);
    exit;
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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

    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['error' => implode(' ', $errors)]);
        exit;
    }

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
    grant_game_access($gameId);

    echo json_encode([
        'game_id' => $gameId,
        'password' => $password,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
