<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    $token = $_POST['_csrf'] ?? '';

    if (!is_string($token) || $token === '') {
        return false;
    }

    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['_csrf_token'], $token);
}

function regenerate_csrf_token(): void
{
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}