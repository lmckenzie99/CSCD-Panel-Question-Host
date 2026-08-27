<?php

/**
 * Shared PDO, session, and JSON helpers. Include from API endpoints only.
 */

function app_config()
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/config.php';
    if (!is_readable($path)) {
        json_response(
            array(
                'error' => 'Server is not configured. Copy api/config.example.php to api/config.php.',
            ),
            500
        );
    }

    $config = require $path;
    return $config;
}

function db()
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['name'],
        isset($db['charset']) ? $db['charset'] : 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    } catch (PDOException $e) {
        json_response(array('error' => 'Could not connect to the database.'), 500);
    }

    return $pdo;
}

function start_app_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ));

    session_start();
}

function json_input()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    return is_array($data) ? $data : array();
}

function json_response($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function require_method($method)
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_response(array('error' => 'Method not allowed.'), 405);
    }
}

function require_moderator()
{
    start_app_session();

    if (empty($_SESSION['moderator'])) {
        json_response(array('error' => 'Unauthorized.'), 401);
    }
}

function client_ip_hash()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    return hash('sha256', $ip);
}

function app_strlen($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function question_payload($row)
{
    return array(
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'body' => $row['body'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
    );
}
