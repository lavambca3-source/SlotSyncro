<?php
session_start();
require_once 'db.php';

// Auth check
if (!isset($_SESSION['teacher_user_id']) || $_SESSION['teacher_role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_user_id'];
$message = '';

// Fetch Department
$dept_stmt = $pdo->prepare("SELECT department FROM users WHERE id = ?");
$dept_stmt->execute([$teacher_id]);
$teacher_dept = $dept_stmt->fetchColumn();

// Add Slot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_slot') {
    $date       = $_POST['date'];
    $start_time = $_POST['start_time'];
    $end_time   = $_POST['end_time'];
    $subject    = trim($_POST['subject'] ?? '');
    $department = trim($_POST['department'] ?? '');
    
    if (!empty($date) && !empty($start_time) && !empty($end_time)) {
        $stmt = $pdo->prepare("INSERT INTO slots (teacher_id, slot_date, start_time, end_time, subject, department) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$teacher_id, $date, $start_time, $end_time, $subject ?: null, $department ?: null])) {
            $message = "Slot added successfully!";
        }
    }
}

// Bulk Add Slots
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_add_slot') {
    $start_date = $_POST['start_date'];
    $end_date   = $_POST['end_date'];
    $start_time = $_POST['start_time'];
    $end_time   = $_POST['end_time'];
    $duration   = (int)$_POST['duration'];
    $subject    = trim($_POST['subject'] ?? '');
    $department = trim($_POST['department'] ?? '');
    
    if (!empty($start_date) && !empty($end_date) && !empty($start_time) && !empty($end_time) && $duration > 0) {
        $current_date = strtotime($start_date);
        $end_date_ts = strtotime($end_date);
        
        $slots_added = 0;
        
        while ($current_date <= $end_date_ts) {
            $date_str = date('Y-m-d', $current_date);
            
            $current_time = strtotime($start_time);
            $end_time_ts = strtotime($end_time);
            
            while ($current_time + ($duration * 60) <= $end_time_ts) {
                $slot_start = date('H:i:s', $current_time);
                $current_time += ($duration * 60);
                $slot_end = date('H:i:s', $current_time);
                
                $stmt = $pdo->prepare("INSERT INTO slots (teacher_id, slot_date, start_time, end_time, subject, department) VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$teacher_id, $date_str, $slot_start, $slot_end, $subject ?: null, $department ?: null])) {
                    $slots_added++;
                }
            }
            $current_date = strtotime("+1 day", $current_date);
        }
        $message = "$slots_added bulk slots added successfully!";
    }
}

// Clear Today's Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_today') {
    $today = date('Y-m-d');
    
    // Find booked slots for today to notify students
    $stmt = $pdo->prepare("SELECT id, student_id, start_time, end_time FROM slots WHERE teacher_id = ? AND slot_date = ? AND status = 'booked'");
    $stmt->execute([$teacher_id, $today]);
    $booked_slots = $stmt->fetchAll();
    
    foreach ($booked_slots as $slot) {
        if ($slot['student_id']) {
            $msg = "Your booked slot on " . $today . " from " . $slot['start_time'] . " to " . $slot['end_time'] . " has been cancelled by the teacher.";
            $notify_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $notify_stmt->execute([$slot['student_id'], $msg]);
        }
    }
    
    // Update status
    $stmt = $pdo->prepare("UPDATE slots SET status = 'cancelled' WHERE teacher_id = ? AND slot_date = ?");
    $stmt->execute([$teacher_id, $today]);
    $message = "Today's schedule has been cleared and students notified.";
}

// Fetch Slots
$stmt = $pdo->prepare("
    SELECT s.*, u.name AS student_name,
           COALESCE(s.department, t.department) AS teacher_department
    FROM slots s
    LEFT JOIN users u ON s.student_id = u.id
    JOIN users t ON s.teacher_id = t.id
    WHERE s.teacher_id = ?
    ORDER BY s.slot_date DESC, s.start_time DESC
");
$stmt->execute([$teacher_id]);
$slots = $stmt->fetchAll();

// Fetch teacher notifications
$notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
$notif_stmt->execute([$teacher_id]);
$teacher_notifications = $notif_stmt->fetchAll();
$unread_count = count(array_filter($teacher_notifications, fn($n) => !$n['is_read']));

// Mark as read
$pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?")->execute([$teacher_id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - SlotSyncro</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .page-wrapper { display: grid; grid-template-columns: 1fr 340px; gap: 2rem; max-width: 1300px; margin: 6rem auto 2rem; padding: 0 5%; }
        @media(max-width: 992px) { .page-wrapper { grid-template-columns: 1fr; } }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .card { background: var(--surface); padding: 2rem; border-radius: 1rem; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); margin-bottom: 2rem; }
        .table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .table th, .table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border); }
        .table th { font-weight: 600; color: var(--text-muted); }
        .form-row { display: flex; gap: 1rem; margin-bottom: 1rem; }
        .form-row > div { flex: 1; }
        .btn-danger { background-color: var(--danger); color: white; border: none; }
        .btn-danger:hover { background-color: #dc2626; transform: translateY(-2px); }
        .btn-small { padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 0.5rem; cursor: pointer; border: none; font-weight: 600; transition: all 0.2s; }
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 1rem; font-size: 0.875rem; font-weight: 600; }
        .badge-available { background: #d1fae5; color: #065f46; }
        .badge-booked { background: #fee2e2; color: #991b1b; }
        .badge-cancelled { background: #f3f4f6; color: #4b5563; }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 0.5rem; }
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 500; background: #d1fae5; color: #047857; }
        /* Notification sidebar */
        .notif-sidebar { position: sticky; top: 6rem; }
        .notif-panel { background: var(--surface); border-radius: 1rem; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; }
        .notif-header { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); background: linear-gradient(135deg, var(--primary) 0%, #818cf8 100%); color: white; }
        .notif-header h3 { margin: 0; font-size: 1rem; }
        .notif-badge { background: white; color: var(--primary); font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 1rem; }
        .notif-list { max-height: 600px; overflow-y: auto; }
        .notif-item { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); font-size: 0.875rem; line-height: 1.5; transition: background 0.2s; }
        .notif-item:hover { background: #f8fafc; }
        .notif-item.unread { background: #eef2ff; border-left: 3px solid var(--primary); }
        .notif-item .notif-time { color: var(--text-muted); font-size: 0.78rem; margin-top: 0.35rem; }
        .notif-empty { padding: 2.5rem 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.9rem; }
        /* Navbar badge */
        .notif-dot { display: inline-block; width: 8px; height: 8px; background: var(--danger); border-radius: 50%; margin-left: 4px; vertical-align: middle; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.6;transform:scale(1.3)} }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo">SlotSyncro Dashboard</div>
        <div class="nav-links">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['teacher_name']); ?> <?php if($teacher_dept) echo '(' . htmlspecialchars($teacher_dept) . ')'; ?></span>
            <span style="position:relative; cursor:default;">
                🔔 Notifications
                <?php if ($unread_count > 0): ?>
                <span class="notif-dot" title="<?php echo $unread_count; ?> unread"></span>
                <span style="font-size:0.75rem; background:var(--danger); color:white; border-radius:1rem; padding:0.1rem 0.45rem; vertical-align:middle;"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </span>
            <a href="logout.php?role=teacher" style="color: var(--danger); font-weight: 600;">Log Out</a>
        </div>
    </nav>

    <div class="page-wrapper">
        <!-- Main content column -->
        <div class="main-col">
            <div class="dashboard-header">
                <h2>Teacher Dashboard</h2>
                <form method="POST" action="teacher_dashboard.php" onsubmit="return confirm('Are you sure you want to clear all slots for today? This will notify students.');">
                    <input type="hidden" name="action" value="clear_today">
                    <button type="submit" class="btn btn-danger"><i data-lucide="trash-2" style="display:inline; width:16px; margin-right:5px;"></i> Clear Today's Schedule</button>
                </form>
            </div>

            <?php if ($message): ?>
                <div class="alert"><?php echo $message; ?></div>
            <?php endif; ?>

            <div class="card">
                <h3>Add New Availability Slot</h3>
                <form method="POST" action="teacher_dashboard.php" style="margin-top: 1rem;">
                    <input type="hidden" name="action" value="add_slot">
                    <div class="form-row">
                        <div>
                            <label>Date</label>
                            <input type="date" name="date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label>Start Time</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        <div>
                            <label>End Time</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div style="flex: 2;">
                            <label>Subject / Topic</label>
                            <input type="text" name="subject" class="form-control" placeholder="e.g. Data Structures, Calculus…">
                        </div>
                        <div style="flex: 1;">
                            <label>Department</label>
                            <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($teacher_dept ?? ''); ?>" placeholder="e.g. Computer Science">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-small">Add Slot</button>
                </form>
            </div>

            <div class="card">
                <h3>Bulk Add Slots</h3>
                <form method="POST" action="teacher_dashboard.php" style="margin-top: 1rem;">
                    <input type="hidden" name="action" value="bulk_add_slot">
                    <div class="form-row">
                        <div>
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label>Start Time</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        <div>
                            <label>End Time</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                        <div>
                            <label>Duration (mins)</label>
                            <input type="number" name="duration" class="form-control" value="30" min="15" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div style="flex: 2;">
                            <label>Subject / Topic</label>
                            <input type="text" name="subject" class="form-control" placeholder="e.g. Data Structures, Calculus…">
                        </div>
                        <div style="flex: 1;">
                            <label>Department</label>
                            <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($teacher_dept ?? ''); ?>" placeholder="e.g. Computer Science">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-small" style="background-color: var(--success);">Bulk Add Slots</button>
                </form>
            </div>

            <div class="card">
                <h3>Your Schedule</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Department</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Booked By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slots as $slot): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($slot['slot_date']); ?></td>
                                <td><?php echo htmlspecialchars($slot['start_time'] . ' - ' . $slot['end_time']); ?></td>
                                <td>
                                    <span style="font-size:0.85em; background:var(--secondary); color:var(--primary); padding:0.2rem 0.6rem; border-radius:1rem;">
                                        <?php echo htmlspecialchars($slot['teacher_department'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td><?php echo $slot['subject'] ? htmlspecialchars($slot['subject']) : '<span style="color:var(--text-muted);">—</span>'; ?></td>
                                <td>
                                    <?php if ($slot['status'] === 'available'): ?>
                                        <span class="status-badge badge-available">Available</span>
                                    <?php elseif ($slot['status'] === 'booked'): ?>
                                        <span class="status-badge badge-booked">Booked</span>
                                    <?php else: ?>
                                        <span class="status-badge badge-cancelled">Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $slot['student_name'] ? htmlspecialchars($slot['student_name']) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($slots)): ?>
                            <tr><td colspan="6">No slots created yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Notifications sidebar -->
        <aside class="notif-sidebar">
            <div class="notif-panel" id="notif-panel">
                <div class="notif-header">
                    <h3>🔔 Notifications</h3>
                    <?php if ($unread_count > 0): ?>
                        <span class="notif-badge"><?php echo $unread_count; ?> new</span>
                    <?php endif; ?>
                </div>
                <div class="notif-list" id="notif-list">
                    <?php if (empty($teacher_notifications)): ?>
                        <div class="notif-empty">No notifications yet.<br><small>You'll see alerts here when students book or cancel slots.</small></div>
                    <?php else: ?>
                        <?php foreach ($teacher_notifications as $n): ?>
                            <div class="notif-item <?php echo !$n['is_read'] ? 'unread' : ''; ?>">
                                <div><?php echo htmlspecialchars($n['message']); ?></div>
                                <div class="notif-time"><?php echo date('M d, H:i', strtotime($n['created_at'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();

        // Poll for new notifications every 30 seconds
        function pollNotifications() {
            fetch('teacher_notifications_poll.php')
                .then(r => r.json())
                .then(data => {
                    if (data.count > 0) {
                        const list = document.getElementById('notif-list');
                        // Prepend new items
                        data.notifications.forEach(n => {
                            const div = document.createElement('div');
                            div.className = 'notif-item unread';
                            div.innerHTML = `<div>${n.message}</div><div class="notif-time">${n.created_at}</div>`;
                            list.prepend(div);
                        });
                        // Update badge in navbar
                        document.title = `(${data.count}) Teacher Dashboard - SlotSyncro`;
                    }
                })
                .catch(() => {}); // Silently fail if poll endpoint not available
        }
        setInterval(pollNotifications, 30000);
    </script>
</body>
</html>
