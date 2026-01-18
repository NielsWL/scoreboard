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
