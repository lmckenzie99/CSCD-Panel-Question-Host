<?php

require __DIR__ . '/bootstrap.php';

require_method('GET');
require_moderator();

$pdo = db();
$rows = $pdo->query(
    "SELECT id, name, body, status, created_at
     FROM questions
     ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END ASC,
              CASE status WHEN 'pending' THEN created_at END ASC,
              created_at DESC"
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
