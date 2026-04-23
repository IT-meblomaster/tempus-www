<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_page(): string
{
    $page = $_GET['page'] ?? 'home';
    $page = preg_replace('/[^a-z0-9_-]/i', '', (string) $page);

    return $page !== '' ? $page : 'home';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function set_flash(string $type, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    $messages = get_flash_messages();

    if ($messages === []) {
        return null;
    }

    return $messages[0];
}

function get_flash_messages(): array
{
    $messages = $_SESSION['flash'] ?? [];

    if (!is_array($messages)) {
        $messages = [];
    }

    unset($_SESSION['flash']);

    return $messages;
}