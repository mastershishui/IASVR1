<?php
$pageTitle = 'Grade Encoding';
require_once 'includes/config.php';
requireLogin();
requireRole(['admin','registrar','faculty']);
$db = getDB();
$role = $_SESSION['role'];

// --- Auto-migrate: ensure required schema exists ---
$r = $db->query("SHOW COLUMNS FROM grades LIKE 'prelim'");
if ($r && $r->num_rows === 0) {
    $db->query("ALTER TABLE grades ADD COLUMN prelim DECIMAL(5,2) NULL AFTER enrollment_id");
}
$db->query("CREATE TABLE IF NOT EXISTS grade_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_id INT NOT NULL,
    period ENUM('prelim','midterm','finals') NOT NULL,
    quizzes DECIMAL(5,2) NULL,
    assignments DECIMAL(5,2) NULL,
    recitation DECIMAL(5,2) NULL,
    projects DECIMAL(5,2) NULL,
    midterm_exam DECIMAL(5,2) NULL,
    final_exam DECIMAL(5,2) NULL,
    UNIQUE KEY unique_grade_period (grade_id, period),
    FOREIGN KEY (grade_id) REFERENCES grades(id)
)");

// Component definitions with weights
$compDefs = [
    'quizzes'      => ['label' => 'Quizzes / Exams',           'weight' => 0.20],
    'assignments'  => ['label' => 'Assignments / Activities',   'weight' => 0.20],
    'recitation'   => ['label' => 'Recitation / Participation', 'weight' => 0.10],
    'projects'     => ['label' => 'Projects / Research',        'weight' => 0.10],
    'midterm_exam' => ['label' => 'Midterm Exam',               'weight' => 0.20],
    'final_exam'   => ['label' => 'Final Exam',                 'weight' => 0.20],
];
$periodDefs    = ['prelim' => 'Prelim', 'midterm' => 'Midterm', 'finals' => 'Finals'];
$periodWeights = ['prelim' => 0.20, 'midterm' => 0.30, 'finals' => 0.50];

// Transmute percentage (0-100) to GPA (1.00-5.00)
function transmuteGrade($pct) {
    if ($pct === null) return null;
    if ($pct >= 97) return 1.00;
    if ($pct >= 94) return 1.25;
    if ($pct >= 91) return 1.50;
    if ($pct >= 88) return 1.75;
    if ($pct >= 85) return 2.00;
    if ($pct >= 82) return 2.25;
    if ($pct >= 79) return 2.50;
    if ($pct >= 76) return 2.75;
    if ($pct >= 75) return 3.00;
    return 5.00;
}

// --- Handle POST actions ---
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'encode_grade') {
        $eid = (int)$_POST['enrollment_id'];
        $uid = $_SESSION['user_id'];
        $periodGrades = [];
        $allComps = [];

        foreach (array_keys($periodDefs) as $p) {
            $sw = 0; $swt = 0;
            $comps = [];
            foreach ($compDefs as $c => $info) {
                $key = "{$p}_{$c}";
                $v = ($_POST[$key] ?? '') === '' ? null : (float)$_POST[$key];
                $comps[$c] = $v;
                if ($v !== null) { $sw += $v * $info['weight']; $swt += $info['weight']; }
            }
            $allComps[$p] = $comps;
            $pct = $swt > 0 ? round($sw / $swt, 2) : null;
            $periodGrades[$p] = transmuteGrade($pct);
        }

        $pre = $periodGrades['prelim'];
        $mid = $periodGrades['midterm'];
        $fin = $periodGrades['finals'];

        // Final grade: Prelim 20%, Midterm 30%, Finals 50%
        $sw = 0; $swt = 0;
        if ($pre !== null) { $sw += $pre * 0.20; $swt += 0.20; }
        if ($mid !== null) { $sw += $mid * 0.30; $swt += 0.30; }
        if ($fin !== null) { $sw += $fin * 0.50; $swt += 0.50; }
        $final_grade = $swt > 0 ? round($sw / $swt, 2) : null;

        $remarks = null;
        if ($final_grade !== null) {
            if ($final_grade <= 3.00) $remarks = 'Passed';
            elseif ($final_grade >= 5.00) $remarks = 'Failed';
            else $remarks = 'Incomplete';
        }

        // Upsert grades
        $existing = $db->query("SELECT id FROM grades WHERE enrollment_id=$eid")->fetch_assoc();
        if ($existing) {
            $gradeId = $existing['id'];
            $stmt = $db->prepare("UPDATE grades SET prelim=?,midterm=?,finals=?,final_grade=?,remarks=?,encoded_by=?,encoded_at=NOW() WHERE enrollment_id=?");
            $stmt->bind_param('dddssii', $pre, $mid, $fin, $final_grade, $remarks, $uid, $eid);
            $stmt->execute();
        } else {
            $stmt = $db->prepare("INSERT INTO grades (enrollment_id,prelim,midterm,finals,final_grade,remarks,encoded_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('iddddsi', $eid, $pre, $mid, $fin, $final_grade, $remarks, $uid);
            $stmt->execute();
            $gradeId = $db->insert_id;
        }

        // Upsert grade_components per period
        foreach (array_keys($periodDefs) as $p) {
            $c = $allComps[$p];
            $exists = $db->query("SELECT id FROM grade_components WHERE grade_id=$gradeId AND period='$p'")->fetch_assoc();
            if ($exists) {
                $stmt = $db->prepare("UPDATE grade_components SET quizzes=?,assignments=?,recitation=?,projects=?,midterm_exam=?,final_exam=? WHERE grade_id=? AND period=?");
                $stmt->bind_param('ddddddis', $c['quizzes'], $c['assignments'], $c['recitation'], $c['projects'], $c['midterm_exam'], $c['final_exam'], $gradeId, $p);
            } else {
                $stmt = $db->prepare("INSERT INTO grade_components (grade_id,period,quizzes,assignments,recitation,projects,midterm_exam,final_exam) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('isdddddd', $gradeId, $p, $c['quizzes'], $c['assignments'], $c['recitation'], $c['projects'], $c['midterm_exam'], $c['final_exam']);
            }
            $stmt->execute();
        }

        $_SESSION['flash'] = ['type'=>'success','msg'=>'Grade saved successfully.'];
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'approve_grade' && in_array($role,['registrar','admin'])) {
        $gid = (int)$_POST['grade_id'];
        $uid = $_SESSION['user_id'];
        $stmt = $db->prepare("UPDATE grades SET approved_by=?,approved_at=NOW() WHERE id=?");
        $stmt->bind_param('ii', $uid, $gid);
        $stmt->execute();
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Grade approved and posted.'];
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'request_change') {
        $gradeId = (int)$_POST['grade_id'];
        $reason  = trim($_POST['reason'] ?? '');
        $pre     = $_POST['new_prelim'] === '' ? null : (float)$_POST['new_prelim'];
        $mid     = $_POST['new_midterm'] === '' ? null : (float)$_POST['new_midterm'];
        $fin     = $_POST['new_finals'] === '' ? null : (float)$_POST['new_finals'];

        $sumWeighted = 0; $sumWeights = 0;
        if ($pre !== null) { $sumWeighted += $pre * 0.20; $sumWeights += 0.20; }
        if ($mid !== null) { $sumWeighted += $mid * 0.30; $sumWeights += 0.30; }
        if ($fin !== null) { $sumWeighted += $fin * 0.50; $sumWeights += 0.50; }
        $final   = $sumWeights > 0 ? round($sumWeighted / $sumWeights, 2) : null;
        $remarks = null;
        if ($final !== null) {
            if ($final <= 3.00) $remarks = 'Passed';
            elseif ($final >= 5.00) $remarks = 'Failed';
            else $remarks = 'Incomplete';
        }

        if ($gradeId && $reason && $final !== null) {
            $uid = $_SESSION['user_id'];
            $stmt = $db->prepare("
                INSERT INTO grade_change_requests
                    (grade_id, requested_by, reason, new_prelim, new_midterm, new_finals, new_final_grade, new_remarks, status, requested_at)
                VALUES (?,?,?,?,?,?,?,?,'Pending',NOW())
            ");
            $stmt->bind_param('iisdddds', $gradeId, $uid, $reason, $pre, $mid, $fin, $final, $remarks);
            $stmt->execute();
            logActivity('Requested grade change', 'Grades', "grade_id={$gradeId}");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Grade change request submitted to registrar.'];
        } else {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Please provide a reason and complete new grade values.'];
        }
        redirect($_SERVER['REQUEST_URI']);
    }
}

// --- Fetch sections ---
$sectionFilter = (int)($_GET['section'] ?? 0);

if ($role === 'faculty') {
    $facultyId = $db->query("SELECT id FROM faculty WHERE user_id={$_SESSION['user_id']}")->fetch_assoc()['id'] ?? 0;
    $sections = $db->query("SELECT sec.*, sub.name as subject_name, sub.code as subject_code FROM sections sec JOIN subjects sub ON sec.subject_id=sub.id WHERE sec.faculty_id=$facultyId ORDER BY sub.code")->fetch_all(MYSQLI_ASSOC);
} else {
    $sections = $db->query("SELECT sec.*, sub.name as subject_name, sub.code as subject_code, u.first_name as fac_first, u.last_name as fac_last FROM sections sec JOIN subjects sub ON sec.subject_id=sub.id LEFT JOIN faculty f ON sec.faculty_id=f.id LEFT JOIN users u ON f.user_id=u.id ORDER BY sub.code")->fetch_all(MYSQLI_ASSOC);
}

// --- Fetch enrollments for selected section ---
$enrollments = [];
if ($sectionFilter) {
    $enrollments = $db->query("
        SELECT e.*, u.first_name, u.last_name, s.student_number,
               g.id as grade_id, g.prelim, g.midterm, g.finals, g.final_grade, g.remarks, g.approved_at
        FROM enrollments e
        JOIN students s ON e.student_id=s.id
        JOIN users u ON s.user_id=u.id
        LEFT JOIN grades g ON g.enrollment_id=e.id
        WHERE e.section_id=$sectionFilter AND e.status='Enrolled'
        ORDER BY u.last_name
    ")->fetch_all(MYSQLI_ASSOC);
}

// --- Fetch grade components for JS population ---
$gradeComponentsJs = [];
if (!empty($enrollments)) {
    $gradeIds = array_filter(array_column($enrollments, 'grade_id'));
    if (!empty($gradeIds)) {
        $ids = implode(',', array_map('intval', $gradeIds));
        $compResult = $db->query("SELECT * FROM grade_components WHERE grade_id IN ($ids)");
        if ($compResult) {
            $gradeToEnroll = [];
            foreach ($enrollments as $en) {
                if ($en['grade_id']) $gradeToEnroll[$en['grade_id']] = $en['id'];
            }
            foreach ($compResult->fetch_all(MYSQLI_ASSOC) as $c) {
                $enrollId = $gradeToEnroll[$c['grade_id']] ?? null;
                if ($enrollId) {
                    $gradeComponentsJs[$enrollId][$c['period']] = [
                        'quizzes'      => $c['quizzes'],
                        'assignments'  => $c['assignments'],
                        'recitation'   => $c['recitation'],
                        'projects'     => $c['projects'],
                        'midterm_exam' => $c['midterm_exam'],
                        'final_exam'   => $c['final_exam'],
                    ];
                }
            }
        }
    }
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">Grade Encoding</h1>
            <p class="page-subtitle">Encode and manage student grades per subject section.</p>
        </div>
        <?php if ($sectionFilter && !empty($enrollments)): ?>
        <button onclick="printSection('grade-table')" class="btn btn-outline">🖨 Print Grade Sheet</button>
        <?php endif; ?>
    </div>
</div>

<!-- Section Selector -->
<div class="card mb-4">
    <div class="card-header"><span class="card-title">Select Class Section</span></div>
    <div class="card-body">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div style="flex:1;min-width:240px;">
                <label class="form-label">Section</label>
                <select class="form-control" onchange="location='?section='+this.value">
                    <option value="">-- Select Section --</option>
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>" <?= $sectionFilter==$sec['id']?'selected':'' ?>>
                        <?= escape($sec['section_code'].' - '.$sec['subject_code'].' '.$sec['subject_name']) ?>
                        <?php if ($role !== 'faculty' && isset($sec['fac_first'])): ?>
                        (<?= escape($sec['fac_first'].' '.$sec['fac_last']) ?>)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<?php if ($sectionFilter): ?>
<!-- Grade Legend -->
<div class="card mb-4">
    <div class="card-body" style="padding:12px 20px;">
        <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
            <div style="font-size:12.5px;font-weight:600;color:var(--text-muted);">Grading Scale (1.00–5.00):</div>
            <span class="badge badge-success">Passed: 1.00–3.00</span>
            <span class="badge badge-warning">Incomplete: 3.01–4.99</span>
            <span class="badge badge-danger">Failed: 5.00</span>
        </div>
        <div style="display:flex;gap:16px;margin-top:8px;flex-wrap:wrap;font-size:11.5px;color:var(--text-muted);">
            <span><b>Period Weights:</b> Prelim 20% · Midterm 30% · Finals 50%</span>
            <span>|</span>
            <span><b>Components (scored 0–100):</b> Quizzes 20% · Assignments 20% · Recitation 10% · Projects 10% · Midterm Exam 20% · Final Exam 20%</span>
        </div>
    </div>
</div>

<!-- Grade Table -->
<div class="card" id="grade-table">
    <div class="card-header">
        <span class="card-title">Grade Sheet — <?= count($enrollments) ?> Students</span>
        <?php if ($role === 'faculty'): ?>
        <span class="badge badge-warning">Faculty Encoding</span>
        <?php elseif (in_array($role,['registrar','admin'])): ?>
        <span class="badge badge-primary">Registrar View</span>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Student No.</th>
                    <th>Prelim</th>
                    <th>Midterm</th>
                    <th>Finals</th>
                    <th>Final Grade</th>
                    <th>Remarks</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($enrollments)): ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">No enrolled students in this section.</td></tr>
                <?php else: ?>
                <?php foreach ($enrollments as $i => $en): ?>
                <?php $locked = $en['approved_at'] ? true : false; ?>
                <tr class="searchable-row">
                    <td style="color:var(--text-muted);font-size:12px;"><?= $i+1 ?></td>
                    <td><div style="font-weight:600;"><?= escape($en['last_name'].', '.$en['first_name']) ?></div></td>
                    <td><code style="font-size:12px;"><?= escape($en['student_number']) ?></code></td>
                    <td style="font-weight:600;"><?= $en['prelim'] !== null ? number_format($en['prelim'],2) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                    <td style="font-weight:600;"><?= $en['midterm'] !== null ? number_format($en['midterm'],2) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                    <td style="font-weight:600;"><?= $en['finals'] !== null ? number_format($en['finals'],2) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                    <td style="font-weight:700;font-size:15px;<?php
                        if ($en['remarks'] === 'Passed') echo 'color:var(--success)';
                        elseif ($en['remarks'] === 'Failed') echo 'color:var(--danger)';
                        elseif ($en['remarks'] === 'Incomplete') echo 'color:var(--warning)';
                    ?>">
                        <?= $en['final_grade'] !== null ? number_format($en['final_grade'],2) : '—' ?>
                    </td>
                    <td>
                        <?php if ($en['remarks']): ?>
                        <?php $badge = $en['remarks']==='Passed'?'success':($en['remarks']==='Failed'?'danger':'warning'); ?>
                        <span class="badge badge-<?= $badge ?>"><?= $en['remarks'] ?></span>
                        <?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($en['approved_at']): ?>
                        <span class="badge badge-success">✓ Posted</span>
                        <?php elseif ($en['final_grade'] !== null): ?>
                        <span class="badge badge-warning">Pending Approval</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">Not Encoded</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php if ($role === 'faculty' && !$locked): ?>
                            <button type="button" class="btn btn-sm btn-primary" onclick="openEncodeModal(<?= $en['id'] ?>, '<?= escape($en['last_name'].', '.$en['first_name']) ?>')">Encode</button>
                        <?php endif; ?>
                        <?php if ($role === 'faculty' && $locked): ?>
                            <button type="button" class="btn btn-sm btn-outline" onclick="openEncodeModal(<?= $en['id'] ?>, '<?= escape($en['last_name'].', '.$en['first_name']) ?>', true)">View</button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="openChangeRequestModal(<?= (int)$en['grade_id'] ?>, <?= $en['prelim']!==null?(float)$en['prelim']:0 ?>, <?= $en['midterm']!==null?(float)$en['midterm']:0 ?>, <?= $en['finals']!==null?(float)$en['finals']:0 ?>)">Request Change</button>
                        <?php endif; ?>
                        <?php if (in_array($role,['registrar','admin'])): ?>
                            <button type="button" class="btn btn-sm btn-outline" onclick="openEncodeModal(<?= $en['id'] ?>, '<?= escape($en['last_name'].', '.$en['first_name']) ?>', true)">View</button>
                            <?php if ($en['grade_id'] && !$en['approved_at']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="approve_grade">
                                <input type="hidden" name="grade_id" value="<?= $en['grade_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success" data-confirm="Approve and post this grade?">✓ Approve</button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!$role): ?>—<?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ===== ENCODE GRADE MODAL ===== -->
<div class="modal-overlay" id="modal-encode">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3 class="modal-title">Encode Grades — <span id="enc_student_name"></span></h3>
            <button class="modal-close">×</button>
        </div>
        <form method="POST" id="encodeForm">
            <input type="hidden" name="action" value="encode_grade">
            <input type="hidden" name="enrollment_id" id="enc_eid">
            <div class="modal-body" style="padding:16px 24px;">
                <!-- Period Tabs -->
                <div class="tabs-container">
                    <div class="tabs-nav" style="margin-bottom:16px;">
                        <?php $first = true; foreach ($periodDefs as $pKey => $pLabel): ?>
                        <button type="button" class="tab-btn <?= $first?'active':'' ?>" data-tab="enc-<?= $pKey ?>"><?= $pLabel ?> (<?= intval($periodWeights[$pKey]*100) ?>%)</button>
                        <?php $first = false; endforeach; ?>
                    </div>

                    <?php $first = true; foreach ($periodDefs as $pKey => $pLabel): ?>
                    <div class="tab-panel <?= $first?'active':'' ?>" id="tab-enc-<?= $pKey ?>">
                        <table style="width:100%;border-collapse:collapse;">
                            <thead>
                                <tr style="border-bottom:2px solid var(--border);">
                                    <th style="text-align:left;padding:8px 0;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Component</th>
                                    <th style="width:110px;text-align:center;padding:8px 0;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Score</th>
                                    <th style="width:70px;text-align:center;padding:8px 0;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Weight</th>
                                    <th style="width:80px;text-align:center;padding:8px 0;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($compDefs as $cKey => $cInfo): ?>
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td style="padding:10px 0;font-weight:600;font-size:13px;"><?= $cInfo['label'] ?></td>
                                    <td style="padding:6px 4px;text-align:center;">
                                        <input type="number" name="<?= $pKey ?>_<?= $cKey ?>" class="form-control comp-input" data-period="<?= $pKey ?>" min="0" max="100" step="1" placeholder="0–100" style="width:90px;padding:6px 10px;margin:0 auto;text-align:center;">
                                    </td>
                                    <td style="text-align:center;font-size:13px;color:var(--text-muted);font-weight:500;"><?= intval($cInfo['weight']*100) ?>%</td>
                                    <td style="text-align:center;font-weight:700;font-size:13px;color:var(--text-primary);" class="comp-result" data-field="<?= $pKey ?>_<?= $cKey ?>">—</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div style="display:flex;justify-content:flex-end;gap:20px;padding:10px 0;border-top:2px solid var(--border);margin-top:2px;align-items:center;">
                            <span style="font-size:13px;color:var(--text-muted);">Total: <b id="pct-<?= $pKey ?>" style="color:var(--text-primary);">—</b></span>
                            <span style="font-size:15px;font-weight:700;"><?= $pLabel ?> Grade: <span id="pg-<?= $pKey ?>" style="color:var(--primary);font-size:16px;">—</span></span>
                        </div>
                    </div>
                    <?php $first = false; endforeach; ?>
                </div>

                <!-- Summary -->
                <div style="background:var(--bg);border-radius:8px;padding:14px 16px;margin-top:12px;">
                    <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px;">
                        <?php foreach ($periodDefs as $pKey => $pLabel): ?>
                        <div>
                            <span style="color:var(--text-muted);"><?= $pLabel ?>:</span>
                            <span id="sumpct-<?= $pKey ?>" style="font-size:11.5px;color:var(--text-muted);"></span>
                            <b id="sum-<?= $pKey ?>">—</b>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:20px;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);align-items:center;">
                        <div style="font-size:15px;font-weight:700;">
                            Final Grade: <span id="final-computed" style="font-size:18px;color:var(--primary);">—</span>
                        </div>
                        <div>
                            Remarks: <span id="remarks-computed" class="badge badge-secondary">—</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="enc-footer">
                <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary" id="enc-submit-btn">Save Grades</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== GRADE CHANGE REQUEST MODAL ===== -->
<?php if ($role === 'faculty'): ?>
<div class="modal-overlay" id="modal-grade-change">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h3 class="modal-title">Request Grade Correction</h3>
            <button class="modal-close">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="request_change">
            <input type="hidden" name="grade_id" id="gc_grade_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">New Prelim Grade *</label>
                    <input type="number" name="new_prelim" id="gc_prelim" class="form-control" min="1" max="5" step="0.25" required>
                </div>
                <div class="form-group">
                    <label class="form-label">New Midterm Grade *</label>
                    <input type="number" name="new_midterm" id="gc_midterm" class="form-control" min="1" max="5" step="0.25" required>
                </div>
                <div class="form-group">
                    <label class="form-label">New Finals Grade *</label>
                    <input type="number" name="new_finals" id="gc_finals" class="form-control" min="1" max="5" step="0.25" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason for Correction *</label>
                    <textarea name="reason" class="form-control" rows="3" required placeholder="Explain why the grade needs to be corrected..."></textarea>
                </div>
                <p style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                    Submitted requests will be routed to the Registrar for validation and approval.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Request</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Grade component data from DB
const gradeData = <?= json_encode($gradeComponentsJs, JSON_FORCE_OBJECT) ?>;

const compWeights = {
    quizzes: 0.20, assignments: 0.20, recitation: 0.10,
    projects: 0.10, midterm_exam: 0.20, final_exam: 0.20
};
const periodWeights = { prelim: 0.20, midterm: 0.30, finals: 0.50 };
const periods = ['prelim', 'midterm', 'finals'];
const compKeys = Object.keys(compWeights);

// Transmute percentage to 1.00-5.00 GPA scale
function transmuteGrade(pct) {
    if (pct >= 97) return 1.00;
    if (pct >= 94) return 1.25;
    if (pct >= 91) return 1.50;
    if (pct >= 88) return 1.75;
    if (pct >= 85) return 2.00;
    if (pct >= 82) return 2.25;
    if (pct >= 79) return 2.50;
    if (pct >= 76) return 2.75;
    if (pct >= 75) return 3.00;
    return 5.00;
}

// Compute period and final grades from current inputs
function computeGrades() {
    var periodGrades = {};
    periods.forEach(function(p) {
        var totalResult = 0, totalWeight = 0;
        compKeys.forEach(function(c) {
            var input = document.querySelector('[name="' + p + '_' + c + '"]');
            var resultEl = document.querySelector('.comp-result[data-field="' + p + '_' + c + '"]');
            if (input && input.value !== '') {
                var score = parseFloat(input.value);
                if (!isNaN(score)) {
                    var result = score * compWeights[c];
                    totalResult += result;
                    totalWeight += compWeights[c];
                    if (resultEl) resultEl.textContent = result.toFixed(1);
                } else {
                    if (resultEl) resultEl.textContent = '—';
                }
            } else {
                if (resultEl) resultEl.textContent = '—';
            }
        });

        var pct = totalWeight > 0 ? totalResult / totalWeight : null;
        var pctEl = document.getElementById('pct-' + p);
        if (pctEl) pctEl.textContent = pct !== null ? pct.toFixed(1) + '%' : '—';

        var grade = pct !== null ? transmuteGrade(pct) : null;
        periodGrades[p] = grade;

        var pgEl = document.getElementById('pg-' + p);
        if (pgEl) pgEl.textContent = grade !== null ? grade.toFixed(2) : '—';
        var sumPctEl = document.getElementById('sumpct-' + p);
        if (sumPctEl) sumPctEl.textContent = pct !== null ? '(' + pct.toFixed(1) + '%)' : '';
        var sumEl = document.getElementById('sum-' + p);
        if (sumEl) sumEl.textContent = grade !== null ? grade.toFixed(2) : '—';
    });

    // Final grade (weighted average of transmuted period grades)
    var sw = 0, swt = 0;
    if (periodGrades.prelim !== null)  { sw += periodGrades.prelim  * 0.20; swt += 0.20; }
    if (periodGrades.midterm !== null) { sw += periodGrades.midterm * 0.30; swt += 0.30; }
    if (periodGrades.finals !== null)  { sw += periodGrades.finals  * 0.50; swt += 0.50; }
    var fg = swt > 0 ? (sw / swt).toFixed(2) : null;
    var fgEl = document.getElementById('final-computed');
    if (fgEl) fgEl.textContent = fg || '—';

    // Remarks
    var remEl = document.getElementById('remarks-computed');
    if (remEl) {
        if (fg) {
            var fv = parseFloat(fg);
            if (fv <= 3.00) { remEl.textContent = 'Passed'; remEl.className = 'badge badge-success'; }
            else if (fv >= 5.00) { remEl.textContent = 'Failed'; remEl.className = 'badge badge-danger'; }
            else { remEl.textContent = 'Incomplete'; remEl.className = 'badge badge-warning'; }
        } else {
            remEl.textContent = '—'; remEl.className = 'badge badge-secondary';
        }
    }
}

// Listen for input changes on component fields
document.querySelectorAll('.comp-input').forEach(function(input) {
    input.addEventListener('input', computeGrades);
});

// Open encode modal
function openEncodeModal(enrollmentId, studentName, readOnly) {
    document.getElementById('enc_eid').value = enrollmentId;
    document.getElementById('enc_student_name').textContent = studentName;

    var data = gradeData[enrollmentId] || {};

    // Populate fields
    periods.forEach(function(p) {
        var pData = data[p] || {};
        compKeys.forEach(function(c) {
            var input = document.querySelector('[name="' + p + '_' + c + '"]');
            if (input) {
                input.value = (pData[c] !== null && pData[c] !== undefined) ? pData[c] : '';
                input.readOnly = !!readOnly;
                input.style.opacity = readOnly ? '0.7' : '1';
            }
        });
    });

    // Show/hide submit button
    var submitBtn = document.getElementById('enc-submit-btn');
    if (submitBtn) submitBtn.style.display = readOnly ? 'none' : '';

    // Reset to first tab
    var container = document.querySelector('#modal-encode .tabs-container');
    if (container) {
        container.querySelectorAll('.tab-btn').forEach(function(b, i) { b.classList.toggle('active', i === 0); });
        container.querySelectorAll('.tab-panel').forEach(function(p, i) { p.classList.toggle('active', i === 0); });
    }

    computeGrades();
    document.getElementById('modal-encode').classList.add('open');
}

// Open change request modal
function openChangeRequestModal(gradeId, pre, mid, fin) {
    document.getElementById('gc_grade_id').value = gradeId;
    document.getElementById('gc_prelim').value = pre || '';
    document.getElementById('gc_midterm').value = mid || '';
    document.getElementById('gc_finals').value = fin || '';
    document.getElementById('modal-grade-change').classList.add('open');
}
</script>

<?php require_once 'includes/footer.php'; ?>
