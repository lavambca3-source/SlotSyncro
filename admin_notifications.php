<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_user_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$success = $error = '';

// Handle Send Notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notif'])) {
    $target = $_POST['target']; // 'all', 'all_teachers', or user id
    $message = trim($_POST['message']);

    if (!$message) {
        $error = "Message cannot be empty.";
    } else {
        if ($target === 'all') {
            $users = $pdo->query("SELECT id FROM users WHERE role IN ('student','teacher')")->fetchAll();
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            foreach ($users as $u) { $stmt->execute([$u['id'], $message]); }
            $success = "Notification sent to all users.";
        } elseif ($target === 'all_students') {
            $users = $pdo->query("SELECT id FROM users WHERE role='student'")->fetchAll();
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            foreach ($users as $u) { $stmt->execute([$u['id'], $message]); }
            $success = "Notification sent to all students.";
        } elseif ($target === 'all_teachers') {
            $users = $pdo->query("SELECT id FROM users WHERE role='teacher'")->fetchAll();
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            foreach ($users as $u) { $stmt->execute([$u['id'], $message]); }
            $success = "Notification sent to all teachers.";
        } else {
            $uid = (int)$target;
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $stmt->execute([$uid, $message]);
            $success = "Notification sent.";
        }
    }
}

// Handle Delete Notification
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM notifications WHERE id=?")->execute([$id]);
    $success = "Notification deleted.";
}

// Handle Mark All Read (optional admin utility)
if (isset($_GET['mark_read'])) {
    $pdo->query("UPDATE notifications SET is_read=1");
    $success = "All notifications marked as read.";
}

// Fetch all students + teachers for dropdown
$students_list = $pdo->query("SELECT id, name, email, role FROM users WHERE role IN ('student','teacher') ORDER BY role, name")->fetchAll();

// Fetch notifications
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$where = [];
$params = [];

if ($search) {
    $where[] = "(n.message LIKE ? OR u.name LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like;
}
if ($filter === 'unread') { $where[] = "n.is_read = 0"; }
if ($filter === 'read') { $where[] = "n.is_read = 1"; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("
    SELECT n.*, u.name as student_name, u.email as student_email, u.role as user_role
    FROM notifications n
    JOIN users u ON n.user_id = u.id
    $where_sql
    ORDER BY n.created_at DESC
");
$stmt->execute($params);
$notifs = $stmt->fetchAll();

$total_notifs = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$unread_notifs = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
$read_notifs   = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read=1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - SlotSyncro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --sidebar-width: 250px; --admin-primary: #ef4444; --admin-bg: #f8fafc; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--admin-bg); display: flex; min-height: 100vh; }

        .sidebar { width: var(--sidebar-width); background: #1e293b; color: white; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid #334155; display: flex; align-items: center; gap: 0.75rem; }
        .sidebar-header h2 { font-size: 1.1rem; font-weight: 700; }
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
        .btn-secondary { background: #f1f5f9; color: #475569; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger { background: #fee2e2; color: #dc2626; }
        .btn-danger:hover { background: #fecaca; }
        .btn-sm { padding: 0.35rem 0.65rem; font-size: 0.78rem; }

        .alert { padding: 0.875rem 1.25rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 500; font-size: 0.9rem; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Compose Card */
        .compose-card { background: white; border-radius: 1rem; border: 1px solid #e2e8f0; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .compose-title { font-size: 1rem; font-weight: 700; color: #0f172a; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; }
        .compose-grid { display: grid; grid-template-columns: 1fr 2fr auto; gap: 0.75rem; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 0.35rem; }
        .form-label { font-weight: 600; color: #374151; font-size: 0.8rem; }
        .form-control { padding: 0.65rem 0.875rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-family: 'Inter', sans-serif; font-size: 0.875rem; outline: none; transition: border 0.2s; width: 100%; }
        .form-control:focus { border-color: var(--admin-primary); }
        select.form-control { cursor: pointer; }

        /* Stats Row */
        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-mini { background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1rem 1.25rem; display: flex; align-items: center; gap: 0.75rem; }
        .stat-mini-icon { width: 40px; height: 40px; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; }
        .icon-total { background: #64748b; }
        .icon-unread { background: #f59e0b; }
        .icon-read { background: #10b981; }
        .stat-mini-info .label { font-size: 0.75rem; color: #64748b; font-weight: 500; }
        .stat-mini-info .value { font-size: 1.25rem; font-weight: 700; color: #0f172a; }

        /* Filters */
        .filters-bar { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; align-items: center; }
        .filters-bar input { padding: 0.6rem 1rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-family: 'Inter', sans-serif; font-size: 0.875rem; outline: none; min-width: 220px; }
        .filters-bar input:focus { border-color: var(--admin-primary); }
        .filter-tabs { display: flex; gap: 0.5rem; }
        .filter-tab { padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; text-decoration: none; color: #64748b; background: white; border: 1.5px solid #e2e8f0; transition: all 0.2s; }
        .filter-tab:hover { background: #f1f5f9; }
        .filter-tab.tab-active { background: #0f172a; color: white; border-color: #0f172a; }
        .filter-tab.tab-unread { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .filter-tab.tab-read { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }

        .card { background: white; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; overflow: hidden; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 0.875rem 1.25rem; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .table th { font-weight: 600; color: #64748b; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.05em; background: #f8fafc; }
        .table tbody tr:hover { background: #f8fafc; }
        .table td { color: #334155; font-size: 0.875rem; }
        .table tbody tr.unread-row { background: #fffbeb; }

        .read-badge { padding: 0.25rem 0.65rem; border-radius: 1rem; font-size: 0.72rem; font-weight: 600; }
        .badge-unread { background: #fef3c7; color: #92400e; }
        .badge-read { background: #d1fae5; color: #065f46; }
        .msg-text { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .avatar-sm { width: 30px; height: 30px; border-radius: 50%; background: #8b5cf6; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; flex-shrink: 0; }
        .user-cell { display: flex; align-items: center; gap: 0.6rem; }
        .empty-state { text-align: center; padding: 3rem; color: #64748b; }

        @media (max-width: 900px) {
            .compose-grid { grid-template-columns: 1fr; }
        }
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
        <a href="admin_students.php" class="nav-item"><i data-lucide="graduation-cap"></i> Students</a>
        <a href="admin_bookings.php" class="nav-item"><i data-lucide="calendar-check"></i> Bookings</a>
        <a href="admin_notifications.php" class="nav-item active"><i data-lucide="bell"></i> Notifications</a>
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
            <h1 class="page-title">Notifications</h1>
            <?php if ($unread_notifs > 0): ?>
            <a href="?mark_read=1" class="btn btn-secondary">
                <i data-lucide="check-check" style="width:15px;height:15px;"></i> Mark All Read
            </a>
            <?php endif; ?>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>

        <!-- Compose Notification -->
        <div class="compose-card">
            <div class="compose-title">
                <i data-lucide="send" style="width:18px;height:18px;color:var(--admin-primary);"></i>
                Send Notification
            </div>
            <form method="POST">
                <div class="compose-grid">
                    <div class="form-group">
                        <label class="form-label">Send To</label>
                        <select name="target" class="form-control" required>
                            <option value="all">📢 Everyone (All Users)</option>
                            <option value="all_students">🎓 All Students</option>
                            <option value="all_teachers">🏫 All Teachers</option>
                            <?php
                            $current_role = '';
                            foreach ($students_list as $s):
                                if ($s['role'] !== $current_role) {
                                    if ($current_role !== '') echo '</optgroup>';
                                    $current_role = $s['role'];
                                    echo '<optgroup label="' . ucfirst($s['role']) . 's">';
                                }
                            ?>
                            <option value="<?php echo $s['id']; ?>">👤 <?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                            <?php if ($current_role !== '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <input type="text" name="message" class="form-control" placeholder="Type your notification message..." required>
                    </div>
                    <button type="submit" name="send_notif" class="btn btn-primary">
                        <i data-lucide="send" style="width:15px;height:15px;"></i> Send
                    </button>
                </div>
            </form>
        </div>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-mini">
                <div class="stat-mini-icon icon-total"><i data-lucide="bell" style="width:18px;height:18px;"></i></div>
                <div class="stat-mini-info"><div class="label">Total</div><div class="value"><?php echo $total_notifs; ?></div></div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon icon-unread"><i data-lucide="bell-ring" style="width:18px;height:18px;"></i></div>
                <div class="stat-mini-info"><div class="label">Unread</div><div class="value"><?php echo $unread_notifs; ?></div></div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon icon-read"><i data-lucide="check-circle" style="width:18px;height:18px;"></i></div>
                <div class="stat-mini-info"><div class="label">Read</div><div class="value"><?php echo $read_notifs; ?></div></div>
            </div>
        </div>

        <!-- Filters -->
        <form class="filters-bar" method="GET">
            <input type="text" name="search" placeholder="Search message or student..." value="<?php echo htmlspecialchars($search); ?>">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
            <button class="btn btn-primary" type="submit"><i data-lucide="search" style="width:15px;height:15px;"></i> Search</button>
            <div class="filter-tabs">
                <a href="?filter=all&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter==='all'?'tab-active':''; ?>">All</a>
                <a href="?filter=unread&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter==='unread'?'tab-unread':''; ?>">Unread</a>
                <a href="?filter=read&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter==='read'?'tab-read':''; ?>">Read</a>
            </div>
        </form>

        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Sent At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($notifs) > 0): ?>
                        <?php foreach ($notifs as $i => $n): ?>
                        <tr class="<?php echo !$n['is_read'] ? 'unread-row' : ''; ?>">
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <div class="user-cell">
                                    <div class="avatar-sm"><?php echo strtoupper(substr($n['student_name'],0,1)); ?></div>
                                    <div>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($n['student_name']); ?></div>
                                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars($n['student_email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><div class="msg-text" title="<?php echo htmlspecialchars($n['message']); ?>"><?php echo htmlspecialchars($n['message']); ?></div></td>
                            <td>
                                <span class="read-badge <?php echo $n['is_read'] ? 'badge-read' : 'badge-unread'; ?>">
                                    <?php echo $n['is_read'] ? 'Read' : 'Unread'; ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y H:i', strtotime($n['created_at'])); ?></td>
                            <td>
                                <a href="?delete=<?php echo $n['id']; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Delete this notification?')">
                                   <i data-lucide="trash-2" style="width:12px;height:12px;"></i> Delete
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6">
                            <div class="empty-state">
                                <i data-lucide="bell-off" style="width:48px;height:48px;opacity:0.3;display:block;margin:0 auto 1rem;"></i>
                                <p>No notifications found.</p>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
</body>
</html>
