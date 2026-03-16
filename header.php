<?php
require_once __DIR__ . '/config.php';
requireLogin();
$user = currentUser();
$notifCount = getUnreadNotifications($user['user_id']);

// Role-based nav items
$navItems = [];
$role = $user['role'];

// SVG icon definitions
$icons = [
    'dashboard'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
    'users'       => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'programs'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>',
    'announcements'=> '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3 2 12l8 3"/><path d="M22 3 12 22l-2-7"/><path d="M22 3 10 15"/></svg>',
    'reports'     => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>',
    'logs'        => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
    'settings'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    'enrollment_queue' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
    'student_records'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'class_schedule'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'documents'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>',
    'grades'      => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
    'my_classes'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
    'grade_encoding'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
    'student_list'=> '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
    'profile'     => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'enrollment'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
    'my_grades'   => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
    'my_schedule' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    'billing'     => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    'payments'    => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
    'scholarships'=> '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>',
    'logout'      => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
];

if ($role === 'admin') {
    $navItems = [
        'group' => 'Main',
        'items' => [
            ['url'=>'dashboard.php','icon'=>$icons['dashboard'],'label'=>'Dashboard','id'=>'dashboard'],
            ['url'=>'users.php','icon'=>$icons['users'],'label'=>'User Management','id'=>'users'],
            ['url'=>'programs.php','icon'=>$icons['programs'],'label'=>'Programs','id'=>'programs'],
            ['url'=>'announcements.php','icon'=>$icons['announcements'],'label'=>'Announcements','id'=>'announcements'],
            ['url'=>'reports.php','icon'=>$icons['reports'],'label'=>'Reports','id'=>'reports'],
            ['url'=>'logs.php','icon'=>$icons['logs'],'label'=>'Activity Logs','id'=>'logs'],
            ['url'=>'settings.php','icon'=>$icons['settings'],'label'=>'Settings','id'=>'settings'],
        ]
    ];
} elseif ($role === 'registrar') {
    $navItems = [
        'group' => 'Main',
        'items' => [
            ['url'=>'dashboard.php','icon'=>$icons['dashboard'],'label'=>'Dashboard','id'=>'dashboard'],
            ['url'=>'enrollment_queue.php','icon'=>$icons['enrollment_queue'],'label'=>'Enrollment Queue','id'=>'enrollment_queue'],
            ['url'=>'student_records.php','icon'=>$icons['student_records'],'label'=>'Student Records','id'=>'student_records'],
            ['url'=>'class_schedule.php','icon'=>$icons['class_schedule'],'label'=>'Class Schedule','id'=>'class_schedule'],
            ['url'=>'documents.php','icon'=>$icons['documents'],'label'=>'Documents','id'=>'documents'],
            ['url'=>'grades.php','icon'=>$icons['grades'],'label'=>'Grades','id'=>'grades'],
            ['url'=>'reports.php','icon'=>$icons['reports'],'label'=>'Reports','id'=>'reports'],
            ['url'=>'settings.php','icon'=>$icons['settings'],'label'=>'Settings','id'=>'settings'],
        ]
    ];
} elseif ($role === 'faculty') {
    $navItems = [
        'group' => 'Main',
        'items' => [
            ['url'=>'dashboard.php','icon'=>$icons['dashboard'],'label'=>'Dashboard','id'=>'dashboard'],
            ['url'=>'my_classes.php','icon'=>$icons['my_classes'],'label'=>'My Classes','id'=>'my_classes'],
            ['url'=>'grade_encoding.php','icon'=>$icons['grade_encoding'],'label'=>'Grade Encoding','id'=>'grade_encoding'],
            ['url'=>'student_list.php','icon'=>$icons['student_list'],'label'=>'Student List','id'=>'student_list'],
            ['url'=>'profile.php','icon'=>$icons['profile'],'label'=>'My Profile','id'=>'profile'],
        ]
    ];
} elseif ($role === 'student') {
    $navItems = [
        'group' => 'Main',
        'items' => [
            ['url'=>'dashboard.php','icon'=>$icons['dashboard'],'label'=>'Dashboard','id'=>'dashboard'],
            ['url'=>'enrollment.php','icon'=>$icons['enrollment'],'label'=>'My Enrollment','id'=>'enrollment'],
            ['url'=>'my_grades.php','icon'=>$icons['my_grades'],'label'=>'My Grades','id'=>'my_grades'],
            ['url'=>'my_schedule.php','icon'=>$icons['my_schedule'],'label'=>'My Schedule','id'=>'my_schedule'],
            ['url'=>'student_soa.php','icon'=>$icons['billing'],'label'=>'Statement of Account','id'=>'soa'],
            ['url'=>'student_payments.php','icon'=>$icons['payments'],'label'=>'Submit Payment','id'=>'payments'],
            ['url'=>'documents.php','icon'=>$icons['documents'],'label'=>'Request Documents','id'=>'documents'],
            ['url'=>'profile.php','icon'=>$icons['profile'],'label'=>'My Profile','id'=>'profile'],
        ]
    ];
} elseif ($role === 'accounting') {
    $navItems = [
        'group' => 'Main',
        'items' => [
            ['url'=>'dashboard.php','icon'=>$icons['dashboard'],'label'=>'Dashboard','id'=>'dashboard'],
            ['url'=>'accounting_billing.php','icon'=>$icons['billing'],'label'=>'Billing','id'=>'billing'],
            ['url'=>'accounting_payments.php','icon'=>$icons['payments'],'label'=>'Payments','id'=>'payments'],
            ['url'=>'accounting_scholarships.php','icon'=>$icons['scholarships'],'label'=>'Scholarships','id'=>'scholarships'],
            ['url'=>'accounting_reports.php','icon'=>$icons['reports'],'label'=>'Financial Reports','id'=>'reports'],
            ['url'=>'settings.php','icon'=>$icons['settings'],'label'=>'Settings','id'=>'settings'],
        ]
    ];
}

// Current page for active state
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? escape($pageTitle).' - ' : '' ?>BCP-UMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="app-layout">
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <div class="logo-icon">B</div>
        <div class="logo-text">
            <h1>BCP-UMS</h1>
            <p>University System</p>
        </div>
        <button class="collapse-btn" title="Collapse sidebar">&#8249;</button>
    </div>
    
    <nav class="sidebar-nav">
        <?php if (!empty($navItems['items'])): ?>
        <div class="nav-label"><?= $navItems['group'] ?></div>
        <?php foreach ($navItems['items'] as $item): ?>
        <a href="<?= $item['url'] ?>" class="nav-item <?= $currentPage === $item['url'] ? 'active' : '' ?>">
            <span class="nav-icon"><?= $item['icon'] ?></span>
            <span><?= $item['label'] ?></span>
            <?php if ($item['id'] === 'enrollment_queue' && $role === 'registrar'): ?>
            <span class="nav-badge">●</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </nav>
    
    <div class="sidebar-footer">
        <div class="current-role">
            <div class="current-role-label">CURRENT ROLE</div>
            <div class="current-role-value"><?= ucfirst($user['role']) ?></div>
        </div>
        <a href="logout.php" class="nav-item sidebar-logout" onclick="return confirm('Logout?')">
            <span class="nav-icon"><?= $icons['logout'] ?></span>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- MAIN -->
<div class="main-content">
    <!-- HEADER -->
    <header class="header">
        <div class="search-bar">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
            </svg>
            <input type="text" id="tableSearch" placeholder="Search students, courses, documents...">
        </div>
        
        <div class="header-actions">
            <button class="header-btn notif-bell" title="Notifications">
                🔔
                <?php if ($notifCount > 0): ?>
                <span class="notif-dot"></span>
                <?php endif; ?>
            </button>
            
            <div class="user-profile">
                <div class="avatar-initials">
                    <?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?>
                </div>
                <div class="user-info">
                    <div class="name"><?= escape($user['first_name'].' '.$user['last_name']) ?></div>
                    <div class="role-tag"><?= ucfirst($user['role']) ?></div>
                </div>
            </div>
        </div>
    </header>
    
    <div class="page-content">
    <?php
    // Show flash messages
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        echo '<div class="alert alert-'.$flash['type'].'" data-dismiss="4000">'.$flash['msg'].'</div>';
        unset($_SESSION['flash']);
    }
    ?>