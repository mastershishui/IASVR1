<?php
$pageTitle = 'Student List';
require_once 'includes/config.php';
requireLogin();
requireRole(['faculty']);
$db   = getDB();
$user = currentUser();

// Get faculty ID
$facRow    = $db->query("SELECT id, department FROM faculty WHERE user_id={$user['user_id']}")->fetch_assoc();
$facultyId = $facRow['id'] ?? 0;

if (!$facultyId) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Faculty profile not found.'];
}

$ay  = $_GET['ay']  ?? ACADEMIC_YEAR;
$sem = (int)($_GET['sem'] ?? CURRENT_SEMESTER);
$sectionFilter = (int)($_GET['section'] ?? 0);

// Get faculty's sections for the term
$sections = [];
if ($facultyId) {
    $sections = $db->query("
        SELECT sec.id, sec.section_code, sub.code AS subject_code, sub.name AS subject_name
        FROM sections sec
        JOIN subjects sub ON sec.subject_id=sub.id
        WHERE sec.faculty_id={$facultyId}
          AND sec.academic_year='{$db->real_escape_string($ay)}'
          AND sec.semester={$sem}
        ORDER BY sub.code, sec.section_code
    ")->fetch_all(MYSQLI_ASSOC);
}

// Auto-select first section if none chosen
if (!$sectionFilter && !empty($sections)) {
    $sectionFilter = $sections[0]['id'];
}

// Get enrolled students for selected section
$students = [];
$sectionInfo = null;
if ($sectionFilter) {
    // Verify section belongs to this faculty
    $secCheck = $db->query("
        SELECT sec.*, sub.code AS subject_code, sub.name AS subject_name, sub.units
        FROM sections sec
        JOIN subjects sub ON sec.subject_id=sub.id
        WHERE sec.id={$sectionFilter} AND sec.faculty_id={$facultyId}
    ")->fetch_assoc();

    if ($secCheck) {
        $sectionInfo = $secCheck;
        $students = $db->query("
            SELECT e.id AS enrollment_id, e.status AS enrollment_status, e.enrolled_at,
                   s.student_number, s.year_level, s.gender,
                   u.first_name, u.last_name, u.email,
                   p.code AS program_code, p.name AS program_name,
                   g.final_grade, g.remarks, g.approved_at
            FROM enrollments e
            JOIN students s ON e.student_id=s.id
            JOIN users u ON s.user_id=u.id
            LEFT JOIN programs p ON s.program_id=p.id
            LEFT JOIN grades g ON g.enrollment_id=e.id
            WHERE e.section_id={$sectionFilter} AND e.status='Enrolled'
            ORDER BY u.last_name, u.first_name
        ")->fetch_all(MYSQLI_ASSOC);
    }
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">Student List</h1>
            <p class="page-subtitle">View enrolled students per class section.</p>
        </div>
        <?php if (!empty($students)): ?>
        <button onclick="printSection('student-table')" class="btn btn-outline">🖨 Print Roster</button>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header"><span class="card-title">Select Class Section</span></div>
    <div class="card-body">
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
            <div style="flex:1;min-width:240px;">
                <label class="form-label">Section</label>
                <select name="section" class="form-control">
                    <option value="">-- Select Section --</option>
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>" <?= $sectionFilter==$sec['id']?'selected':'' ?>>
                        <?= escape($sec['section_code'].' - '.$sec['subject_code'].' '.$sec['subject_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Show</button>
        </form>
    </div>
</div>

<?php if ($sectionInfo): ?>
<!-- Section Summary -->
<div class="card mb-4">
    <div class="card-body" style="padding:14px 20px;">
        <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
            <div>
                <span style="font-size:12px;color:var(--text-muted);">Section</span>
                <div style="font-weight:700;"><?= escape($sectionInfo['section_code']) ?></div>
            </div>
            <div>
                <span style="font-size:12px;color:var(--text-muted);">Subject</span>
                <div style="font-weight:600;"><?= escape($sectionInfo['subject_code'].' - '.$sectionInfo['subject_name']) ?></div>
            </div>
            <div>
                <span style="font-size:12px;color:var(--text-muted);">Schedule</span>
                <div><?= escape($sectionInfo['day_time'] ?: 'TBA') ?></div>
            </div>
            <div>
                <span style="font-size:12px;color:var(--text-muted);">Room</span>
                <div><?= escape($sectionInfo['room'] ?: 'TBA') ?></div>
            </div>
            <div style="margin-left:auto;">
                <span class="badge badge-primary" style="font-size:13px;padding:6px 14px;"><?= count($students) ?> / <?= $sectionInfo['max_students'] ?> Students</span>
            </div>
        </div>
    </div>
</div>

<!-- Student Table -->
<div class="card" id="student-table">
    <div class="card-header">
        <span class="card-title">Enrolled Students (<?= count($students) ?>)</span>
        <input type="text" id="tableSearch" class="form-control" placeholder="Search students..." style="width:220px;padding:6px 12px;">
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student Number</th>
                    <th>Name</th>
                    <th>Program</th>
                    <th>Year Level</th>
                    <th>Gender</th>
                    <th>Email</th>
                    <th>Grade Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No enrolled students in this section.</td></tr>
                <?php else: ?>
                <?php foreach ($students as $i => $stu): ?>
                <tr class="searchable-row">
                    <td style="color:var(--text-muted);font-size:12px;"><?= $i + 1 ?></td>
                    <td><code style="font-size:12.5px;background:var(--bg);padding:2px 8px;border-radius:4px;"><?= escape($stu['student_number']) ?></code></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="avatar-initials" style="width:28px;height:28px;font-size:10px;flex-shrink:0;">
                                <?= strtoupper(substr($stu['first_name'],0,1).substr($stu['last_name'],0,1)) ?>
                            </div>
                            <span style="font-weight:600;font-size:13px;"><?= escape($stu['last_name'].', '.$stu['first_name']) ?></span>
                        </div>
                    </td>
                    <td><span class="badge badge-secondary"><?= escape($stu['program_code'] ?? 'N/A') ?></span></td>
                    <td style="text-align:center;"><?= (int)$stu['year_level'] ?></td>
                    <td style="font-size:13px;"><?= escape($stu['gender'] ?? '—') ?></td>
                    <td style="font-size:12.5px;color:var(--text-muted);"><?= escape($stu['email']) ?></td>
                    <td>
                        <?php if ($stu['approved_at']): ?>
                            <span class="badge badge-success">Posted (<?= number_format($stu['final_grade'],2) ?>)</span>
                        <?php elseif ($stu['final_grade'] !== null): ?>
                            <span class="badge badge-warning">Encoded</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Not Encoded</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:60px 20px;color:var(--text-muted);">
        <?php if (empty($sections)): ?>
        <p>No assigned sections for this term.</p>
        <?php else: ?>
        <p>Select a section above to view the student roster.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
