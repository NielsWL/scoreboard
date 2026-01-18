<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dataDir = dirname(__DIR__) . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }

    $dbPath = $dataDir . '/scoreboard.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec('CREATE TABLE IF NOT EXISTS games (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        team_home TEXT NOT NULL,
        team_away TEXT NOT NULL,
        home_score INTEGER NOT NULL DEFAULT 0,
        away_score INTEGER NOT NULL DEFAULT 0,
        period INTEGER NOT NULL DEFAULT 1,
        clock_seconds INTEGER NOT NULL DEFAULT 600,
        status TEXT NOT NULL DEFAULT "active",
        quarter_history TEXT NOT NULL DEFAULT "[]",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id INTEGER NOT NULL,
        team TEXT NOT NULL,
        number TEXT NULL,
        name TEXT NOT NULL,
        fouls INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_players_game_id ON players(game_id)');

    return $pdo;
}

function config(): array
{
    static $config;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config.php';
    }
    return $config;
}
