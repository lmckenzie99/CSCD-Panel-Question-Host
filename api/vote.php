<?php

require __DIR__ . '/bootstrap.php';

require_method('POST');

$input = json_input();
$id = isset($input['id']) ? (int) $input['id'] : 0;
if ($id < 1) {
    json_response(array('error' => 'Question id is required.'), 400);
}

$value = isset($input['value']) ? (int) $input['value'] : 1;
if ($value !== 1 && $value !== -1) {
    json_response(array('error' => 'Vote value must be 1 or -1.'), 400);
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
    'SELECT id, value FROM votes WHERE question_id = :id AND voter_token = :token'
);
$existing->execute(array('id' => $id, 'token' => $token));
$row = $existing->fetch();
$myVote = 0;

if (!$row) {
    $insert = $pdo->prepare(
        'INSERT INTO votes (question_id, voter_token, value) VALUES (:id, :token, :value)'
    );
    $insert->execute(array('id' => $id, 'token' => $token, 'value' => $value));
    $myVote = $value;
} elseif ((int) $row['value'] === $value) {
    $delete = $pdo->prepare('DELETE FROM votes WHERE id = :vote_id');
    $delete->execute(array('vote_id' => $row['id']));
} else {
    $update = $pdo->prepare('UPDATE votes SET value = :value WHERE id = :vote_id');
    $update->execute(array('value' => $value, 'vote_id' => $row['id']));
    $myVote = $value;
}

$state = $pdo->prepare(
    'SELECT COALESCE(SUM(value), 0) AS score FROM votes WHERE question_id = :id'
);
$state->execute(array('id' => $id));
$totals = $state->fetch();

json_response(array(
    'ok' => true,
    'my_vote' => $myVote,
    'vote_count' => (int) $totals['score'],
));
