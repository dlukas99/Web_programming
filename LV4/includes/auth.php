<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in(): bool
{
    return isset($_SESSION["user"]);
}

function current_user(): ?array
{
    return $_SESSION["user"] ?? null;
}

function login_user(array $user): void
{
    $_SESSION["user"] = $user;
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

function set_flash(string $type, string $message): void
{
    $_SESSION["flash"] = [
        "type" => $type,
        "message" => $message,
    ];
}

function get_flash(): ?array
{
    if (!isset($_SESSION["flash"])) {
        return null;
    }

    $flash = $_SESSION["flash"];
    unset($_SESSION["flash"]);
    return $flash;
}

function redirect(string $path): void
{
    header("Location: {$path}");
    exit;
}
