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
            $chk = $pdo->prepare("SELECT s.id, s.teacher_id, s.slot_date, s.start_time, t.name AS teacher_name FROM slots s JOIN users t ON s.teacher_id = t.id WHERE s.id = ? AND s.status = 'available'");
            $chk->execute([$slot_id]);
            $slot_info = $chk->fetch();
            if ($slot_info) {
                $stmt = $pdo->prepare("UPDATE slots SET status = 'booked', student_id = ? WHERE id = ?");
                $stmt->execute([$student_id, $slot_id]);
                $message = "Slot booked successfully!";
                // Notify the teacher
                $student_name = $_SESSION['name'];
                $notify_msg = "📅 {$student_name} booked your slot on {$slot_info['slot_date']} at {$slot_info['start_time']}.";
                $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$slot_info['teacher_id'], $notify_msg]);
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
            // Fetch slot info before cancelling
            $info_stmt = $pdo->prepare("SELECT s.slot_date, s.start_time, s.teacher_id FROM slots s WHERE s.id = ? AND s.student_id = ?");
            $info_stmt->execute([$slot_id, $student_id]);
            $info = $info_stmt->fetch();

            $stmt = $pdo->prepare("UPDATE slots SET status = 'available', student_id = NULL WHERE id = ? AND student_id = ?");
            $stmt->execute([$slot_id, $student_id]);
            $message = "Booking cancelled.";

            if ($info) {
                // Notify the teacher
                $student_name = $_SESSION['name'];
                $cancel_msg = "❌ {$student_name} cancelled their booking on {$info['slot_date']} at {$info['start_time']}.";
                $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$info['teacher_id'], $cancel_msg]);
            }

            // Notify waitlisted students
            $stmt = $pdo->prepare("SELECT student_id FROM waitlist WHERE slot_id = ?");
            $stmt->execute([$slot_id]);
            $waitlisted = $stmt->fetchAll();

            foreach ($waitlisted as $w) {
                $msg = "A slot you are waitlisted for on " . ($info['slot_date'] ?? '') . " at " . ($info['start_time'] ?? '') . " is now available!";
                $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$w['student_id'], $msg]);
            }
            // Remove them from waitlist since it's available? Let's leave them or they can book.
        }
    }
}

// Fetch Notifications
$notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
$notif_stmt->execute([$student_id]);
$notifications = $notif_stmt->fetchAll();
$unread_count  = count(array_filter($notifications, fn($n) => !$n['is_read']));

// Mark notifications as read
$pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?")->execute([$student_id]);

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
        .dashboard-container { max-width: 1300px; margin: 6rem auto 2rem; padding: 0 5%; display: grid; grid-template-columns: 1fr; gap: 2rem; }
        @media(min-width: 992px) { .dashboard-container { grid-template-columns: 2fr 340px; } }
        .card { background: var(--surface); padding: 2rem; border-radius: 1rem; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .calendar-container { margin-top: 1rem; min-height: 500px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .table th, .table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border); }
        .btn-small { padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 0.5rem; cursor: pointer; border: none; font-weight: 600; transition: all 0.2s; }
        .btn-outline { background: transparent; border: 1px solid var(--primary); color: var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 500; background: #d1fae5; color: #047857; }
        /* Calendar tweaks */
        .fc-theme-standard td, .fc-theme-standard th { border-color: var(--border); }
        .fc-event { border: none; border-radius: 4px; padding: 2px 4px; font-size: 0.8em; }
        /* Notification sidebar — matches teacher dashboard */
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
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span style="position:relative; cursor:default;">
                🔔 Notifications
                <?php if ($unread_count > 0): ?>
                <span class="notif-dot" title="<?php echo $unread_count; ?> unread"></span>
                <span style="font-size:0.75rem; background:var(--danger); color:white; border-radius:1rem; padding:0.1rem 0.45rem; vertical-align:middle;"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </span>
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

        <aside class="notif-sidebar">
            <div class="notif-panel" id="notif-panel">
                <div class="notif-header">
                    <h3>🔔 Notifications</h3>
                    <?php if ($unread_count > 0): ?>
                        <span class="notif-badge"><?php echo $unread_count; ?> new</span>
                    <?php endif; ?>
                </div>
                <div class="notif-list" id="notif-list">
                    <?php if (empty($notifications)): ?>
                        <div class="notif-empty">No notifications yet.<br><small>You'll see alerts here for slot updates &amp; waitlist availability.</small></div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <div class="notif-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>">
                                <div><?php echo htmlspecialchars($notif['message']); ?></div>
                                <div class="notif-time"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></div>
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

        // Poll for new notifications every 30 seconds
        function pollNotifications() {
            fetch('student_notifications_poll.php')
                .then(r => r.json())
                .then(data => {
                    if (data.count > 0) {
                        const list = document.getElementById('notif-list');
                        // Remove empty state if present
                        const empty = list.querySelector('.notif-empty');
                        if (empty) empty.remove();
                        // Prepend new items
                        data.notifications.forEach(n => {
                            const div = document.createElement('div');
                            div.className = 'notif-item unread';
                            div.innerHTML = `<div>${n.message}</div><div class="notif-time">${n.created_at}</div>`;
                            list.prepend(div);
                        });
                        document.title = `(${data.count}) Student Dashboard - SlotSyncro`;
                    }
                })
                .catch(() => {});
        }
        setInterval(pollNotifications, 30000);
    </script>
</body>
</html>
