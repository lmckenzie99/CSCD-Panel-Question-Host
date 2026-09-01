<?php

require __DIR__ . '/bootstrap.php';

require_method('GET');

$token = voter_token();
$pdo = db();

$stmt = $pdo->prepare(
    "SELECT q.id, q.name, q.body, q.status, q.created_at, q.visible, q.is_current,
            (SELECT COALESCE(SUM(v.value), 0) FROM votes v WHERE v.question_id = q.id) AS vote_count,
            (SELECT COALESCE(SUM(v2.value), 0) FROM votes v2
              WHERE v2.question_id = q.id AND v2.voter_token = :token) AS my_vote
     FROM questions q
     WHERE q.visible = 1 AND q.status = 'pending'
     ORDER BY vote_count DESC, q.created_at ASC"
);
$stmt->execute(array('token' => $token));

$questions = array();
foreach ($stmt->fetchAll() as $row) {
    $questions[] = question_payload($row);
}

json_response(array('questions' => $questions));
