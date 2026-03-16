<?php
$pageTitle = 'Enrollment Queue';
require_once 'includes/config.php';
requireLogin();
requireRole(['admin','registrar']);
$db  = getDB();
$rid = (int)$_SESSION['user_id'];

/* ─────────────────────────────────────────────────────────────────────────────
   POST HANDLERS
───────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $eid    = (int)($_POST['enrollment_id']  ?? 0);   // single enrollment
    $sid    = (int)($_POST['student_id']     ?? 0);   // all enrollments of a student

    /* ── Validate one subject ── */
    if ($action === 'validate' && $eid) {
        $st = $db->prepare("UPDATE enrollments SET status='Validated',validated_by=?,validated_at=NOW() WHERE id=? AND status='Pending'");
        $st->bind_param('ii',$rid,$eid);
        $st->execute();
        logActivity('validate','Enrollment',"Validated enrollment #$eid");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Subject validated.'];
    }

    /* ── Validate ALL pending subjects for a student ── */
    if ($action === 'validate_all' && $sid) {
        $st = $db->prepare("UPDATE enrollments SET status='Validated',validated_by=?,validated_at=NOW() WHERE student_id=? AND status='Pending' AND academic_year=? AND semester=?");
        $ay  = ACADEMIC_YEAR;
        $sem = CURRENT_SEMESTER;
        $st->bind_param('iisi',$rid,$sid,$ay,$sem);
        $st->execute();
        $n = $db->affected_rows;

        /* Update student enrollment_status */
        $db->query("UPDATE students SET enrollment_status='Active' WHERE id=$sid");

        /* Notify the student */
        $sRow = $db->query("SELECT user_id FROM students WHERE id=$sid")->fetch_assoc();
        if ($sRow) {
            $suid = $sRow['user_id'];
            $db->query("INSERT INTO notifications (user_id,title,message,type)
                VALUES ($suid,'Enrollment Validated','Your enrollment has been validated by the Registrar. Please proceed to the Accounting Office to pay your fees.','success')");
        }

        logActivity('validate_all','Enrollment',"Validated $n subject(s) for student #$sid");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ All $n subject(s) validated. Student notified."];
    }

    /* ── Approve/Enroll (after payment) all validated for a student ── */
    if ($action === 'enroll_all' && $sid) {
        $st = $db->prepare("UPDATE enrollments SET status='Enrolled' WHERE student_id=? AND status IN ('Validated','Paid') AND academic_year=? AND semester=?");
        $ay  = ACADEMIC_YEAR; $sem = CURRENT_SEMESTER;
        $st->bind_param('isi',$sid,$ay,$sem);
        $st->execute();
        $n = $db->affected_rows;
        $db->query("UPDATE students SET enrollment_status='Active' WHERE id=$sid");

        $sRow = $db->query("SELECT user_id FROM students WHERE id=$sid")->fetch_assoc();
        if ($sRow) {
            $suid = $sRow['user_id'];
            $db->query("INSERT INTO notifications (user_id,title,message,type)
                VALUES ($suid,'You Are Now Enrolled!','Congratulations! Your enrollment for AY " . ACADEMIC_YEAR . " is now official.','success')");
        }

        logActivity('enroll_all','Enrollment',"Officially enrolled student #$sid ($n subjects)");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"🎓 Student officially enrolled in $n subject(s)."];
    }

    /* ── Reject one subject ── */
    if ($action === 'reject' && $eid) {
        $reason = trim($_POST['reason'] ?? 'Rejected by Registrar.');
        $st = $db->prepare("UPDATE enrollments SET status='Dropped' WHERE id=?");
        $st->bind_param('i',$eid);
        $st->execute();
        logActivity('reject','Enrollment',"Rejected enrollment #$eid: $reason");
        $_SESSION['flash'] = ['type'=>'warning','msg'=>'Subject rejected.'];
    }

    /* ── Reject all pending for a student ── */
    if ($action === 'reject_all' && $sid) {
        $st = $db->prepare("UPDATE enrollments SET status='Dropped' WHERE student_id=? AND status='Pending' AND academic_year=? AND semester=?");
        $ay=$db->real_escape_string(ACADEMIC_YEAR); $sem=CURRENT_SEMESTER;
        $st->bind_param('isi',$sid,$ay,$sem);
        $st->execute();
        $n = $db->affected_rows;

        $sRow = $db->query("SELECT user_id FROM students WHERE id=$sid")->fetch_assoc();
        if ($sRow) {
            $suid = $sRow['user_id'];
            $db->query("INSERT INTO notifications (user_id,title,message,type)
                VALUES ($suid,'Enrollment Not Approved','Your enrollment application was not approved. Please visit the Registrar\\'s Office for more information.','warning')");
        }

        logActivity('reject_all','Enrollment',"Rejected all $n subject(s) for student #$sid");
        $_SESSION['flash'] = ['type'=>'warning','msg'=>"Rejected $n subject(s). Student notified."];
    }

    redirect('enrollment_queue.php' . (isset($_GET['status']) ? '?status='.$_GET['status'] : ''));
}

/* ─────────────────────────────────────────────────────────────────────────────
   QUERY – group enrollments by student
───────────────────────────────────────────────────────────────────────────── */
$statusFilter  = $_GET['status'] ?? 'Pending';
$validStatuses = ['Pending','Validated','Paid','Enrolled','Dropped'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'Pending';

/* Count per status */
$counts = [];
foreach ($validStatuses as $st) {
    $counts[$st] = $db->query("
        SELECT COUNT(*) as c FROM enrollments
        WHERE status='$st'
          AND academic_year='" . ACADEMIC_YEAR . "'
          AND semester=" . CURRENT_SEMESTER
    )->fetch_assoc()['c'] ?? 0;
}

/* Raw enrollments */
$rawEnrollments = $db->query("
    SELECT e.*,
           u.first_name, u.last_name, u.email,
           s.id AS student_db_id, s.student_number, s.year_level, s.enrollment_status AS stu_status,
           p.name AS program_name, p.code AS program_code,
           sec.section_code, sec.room, sec.day_time,
           sub.name AS subject_name, sub.code AS subject_code, sub.units,
           fu.first_name AS fac_first, fu.last_name AS fac_last,
           vr.first_name AS val_first, vr.last_name AS val_last
    FROM   enrollments e
    JOIN   students s   ON e.student_id  = s.id
    JOIN   users u      ON s.user_id     = u.id
    LEFT JOIN programs p  ON s.program_id  = p.id
    JOIN   sections sec ON e.section_id  = sec.id
    JOIN   subjects sub ON sec.subject_id = sub.id
    LEFT JOIN faculty f   ON sec.faculty_id = f.id
    LEFT JOIN users fu    ON f.user_id      = fu.id
    LEFT JOIN users vr    ON e.validated_by = vr.id
    WHERE  e.status        = '$statusFilter'
      AND  e.academic_year = '" . ACADEMIC_YEAR . "'
      AND  e.semester      = " . CURRENT_SEMESTER . "
    ORDER  BY s.id, sub.code
")->fetch_all(MYSQLI_ASSOC);

/* Group by student */
$grouped = [];
foreach ($rawEnrollments as $row) {
    $sid = $row['student_db_id'];
    if (!isset($grouped[$sid])) {
        $grouped[$sid] = [
            'student_db_id' => $sid,
            'student_number'=> $row['student_number'],
            'first_name'    => $row['first_name'],
            'last_name'     => $row['last_name'],
            'email'         => $row['email'],
            'program_name'  => $row['program_name'],
            'program_code'  => $row['program_code'],
            'year_level'    => $row['year_level'],
            'stu_status'    => $row['stu_status'],
            'enrollments'   => [],
        ];
    }
    $grouped[$sid]['enrollments'][] = $row;
}

require_once 'includes/header.php';
?>

<style>
.queue-student-card{border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;background:white;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:.2s;}
.queue-student-card:hover{box-shadow:0 4px 18px rgba(0,0,0,.08);}
.queue-student-head{padding:16px 20px;background:var(--bg);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;border-bottom:1px solid var(--border);}
.student-id-pill{background:var(--primary);color:white;font-family:'DM Mono',monospace;font-size:13px;font-weight:800;padding:5px 14px;border-radius:20px;letter-spacing:.05em;}
.action-bar{display:flex;gap:8px;flex-wrap:wrap;}
.enroll-sub-table{width:100%;border-collapse:collapse;}
.enroll-sub-table th{padding:9px 16px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);font-weight:700;text-align:left;background:#FAFBFF;}
.enroll-sub-table td{padding:10px 16px;border-top:1px solid var(--border);font-size:13.5px;vertical-align:middle;}
</style>

<div class="page-header">
    <div class="flex items-center justify-between" style="flex-wrap:wrap;gap:12px;">
        <div>
            <h1 class="page-title">Enrollment Queue</h1>
            <p class="page-subtitle">Review, validate, and approve student enrollment applications.</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <span class="badge badge-danger" style="font-size:13px;padding:6px 14px;"><?= $counts['Pending'] ?> Pending</span>
            <span class="badge badge-primary" style="font-size:13px;padding:6px 14px;"><?= $counts['Validated'] ?> Validated</span>
            <span class="badge badge-success" style="font-size:13px;padding:6px 14px;"><?= $counts['Enrolled'] ?> Enrolled</span>
        </div>
    </div>
</div>

<?php if (!empty($_SESSION['flash'])):
    $f = $_SESSION['flash']; unset($_SESSION['flash']);
?>
<div class="alert alert-<?= $f['type'] ?>" style="margin-bottom:18px;display:flex;align-items:center;gap:10px;">
    <span><?= $f['type']==='success'?'✅':($f['type']==='warning'?'⚠️':'ℹ️') ?></span>
    <span><?= escape($f['msg']) ?></span>
</div>
<?php endif; ?>

<!-- Workflow Banner -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-body" style="padding:14px 22px;">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px;">Enrollment Workflow</div>
        <div style="display:flex;align-items:center;gap:0;overflow-x:auto;">
            <?php
            $flow = [
                ['Student Applies','Pending','warning'],
                ['Registrar Validates','Validated','primary'],
                ['Student Pays Fees','Paid','purple'],
                ['Officially Enrolled','Enrolled','success'],
            ];
            foreach ($flow as $i => [$lbl,$st,$clr]):
                $active = $statusFilter === $st;
            ?>
            <div style="flex:1;min-width:110px;text-align:center;padding:0 6px;">
                <div style="width:36px;height:36px;border-radius:50%;margin:0 auto 6px;
                    background:<?= $active?"var(--$clr)":'var(--bg)' ?>;
                    color:<?= $active?'white':'var(--text-muted)' ?>;
                    border:2px solid <?= $active?"var(--$clr)":'var(--border)' ?>;
                    display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">
                    <?= $i+1 ?>
                </div>
                <div style="font-size:11.5px;font-weight:<?= $active?'700':'500' ?>;color:<?= $active?"var(--$clr)":'var(--text-muted)' ?>;"><?= $lbl ?></div>
            </div>
            <?php if ($i < count($flow)-1): ?>
            <div style="width:30px;height:2px;background:var(--border);flex-shrink:0;margin-bottom:18px;"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Status Filter Tabs -->
<div class="tabs-nav" style="margin-bottom:20px;">
    <?php foreach ($validStatuses as $st): ?>
    <a href="?status=<?= $st ?>" class="tab-btn <?= $statusFilter===$st?'active':'' ?>">
        <?= $st ?>
        <span style="font-size:11px;margin-left:5px;opacity:.7;">(<?= $counts[$st] ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Student Cards -->
<?php if (empty($grouped)): ?>
<div class="card">
    <div style="text-align:center;padding:60px 24px;color:var(--text-muted);">
        <div style="font-size:44px;margin-bottom:14px;">📭</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:6px;">No <?= $statusFilter ?> Enrollments</div>
        <div style="font-size:13px;">There are no enrollment applications with status <strong><?= $statusFilter ?></strong> right now.</div>
    </div>
</div>
<?php else: ?>

<?php foreach ($grouped as $group): ?>
<div class="queue-student-card">

    <!-- Student Header -->
    <div class="queue-student-head">
        <div style="display:flex;align-items:center;gap:14px;">
            <!-- Avatar -->
            <div style="width:46px;height:46px;border-radius:50%;background:var(--primary-light);
                display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:var(--primary);flex-shrink:0;">
                <?= strtoupper(substr($group['first_name'],0,1).substr($group['last_name'],0,1)) ?>
            </div>
            <div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:15px;font-weight:700;"><?= escape($group['first_name'].' '.$group['last_name']) ?></span>
                    <?php if ($group['student_number']): ?>
                    <span class="student-id-pill"><?= escape($group['student_number']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size:12.5px;color:var(--text-muted);margin-top:3px;">
                    <?= escape($group['email']) ?>
                    &bull; <?= escape($group['program_code'] ?? '—') ?>
                    &bull; Year <?= $group['year_level'] ?>
                    &bull; <strong><?= count($group['enrollments']) ?> subject(s)</strong>
                    &bull; <strong><?= array_sum(array_column($group['enrollments'],'units')) ?> units</strong>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-bar">
            <?php if ($statusFilter === 'Pending'): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="validate_all">
                <input type="hidden" name="student_id" value="<?= $group['student_db_id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm"
                    onclick="return confirm('Validate ALL subjects for <?= escape($group['first_name'].' '.$group['last_name']) ?>?')">
                    ✅ Validate All Subjects
                </button>
            </form>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="reject_all">
                <input type="hidden" name="student_id" value="<?= $group['student_db_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Reject ALL subjects for this student?')">
                    ✕ Reject All
                </button>
            </form>

            <?php elseif ($statusFilter === 'Validated'): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="enroll_all">
                <input type="hidden" name="student_id" value="<?= $group['student_db_id'] ?>">
                <button type="submit" class="btn btn-success btn-sm"
                    onclick="return confirm('Mark this student as OFFICIALLY ENROLLED?')">
                    🎓 Mark as Enrolled
                </button>
            </form>

            <?php elseif ($statusFilter === 'Enrolled'): ?>
            <span class="badge badge-success" style="padding:8px 16px;font-size:12px;">🎓 Enrolled</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Subjects Table -->
    <div style="overflow-x:auto;">
        <table class="enroll-sub-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Subject Code</th>
                    <th>Subject Name</th>
                    <th>Section</th>
                    <th>Faculty</th>
                    <th>Schedule / Room</th>
                    <th style="text-align:center;">Units</th>
                    <th>Status</th>
                    <th>Applied At</th>
                    <?php if ($statusFilter === 'Pending'): ?><th style="width:100px;">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($group['enrollments'] as $idx => $en):
                    $stMap = ['Pending'=>'warning','Validated'=>'primary','Paid'=>'purple','Enrolled'=>'success','Dropped'=>'danger'];
                    $bc    = $stMap[$en['status']] ?? 'secondary';
                ?>
                <tr>
                    <td style="color:var(--text-muted);font-size:12px;"><?= $idx+1 ?></td>
                    <td>
                        <span style="font-weight:700;color:var(--primary);"><?= escape($en['subject_code']) ?></span>
                    </td>
                    <td style="font-weight:600;"><?= escape($en['subject_name']) ?></td>
                    <td>
                        <code style="font-size:12px;background:var(--bg);padding:2px 7px;border-radius:4px;"><?= escape($en['section_code']) ?></code>
                    </td>
                    <td style="font-size:13px;">
                        <?= $en['fac_first'] ? escape($en['fac_first'].' '.$en['fac_last']) : '<span style="color:var(--text-muted)">TBA</span>' ?>
                    </td>
                    <td style="font-size:12.5px;">
                        <?= escape($en['day_time'] ?: 'TBA') ?>
                        <?php if ($en['room']): ?>
                        <div style="font-size:11px;color:var(--text-muted);"><?= escape($en['room']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;"><span class="badge badge-primary"><?= $en['units'] ?></span></td>
                    <td>
                        <span class="badge badge-<?= $bc ?>"><?= $en['status'] ?></span>
                        <?php if ($en['validated_at']): ?>
                        <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px;">
                            by <?= escape($en['val_first'].' '.$en['val_last']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);">
                        <?= date('M d, Y', strtotime($en['enrolled_at'])) ?><br>
                        <span style="font-size:11px;"><?= date('h:i A', strtotime($en['enrolled_at'])) ?></span>
                    </td>
                    <?php if ($statusFilter === 'Pending'): ?>
                    <td>
                        <div style="display:flex;gap:5px;flex-wrap:wrap;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="validate">
                                <input type="hidden" name="enrollment_id" value="<?= $en['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary" title="Validate this subject"
                                    onclick="return confirm('Validate <?= escape($en['subject_code']) ?>?')">✓</button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="enrollment_id" value="<?= $en['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Reject this subject"
                                    onclick="return confirm('Reject <?= escape($en['subject_code']) ?>?')">✕</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--bg);">
                    <td colspan="<?= $statusFilter==='Pending'?'6':'6' ?>" style="text-align:right;padding:10px 16px;font-size:12px;font-weight:700;color:var(--text-muted);">
                        TOTAL UNITS:
                    </td>
                    <td style="text-align:center;font-weight:900;font-size:17px;color:var(--primary);padding:10px 16px;">
                        <?= array_sum(array_column($group['enrollments'],'units')) ?>
                    </td>
                    <td colspan="<?= $statusFilter==='Pending'?'3':'2' ?>"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endforeach; ?>

<div style="padding:12px 0;font-size:13px;color:var(--text-muted);text-align:center;">
    Showing <?= count($grouped) ?> student application(s) with status <strong><?= $statusFilter ?></strong>
    &mdash; AY <?= ACADEMIC_YEAR ?>
</div>

<?php endif; // end empty check ?>

<?php require_once 'includes/footer.php'; ?>
