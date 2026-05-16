<?php
session_start();
require_once 'db.php';

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$message = '';

// Handle actions (Book, Notify, Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $slot_id = $_POST['slot_id'];
        
        if ($_POST['action'] === 'book') {
            // Check if available
            $chk = $pdo->prepare("SELECT id FROM slots WHERE id = ? AND status = 'available'");
            $chk->execute([$slot_id]);
            if ($chk->fetch()) {
                $stmt = $pdo->prepare("UPDATE slots SET status = 'booked', student_id = ? WHERE id = ?");
                $stmt->execute([$student_id, $slot_id]);
                $message = "Slot booked successfully!";
            } else {
                $message = "Failed to book slot. It may have been booked or cancelled.";
            }
        } elseif ($_POST['action'] === 'notify') {
            // Add to waitlist
            $stmt = $pdo->prepare("SELECT id FROM waitlist WHERE slot_id = ? AND student_id = ?");
            $stmt->execute([$slot_id, $student_id]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO waitlist (slot_id, student_id) VALUES (?, ?)");
                $stmt->execute([$slot_id, $student_id]);
                $message = "You are on the waitlist! We will notify you if this slot becomes available.";
            }
        } elseif ($_POST['action'] === 'cancel_booking') {
            $stmt = $pdo->prepare("UPDATE slots SET status = 'available', student_id = NULL WHERE id = ? AND student_id = ?");
            $stmt->execute([$slot_id, $student_id]);
            $message = "Booking cancelled.";
            
            // Notify waitlisted students
            $stmt = $pdo->prepare("SELECT student_id FROM waitlist WHERE slot_id = ?");
            $stmt->execute([$slot_id]);
            $waitlisted = $stmt->fetchAll();
            
            $info_stmt = $pdo->prepare("SELECT slot_date, start_time FROM slots WHERE id = ?");
            $info_stmt->execute([$slot_id]);
            $info = $info_stmt->fetch();
            
            foreach ($waitlisted as $w) {
                $msg = "A slot you are waitlisted for on " . $info['slot_date'] . " at " . $info['start_time'] . " is now available!";
                $pdo->prepare("INSERT INTO notifications (student_id, message) VALUES (?, ?)")->execute([$w['student_id'], $msg]);
            }
            // Remove them from waitlist since it's available? Let's leave them or they can book.
        }
    }
}

// Fetch Notifications
$notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE student_id = ? ORDER BY created_at DESC");
$notif_stmt->execute([$student_id]);
$notifications = $notif_stmt->fetchAll();

// Mark notifications as read
$pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE student_id = ?")->execute([$student_id]);

// Fetch distinct departments (from slots, falling back to teacher profile)
$depts_stmt = $pdo->prepare("
    SELECT DISTINCT COALESCE(s.department, t.department) AS dept
    FROM slots s
    JOIN users t ON s.teacher_id = t.id
    WHERE s.slot_date >= CURDATE()
      AND s.status != 'cancelled'
      AND COALESCE(s.department, t.department) IS NOT NULL
      AND COALESCE(s.department, t.department) != ''
");
$depts_stmt->execute();
$departments = $depts_stmt->fetchAll(PDO::FETCH_COLUMN);

$selected_dept = isset($_GET['dept']) ? $_GET['dept'] : '';

// Fetch All Future Slots (for booking)
if ($selected_dept) {
    $slots_stmt = $pdo->prepare("
        SELECT s.*, t.name as teacher_name,
               COALESCE(s.department, t.department) AS department
        FROM slots s
        JOIN users t ON s.teacher_id = t.id
        WHERE s.slot_date >= CURDATE()
          AND s.status != 'cancelled'
          AND COALESCE(s.department, t.department) = ?
        ORDER BY s.slot_date, s.start_time
    ");
    $slots_stmt->execute([$selected_dept]);
} else {
    $slots_stmt = $pdo->prepare("
        SELECT s.*, t.name as teacher_name,
               COALESCE(s.department, t.department) AS department
        FROM slots s
        JOIN users t ON s.teacher_id = t.id
        WHERE s.slot_date >= CURDATE()
          AND s.status != 'cancelled'
        ORDER BY s.slot_date, s.start_time
    ");
    $slots_stmt->execute();
}
$all_slots = $slots_stmt->fetchAll();

// Fetch Student's Booked Slots
$my_slots_stmt = $pdo->prepare("
    SELECT s.*, t.name AS teacher_name,
           COALESCE(s.department, t.department) AS teacher_department
    FROM slots s
    JOIN users t ON s.teacher_id = t.id
    WHERE s.student_id = ? AND s.status = 'booked'
");
$my_slots_stmt->execute([$student_id]);
$my_slots = $my_slots_stmt->fetchAll();

$calendar_events = [];
foreach ($my_slots as $ms) {
    $calendar_events[] = [
        'title' => 'Session w/ ' . $ms['teacher_name'],
        'start' => $ms['slot_date'] . 'T' . $ms['start_time'],
        'end' => $ms['slot_date'] . 'T' . $ms['end_time'],
        'color' => '#4f46e5'
    ];
}
$events_json = json_encode($calendar_events);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - SlotSyncro</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FullCalendar JS & CSS -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>
    <style>
        .dashboard-container { max-width: 1200px; margin: 6rem auto 2rem; padding: 0 5%; display: grid; grid-template-columns: 1fr; gap: 2rem; }
        @media(min-width: 992px) { .dashboard-container { grid-template-columns: 2fr 1fr; } }
        .card { background: var(--surface); padding: 2rem; border-radius: 1rem; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .calendar-container { margin-top: 1rem; min-height: 500px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .table th, .table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border); }
        .btn-small { padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 0.5rem; cursor: pointer; border: none; font-weight: 600; transition: all 0.2s; }
        .btn-outline { background: transparent; border: 1px solid var(--primary); color: var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .notification-item { padding: 1rem; border-bottom: 1px solid var(--border); font-size: 0.95rem; }
        .notification-item.unread { background: #f8fafc; border-left: 4px solid var(--primary); }
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 500; background: #d1fae5; color: #047857; }
        /* Calendar tweaks */
        .fc-theme-standard td, .fc-theme-standard th { border-color: var(--border); }
        .fc-event { border: none; border-radius: 4px; padding: 2px 4px; font-size: 0.8em; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo">SlotSyncro Dashboard</div>
        <div class="nav-links">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <a href="logout.php" style="color: var(--danger); font-weight: 600;">Log Out</a>
        </div>
    </nav>

    <div class="dashboard-container">
        
        <div class="main-content">
            <?php if ($message): ?>
                <div class="alert"><?php echo $message; ?></div>
            <?php endif; ?>

            <div class="card" style="margin-bottom: 2rem;">
                <h3>Your Personalized Calendar</h3>
                <div id='calendar' class="calendar-container"></div>
            </div>

            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="margin: 0;">Available Slots</h3>
                    <form method="GET" action="student_dashboard.php" style="display: flex; gap: 0.5rem;">
                        <select name="dept" style="padding: 0.5rem; border-radius: 0.5rem; border: 1px solid var(--border);" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $selected_dept === $d ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Department</th>
                            <th>Subject</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_slots as $slot): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($slot['teacher_name']); ?></td>
                                <td><span style="font-size: 0.85em; background: var(--secondary); color: var(--primary); padding: 0.2rem 0.5rem; border-radius: 1rem;"><?php echo htmlspecialchars($slot['department'] ?? 'N/A'); ?></span></td>
                                <td><?php echo $slot['subject'] ? htmlspecialchars($slot['subject']) : '<span style="color:var(--text-muted);">—</span>'; ?></td>
                                <td><?php echo htmlspecialchars($slot['slot_date']); ?></td>
                                <td><?php echo htmlspecialchars($slot['start_time'] . ' - ' . $slot['end_time']); ?></td>
                                <td>
                                    <form method="POST" action="student_dashboard.php" style="display:inline;">
                                        <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                                        <?php if ($slot['status'] === 'available'): ?>
                                            <input type="hidden" name="action" value="book">
                                            <button type="submit" class="btn-small btn-primary">Book Class</button>
                                        <?php elseif ($slot['status'] === 'booked'): ?>
                                            <?php if ($slot['student_id'] == $student_id): ?>
                                                <input type="hidden" name="action" value="cancel_booking">
                                                <button type="submit" class="btn-small btn-outline" style="color:var(--danger); border-color:var(--danger);">Cancel</button>
                                            <?php else: ?>
                                                <input type="hidden" name="action" value="notify">
                                                <button type="submit" class="btn-small btn-outline">Notify Me</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($all_slots)): ?>
                            <tr><td colspan="6">No slots available at the moment.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card" style="margin-top: 2rem;">
                <h3>My Booked Slots</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Department</th>
                            <th>Subject</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_slots as $slot): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($slot['teacher_name']); ?></td>
                                <td>
                                    <span style="font-size:0.85em; background:var(--secondary); color:var(--primary); padding:0.2rem 0.5rem; border-radius:1rem;">
                                        <?php echo htmlspecialchars($slot['teacher_department'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td><?php echo $slot['subject'] ? htmlspecialchars($slot['subject']) : '<span style="color:var(--text-muted);">—</span>'; ?></td>
                                <td><?php echo htmlspecialchars($slot['slot_date']); ?></td>
                                <td><?php echo htmlspecialchars($slot['start_time'] . ' - ' . $slot['end_time']); ?></td>
                                <td>
                                    <form method="POST" action="student_dashboard.php" style="display:inline;">
                                        <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                                        <input type="hidden" name="action" value="cancel_booking">
                                        <button type="submit" class="btn-small btn-outline" style="color:var(--danger); border-color:var(--danger);">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($my_slots)): ?>
                            <tr><td colspan="6" style="color:var(--text-muted);">You have no booked slots.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="sidebar">
            <div class="card">
                <h3>Notifications</h3>
                <div class="notifications-list" style="margin-top: 1rem;">
                    <?php if (empty($notifications)): ?>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">You have no notifications.</p>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                <small style="color: var(--text-muted);"><?php echo htmlspecialchars($notif['created_at']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: <?php echo $events_json; ?>,
                eventTimeFormat: {
                    hour: 'numeric',
                    minute: '2-digit',
                    meridiem: 'short'
                }
            });
            calendar.render();
        });
    </script>
</body>
</html>
