<?php
session_start();
require_once 'db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $department = isset($_POST['department']) ? trim($_POST['department']) : null;
    
    if (empty($name) || empty($email) || empty($password) || empty($role)) {
        $error = "All fields are required.";
    } elseif (!in_array($role, ['student', 'teacher'])) {
        $error = "Invalid role selected.";
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email already registered.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, department) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $email, $hashedPassword, $role, $role === 'teacher' ? $department : null])) {
                $success = "Registration successful! You can now <a href='login.php'>login</a>.";
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}

// Pre-select role if passed via URL
$defaultRole = isset($_GET['role']) && $_GET['role'] === 'teacher' ? 'teacher' : 'student';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - SlotSyncro</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .auth-container { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; background: linear-gradient(135deg, #eef2ff 0%, #f8fafc 100%); }
        .auth-card { background: var(--surface); padding: 3rem; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); width: 100%; max-width: 450px; border: 1px solid var(--border); }
        .auth-card h2 { margin-bottom: 2rem; text-align: center; font-size: 2rem; color: var(--text-main); }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-main); }
        .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: 0.5rem; font-family: inherit; font-size: 1rem; transition: border-color 0.2s; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        .btn-full { width: 100%; }
        .auth-links { margin-top: 1.5rem; text-align: center; font-size: 0.875rem; color: var(--text-muted); }
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-weight: 500; }
        .alert-error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #f87171; }
        .alert-success { background-color: #d1fae5; color: #047857; border: 1px solid #34d399; }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <h2>Join SlotSyncro</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php else: ?>
                <form method="POST" action="register.php">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="role">I am a...</label>
                        <select id="role" name="role" class="form-control" required onchange="toggleDepartment()">
                            <option value="student" <?php echo $defaultRole === 'student' ? 'selected' : ''; ?>>Student</option>
                            <option value="teacher" <?php echo $defaultRole === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                        </select>
                    </div>
                    <div class="form-group" id="department-group" style="<?php echo $defaultRole === 'teacher' ? 'display:block;' : 'display:none;'; ?>">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" class="form-control" placeholder="e.g. Computer Science">
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">Create Account</button>
                </form>
            <?php endif; ?>
            
            <div class="auth-links">
                Already have an account? <a href="login.php">Log in</a>
            </div>
            <div class="auth-links" style="margin-top: 0.5rem;">
                <a href="index.php">← Back to Home</a>
            </div>
        </div>
    </div>
    <script>
        function toggleDepartment() {
            const role = document.getElementById('role').value;
            const deptGroup = document.getElementById('department-group');
            const deptInput = document.getElementById('department');
            if (role === 'teacher') {
                deptGroup.style.display = 'block';
                deptInput.setAttribute('required', 'required');
            } else {
                deptGroup.style.display = 'none';
                deptInput.removeAttribute('required');
            }
        }
        // Run once on load to ensure correct state based on default selection
        toggleDepartment();
    </script>
</body>
</html>
