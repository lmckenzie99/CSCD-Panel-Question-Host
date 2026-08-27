<?php

require __DIR__ . '/bootstrap.php';

require_method('POST');
start_app_session();

$input = json_input();
$password = isset($input['password']) ? (string) $input['password'] : '';

if ($password === '') {
    json_response(array('error' => 'Password is required.'), 400);
}

$config = app_config();
$hash = isset($config['moderator_password_hash']) ? $config['moderator_password_hash'] : '';

if ($hash === '' || $hash === 'PASTE_PASSWORD_HASH_HERE' || !password_verify($password, $hash)) {
    json_response(array('error' => 'Incorrect password.'), 401);
}

$_SESSION['moderator'] = true;

json_response(array('ok' => true));
