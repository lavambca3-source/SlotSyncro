<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    echo json_encode(['count' => 0, 'notifications' => []]);
    exit();
}

$teacher_id = $_SESSION['user_id'];

// Fetch only unread notifications since the last poll
$stmt = $pdo->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$teacher_id]);
$notifs = $stmt->fetchAll();

// Format created_at
foreach ($notifs as &$n) {
    $n['created_at'] = date('M d, H:i', strtotime($n['created_at']));
}
unset($n);

// Mark them as read
$pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE")->execute([$teacher_id]);

echo json_encode(['count' => count($notifs), 'notifications' => $notifs]);
