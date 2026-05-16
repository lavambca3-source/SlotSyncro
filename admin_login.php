<?php
session_start();
require_once 'db.php';

// Redirect if already logged in as admin
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin_dashboard.php");
        exit();
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $pdo->prepare("SELECT id, name, password_hash, role FROM users WHERE email = ? AND role = 'admin'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            
            header("Location: admin_dashboard.php");
            exit();
        } else {
            $error = "Invalid admin credentials.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - SlotSyncro</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .auth-container { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); }
        .auth-card { background: var(--surface); padding: 3rem; border-radius: 1.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); width: 100%; max-width: 450px; border: 1px solid #334155; }
        .auth-card h2 { margin-bottom: 2rem; text-align: center; font-size: 2rem; color: var(--text-main); }
        .auth-card .admin-badge { display: inline-block; background: #ef4444; color: white; padding: 0.25rem 0.75rem; border-radius: 1rem; font-size: 0.875rem; font-weight: 600; margin-bottom: 1rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-main); }
        .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: 0.5rem; font-family: inherit; font-size: 1rem; transition: border-color 0.2s; }
        .form-control:focus { outline: none; border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1); }
        .btn-full { width: 100%; }
        .btn-admin { background-color: #ef4444; color: white; }
        .btn-admin:hover { background-color: #dc2626; }
        .auth-links { margin-top: 1.5rem; text-align: center; font-size: 0.875rem; color: var(--text-muted); }
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-weight: 500; }
        .alert-error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #f87171; }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div style="text-align: center;"><span class="admin-badge">Admin Portal</span></div>
            <h2>SlotSyncro Admin</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="admin_login.php">
                <div class="form-group">
                    <label for="email">Admin Email</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-admin btn-full" style="padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; border: none; cursor: pointer; transition: background-color 0.2s;">Secure Login</button>
            </form>
            
            <div class="auth-links" style="margin-top: 1.5rem;">
                <a href="index.html">← Back to Main Site</a>
            </div>
        </div>
    </div>
</body>
</html>
