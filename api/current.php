<?php

require __DIR__ . '/bootstrap.php';

require_method('GET');

$pdo = db();
$row = $pdo->query(
    "SELECT q.id, q.name, q.body, q.status, q.created_at, q.visible, q.is_current,
            (SELECT COALESCE(SUM(v.value), 0) FROM votes v WHERE v.question_id = q.id) AS vote_count
     FROM questions q
     WHERE q.is_current = 1 AND q.status = 'pending'
     LIMIT 1"
)->fetch();

json_response(array(
    'question' => $row ? question_payload($row) : null,
));
