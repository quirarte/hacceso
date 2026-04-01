<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        auth_require_csrf_token($config);
    } catch (Throwable $exception) {
        auth_logout_user($config);
        auth_redirect('/login.php');
    }
}

auth_logout_user($config);
auth_redirect('/login.php');
