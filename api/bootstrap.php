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
    $payload = array(
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'body' => $row['body'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
    );

    if (array_key_exists('visible', $row)) {
        $payload['visible'] = (int) $row['visible'] === 1;
    }
    if (array_key_exists('is_current', $row)) {
        $payload['is_current'] = (int) $row['is_current'] === 1;
    }
    if (array_key_exists('vote_count', $row)) {
        $payload['vote_count'] = (int) $row['vote_count'];
    }
    if (array_key_exists('voted', $row)) {
        $payload['voted'] = (int) $row['voted'] === 1;
    }

    return $payload;
}

function voter_token()
{
    $fromCookie = isset($_COOKIE['panel_voter']) ? $_COOKIE['panel_voter'] : '';
    if (preg_match('/^[a-f0-9]{64}$/', $fromCookie)) {
        return $fromCookie;
    }

    $fromHeader = isset($_SERVER['HTTP_X_VOTER_TOKEN']) ? $_SERVER['HTTP_X_VOTER_TOKEN'] : '';
    if (preg_match('/^[a-f0-9]{64}$/', $fromHeader)) {
        set_voter_cookie($fromHeader);
        return $fromHeader;
    }

    $token = bin2hex(random_bytes(32));
    set_voter_cookie($token);
    return $token;
}

function set_voter_cookie($token)
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('panel_voter', $token, array(
        'expires' => time() + 86400 * 30,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    $_COOKIE['panel_voter'] = $token;
}

function enforce_vote_cooldown($pdo, $token, $seconds = 2)
{
    $stmt = $pdo->prepare('SELECT last_vote_at FROM vote_limits WHERE voter_token = :token');
    $stmt->execute(array('token' => $token));
    $row = $stmt->fetch();
    if ($row) {
        $elapsed = time() - strtotime($row['last_vote_at']);
        if ($elapsed >= 0 && $elapsed < $seconds) {
            json_response(
                array(
                    'error' => 'Please wait a moment before voting again.',
                    'retry_after' => $seconds - $elapsed,
                ),
                429
            );
        }
    }

    $upsert = $pdo->prepare(
        'INSERT INTO vote_limits (voter_token, last_vote_at)
         VALUES (:token, NOW())
         ON DUPLICATE KEY UPDATE last_vote_at = NOW()'
    );
    $upsert->execute(array('token' => $token));
}
