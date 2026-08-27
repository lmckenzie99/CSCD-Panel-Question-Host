<?php

require __DIR__ . '/bootstrap.php';

require_method('POST');

$input = json_input();
$id = isset($input['id']) ? (int) $input['id'] : 0;
if ($id < 1) {
    json_response(array('error' => 'Question id is required.'), 400);
}

$token = voter_token();
$pdo = db();

$check = $pdo->prepare(
    "SELECT id FROM questions WHERE id = :id AND visible = 1 AND status = 'pending'"
);
$check->execute(array('id' => $id));
if (!$check->fetch()) {
    json_response(array('error' => 'Question is not on the wall.'), 404);
}

enforce_vote_cooldown($pdo, $token);

$existing = $pdo->prepare(
    'SELECT id FROM votes WHERE question_id = :id AND voter_token = :token'
);
$existing->execute(array('id' => $id, 'token' => $token));
$row = $existing->fetch();

if ($row) {
    $delete = $pdo->prepare('DELETE FROM votes WHERE id = :vote_id');
    $delete->execute(array('vote_id' => $row['id']));
    $voted = false;
} else {
    $insert = $pdo->prepare(
        'INSERT INTO votes (question_id, voter_token) VALUES (:id, :token)'
    );
    $insert->execute(array('id' => $id, 'token' => $token));
    $voted = true;
}

$count = $pdo->prepare('SELECT COUNT(*) AS n FROM votes WHERE question_id = :id');
$count->execute(array('id' => $id));
$n = $count->fetch();

json_response(array(
    'ok' => true,
    'voted' => $voted,
    'vote_count' => (int) $n['n'],
));
