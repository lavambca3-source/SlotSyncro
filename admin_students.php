<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_user_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$success = $error = '';

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$id]);
    $success = "Student deleted successfully.";
}

// Handle Add Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $dept = trim($_POST['department']);
    $pass = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);

    if ($name && $email && $dept && $_POST['password']) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, department) VALUES (?, ?, ?, 'student', ?)");
        try {
            $stmt->execute([$name, $email, $pass, $dept]);
            $success = "Student added successfully.";
        } catch (PDOException $e) {
            $error = "Email already exists.";
        }
    } else {
        $error = "All fields are required.";
    }
}

// Fetch students (with booking count)
$search = trim($_GET['search'] ?? '');
if ($search) {
    $like = "%$search%";
    $stmt = $pdo->prepare("
        SELECT u.*, COUNT(s.id) as booking_count
        FROM users u
        LEFT JOIN slots s ON s.student_id = u.id AND s.status = 'booked'
        WHERE u.role = 'student' AND (u.name LIKE ? OR u.email LIKE ? OR u.department LIKE ?)
        GROUP BY u.id ORDER BY u.created_at DESC
    ");
    $stmt->execute([$like, $like, $like]);
} else {
    $stmt = $pdo->query("
        SELECT u.*, COUNT(s.id) as booking_count
        FROM users u
        LEFT JOIN slots s ON s.student_id = u.id AND s.status = 'booked'
        WHERE u.role = 'student'
        GROUP BY u.id ORDER BY u.created_at DESC
    ");
}
$students = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - SlotSyncro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --sidebar-width: 250px; --admin-primary: #ef4444; --admin-bg: #f8fafc; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--admin-bg); display: flex; min-height: 100vh; }

        .sidebar { width: var(--sidebar-width); background: #1e293b; color: white; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid #334155; display: flex; align-items: center; gap: 0.75rem; }
        .sidebar-header h2 { font-size: 1.1rem; font-weight: 700; color: white; }
        .sidebar-logo { width: 32px; height: 32px; background: var(--admin-primary); border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .nav-menu { padding: 1.5rem 0; flex: 1; display: flex; flex-direction: column; gap: 0.25rem; }
        .nav-item { display: flex; align-items: center; padding: 0.75rem 1.5rem; color: #cbd5e1; text-decoration: none; transition: all 0.2s; gap: 0.75rem; font-weight: 500; font-size: 0.9rem; }
        .nav-item:hover, .nav-item.active { background: #334155; color: white; border-left: 4px solid var(--admin-primary); }
        .logout-mt { margin-top: auto; border-top: 1px solid #334155; padding: 1rem 0; }
        .nav-item.logout { color: #ef4444; }
        .nav-item.logout:hover { background: rgba(239,68,68,0.1); }

        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; }
        .top-navbar { background: white; height: 70px; display: flex; align-items: center; justify-content: space-between; padding: 0 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 90; }
        .navbar-brand { font-weight: 700; font-size: 1.1rem; color: #0f172a; }
        .admin-profile { display: flex; align-items: center; gap: 0.75rem; background: #f1f5f9; padding: 0.5rem 1rem; border-radius: 2rem; font-weight: 500; color: #334155; }
        .admin-avatar { width: 32px; height: 32px; background: var(--admin-primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.875rem; }

        .page-body { padding: 2rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .page-title { font-size: 1.75rem; font-weight: 700; color: #0f172a; }
        .btn { padding: 0.6rem 1.25rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.875rem; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-primary { background: var(--admin-primary); color: white; }
        .btn-primary:hover { background: #dc2626; }
        .btn-danger { background: #fee2e2; color: #dc2626; }
        .btn-danger:hover { background: #fecaca; }
        .btn-secondary { background: #f1f5f9; color: #475569; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-sm { padding: 0.4rem 0.75rem; font-size: 0.8rem; }

        .alert { padding: 0.875rem 1.25rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 500; font-size: 0.9rem; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .search-bar { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; }
        .search-bar input { flex: 1; max-width: 360px; padding: 0.6rem 1rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-family: 'Inter', sans-serif; font-size: 0.9rem; outline: none; transition: border 0.2s; }
        .search-bar input:focus { border-color: var(--admin-primary); }

        .card { background: white; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; overflow: hidden; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 1rem 1.25rem; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .table th { font-weight: 600; color: #64748b; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; background: #f8fafc; }
        .table tbody tr:hover { background: #f8fafc; }
        .table td { color: #334155; font-size: 0.9rem; }
        .avatar-cell { display: flex; align-items: center; gap: 0.75rem; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #10b981; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.875rem; flex-shrink: 0; }
        .dept-badge { background: #d1fae5; color: #065f46; padding: 0.2rem 0.6rem; border-radius: 1rem; font-size: 0.75rem; font-weight: 600; }
        .booking-badge { background: #ede9fe; color: #5b21b6; padding: 0.2rem 0.6rem; border-radius: 1rem; font-size: 0.75rem; font-weight: 700; }
        .empty-state { text-align: center; padding: 3rem; color: #64748b; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal { background: white; border-radius: 1rem; padding: 2rem; width: 100%; max-width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: modalIn 0.2s ease; }
        @keyframes modalIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-weight: 600; color: #374151; font-size: 0.875rem; margin-bottom: 0.4rem; }
        .form-control { width: 100%; padding: 0.65rem 0.875rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-family: 'Inter', sans-serif; font-size: 0.9rem; outline: none; transition: border 0.2s; }
        .form-control:focus { border-color: var(--admin-primary); }
        .modal-footer { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo"><i data-lucide="shield" style="width:18px;height:18px;color:white;"></i></div>
        <h2>Admin Panel</h2>
    </div>
    <nav class="nav-menu">
        <a href="admin_dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="admin_teachers.php" class="nav-item"><i data-lucide="users"></i> Teachers</a>
        <a href="admin_students.php" class="nav-item active"><i data-lucide="graduation-cap"></i> Students</a>
        <a href="admin_bookings.php" class="nav-item"><i data-lucide="calendar-check"></i> Bookings</a>
        <a href="admin_notifications.php" class="nav-item"><i data-lucide="bell"></i> Notifications</a>
    </nav>
    <div class="logout-mt">
        <a href="logout.php?role=admin" class="nav-item logout"><i data-lucide="log-out"></i> Logout</a>
    </div>
</aside>

<main class="main-content">
    <header class="top-navbar">
        <div class="navbar-brand">SlotSyncro Admin</div>
        <div class="admin-profile">
            <div class="admin-avatar"><?php echo strtoupper(substr($_SESSION['admin_name'], 0, 1)); ?></div>
            <span><?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
        </div>
    </header>

    <div class="page-body">
        <div class="page-header">
            <h1 class="page-title">Manage Students</h1>
            <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('active')">
                <i data-lucide="plus" style="width:16px;height:16px;"></i> Add Student
            </button>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>

        <form class="search-bar" method="GET">
            <input type="text" name="search" placeholder="Search by name, email, department..." value="<?php echo htmlspecialchars($search); ?>">
            <button class="btn btn-primary" type="submit"><i data-lucide="search" style="width:15px;height:15px;"></i> Search</button>
            <?php if ($search): ?><a href="admin_students.php" class="btn btn-secondary">Clear</a><?php endif; ?>
        </form>

        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Bookings</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($students) > 0): ?>
                        <?php foreach ($students as $i => $s): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <div class="avatar-cell">
                                    <div class="avatar"><?php echo strtoupper(substr($s['name'],0,1)); ?></div>
                                    <span><?php echo htmlspecialchars($s['name']); ?></span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($s['email']); ?></td>
                            <td><span class="dept-badge"><?php echo htmlspecialchars($s['department'] ?? 'N/A'); ?></span></td>
                            <td><span class="booking-badge"><?php echo $s['booking_count']; ?> bookings</span></td>
                            <td><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                            <td>
                                <a href="?delete=<?php echo $s['id']; ?>" class="btn btn-danger btn-sm"
                                   onclick="return confirm('Delete this student?')">
                                   <i data-lucide="trash-2" style="width:13px;height:13px;"></i> Delete
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7">
                            <div class="empty-state">
                                <i data-lucide="graduation-cap" style="width:48px;height:48px;opacity:0.3;display:block;margin:0 auto 1rem;"></i>
                                <p>No students found.</p>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Add Student Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <h2 class="modal-title">Add New Student</h2>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" placeholder="John Doe" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="john@example.com" required>
            </div>
            <div class="form-group">
                <label class="form-label">Department</label>
                <input type="text" name="department" class="form-control" placeholder="Computer Science" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Set initial password" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('addModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="add_student" class="btn btn-primary">Add Student</button>
            </div>
        </form>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
</body>
</html>
