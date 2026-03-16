<?php
$pageTitle = 'My Grades';
require_once 'includes/config.php';
requireLogin();
requireRole(['student']);
$db   = getDB();
$user = currentUser();

// Get student id
$sidRow = $db->query("SELECT id FROM students WHERE user_id={$user['user_id']}")->fetch_assoc();
$studentId = $sidRow['id'] ?? 0;

if (!$studentId) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Student profile not found.'];
}

$ay  = $_GET['ay']  ?? ACADEMIC_YEAR;
$sem = (int)($_GET['sem'] ?? CURRENT_SEMESTER);

// Fetch enrollments with grades
$grades = [];
if ($studentId) {
    $grades = $db->query("
        SELECT e.*, sec.section_code, sub.code as subject_code, sub.name as subject_name, sub.units,
               g.final_grade, g.remarks, g.approved_at, g.prelim, g.midterm, g.finals
        FROM enrollments e
        JOIN sections sec ON e.section_id=sec.id
        JOIN subjects sub ON sec.subject_id=sub.id
        LEFT JOIN grades g ON g.enrollment_id=e.id
        WHERE e.student_id={$studentId}
          AND sec.academic_year='{$db->real_escape_string($ay)}'
          AND sec.semester={$sem}
        ORDER BY sub.code, sec.section_code
    ")->fetch_all(MYSQLI_ASSOC);
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">My Grades</h1>
            <p class="page-subtitle">View your subject grades for the selected term.</p>
        </div>
        <?php if (!empty($grades)): ?>
        <button onclick="printSection('my-grades-table')" class="btn btn-outline">🖨 Print Grade Sheet</button>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body" style="padding:14px 20px;">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label class="form-label">Academic Year</label>
                <input type="text" name="ay" class="form-control" value="<?= escape($ay) ?>">
            </div>
            <div>
                <label class="form-label">Semester</label>
                <select name="sem" class="form-control">
                    <option value="1" <?= $sem===1?'selected':'' ?>>1st</option>
                    <option value="2" <?= $sem===2?'selected':'' ?>>2nd</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Show</button>
        </form>
    </div>
</div>

<div class="card" id="my-grades-table">
    <div class="card-header">
        <span class="card-title">Grades — AY <?= escape($ay) ?>, <?= $sem===1?'1st':'2nd' ?> Semester</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Subject Code</th>
                    <th>Subject Name</th>
                    <th>Section</th>
                    <th>Units</th>
                    <th>Prelim</th>
                    <th>Midterm</th>
                    <th>Finals</th>
                    <th>Final Grade</th>
                    <th>Remarks</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grades)): ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">No enrolled subjects or grades for this term.</td></tr>
                <?php else: ?>
                <?php foreach ($grades as $g): ?>
                <tr>
                    <td><code style="font-size:12px;"><?= escape($g['subject_code']) ?></code></td>
                    <td><?= escape($g['subject_name']) ?></td>
                    <td><code style="font-size:12px;"><?= escape($g['section_code']) ?></code></td>
                    <td><?= (int)$g['units'] ?></td>
                    <!-- Only show component grades after registrar approval -->
                    <td><?= ($g['approved_at'] && $g['prelim'] !== null) ? number_format($g['prelim'],2) : '—' ?></td>
                    <td><?= ($g['approved_at'] && $g['midterm'] !== null) ? number_format($g['midterm'],2) : '—' ?></td>
                    <td><?= ($g['approved_at'] && $g['finals'] !== null) ? number_format($g['finals'],2) : '—' ?></td>
                    <!-- Only show numeric final grade after registrar approval -->
                    <td style="font-weight:700;<?php
                        if ($g['approved_at'] && $g['remarks'] === 'Passed') echo 'color:var(--success)';
                        elseif ($g['approved_at'] && $g['remarks'] === 'Failed') echo 'color:var(--danger)';
                        elseif ($g['approved_at'] && $g['remarks'] === 'Incomplete') echo 'color:var(--warning)';
                    ?>">
                        <?= ($g['approved_at'] && $g['final_grade'] !== null) ? number_format($g['final_grade'],2) : '—' ?>
                    </td>
                    <td>
                        <?php if (!$g['approved_at']): ?>
                            <span style="color:var(--text-muted);">—</span>
                        <?php elseif ($g['remarks']): ?>
                            <span class="badge badge-<?= $g['remarks']==='Passed'?'success':($g['remarks']==='Failed'?'danger':'warning') ?>"><?= $g['remarks'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($g['approved_at']): ?>
                            <span class="badge badge-success">Posted</span>
                        <?php elseif ($g['final_grade'] !== null): ?>
                            <span class="badge badge-warning">Pending Registrar</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Not Yet Encoded</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

