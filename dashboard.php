<?php
$pageTitle = 'Dashboard';
require_once 'includes/config.php';
requireLogin();
$db = getDB();
$user = currentUser();
$role = $user['role'];

// Stats based on role
$stats = [];
if ($role === 'admin' || $role === 'registrar') {
    $stats['students'] = $db->query("SELECT COUNT(*) as c FROM users WHERE role='student' AND status='active'")->fetch_assoc()['c'] ?? 0;
    $stats['enrollments'] = $db->query("SELECT COUNT(*) as c FROM enrollments WHERE status='Enrolled'")->fetch_assoc()['c'] ?? 0;
    $stats['pending'] = $db->query("SELECT COUNT(*) as c FROM enrollments WHERE status='Pending'")->fetch_assoc()['c'] ?? 0;
    $stats['faculty'] = $db->query("SELECT COUNT(*) as c FROM users WHERE role='faculty' AND status='active'")->fetch_assoc()['c'] ?? 0;
}

// Announcements
$announcements = $db->query("SELECT a.*, u.first_name, u.last_name FROM announcements a LEFT JOIN users u ON a.posted_by=u.id WHERE (a.expires_at IS NULL OR a.expires_at >= CURDATE()) AND (a.target_role='all' OR a.target_role='{$role}') ORDER BY a.posted_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Recent activities
$recentLogs = $db->query("SELECT l.*, u.first_name, u.last_name, u.role FROM activity_logs l LEFT JOIN users u ON l.user_id=u.id ORDER BY l.logged_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title"><?= ucfirst($role) ?> Dashboard</h1>
            <p class="page-subtitle">
                <?php
                $subtitles = [
                    'admin' => 'Manage the entire BCP University system and all users.',
                    'registrar' => 'Manage enrollments, student records, and academic processes.',
                    'faculty' => 'Manage your classes, students, and grade submissions.',
                    'student' => 'Track your enrollment, grades, and academic progress.',
                    'accounting' => 'Manage billing, payments, and financial records.',
                ];
                echo $subtitles[$role] ?? '';
                ?>
            </p>
        </div>
        <!-- Role Switcher (display only) -->
        <div class="role-switcher">
            <?php foreach (['Student','Registrar','Admin','Accounting','Hr'] as $r): ?>
            <button class="role-tab <?= strtolower($r)===$role?'active':'' ?>"><?= $r ?></button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<?php if ($role === 'admin' || $role === 'registrar'): ?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">👥</div>
            <span class="stat-badge up">↑ +5.2%</span>
        </div>
        <div class="stat-value"><?= number_format($stats['students']) ?></div>
        <div class="stat-label">Total Students</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">✅</div>
            <span class="stat-badge up">↑ +12%</span>
        </div>
        <div class="stat-value"><?= number_format($stats['enrollments']) ?></div>
        <div class="stat-label">Active Enrollments</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon orange">🕐</div>
            <span class="stat-badge urgent">⚠ Urgent</span>
        </div>
        <div class="stat-value"><?= number_format($stats['pending']) ?></div>
        <div class="stat-label">Pending Applications</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon purple">📋</div>
            <span class="stat-badge active">Active</span>
        </div>
        <div class="stat-value"><?= number_format($stats['faculty']) ?></div>
        <div class="stat-label">Faculty Members</div>
    </div>
</div>
<?php elseif ($role === 'student'): ?>
<?php
$db2 = getDB();
$sid = $db2->query("SELECT id FROM students WHERE user_id={$user['user_id']}")->fetch_assoc()['id'] ?? 0;
$myEnroll = $sid ? $db2->query("SELECT COUNT(*) as c FROM enrollments WHERE student_id=$sid AND status='Enrolled'")->fetch_assoc()['c'] : 0;
$myGrades = $sid ? $db2->query("SELECT AVG(final_grade) as avg FROM grades g JOIN enrollments e ON g.enrollment_id=e.id WHERE e.student_id=$sid AND g.final_grade IS NOT NULL")->fetch_assoc()['avg'] : null;
$myBill = $sid ? $db2->query("SELECT balance FROM billing WHERE student_id=$sid ORDER BY id DESC LIMIT 1")->fetch_assoc()['balance'] : 0;
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon blue">📚</div><span class="stat-badge active">Current</span></div>
        <div class="stat-value"><?= $myEnroll ?></div>
        <div class="stat-label">Enrolled Subjects</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon green">📝</div></div>
        <div class="stat-value"><?= $myGrades ? number_format($myGrades,2) : 'N/A' ?></div>
        <div class="stat-label">Average Grade</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon orange">💳</div><span class="stat-badge <?= $myBill>0?'urgent':'up' ?>"><?= $myBill>0?'Pending':'Paid' ?></span></div>
        <div class="stat-value">₱<?= number_format($myBill??0,2) ?></div>
        <div class="stat-label">Balance Due</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon purple">📄</div></div>
        <div class="stat-value"><?= $db2->query("SELECT COUNT(*) as c FROM document_requests WHERE student_id=$sid")->fetch_assoc()['c'] ?? 0 ?></div>
        <div class="stat-label">Document Requests</div>
    </div>
</div>
<?php elseif ($role === 'accounting'): ?>
<?php
$totalBilling = $db->query("SELECT SUM(total_amount) as t FROM billing")->fetch_assoc()['t'] ?? 0;
$totalPaid    = $db->query("SELECT SUM(amount_paid) as t FROM billing")->fetch_assoc()['t'] ?? 0;
$totalBalance = $db->query("SELECT SUM(balance) as t FROM billing WHERE balance>0")->fetch_assoc()['t'] ?? 0;
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon blue">💰</div><span class="stat-badge active">Total</span></div>
        <div class="stat-value">₱<?= number_format($totalBilling/1000,1) ?>K</div>
        <div class="stat-label">Total Billed</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon green">✅</div><span class="stat-badge up">Collected</span></div>
        <div class="stat-value">₱<?= number_format($totalPaid/1000,1) ?>K</div>
        <div class="stat-label">Total Paid</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon orange">⚠️</div><span class="stat-badge urgent">Unpaid</span></div>
        <div class="stat-value">₱<?= number_format($totalBalance/1000,1) ?>K</div>
        <div class="stat-label">Total Balance</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon purple">📋</div></div>
        <div class="stat-value"><?= $db->query("SELECT COUNT(*) as c FROM payments")->fetch_assoc()['c'] ?? 0 ?></div>
        <div class="stat-label">Transactions</div>
    </div>
</div>
<?php endif; ?>

<!-- Charts & Process Flow -->
<div class="grid-2" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-header">
            <span class="card-title">Enrollment Trend</span>
            <span class="badge badge-primary">AY 2024-2025</span>
        </div>
        <div class="card-body">
            <div class="chart-area" id="chart1">
                <?php
                $months = ['Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar'];
                $vals   = [80, 35, 25, 10, 5, 70, 85, 100];
                foreach ($months as $i => $m):
                ?>
                <div class="chart-bar <?= $i===7?'active':'' ?>" data-value="<?= $vals[$i] ?>" title="<?= $m ?>: <?= $vals[$i] ?>%"></div>
                <?php endforeach; ?>
            </div>
            <div class="chart-labels">
                <?php foreach ($months as $m): ?>
                <div class="chart-label"><?= $m ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <span class="card-title">Enrollment Process Flow</span>
            <span class="badge badge-success">Live Status</span>
        </div>
        <div class="card-body">
            <div class="flow-steps" style="flex-wrap:wrap;gap:16px;">
                <?php
                $steps = [
                    ['1','Profile Creation','Sub-system 1','done'],
                    ['2','Online Application','Sub-system 2','done'],
                    ['3','Subject Selection','Sub-system 2','done'],
                    ['4','Fee Assessment','Sub-system 6',''],
                    ['5','Payment','Sub-system 6','pending'],
                    ['6','Registrar Approval','Sub-system 2','pending'],
                    ['7','Official Enrollment','Sub-system 2','pending'],
                ];
                foreach ($steps as $step):
                ?>
                <div style="display:flex;flex-direction:column;align-items:center;min-width:70px;text-align:center;">
                    <div class="flow-step-circle <?= $step[3] ?>" style="font-size:12px;width:36px;height:36px;"><?= $step[3]==='done' ? '✓' : $step[0] ?></div>
                    <div style="font-size:11px;font-weight:600;margin-top:4px;color:var(--text-primary);"><?= $step[1] ?></div>
                    <div style="font-size:10px;color:var(--text-muted);"><?= $step[2] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3" style="padding:10px;background:var(--primary-light);border-radius:8px;">
                <div style="font-size:12px;font-weight:600;color:var(--primary);">Process: Pending → Validated → Paid → Enrolled</div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Students track status in real-time through the portal.</div>
            </div>
        </div>
    </div>
</div>

<!-- Sub-systems Overview + Announcements -->
<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <span class="card-title">System Modules Overview</span>
        </div>
        <div class="card-body" style="padding:0;">
            <?php
            $modules = [
                ['1','Student Information','Capturing and maintaining Master Records (Profile, ID, Status)','blue'],
                ['2','Enrollment & Registration','Managing the workflow from initial application to official enrollment','green'],
                ['4','Class Scheduling','Organizing sections, rooms, and instructor assignments','purple'],
                ['5','Grades & Assessment','Faculty input grades, registrar verifies before posting','orange'],
                ['6','Payment & Accounting','Fee computation, payments, scholarships management','blue'],
                ['7','Documents & Credentials','TOR, Good Moral, and official credential requests','green'],
                ['8','Human Resource (HR)','Managing faculty and staff lifecycle','purple'],
                ['10','User Management','Security layer — access control and permissions','orange'],
            ];
            foreach ($modules as $mod):
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--border);">
                <div style="width:28px;height:28px;border-radius:8px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;"><?= $mod[0] ?></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;"><?= $mod[1] ?></div>
                    <div style="font-size:11.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $mod[2] ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div style="display:flex;flex-direction:column;gap:16px;">
        <!-- Announcements -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📢 Announcements</span>
                <?php if ($role === 'admin'): ?><a href="announcements.php" class="btn btn-sm btn-outline">Manage</a><?php endif; ?>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($announcements)): ?>
                <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">No announcements.</div>
                <?php else: ?>
                <?php foreach ($announcements as $ann): ?>
                <div style="padding:14px 20px;border-bottom:1px solid var(--border);">
                    <div style="font-size:13.5px;font-weight:600;"><?= escape($ann['title']) ?></div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:3px;"><?= escape(substr($ann['content'],0,80)) ?>...</div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                        By <?= escape($ann['first_name'].' '.$ann['last_name']) ?> · <?= date('M d, Y', strtotime($ann['posted_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Activity</span>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($recentLogs)): ?>
                <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">No recent activity.</div>
                <?php else: ?>
                <?php foreach (array_slice($recentLogs,0,5) as $log): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 20px;border-bottom:1px solid var(--border);">
                    <div class="avatar-initials" style="width:30px;height:30px;font-size:11px;">
                        <?= strtoupper(substr($log['first_name']??'?',0,1).substr($log['last_name']??'',0,1)) ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:12.5px;font-weight:600;"><?= escape($log['first_name'].' '.$log['last_name']) ?> <span style="font-weight:400;color:var(--text-muted);"><?= escape($log['action']) ?></span></div>
                        <div style="font-size:11px;color:var(--text-muted);"><?= date('M d, h:i A', strtotime($log['logged_at'])) ?></div>
                    </div>
                    <span class="badge badge-secondary" style="font-size:10px;"><?= escape($log['module']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
