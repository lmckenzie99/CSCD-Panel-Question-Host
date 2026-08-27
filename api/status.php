<?php

require __DIR__ . '/bootstrap.php';

require_method('POST');
require_moderator();

$input = json_input();
$id = isset($input['id']) ? (int) $input['id'] : 0;
$status = isset($input['status']) ? (string) $input['status'] : '';

if ($id < 1) {
    json_response(array('error' => 'Question id is required.'), 400);
}

if ($status !== 'asked' && $status !== 'dismissed') {
    json_response(array('error' => 'Status must be asked or dismissed.'), 400);
}

$pdo = db();
$update = $pdo->prepare(
    "UPDATE questions
     SET status = :status
     WHERE id = :id AND status = 'pending'"
);
$update->execute(array(
    'status' => $status,
    'id' => $id,
));

if ($update->rowCount() < 1) {
    json_response(array('error' => 'Question not found or already handled.'), 404);
}

json_response(array('ok' => true));
