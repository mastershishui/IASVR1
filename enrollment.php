<?php
$pageTitle = 'My Enrollment';
require_once 'includes/config.php';
requireLogin();
requireRole(['student']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

/* ─────────────────────────────────────────────────────────────────────────────
   HELPER – generate unique student number  e.g.  2024-0001
───────────────────────────────────────────────────────────────────────────── */
function generateStudentNumber($db) {
    $year = date('Y');
    do {
        $rand = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $num  = $year . '-' . $rand;
        $r    = $db->query("SELECT id FROM students WHERE student_number='$num'");
    } while ($r && $r->num_rows > 0);
    return $num;
}

/* ─────────────────────────────────────────────────────────────────────────────
   LOAD USER + STUDENT PROFILE
───────────────────────────────────────────────────────────────────────────── */
$userRow = $db->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();
$student = $db->query("
    SELECT s.*, p.name AS program_name, p.code AS program_code
    FROM   students s
    LEFT JOIN programs p ON s.program_id = p.id
    WHERE  s.user_id = $uid LIMIT 1
")->fetch_assoc();

/* ─────────────────────────────────────────────────────────────────────────────
   POST HANDLERS
───────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    /* ── SUBMIT ENROLLMENT APPLICATION ─────────────────────────────────── */
    if ($action === 'submit_enrollment') {
        $program_id  = (int)($_POST['program_id']       ?? 0);
        $year_level  = (int)($_POST['year_level']       ?? 1);
        $semester    = (int)($_POST['semester']         ?? 1);
        $birthdate   = trim($_POST['birthdate']         ?? '');
        $gender      = trim($_POST['gender']            ?? '');
        $address     = trim($_POST['address']           ?? '');
        $contact     = trim($_POST['contact_number']    ?? '');
        $guardian    = trim($_POST['guardian_name']     ?? '');
        $gcontact    = trim($_POST['guardian_contact']  ?? '');
        $section_ids = array_map('intval', $_POST['section_ids'] ?? []);
        $ay          = ACADEMIC_YEAR;

        if (!$program_id || !$birthdate || !$gender || !$address || !$contact) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Please complete all required fields.'];
            redirect('enrollment.php');
        }
        if (empty($section_ids)) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Please select at least one subject/section.'];
            redirect('enrollment.php');
        }

        /* Create or update student profile */
        if (!$student) {
            $stnum = generateStudentNumber($db);
            $st = $db->prepare("
                INSERT INTO students
                    (user_id,student_number,program_id,year_level,semester,
                     academic_year,birthdate,gender,address,
                     contact_number,guardian_name,guardian_contact,enrollment_status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'Pending')
            ");
            // 12 parameters: uid, stnum, program_id, year_level, semester,
            // ay, birthdate, gender, address, contact, guardian, gcontact
            $st->bind_param('isiiisssssss',
                $uid,$stnum,$program_id,$year_level,$semester,
                $ay,$birthdate,$gender,$address,$contact,$guardian,$gcontact
            );
            $st->execute();
            $student_id = $db->insert_id;
            $student    = $db->query("
                SELECT s.*,p.name AS program_name,p.code AS program_code
                FROM students s LEFT JOIN programs p ON s.program_id=p.id
                WHERE s.id=$student_id
            ")->fetch_assoc();
        } else {
            $student_id = (int)$student['id'];
            $st = $db->prepare("
                UPDATE students
                SET program_id=?,year_level=?,semester=?,birthdate=?,
                    gender=?,address=?,contact_number=?,
                    guardian_name=?,guardian_contact=?,enrollment_status='Pending'
                WHERE id=?
            ");
            $st->bind_param('iiissssssi',
                $program_id,$year_level,$semester,$birthdate,
                $gender,$address,$contact,$guardian,$gcontact,$student_id
            );
            $st->execute();
        }

        /* Insert enrollments */
        $inserted = 0;
        foreach ($section_ids as $sec_id) {
            $chk = $db->query("SELECT id FROM enrollments WHERE student_id=$student_id AND section_id=$sec_id AND status!='Dropped'");
            if ($chk && $chk->num_rows > 0) continue;
            $ins = $db->prepare("INSERT INTO enrollments (student_id,section_id,academic_year,semester,status) VALUES (?,?,?,?,'Pending')");
            $ins->bind_param('iisi',$student_id,$sec_id,$ay,$semester);
            $ins->execute();
            $inserted++;
        }

        /* Notify registrar */
        $rMsg  = escape($userRow['first_name'].' '.$userRow['last_name'])." submitted an enrollment application ($inserted subject/s).";
        $regs  = $db->query("SELECT id FROM users WHERE role IN ('registrar','admin') AND status='active'");
        while ($reg = $regs->fetch_assoc()) {
            $rid = $reg['id'];
            $db->query("INSERT INTO notifications (user_id,title,message,type) VALUES ($rid,'New Enrollment Application','$rMsg','info')");
        }

        logActivity('submit_enrollment','Enrollment',"Submitted $inserted subject(s) for AY $ay");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Enrollment submitted for $inserted subject(s). Awaiting Registrar validation."];
        redirect('enrollment.php');
    }

    /* ── DROP A PENDING ENROLLMENT ──────────────────────────────────────── */
    if ($action === 'drop' && $student) {
        $eid = (int)($_POST['enrollment_id'] ?? 0);
        $sid = (int)$student['id'];
        $upd = $db->prepare("UPDATE enrollments SET status='Dropped' WHERE id=? AND student_id=? AND status='Pending'");
        $upd->bind_param('ii',$eid,$sid);
        $upd->execute();
        $_SESSION['flash'] = $db->affected_rows > 0
            ? ['type'=>'success','msg'=>'Subject dropped successfully.']
            : ['type'=>'warning','msg'=>'Only Pending subjects can be dropped.'];
        redirect('enrollment.php');
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   VIEW DATA
───────────────────────────────────────────────────────────────────────────── */
$programs = $db->query("SELECT * FROM programs WHERE status='active' ORDER BY code")->fetch_all(MYSQLI_ASSOC);

/* Current enrollments this AY/Sem */
$myEnrollments = [];
$totalUnits    = 0;
if ($student) {
    $myEnrollments = $db->query("
        SELECT e.*,
               sec.section_code, sec.room, sec.day_time,
               sub.name AS subject_name, sub.code AS subject_code, sub.units,
               u.first_name AS fac_first, u.last_name AS fac_last,
               vr.first_name AS val_first, vr.last_name AS val_last
        FROM   enrollments e
        JOIN   sections sec ON e.section_id  = sec.id
        JOIN   subjects sub ON sec.subject_id = sub.id
        LEFT JOIN faculty  f  ON sec.faculty_id = f.id
        LEFT JOIN users    u  ON f.user_id      = u.id
        LEFT JOIN users   vr  ON e.validated_by = vr.id
        WHERE  e.student_id    = {$student['id']}
          AND  e.academic_year = '" . ACADEMIC_YEAR . "'
          AND  e.semester      = " . CURRENT_SEMESTER . "
          AND  e.status       != 'Dropped'
        ORDER  BY sub.code
    ")->fetch_all(MYSQLI_ASSOC);
    $totalUnits = array_sum(array_column($myEnrollments,'units'));
}

$statusCounts = [
    'Pending'   => count(array_filter($myEnrollments, fn($e)=>$e['status']==='Pending')),
    'Validated' => count(array_filter($myEnrollments, fn($e)=>$e['status']==='Validated')),
    'Paid'      => count(array_filter($myEnrollments, fn($e)=>$e['status']==='Paid')),
    'Enrolled'  => count(array_filter($myEnrollments, fn($e)=>$e['status']==='Enrolled')),
];
$hasEnrollment = count($myEnrollments) > 0;

/* Billing */
$billing = null;
if ($student) {
    $billing = $db->query("
        SELECT b.*, 
               (SELECT SUM(amount) FROM payments WHERE billing_id=b.id) AS total_paid_check
        FROM billing b
        WHERE student_id={$student['id']}
          AND academic_year='" . ACADEMIC_YEAR . "'
          AND semester=" . CURRENT_SEMESTER . "
        ORDER BY id DESC LIMIT 1
    ")->fetch_assoc();
}

/* Payments for billing */
$payments = [];
if ($billing) {
    $payments = $db->query("
        SELECT py.*, u.first_name, u.last_name
        FROM payments py
        LEFT JOIN users u ON py.received_by = u.id
        WHERE py.billing_id = {$billing['id']}
        ORDER BY py.payment_date DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

/* Available sections (exclude already enrolled) */
$enrolledSecIds = array_column($myEnrollments,'section_id');
$excl = !empty($enrolledSecIds) ? "AND sec.id NOT IN (" . implode(',', $enrolledSecIds) . ")" : '';
$availSections = $db->query("
    SELECT sec.*,
           sub.name AS subject_name, sub.code AS subject_code,
           sub.units, sub.lab_units, sub.year_level AS subject_year,
           pre.code AS prereq_code,
           u.first_name AS fac_first, u.last_name AS fac_last,
           (SELECT COUNT(*) FROM enrollments e WHERE e.section_id=sec.id AND e.status!='Dropped') AS enrolled_count
    FROM   sections sec
    JOIN   subjects sub ON sec.subject_id   = sub.id
    LEFT JOIN subjects pre ON sub.prerequisite_id = pre.id
    LEFT JOIN faculty   f  ON sec.faculty_id       = f.id
    LEFT JOIN users     u  ON f.user_id            = u.id
    WHERE  sec.status='Open'
      AND  sec.academic_year='" . ACADEMIC_YEAR . "'
      AND  sec.semester=" . CURRENT_SEMESTER . "
      $excl
    ORDER  BY sub.year_level, sub.code
")->fetch_all(MYSQLI_ASSOC);

require_once 'includes/header.php';

/* ── overall stage for visual progress ── */
$stage = 0;
if ($hasEnrollment)                  $stage = 1; // submitted
if ($statusCounts['Validated'] > 0 || $statusCounts['Paid'] > 0 || $statusCounts['Enrolled'] > 0) $stage = 2;
if ($statusCounts['Paid'] > 0 || $statusCounts['Enrolled'] > 0) $stage = 3;
if ($statusCounts['Enrolled'] > 0)   $stage = 4;
?>

<!---------------------------------------------------------------------------->
<!-- STYLES (scoped to this page)                                            -->
<!---------------------------------------------------------------------------->
<style>
.enroll-hero{display:flex;flex-direction:column;align-items:center;justify-content:center;
    min-height:430px;text-align:center;padding:48px 24px;}
.enroll-hero-icon{width:100px;height:100px;border-radius:50%;background:var(--primary-light);
    display:flex;align-items:center;justify-content:center;font-size:48px;margin-bottom:24px;
    box-shadow:0 8px 32px rgba(37,99,235,.15);}
.enroll-hero h2{font-size:28px;font-weight:800;margin-bottom:10px;}
.enroll-hero p{color:var(--text-muted);font-size:14px;max-width:450px;line-height:1.8;margin-bottom:36px;}
.btn-start{padding:16px 44px;font-size:16px;font-weight:700;border-radius:14px;
    box-shadow:0 8px 28px rgba(37,99,235,.35);transition:.2s;letter-spacing:.01em;}
.btn-start:hover{transform:translateY(-2px);box-shadow:0 12px 36px rgba(37,99,235,.45);}

/* Student ID card */
.student-id-card{background:linear-gradient(135deg,var(--primary) 60%,#1d4ed8);
    color:white;border-radius:14px;padding:14px 22px;text-align:center;
    box-shadow:0 4px 20px rgba(37,99,235,.3);min-width:190px;}
.student-id-card .label{font-size:10px;font-weight:700;letter-spacing:.12em;opacity:.8;text-transform:uppercase;}
.student-id-card .number{font-size:22px;font-weight:900;font-family:'DM Mono',monospace;
    letter-spacing:.06em;margin:4px 0;}
.student-id-card .sub{font-size:11px;opacity:.75;}

/* Stage progress bar */
.stage-bar{display:flex;align-items:center;}
.stage-node{flex:none;display:flex;flex-direction:column;align-items:center;gap:5px;min-width:80px;}
.stage-circle{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;
    justify-content:center;font-weight:800;font-size:16px;transition:.3s;border:2px solid;}
.stage-line{flex:1;height:3px;border-radius:2px;transition:.3s;margin-bottom:20px;}

/* Alert banners */
.enroll-banner{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;
    border-radius:10px;margin:0 0 0 0;}

/* Step modal tabs */
.modal-step-tab{flex:1;padding:11px 6px;text-align:center;font-size:12px;font-weight:600;
    border-bottom:2px solid transparent;color:var(--text-muted);transition:.2s;cursor:default;}
.modal-step-tab.active{color:var(--primary);border-bottom-color:var(--primary);}
.modal-step-tab .s-circle{width:22px;height:22px;border-radius:50%;margin:0 auto 4px;
    display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;
    background:var(--border);color:var(--text-muted);transition:.2s;}
.modal-step-tab.active .s-circle{background:var(--primary);color:white;}
.modal-step-tab.done .s-circle{background:var(--success);color:white;}
.modal-step-tab.done{color:var(--success);}

/* Subject select table */
.subj-table{width:100%;border-collapse:collapse;}
.subj-table th{background:var(--bg);padding:9px 13px;font-size:11px;text-transform:uppercase;
    letter-spacing:.05em;color:var(--text-muted);font-weight:700;text-align:left;
    position:sticky;top:0;z-index:2;}
.subj-table td{padding:10px 13px;border-top:1px solid var(--border);vertical-align:middle;}
.subj-table tr:hover:not(.full-row) td{background:rgba(37,99,235,.04);}
.full-row{opacity:.5;}
.subj-cb{width:17px;height:17px;cursor:pointer;accent-color:var(--primary);}
.slot-bar{height:4px;background:var(--border);border-radius:2px;margin-top:4px;overflow:hidden;}
.slot-fill{height:100%;border-radius:2px;transition:.3s;}

/* Summary unit counter */
.unit-counter{position:sticky;top:0;z-index:5;background:var(--primary-light);
    border:1px solid var(--primary);border-radius:8px;padding:10px 16px;
    display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}

/* Confirm table */
.confirm-tbl{width:100%;border-collapse:collapse;border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.confirm-tbl th{background:var(--bg);padding:9px 14px;font-size:11px;text-transform:uppercase;
    letter-spacing:.05em;color:var(--text-muted);font-weight:700;text-align:left;}
.confirm-tbl td{padding:10px 14px;border-top:1px solid var(--border);}

/* Payment table */
.pay-table{width:100%;border-collapse:collapse;}
.pay-table th{padding:9px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;
    color:var(--text-muted);font-weight:700;text-align:left;background:var(--bg);}
.pay-table td{padding:10px 14px;border-top:1px solid var(--border);font-size:13px;}

/* Responsive */
@media(max-width:640px){
    .stage-node{min-width:60px;} .stage-circle{width:36px;height:36px;font-size:13px;}
    .grid-2{grid-template-columns:1fr !important;}
}
</style>

<!---------------------------------------------------------------------------->
<!-- PAGE HEADER                                                             -->
<!---------------------------------------------------------------------------->
<div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
        <div>
            <h1 class="page-title">My Enrollment</h1>
            <p class="page-subtitle">Academic Year <strong><?= ACADEMIC_YEAR ?></strong> &mdash; Semester <?= CURRENT_SEMESTER ?></p>
        </div>
        <?php if ($student): ?>
        <div class="student-id-card">
            <div class="label">Student No.</div>
            <div class="number"><?= escape($student['student_number']) ?></div>
            <div class="sub"><?= escape($student['program_code'] ?? '—') ?> &middot; Year <?= $student['year_level'] ?> &middot; <?= escape($userRow['first_name'].' '.$userRow['last_name']) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php /* ─── FLASH MESSAGE ─────────────────────────────────────────────────── */
if (!empty($_SESSION['flash'])):
    $f = $_SESSION['flash']; unset($_SESSION['flash']);
?>
<div class="alert alert-<?= escape($f['type']) ?>" style="margin-bottom:18px;display:flex;align-items:center;gap:10px;">
    <span><?= $f['type']==='success'?'✅':($f['type']==='danger'?'❌':'ℹ️') ?></span>
    <span><?= escape($f['msg']) ?></span>
</div>
<?php endif; ?>

<!---------------------------------------------------------------------------->
<!-- NO ENROLLMENT YET  ─  BIG HERO + START BUTTON                         -->
<!---------------------------------------------------------------------------->
<?php if (!$hasEnrollment): ?>

<div class="card">
    <div class="enroll-hero">
        <div class="enroll-hero-icon">🎓</div>
        <h2>Welcome<?= $userRow['first_name'] ? ', '.escape($userRow['first_name']) : '' ?>!</h2>
        <p>
            You have not enrolled yet for <strong>AY <?= ACADEMIC_YEAR ?></strong>.
            Click the button below to start your enrollment. You will fill in your
            personal details, pick your subjects, and submit for Registrar approval.
            <?php if (!$student): ?>
            <br><br>
            <span style="background:var(--primary-light);color:var(--primary);padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600;">
                🆔 A unique Student Number will be assigned automatically
            </span>
            <?php endif; ?>
        </p>
        <button class="btn btn-primary btn-start" onclick="openEnrollModal()">
            🚀 &nbsp; Start Enrollment
        </button>
        <div style="margin-top:14px;font-size:12px;color:var(--text-muted);">
            After submission → Registrar validates → Pay fees → Officially enrolled
        </div>
    </div>
</div>

<?php else: /* ─── HAS ENROLLMENT ─────────────────────────────────────────── */ ?>

<!---------------------------------------------------------------------------->
<!-- STATS CARDS                                                             -->
<!---------------------------------------------------------------------------->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
    <?php
    $sc = [
        ['📚','blue',  count($myEnrollments), 'Subjects'],
        ['⚡','green', $totalUnits,            'Total Units'],
        ['⏳','orange',$statusCounts['Pending'],'Pending'],
        ['🎓','purple',$statusCounts['Enrolled'],'Enrolled'],
    ];
    foreach ($sc as [$icon,$color,$val,$lbl]):
    ?>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon <?= $color ?>"><?= $icon ?></div></div>
        <div class="stat-value"><?= $val ?></div>
        <div class="stat-label"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!---------------------------------------------------------------------------->
<!-- STAGE PROGRESS                                                          -->
<!---------------------------------------------------------------------------->
<div class="card" style="margin-bottom:20px;">
    <div class="card-body" style="padding:20px 28px;">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:16px;">Enrollment Progress</div>
        <div class="stage-bar">
            <?php
            $stages = [
                ['Submitted',  1, 'You applied',           'success'],
                ['Validated',  2, 'Registrar approved',    'primary'],
                ['Paid',       3, 'Fees settled',          'purple' ],
                ['Enrolled',   4, 'Officially enrolled',   'success'],
            ];
            foreach ($stages as $i => [$lbl,$req,$sub,$clr]):
                $done = $stage >= $req;
                $curr = $stage === $req - 1 && !$done;
            ?>
            <div class="stage-node">
                <div class="stage-circle" style="
                    background:<?= $done?"var(--$clr)":($curr?'white':'var(--bg)') ?>;
                    color:<?= $done?'white':($curr?"var(--$clr)":'var(--text-muted)') ?>;
                    border-color:<?= ($done||$curr)?"var(--$clr)":'var(--border)' ?>;
                    <?= $curr?"box-shadow:0 0 0 4px color-mix(in srgb, var(--$clr) 20%, white);":'' ?>
                ">
                    <?= $done ? '✓' : $i+1 ?>
                </div>
                <div style="font-size:12px;font-weight:<?= ($done||$curr)?'700':'500' ?>;color:<?= ($done||$curr)?"var(--$clr)":'var(--text-muted)' ?>;text-align:center;"><?= $lbl ?></div>
                <div style="font-size:10.5px;color:var(--text-muted);text-align:center;"><?= $sub ?></div>
            </div>
            <?php if ($i < count($stages)-1): ?>
            <div class="stage-line" style="background:<?= $stage>$req?"var(--$clr)":'var(--border)' ?>;"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!---------------------------------------------------------------------------->
<!-- STATUS BANNER                                                           -->
<!---------------------------------------------------------------------------->
<?php if ($statusCounts['Pending'] > 0): ?>
<div class="enroll-banner" style="background:#FFFBEB;border:1px solid #FCD34D;margin-bottom:20px;">
    <span style="font-size:22px;line-height:1;">⏳</span>
    <div>
        <div style="font-size:13.5px;font-weight:700;color:#92400E;">Awaiting Registrar Validation</div>
        <div style="font-size:12.5px;color:#92400E;opacity:.85;margin-top:2px;">
            <?= $statusCounts['Pending'] ?> subject(s) are under review. The Registrar will validate or reject your application. You will be notified.
        </div>
    </div>
</div>
<?php elseif ($statusCounts['Validated'] > 0 && (!$billing || $billing['status']==='Unpaid')): ?>
<div class="enroll-banner" style="background:#EFF6FF;border:1px solid #93C5FD;margin-bottom:20px;">
    <span style="font-size:22px;line-height:1;">✅</span>
    <div>
        <div style="font-size:13.5px;font-weight:700;color:#1E40AF;">Enrollment Validated — Proceed to Payment</div>
        <div style="font-size:12.5px;color:#1E40AF;opacity:.85;margin-top:2px;">
            Your enrollment has been validated by the Registrar. Please pay your fees at the Accounting Office to complete enrollment.
        </div>
    </div>
</div>
<?php elseif ($statusCounts['Enrolled'] > 0): ?>
<div class="enroll-banner" style="background:#ECFDF5;border:1px solid #6EE7B7;margin-bottom:20px;">
    <span style="font-size:22px;line-height:1;">🎓</span>
    <div>
        <div style="font-size:13.5px;font-weight:700;color:#065F46;">Congratulations! You are officially enrolled.</div>
        <div style="font-size:12.5px;color:#065F46;opacity:.85;margin-top:2px;">
            Your enrollment for AY <?= ACADEMIC_YEAR ?> is now official. Check your schedule below.
        </div>
    </div>
</div>
<?php endif; ?>

<!---------------------------------------------------------------------------->
<!-- MY ENROLLED SUBJECTS TABLE                                              -->
<!---------------------------------------------------------------------------->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <span class="card-title">📋 My Enrolled Subjects</span>
        <div style="display:flex;gap:8px;align-items:center;">
            <span class="badge badge-primary"><?= $totalUnits ?> units</span>
            <?php if ($stage < 2): ?>
            <button onclick="openEnrollModal()" class="btn btn-sm btn-outline">+ Add More Subjects</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:36px;">#</th>
                    <th>Subject Code</th>
                    <th>Subject Name</th>
                    <th>Section</th>
                    <th>Faculty</th>
                    <th>Schedule</th>
                    <th>Room</th>
                    <th style="text-align:center;">Units</th>
                    <th>Status</th>
                    <th style="width:80px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($myEnrollments as $idx => $en):
                    $stMap = ['Pending'=>'warning','Validated'=>'primary','Paid'=>'purple','Enrolled'=>'success'];
                    $icMap = ['Pending'=>'⏳','Validated'=>'✓','Paid'=>'💳','Enrolled'=>'🎓'];
                    $bc    = $stMap[$en['status']] ?? 'secondary';
                    $ic    = $icMap[$en['status']] ?? '';
                ?>
                <tr>
                    <td style="color:var(--text-muted);font-size:12px;"><?= $idx+1 ?></td>
                    <td>
                        <span style="font-weight:700;color:var(--primary);font-size:13.5px;"><?= escape($en['subject_code']) ?></span>
                    </td>
                    <td style="font-weight:600;"><?= escape($en['subject_name']) ?></td>
                    <td>
                        <code style="font-size:12px;background:var(--bg);padding:2px 7px;border-radius:4px;"><?= escape($en['section_code']) ?></code>
                    </td>
                    <td style="font-size:13px;">
                        <?= $en['fac_first']
                            ? escape($en['fac_first'].' '.$en['fac_last'])
                            : '<span style="color:var(--text-muted)">TBA</span>' ?>
                    </td>
                    <td style="font-size:12.5px;white-space:nowrap;"><?= escape($en['day_time'] ?: 'TBA') ?></td>
                    <td style="font-size:12.5px;"><?= escape($en['room'] ?: 'TBA') ?></td>
                    <td style="text-align:center;"><span class="badge badge-primary"><?= $en['units'] ?></span></td>
                    <td>
                        <span class="badge badge-<?= $bc ?>"><?= $ic ?> <?= $en['status'] ?></span>
                        <?php if ($en['validated_at'] && $en['status']==='Validated'): ?>
                        <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px;">
                            by <?= escape($en['val_first'].' '.$en['val_last']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($en['status']==='Pending'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="drop">
                            <input type="hidden" name="enrollment_id" value="<?= $en['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"
                                onclick="return confirm('Drop <?= escape($en['subject_code']) ?>?')">
                                Drop
                            </button>
                        </form>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--bg);">
                    <td colspan="7" style="text-align:right;font-weight:700;padding:12px 16px;font-size:13px;color:var(--text-muted);">TOTAL UNITS</td>
                    <td style="text-align:center;font-weight:900;font-size:18px;color:var(--primary);padding:12px 13px;"><?= $totalUnits ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!---------------------------------------------------------------------------->
<!-- PAYMENT / BILLING CARD                                                  -->
<!---------------------------------------------------------------------------->
<div class="card">
    <div class="card-header">
        <span class="card-title">💳 Payment for Enrollment</span>
        <?php if ($billing): ?>
        <span class="badge badge-<?= ['Unpaid'=>'danger','Partial'=>'warning','Paid'=>'success'][$billing['status']] ?? 'secondary' ?>">
            <?= $billing['status'] ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if (!$billing): ?>
    <!--- no billing yet -->
    <div style="text-align:center;padding:54px 24px;color:var(--text-muted);">
        <div style="font-size:44px;margin-bottom:14px;">🧾</div>
        <div style="font-size:15px;font-weight:700;margin-bottom:6px;">No Billing Record Yet</div>
        <div style="font-size:13px;max-width:380px;margin:0 auto;line-height:1.7;">
            The Accounting Office will generate your Statement of Account once the Registrar has validated your enrollment.
        </div>
    </div>

    <?php else: /* has billing */ ?>

    <div class="card-body">
        <!-- Fee breakdown + payment status -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;" class="grid-2">
            <!-- LEFT: Fee Breakdown -->
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px;">Statement of Account</div>
                <?php
                $feeRows = [
                    ['Tuition Fee',       $billing['tuition_fee']],
                    ['Miscellaneous',     $billing['misc_fees']],
                    ['Other Fees',        $billing['other_fees']],
                ];
                foreach ($feeRows as [$lbl,$val]):
                    if ((float)$val <= 0) continue; ?>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                    <span style="font-size:13px;color:var(--text-secondary);"><?= $lbl ?></span>
                    <span style="font-size:13px;font-weight:600;">&#8369;<?= number_format($val,2) ?></span>
                </div>
                <?php endforeach;
                if ((float)$billing['discount']>0): ?>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                    <span style="font-size:13px;color:var(--success);">Scholarship / Discount</span>
                    <span style="font-size:13px;font-weight:600;color:var(--success);">&#8722;&#8369;<?= number_format($billing['discount'],2) ?></span>
                </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;padding:12px 0 4px;">
                    <span style="font-size:14px;font-weight:700;">Total Amount Due</span>
                    <span style="font-size:19px;font-weight:900;color:var(--primary);">&#8369;<?= number_format($billing['total_amount'],2) ?></span>
                </div>
            </div>

            <!-- RIGHT: Payment Status -->
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px;">Payment Status</div>
                <div style="padding:14px 18px;background:var(--success-light);border-radius:10px;display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <span style="font-size:13px;color:var(--success);font-weight:600;">Amount Paid</span>
                    <span style="font-size:18px;font-weight:800;color:var(--success);">&#8369;<?= number_format($billing['amount_paid'],2) ?></span>
                </div>
                <div style="padding:14px 18px;background:<?= (float)$billing['balance']>0?'#FEF2F2':'var(--success-light)' ?>;border-radius:10px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="font-size:13px;color:<?= (float)$billing['balance']>0?'var(--danger)':'var(--success)' ?>;font-weight:600;">Remaining Balance</span>
                    <span style="font-size:18px;font-weight:800;color:<?= (float)$billing['balance']>0?'var(--danger)':'var(--success)' ?>;">
                        &#8369;<?= number_format($billing['balance'],2) ?>
                    </span>
                </div>
                <?php if ((float)$billing['balance']>0): ?>
                <div style="padding:11px 14px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;font-size:12.5px;color:#92400E;line-height:1.6;">
                    💡 Please proceed to the Accounting Office or use the online payment portal to settle your balance.
                </div>
                <?php else: ?>
                <div style="padding:11px 14px;background:#ECFDF5;border:1px solid #6EE7B7;border-radius:8px;font-size:12.5px;color:#065F46;line-height:1.6;">
                    ✅ Fully paid! Your enrollment is officially complete.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment History -->
        <?php if (!empty($payments)): ?>
        <div style="margin-top:28px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px;">Payment History</div>
            <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;">
                <table class="pay-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Date</th><th>Amount</th><th>Method</th><th>Reference No.</th><th>Received By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $pi => $py): ?>
                        <tr>
                            <td style="color:var(--text-muted);font-size:12px;"><?= $pi+1 ?></td>
                            <td><?= date('M d, Y h:i A', strtotime($py['payment_date'])) ?></td>
                            <td><strong style="color:var(--success);">&#8369;<?= number_format($py['amount'],2) ?></strong></td>
                            <td><span class="badge badge-primary" style="font-size:11px;"><?= escape($py['payment_method']) ?></span></td>
                            <td style="font-family:'DM Mono',monospace;font-size:12.5px;"><?= escape($py['reference_number'] ?: '—') ?></td>
                            <td><?= $py['first_name'] ? escape($py['first_name'].' '.$py['last_name']) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; /* end billing */ ?>
</div>

<?php endif; /* end hasEnrollment */ ?>


<!-- ═══════════════════════════════════════════════════════════════════════════
     ENROLLMENT MODAL  —  3-STEP FORM
═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-enrollment"
     style="align-items:flex-start;padding:20px 16px;overflow-y:auto;">
<div class="modal" style="max-width:720px;width:100%;margin:auto;border-radius:16px;overflow:hidden;">

    <!-- Modal Header -->
    <div style="background:linear-gradient(135deg,var(--primary),#1d4ed8);padding:20px 26px;display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div style="color:white;font-size:18px;font-weight:800;">🎓 Enrollment Application</div>
            <div style="color:rgba(255,255,255,.75);font-size:12px;margin-top:3px;">AY <?= ACADEMIC_YEAR ?> &middot; Semester <?= CURRENT_SEMESTER ?></div>
        </div>
        <button id="modal-close-btn"
            style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.2);border:none;color:white;cursor:pointer;font-size:18px;line-height:1;display:flex;align-items:center;justify-content:center;">
            &times;
        </button>
    </div>

    <!-- Step Tabs -->
    <div style="display:flex;background:#F8FAFC;border-bottom:1px solid var(--border);">
        <div id="stab-1" class="modal-step-tab active">
            <div class="s-circle">1</div>Personal Info
        </div>
        <div id="stab-2" class="modal-step-tab">
            <div class="s-circle">2</div>Select Subjects
        </div>
        <div id="stab-3" class="modal-step-tab">
            <div class="s-circle">3</div>Confirm
        </div>
    </div>

    <form method="POST" id="enrollment-form" autocomplete="off">
    <input type="hidden" name="action" value="submit_enrollment">

    <!---------------------------------------------------------------------->
    <!-- STEP 1: Personal Information                                       -->
    <!---------------------------------------------------------------------->
    <div id="estep-1" style="padding:26px;">
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:18px;line-height:1.6;">
            Fill in your personal information. Fields marked <span style="color:var(--danger);">*</span> are required.
            <?php if ($student): ?>Your existing profile is pre-filled — update if needed.<?php endif; ?>
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;" class="grid-2">
            <div class="form-group">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" value="<?= escape($userRow['first_name']) ?>"
                    readonly style="background:var(--bg);color:var(--text-muted);">
            </div>
            <div class="form-group">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" value="<?= escape($userRow['last_name']) ?>"
                    readonly style="background:var(--bg);color:var(--text-muted);">
            </div>
            <div class="form-group">
                <label class="form-label">Date of Birth <span style="color:var(--danger);">*</span></label>
                <input type="date" name="birthdate" id="f-birthdate" class="form-control" required
                    value="<?= escape($student['birthdate'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Gender <span style="color:var(--danger);">*</span></label>
                <select name="gender" id="f-gender" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach (['Male','Female','Other'] as $g): ?>
                    <option value="<?= $g ?>" <?= ($student['gender']??'')===$g?'selected':'' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Home Address <span style="color:var(--danger);">*</span></label>
            <input type="text" name="address" id="f-address" class="form-control" required
                placeholder="Street, Barangay, City, Province"
                value="<?= escape($student['address'] ?? '') ?>">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;" class="grid-2">
            <div class="form-group">
                <label class="form-label">Contact Number <span style="color:var(--danger);">*</span></label>
                <input type="text" name="contact_number" id="f-contact" class="form-control" required
                    placeholder="09XXXXXXXXX"
                    value="<?= escape($student['contact_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" class="form-control" value="<?= escape($userRow['email']) ?>"
                    readonly style="background:var(--bg);color:var(--text-muted);">
            </div>
            <div class="form-group">
                <label class="form-label">Program <span style="color:var(--danger);">*</span></label>
                <select name="program_id" id="f-program" class="form-control" required>
                    <option value="">— Select Program —</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= ($student['program_id']??0)==$p['id']?'selected':'' ?>>
                        <?= escape($p['code'].' — '.$p['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Year Level <span style="color:var(--danger);">*</span></label>
                <select name="year_level" id="f-year" class="form-control" required>
                    <?php for ($y=1;$y<=4;$y++): ?>
                    <option value="<?= $y ?>" <?= ($student['year_level']??1)==$y?'selected':'' ?>>Year <?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Semester <span style="color:var(--danger);">*</span></label>
                <select name="semester" id="f-sem" class="form-control" required>
                    <option value="1" <?= CURRENT_SEMESTER==1?'selected':'' ?>>1st Semester</option>
                    <option value="2" <?= CURRENT_SEMESTER==2?'selected':'' ?>>2nd Semester</option>
                    <option value="3">Summer</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Guardian Name <span style="color:var(--danger);">*</span></label>
                <input type="text" name="guardian_name" id="f-gname" class="form-control" required
                    placeholder="Parent / Guardian full name"
                    value="<?= escape($student['guardian_name'] ?? '') ?>">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label class="form-label">Guardian Contact <span style="color:var(--danger);">*</span></label>
                <input type="text" name="guardian_contact" id="f-gcontact" class="form-control" required
                    placeholder="09XXXXXXXXX"
                    value="<?= escape($student['guardian_contact'] ?? '') ?>">
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:10px;">
            <button type="button" class="btn btn-primary" onclick="goStep(2)">
                Next: Select Subjects &rarr;
            </button>
        </div>
    </div>

    <!---------------------------------------------------------------------->
    <!-- STEP 2: Select Subjects                                            -->
    <!---------------------------------------------------------------------->
    <div id="estep-2" style="padding:26px;display:none;">

        <div class="unit-counter">
            <span style="font-size:13px;font-weight:700;color:var(--primary);">
                Selected: <span id="sel-count">0</span> subject(s)
            </span>
            <span style="font-size:13px;font-weight:700;color:var(--primary);">
                Units: <span id="sel-units" style="transition:.2s;">0</span> / 24
            </span>
        </div>

        <?php if (empty($availSections)): ?>
        <div style="text-align:center;padding:40px 20px;color:var(--text-muted);border:1px solid var(--border);border-radius:10px;">
            <div style="font-size:32px;margin-bottom:10px;">📭</div>
            <div style="font-size:14px;font-weight:600;">No open sections available right now.</div>
            <div style="font-size:13px;margin-top:4px;">Please check back later or contact the Registrar.</div>
        </div>
        <?php else: ?>
        <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;max-height:360px;overflow-y:auto;">
            <table class="subj-table">
                <thead>
                    <tr>
                        <th style="width:40px;text-align:center;"></th>
                        <th>Subject</th>
                        <th>Section / Instructor</th>
                        <th>Schedule</th>
                        <th style="text-align:center;">Units</th>
                        <th style="text-align:center;">Slots</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($availSections as $sec):
                        $slots   = (int)$sec['max_students'] - (int)$sec['enrolled_count'];
                        $full    = $slots <= 0;
                        $slotClr = $slots<=5 ? 'var(--danger)' : ($slots<=10 ? 'var(--warning)' : 'var(--success)');
                        $pct     = $sec['max_students']>0 ? min(100, round(($sec['enrolled_count']/$sec['max_students'])*100)) : 0;
                    ?>
                    <tr <?= $full ? 'class="full-row"' : '' ?>>
                        <td style="text-align:center;padding:10px 6px;">
                            <?php if (!$full): ?>
                            <input type="checkbox"
                                name="section_ids[]"
                                value="<?= $sec['id'] ?>"
                                class="subj-cb"
                                data-units="<?= $sec['units'] ?>"
                                data-code="<?= escape($sec['subject_code']) ?>"
                                data-name="<?= escape($sec['subject_name']) ?>"
                                data-sec="<?= escape($sec['section_code']) ?>"
                                data-sched="<?= escape($sec['day_time']?:'TBA') ?>">
                            <?php else: ?>
                            <span style="font-size:10px;background:#FEE2E2;color:var(--danger);padding:2px 6px;border-radius:4px;font-weight:700;">FULL</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:700;color:var(--primary);font-size:13px;"><?= escape($sec['subject_code']) ?></div>
                            <div style="font-size:12px;color:var(--text-secondary);"><?= escape($sec['subject_name']) ?></div>
                            <?php if ($sec['prereq_code']): ?>
                            <span style="font-size:10px;background:#FFFBEB;color:#92400E;padding:1px 7px;border-radius:4px;font-weight:600;border:1px solid #FCD34D;">
                                Pre-req: <?= escape($sec['prereq_code']) ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code style="font-size:11.5px;background:var(--bg);padding:2px 7px;border-radius:4px;"><?= escape($sec['section_code']) ?></code>
                            <?php if ($sec['fac_first']): ?>
                            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;"><?= escape($sec['fac_first'].' '.$sec['fac_last']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;white-space:nowrap;">
                            <?= escape($sec['day_time']?:'TBA') ?>
                            <?php if ($sec['room']): ?>
                            <div style="font-size:11px;color:var(--text-muted);"><?= escape($sec['room']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;"><span class="badge badge-primary"><?= $sec['units'] ?></span></td>
                        <td style="text-align:center;min-width:80px;">
                            <div style="font-size:12px;font-weight:700;color:<?= $slotClr ?>;">
                                <?= $full ? 'Full' : "$slots left" ?>
                            </div>
                            <div class="slot-bar">
                                <div class="slot-fill" style="width:<?= $pct ?>%;background:<?= $slotClr ?>;"></div>
                            </div>
                            <div style="font-size:10px;color:var(--text-muted);"><?= $sec['enrolled_count'] ?>/<?= $sec['max_students'] ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div style="display:flex;justify-content:space-between;margin-top:18px;">
            <button type="button" class="btn btn-outline" onclick="goStep(1)">&larr; Back</button>
            <button type="button" class="btn btn-primary" onclick="goStep(3)">Next: Review &rarr;</button>
        </div>
    </div>

    <!---------------------------------------------------------------------->
    <!-- STEP 3: Confirm & Submit                                           -->
    <!---------------------------------------------------------------------->
    <div id="estep-3" style="padding:26px;display:none;">

        <!-- Student summary box -->
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:42px;height:42px;border-radius:50%;background:var(--primary-light);
                    display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">👤</div>
                <div>
                    <div style="font-size:15px;font-weight:700;"><?= escape($userRow['first_name'].' '.$userRow['last_name']) ?></div>
                    <div id="confirm-meta" style="font-size:12.5px;color:var(--text-muted);margin-top:2px;"></div>
                </div>
                <?php if (!$student): ?>
                <div style="margin-left:auto;background:var(--primary);color:white;padding:6px 12px;border-radius:8px;font-size:11px;font-weight:700;text-align:center;">
                    🆔 Student No.<br>will be assigned
                </div>
                <?php else: ?>
                <div style="margin-left:auto;background:var(--primary-light);color:var(--primary);padding:6px 12px;border-radius:8px;font-size:11.5px;font-weight:700;text-align:center;font-family:'DM Mono',monospace;">
                    <?= escape($student['student_number']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Selected subjects confirmation table -->
        <div id="confirm-list" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px;">
            <!-- filled by JS -->
        </div>

        <!-- Total units -->
        <div style="background:var(--primary-light);border-radius:10px;padding:13px 18px;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <span style="font-size:14px;font-weight:700;color:var(--primary);">Total Units Selected</span>
            <span id="confirm-units" style="font-size:24px;font-weight:900;color:var(--primary);font-family:'DM Mono',monospace;">0</span>
        </div>

        <!-- Info notes -->
        <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:13px 16px;font-size:12.5px;color:#1E40AF;line-height:1.7;margin-bottom:14px;">
            📌 <strong>What happens next:</strong><br>
            1. The Registrar will review and validate your subjects.<br>
            2. Once validated, the Accounting Office will generate your billing.<br>
            3. After payment, you'll be officially enrolled.
            <?php if (!$student): ?>
            <br>🆔 Your unique Student Number will be auto-generated on submission.
            <?php endif; ?>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;">
            <button type="button" class="btn btn-outline" onclick="goStep(2)">&larr; Back</button>
            <button type="submit" class="btn btn-primary" style="padding:13px 36px;font-size:15px;font-weight:700;border-radius:10px;">
                🚀 &nbsp; Submit Enrollment
            </button>
        </div>
    </div>

    </form><!-- end enrollment form -->
</div><!-- end modal box -->
</div><!-- end modal overlay -->


<!-- ═══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════════════════════ -->
<script>
/* Open / close modal */
function openEnrollModal() {
    document.getElementById('modal-enrollment').classList.add('open');
    goStep(1);
}

document.getElementById('modal-close-btn').addEventListener('click', () => {
    document.getElementById('modal-enrollment').classList.remove('open');
});
document.getElementById('modal-enrollment').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});

/* ── Step Navigation ──────────────────────────────────────────────────────── */
function goStep(n) {
    /* validate step 1 before proceeding */
    if (n >= 2) {
        const required = ['f-birthdate','f-gender','f-address','f-contact','f-program','f-gname','f-gcontact'];
        let ok = true;
        required.forEach(id => {
            const el = document.getElementById(id);
            if (el && !el.value.trim()) {
                el.style.borderColor = 'var(--danger)';
                el.style.boxShadow   = '0 0 0 3px rgba(239,68,68,.15)';
                ok = false;
            } else if (el) {
                el.style.borderColor = '';
                el.style.boxShadow   = '';
            }
        });
        if (!ok) {
            showToast('Please complete all required fields.', 'danger');
            goStepUI(1); return;
        }
    }
    /* validate step 2 */
    if (n >= 3) {
        const checked = document.querySelectorAll('.subj-cb:checked');
        if (!checked.length) { showToast('Please select at least one subject.', 'danger'); goStepUI(2); return; }
        const units = [...checked].reduce((s,cb)=>s+parseInt(cb.dataset.units||0),0);
        if (units > 24) { showToast('Maximum unit load is 24. Please deselect some subjects.', 'danger'); goStepUI(2); return; }
        buildConfirm();
    }
    goStepUI(n);
}

function goStepUI(n) {
    [1,2,3].forEach(i => {
        document.getElementById('estep-'+i).style.display = i===n ? 'block' : 'none';
        const tab = document.getElementById('stab-'+i);
        tab.classList.toggle('active', i===n);
        tab.classList.toggle('done',   i<n);
    });
    document.getElementById('modal-enrollment').scrollTop = 0;
}

/* ── Live unit counter ────────────────────────────────────────────────────── */
document.addEventListener('change', e => {
    if (!e.target.classList.contains('subj-cb')) return;
    let total=0, count=0;
    document.querySelectorAll('.subj-cb:checked').forEach(cb => {
        total += parseInt(cb.dataset.units||0); count++;
    });
    document.getElementById('sel-count').textContent = count;
    const unitsEl = document.getElementById('sel-units');
    unitsEl.textContent  = total;
    unitsEl.style.color  = total>24 ? 'var(--danger)' : 'var(--primary)';
    unitsEl.style.fontWeight = total>24 ? '900' : '700';
});

/* ── Build confirm step ───────────────────────────────────────────────────── */
function buildConfirm() {
    const checked = [...document.querySelectorAll('.subj-cb:checked')];
    const progEl  = document.getElementById('f-program');
    const yearEl  = document.getElementById('f-year');
    const semEl   = document.getElementById('f-sem');
    const meta    = [
        progEl.options[progEl.selectedIndex]?.text ?? '',
        yearEl.options[yearEl.selectedIndex]?.text ?? '',
        semEl.options[semEl.selectedIndex]?.text   ?? '',
    ].filter(Boolean).join(' · ');
    document.getElementById('confirm-meta').textContent = meta;

    let html  = '<table style="width:100%;border-collapse:collapse;">';
    html += '<thead><tr style="background:var(--bg);">'
        + '<th style="padding:9px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);text-align:left;">Subject</th>'
        + '<th style="padding:9px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);text-align:left;">Section</th>'
        + '<th style="padding:9px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);text-align:left;">Schedule</th>'
        + '<th style="padding:9px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);text-align:center;">Units</th>'
        + '</tr></thead><tbody>';
    let total = 0;
    checked.forEach(cb => {
        total += parseInt(cb.dataset.units||0);
        html += `<tr style="border-top:1px solid var(--border);">
            <td style="padding:10px 14px;">
                <strong style="color:var(--primary);font-size:13px;">${cb.dataset.code}</strong>
                <div style="font-size:12px;color:var(--text-secondary);">${cb.dataset.name}</div>
            </td>
            <td style="padding:10px 14px;"><code style="font-size:12px;">${cb.dataset.sec}</code></td>
            <td style="padding:10px 14px;font-size:12.5px;">${cb.dataset.sched}</td>
            <td style="padding:10px 14px;text-align:center;"><span class="badge badge-primary">${cb.dataset.units}</span></td>
        </tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('confirm-list').innerHTML = html;
    document.getElementById('confirm-units').textContent = total;
}

/* ── Toast helper ─────────────────────────────────────────────────────────── */
function showToast(msg, type='info') {
    const colors = {success:'#065F46',danger:'#991B1B',warning:'#92400E',info:'#1E40AF'};
    const bgs    = {success:'#ECFDF5',danger:'#FEF2F2',warning:'#FFFBEB',info:'#EFF6FF'};
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;
        background:${bgs[type]};color:${colors[type]};
        border:1px solid;padding:13px 18px;border-radius:10px;
        font-size:13.5px;font-weight:600;max-width:340px;
        box-shadow:0 8px 24px rgba(0,0,0,.12);animation:slideUp .25s ease;`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.style.opacity='0', 3000);
    setTimeout(() => t.remove(), 3300);
}

/* confirm buttons */
document.addEventListener('click', e => {
    const btn = e.target.closest('[data-confirm]');
    if (btn) {
        e.preventDefault();
        if (confirm(btn.dataset.confirm)) btn.closest('form').submit();
    }
});
</script>

<style>
@keyframes slideUp {
    from {transform:translateY(20px);opacity:0;}
    to   {transform:translateY(0);opacity:1;}
}
</style>

<?php require_once 'includes/footer.php'; ?>
