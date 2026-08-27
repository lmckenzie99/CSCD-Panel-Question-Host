<?php

require __DIR__ . '/bootstrap.php';

require_method('POST');
require_moderator();

$input = json_input();
$id = isset($input['id']) ? (int) $input['id'] : 0;

if ($id < 1) {
    json_response(array('error' => 'Question id is required.'), 400);
}

$pdo = db();

if (array_key_exists('visible', $input)) {
    $visible = $input['visible'] ? 1 : 0;
    $sql = $visible
        ? "UPDATE questions SET visible = 1 WHERE id = :id AND status = 'pending'"
        : "UPDATE questions SET visible = 0, is_current = 0 WHERE id = :id AND status = 'pending'";
    $update = $pdo->prepare($sql);
    $update->execute(array('id' => $id));
    if ($update->rowCount() < 1) {
        json_response(array('error' => 'Question not found or already handled.'), 404);
    }
    json_response(array('ok' => true));
}

if (array_key_exists('current', $input)) {
    if ($input['current']) {
        $pdo->beginTransaction();
        $pdo->exec('UPDATE questions SET is_current = 0');
        $update = $pdo->prepare(
            "UPDATE questions
             SET is_current = 1, visible = 1
             WHERE id = :id AND status = 'pending'"
        );
        $update->execute(array('id' => $id));
        if ($update->rowCount() < 1) {
            $pdo->rollBack();
            json_response(array('error' => 'Question not found or already handled.'), 404);
        }
        $pdo->commit();
    } else {
        $update = $pdo->prepare('UPDATE questions SET is_current = 0 WHERE id = :id');
        $update->execute(array('id' => $id));
    }
    json_response(array('ok' => true));
}

$status = isset($input['status']) ? (string) $input['status'] : '';

if ($status === 'pending') {
    $update = $pdo->prepare(
        "UPDATE questions
         SET status = 'pending', visible = 0, is_current = 0
         WHERE id = :id AND status IN ('asked', 'dismissed')"
    );
    $update->execute(array('id' => $id));
    if ($update->rowCount() < 1) {
        json_response(array('error' => 'Question not found or is already pending.'), 404);
    }
    json_response(array('ok' => true));
}

if ($status !== 'asked' && $status !== 'dismissed') {
    json_response(array('error' => 'Provide status, visible, or current.'), 400);
}

$update = $pdo->prepare(
    "UPDATE questions
     SET status = :status, visible = 0, is_current = 0
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
