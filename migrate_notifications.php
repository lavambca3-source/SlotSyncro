<?php
require_once 'db.php';
try {
    // Attempt to rename the column
    $pdo->exec("ALTER TABLE notifications CHANGE student_id user_id INT NOT NULL");
    echo "Column renamed successfully.\n";
} catch (PDOException $e) {
    // Ignore error if it's already renamed
    echo "Error or already renamed: " . $e->getMessage() . "\n";
}
?>
