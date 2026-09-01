<?php

require __DIR__ . '/bootstrap.php';

require_method('GET');
require_moderator();

$pdo = db();
$rows = $pdo->query(
    "SELECT q.id, q.name, q.body, q.status, q.created_at, q.visible, q.is_current,
            (SELECT COALESCE(SUM(v.value), 0) FROM votes v WHERE v.question_id = q.id) AS vote_count
     FROM questions q
     ORDER BY CASE q.status WHEN 'pending' THEN 0 ELSE 1 END ASC,
              CASE q.status WHEN 'pending' THEN q.created_at END ASC,
              q.created_at DESC"
)->fetchAll();

$pending = array();
$handled = array();

foreach ($rows as $row) {
    $item = question_payload($row);
    if ($row['status'] === 'pending') {
        $pending[] = $item;
    } else {
        $handled[] = $item;
    }
}

json_response(array(
    'pending' => $pending,
    'handled' => $handled,
));
