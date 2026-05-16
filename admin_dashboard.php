<?php
session_start();
require_once 'db.php';

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];

// Get counts for dashboard summary
$teachers_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetchColumn();
$students_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$bookings_count = $pdo->query("SELECT COUNT(*) FROM slots WHERE status = 'booked'")->fetchColumn();
$available_slots_count = $pdo->query("SELECT COUNT(*) FROM slots WHERE status = 'available'")->fetchColumn();

// Get recent bookings
$recent_bookings = $pdo->query("
    SELECT s.slot_date, s.start_time, s.end_time, t.name as teacher_name, st.name as student_name
    FROM slots s
    JOIN users t ON s.teacher_id = t.id
    JOIN users st ON s.student_id = st.id
    WHERE s.status = 'booked'
    ORDER BY s.created_at DESC
    LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SlotSyncro</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 250px;
            --admin-primary: #ef4444;
            --admin-primary-dark: #dc2626;
            --admin-bg: #f8fafc;
        }
        
        body {
            background-color: var(--admin-bg);
            margin: 0;
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: #1e293b;
            color: white;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #334155;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .sidebar-header h2 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: white;
            letter-spacing: -0.025em;
        }
        
        .nav-menu {
            padding: 1.5rem 0;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.2s ease;
            gap: 0.75rem;
            font-weight: 500;
        }
        
        .nav-item:hover, .nav-item.active {
            background-color: #334155;
            color: white;
            border-left: 4px solid var(--admin-primary);
        }
        
        .nav-item i {
            width: 20px;
            height: 20px;
        }
        
        .logout-mt {
            margin-top: auto;
            border-top: 1px solid #334155;
            padding: 1rem 0;
        }
        
        .nav-item.logout {
            color: #ef4444;
        }
        
        .nav-item.logout:hover {
            background-color: rgba(239, 68, 68, 0.1);
            border-left-color: #ef4444;
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            display: flex;
            flex-direction: column;
        }
        
        /* Top Navbar */
        .top-navbar {
            background: white;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 90;
        }
        
        .navbar-brand {
            font-weight: 600;
            font-size: 1.25rem;
            color: #0f172a;
        }
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: #f1f5f9;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 500;
            color: #334155;
        }
        
        .admin-avatar {
            width: 32px;
            height: 32px;
            background: var(--admin-primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        /* Dashboard Content */
        .dashboard-body {
            padding: 2rem;
        }
        
        .page-title {
            margin-bottom: 2rem;
            color: #0f172a;
            font-size: 1.75rem;
            font-weight: 700;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid #e2e8f0;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .stat-icon.teachers { background: #3b82f6; }
        .stat-icon.students { background: #10b981; }
        .stat-icon.bookings { background: #8b5cf6; }
        .stat-icon.slots { background: #f59e0b; }
        
        .stat-details {
            flex: 1;
        }
        
        .stat-title {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .stat-value {
            color: #0f172a;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        /* Recent Activity Card */
        .card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            padding: 1.5rem;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th, .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table th {
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        
        .table td {
            color: #334155;
            font-size: 0.95rem;
        }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            background: #d1fae5;
            color: #065f46;
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <i data-lucide="shield" style="color: var(--admin-primary);"></i>
            <h2>Admin Panel</h2>
        </div>
        
        <nav class="nav-menu">
            <a href="admin_dashboard.php" class="nav-item active">
                <i data-lucide="layout-dashboard"></i>
                Dashboard
            </a>
            <a href="admin_teachers.php" class="nav-item">
                <i data-lucide="users"></i>
                Teachers
            </a>
            <a href="admin_students.php" class="nav-item">
                <i data-lucide="graduation-cap"></i>
                Students
            </a>
            <a href="admin_bookings.php" class="nav-item">
                <i data-lucide="calendar-check"></i>
                Bookings
            </a>
            <a href="admin_notifications.php" class="nav-item">
                <i data-lucide="bell"></i>
                Notifications
            </a>
        </nav>
        
        <div class="logout-mt">
            <a href="logout.php" class="nav-item logout">
                <i data-lucide="log-out"></i>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Navbar -->
        <header class="top-navbar">
            <div class="navbar-brand">SlotSyncro Admin</div>
            <div class="admin-profile">
                <div class="admin-avatar">
                    <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                </div>
                <span><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="dashboard-body">
            <h1 class="page-title">Dashboard Overview</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon teachers"><i data-lucide="users"></i></div>
                    <div class="stat-details">
                        <div class="stat-title">Total Teachers</div>
                        <div class="stat-value"><?php echo $teachers_count; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon students"><i data-lucide="graduation-cap"></i></div>
                    <div class="stat-details">
                        <div class="stat-title">Total Students</div>
                        <div class="stat-value"><?php echo $students_count; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon bookings"><i data-lucide="calendar-check"></i></div>
                    <div class="stat-details">
                        <div class="stat-title">Total Bookings</div>
                        <div class="stat-value"><?php echo $bookings_count; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon slots"><i data-lucide="clock"></i></div>
                    <div class="stat-details">
                        <div class="stat-title">Available Slots</div>
                        <div class="stat-value"><?php echo $available_slots_count; ?></div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Recent Bookings</h2>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Teacher</th>
                            <th>Student</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recent_bookings) > 0): ?>
                            <?php foreach ($recent_bookings as $booking): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($booking['slot_date']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['start_time']) . ' - ' . htmlspecialchars($booking['end_time']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['teacher_name']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['student_name']); ?></td>
                                    <td><span class="badge">Confirmed</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b;">No recent bookings found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>lucide.createIcons();</script>
</body>
</html>
