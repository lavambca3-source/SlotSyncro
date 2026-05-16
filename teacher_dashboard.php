<?php
session_start();
require_once 'db.php';

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['user_id'];
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
            $notify_stmt = $pdo->prepare("INSERT INTO notifications (student_id, message) VALUES (?, ?)");
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
        .dashboard-container { max-width: 1200px; margin: 6rem auto 2rem; padding: 0 5%; }
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
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo">SlotSyncro Dashboard</div>
        <div class="nav-links">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> <?php if($teacher_dept) echo '(' . htmlspecialchars($teacher_dept) . ')'; ?></span>
            <a href="logout.php" style="color: var(--danger); font-weight: 600;">Log Out</a>
        </div>
    </nav>

    <div class="dashboard-container">
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
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>lucide.createIcons();</script>
</body>
</html>
