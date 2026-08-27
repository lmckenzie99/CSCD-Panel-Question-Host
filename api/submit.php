<?php

require __DIR__ . '/bootstrap.php';

require_method('POST');

$MAX_NAME = 100;
$MAX_BODY = 1000;
$COOLDOWN_SECONDS = 10;

$input = json_input();
$name = isset($input['name']) ? trim((string) $input['name']) : '';
$body = isset($input['body']) ? trim((string) $input['body']) : '';

if ($name === '') {
    $name = null;
} elseif (app_strlen($name) > $MAX_NAME) {
    json_response(array('error' => 'Name is too long.'), 400);
}

if ($body === '') {
    json_response(array('error' => 'Please enter a question.'), 400);
}

if (app_strlen($body) > $MAX_BODY) {
    json_response(array('error' => 'Question is too long.'), 400);
}

$ipHash = client_ip_hash();
$pdo = db();

$recent = $pdo->prepare(
    'SELECT created_at FROM questions
     WHERE ip_hash = :ip_hash
     ORDER BY id DESC
     LIMIT 1'
);
$recent->execute(array('ip_hash' => $ipHash));
$last = $recent->fetch();

if ($last) {
    $elapsed = time() - strtotime($last['created_at']);
    if ($elapsed >= 0 && $elapsed < $COOLDOWN_SECONDS) {
        json_response(
            array(
                'error' => 'Please wait a few seconds before sending another question.',
                'retry_after' => $COOLDOWN_SECONDS - $elapsed,
            ),
            429
        );
    }
}

$insert = $pdo->prepare(
    'INSERT INTO questions (name, body, status, ip_hash)
     VALUES (:name, :body, :status, :ip_hash)'
);
$insert->execute(array(
    'name' => $name,
    'body' => $body,
    'status' => 'pending',
    'ip_hash' => $ipHash,
));

json_response(array(
    'ok' => true,
    'id' => (int) $pdo->lastInsertId(),
), 201);
