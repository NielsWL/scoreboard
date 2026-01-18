<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function require_admin(): void
{
    start_session();
    if (empty($_SESSION['admin'])) {
        redirect('/admin/login.php');
    }
}

function has_admin(): bool
{
    start_session();
    return !empty($_SESSION['admin']);
}

function grant_game_access(int $gameId): void
{
    start_session();
    if (!isset($_SESSION['game_access']) || !is_array($_SESSION['game_access'])) {
        $_SESSION['game_access'] = [];
    }
    $_SESSION['game_access'][$gameId] = true;
}

function has_game_access(int $gameId): bool
{
    start_session();
    return !empty($_SESSION['game_access'][$gameId]);
}

function require_admin_or_game_access(int $gameId): void
{
    if (has_admin() || has_game_access($gameId)) {
        return;
    }
    redirect('/public/control.php?id=' . $gameId);
}
