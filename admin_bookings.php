<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$success = $error = '';

// Handle Cancel Booking
if (isset($_GET['cancel'])) {
    $id = (int)$_GET['cancel'];
    $stmt = $pdo->prepare("UPDATE slots SET status='available', student_id=NULL WHERE id=? AND status='booked'");
    $stmt->execute([$id]);
    $success = "Booking cancelled successfully.";
}

// Filters
$filter_status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = [];
$params = [];

if ($filter_status !== 'all') {
    $where[] = "s.status = ?";
    $params[] = $filter_status;
}
if ($search) {
    $where[] = "(t.name LIKE ? OR st.name LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT s.id, s.slot_date, s.start_time, s.end_time, s.status, s.created_at,
           t.name as teacher_name, t.department as teacher_dept,
           st.name as student_name, st.email as student_email
    FROM slots s
    JOIN users t ON s.teacher_id = t.id
    LEFT JOIN users st ON s.student_id = st.id
    $where_sql
    ORDER BY s.slot_date DESC, s.start_time DESC
");
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Counts
$total = $pdo->query("SELECT COUNT(*) FROM slots")->fetchColumn();
$booked = $pdo->query("SELECT COUNT(*) FROM slots WHERE status='booked'")->fetchColumn();
$available = $pdo->query("SELECT COUNT(*) FROM slots WHERE status='available'")->fetchColumn();
$cancelled = $pdo->query("SELECT COUNT(*) FROM slots WHERE status='cancelled'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings - SlotSyncro Admin</title>
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
        .page-title { font-size: 1.75rem; font-weight: 700; color: #0f172a; margin-bottom: 1.5rem; }

        /* Stats Row */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-mini { background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1rem 1.25rem; display: flex; align-items: center; gap: 0.75rem; }
        .stat-mini-icon { width: 40px; height: 40px; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; }
        .icon-total { background: #64748b; }
        .icon-booked { background: #8b5cf6; }
        .icon-available { background: #10b981; }
        .icon-cancelled { background: #ef4444; }
        .stat-mini-info .label { font-size: 0.75rem; color: #64748b; font-weight: 500; }
        .stat-mini-info .value { font-size: 1.25rem; font-weight: 700; color: #0f172a; }

        /* Filters */
        .filters-bar { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap; align-items: center; }
        .filters-bar input { padding: 0.6rem 1rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-family: 'Inter', sans-serif; font-size: 0.875rem; outline: none; min-width: 220px; }
        .filters-bar input:focus { border-color: var(--admin-primary); }
        .filter-tabs { display: flex; gap: 0.5rem; }
        .filter-tab { padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; text-decoration: none; color: #64748b; background: white; border: 1.5px solid #e2e8f0; transition: all 0.2s; }
        .filter-tab:hover, .filter-tab.active { background: #0f172a; color: white; border-color: #0f172a; }
        .filter-tab.active-booked { background: #8b5cf6; color: white; border-color: #8b5cf6; }
        .filter-tab.active-available { background: #10b981; color: white; border-color: #10b981; }
        .filter-tab.active-cancelled { background: #ef4444; color: white; border-color: #ef4444; }

        /* Buttons */
        .btn { padding: 0.6rem 1.25rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.875rem; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-primary { background: var(--admin-primary); color: white; }
        .btn-primary:hover { background: #dc2626; }
        .btn-sm { padding: 0.35rem 0.65rem; font-size: 0.78rem; }
        .btn-cancel { background: #fee2e2; color: #dc2626; }
        .btn-cancel:hover { background: #fecaca; }
        .btn-secondary { background: #f1f5f9; color: #475569; }

        .alert { padding: 0.875rem 1.25rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 500; font-size: 0.9rem; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }

        .card { background: white; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; overflow: hidden; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 0.875rem 1.25rem; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .table th { font-weight: 600; color: #64748b; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.05em; background: #f8fafc; }
        .table tbody tr:hover { background: #f8fafc; }
        .table td { color: #334155; font-size: 0.875rem; }

        .status-badge { padding: 0.25rem 0.7rem; border-radius: 1rem; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem; }
        .status-booked { background: #ede9fe; color: #5b21b6; }
        .status-available { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }

        .user-cell { display: flex; flex-direction: column; }
        .user-name { font-weight: 600; color: #0f172a; }
        .user-sub { font-size: 0.78rem; color: #64748b; }

        .empty-state { text-align: center; padding: 3rem; color: #64748b; }
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
        <a href="admin_bookings.php" class="nav-item active"><i data-lucide="calendar-check"></i> Bookings</a>
        <a href="admin_notifications.php" class="nav-item"><i data-lucide="bell"></i> Notifications</a>
    </nav>
    <div class="logout-mt">
        <a href="logout.php" class="nav-item logout"><i data-lucide="log-out"></i> Logout</a>
    </div>
</aside>

<main class="main-content">
    <header class="top-navbar">
        <div class="navbar-brand">SlotSyncro Admin</div>
        <div class="admin-profile">
            <div class="admin-avatar"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
            <span><?php echo htmlspecialchars($_SESSION['name']); ?></span>
        </div>
    </header>

    <div class="page-body">
        <h1 class="page-title">Manage Bookings</h1>

        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-mini">
                <div class="stat-mini-icon icon-total"><i data-lucide="layers" style="width:18px;height:18px;"></i></div>
                <div class="stat-mini-info"><div class="label">Total Slots</div><div class="value"><?php echo $total; ?></div></div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon icon-booked"><i data-lucide="calendar-check" style="width:18px;height:18px;"></i></div>
                <div class="stat-mini-info"><div class="label">Booked</div><div class="value"><?php echo $booked; ?></div></div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon icon-available"><i data-lucide="calendar" style="width:18px;height:18px;"></i></div>
                <div class="stat-mini-info"><div class="label">Available</div><div class="value"><?php echo $available; ?></div></div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon icon-cancelled"><i data-lucide="x-circle" style="width:18px;height:18px;"></i></div>
                <div class="stat-mini-info"><div class="label">Cancelled</div><div class="value"><?php echo $cancelled; ?></div></div>
            </div>
        </div>

        <!-- Filters -->
        <form class="filters-bar" method="GET">
            <input type="text" name="search" placeholder="Search teacher or student..." value="<?php echo htmlspecialchars($search); ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
            <button class="btn btn-primary" type="submit"><i data-lucide="search" style="width:15px;height:15px;"></i> Search</button>
            <div class="filter-tabs">
                <?php
                $statuses = ['all' => 'All', 'booked' => 'Booked', 'available' => 'Available', 'cancelled' => 'Cancelled'];
                foreach ($statuses as $val => $label):
                    $isActive = $filter_status === $val;
                    $cls = 'filter-tab';
                    if ($isActive) $cls .= $val !== 'all' ? " active-$val" : ' active';
                ?>
                <a href="?status=<?php echo $val; ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $cls; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </div>
        </form>

        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date & Time</th>
                        <th>Teacher</th>
                        <th>Student</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($bookings) > 0): ?>
                        <?php foreach ($bookings as $i => $b): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <div class="user-cell">
                                    <span class="user-name"><?php echo date('M d, Y', strtotime($b['slot_date'])); ?></span>
                                    <span class="user-sub"><?php echo substr($b['start_time'],0,5) . ' – ' . substr($b['end_time'],0,5); ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="user-cell">
                                    <span class="user-name"><?php echo htmlspecialchars($b['teacher_name']); ?></span>
                                    <span class="user-sub"><?php echo htmlspecialchars($b['teacher_dept'] ?? ''); ?></span>
                                </div>
                            </td>
                            <td>
                                <?php if ($b['student_name']): ?>
                                <div class="user-cell">
                                    <span class="user-name"><?php echo htmlspecialchars($b['student_name']); ?></span>
                                    <span class="user-sub"><?php echo htmlspecialchars($b['student_email'] ?? ''); ?></span>
                                </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $st = $b['status'];
                                $cls = "status-$st";
                                $icons = ['booked' => 'check-circle', 'available' => 'clock', 'cancelled' => 'x-circle'];
                                ?>
                                <span class="status-badge <?php echo $cls; ?>">
                                    <i data-lucide="<?php echo $icons[$st] ?? 'circle'; ?>" style="width:12px;height:12px;"></i>
                                    <?php echo ucfirst($st); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($b['created_at'])); ?></td>
                            <td>
                                <?php if ($b['status'] === 'booked'): ?>
                                <a href="?cancel=<?php echo $b['id']; ?>&status=<?php echo $filter_status; ?>&search=<?php echo urlencode($search); ?>"
                                   class="btn btn-cancel btn-sm"
                                   onclick="return confirm('Cancel this booking?')">
                                   <i data-lucide="x" style="width:12px;height:12px;"></i> Cancel
                                </a>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7">
                            <div class="empty-state">
                                <i data-lucide="calendar-x" style="width:48px;height:48px;opacity:0.3;display:block;margin:0 auto 1rem;"></i>
                                <p>No bookings found.</p>
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
