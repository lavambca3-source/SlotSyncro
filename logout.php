<?php
session_start();
$role = $_GET['role'] ?? '';

if ($role === 'admin') {
    unset($_SESSION['admin_user_id']);
    unset($_SESSION['admin_name']);
    unset($_SESSION['admin_role']);
    header("Location: admin_login.php");
} elseif ($role === 'student') {
    unset($_SESSION['student_user_id']);
    unset($_SESSION['student_name']);
    unset($_SESSION['student_role']);
    header("Location: login.php");
} elseif ($role === 'teacher') {
    unset($_SESSION['teacher_user_id']);
    unset($_SESSION['teacher_name']);
    unset($_SESSION['teacher_role']);
    header("Location: login.php");
} else {
    session_unset();
    session_destroy();
    header("Location: index.html");
}
exit();
?>
