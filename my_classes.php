<?php
$pageTitle = 'My Classes';
require_once 'includes/config.php';
requireLogin();
requireRole(['faculty']);
$db   = getDB();
$user = currentUser();

// Get faculty ID for the logged-in user
$facRow    = $db->query("SELECT id, department, specialization FROM faculty WHERE user_id={$user['user_id']}")->fetch_assoc();
$facultyId = $facRow['id'] ?? 0;

if (!$facultyId) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Faculty profile not found.'];
}

$ay  = $_GET['ay']  ?? ACADEMIC_YEAR;
$sem = (int)($_GET['sem'] ?? CURRENT_SEMESTER);

// Fetch assigned sections with subject info and enrollment counts
$classes = [];
if ($facultyId) {
    $classes = $db->query("
        SELECT sec.id, sec.section_code, sec.room, sec.day_time, sec.max_students, sec.status,
               sec.academic_year, sec.semester,
               sub.code AS subject_code, sub.name AS subject_name, sub.units, sub.lab_units,
               (SELECT COUNT(*) FROM enrollments e WHERE e.section_id=sec.id AND e.status='Enrolled') AS enrolled_count
        FROM sections sec
        JOIN subjects sub ON sec.subject_id=sub.id
        WHERE sec.faculty_id={$facultyId}
          AND sec.academic_year='{$db->real_escape_string($ay)}'
          AND sec.semester={$sem}
        ORDER BY sub.code, sec.section_code
    ")->fetch_all(MYSQLI_ASSOC);
}

// Compute summary stats
$totalSections = count($classes);
$totalStudents = array_sum(array_column($classes, 'enrolled_count'));
$totalUnits    = array_sum(array_column($classes, 'units'));

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">My Classes</h1>
            <p class="page-subtitle">View your assigned class sections and student information.</p>
        </div>
    </div>
</div>

<!-- Term Selector -->
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

<!-- Summary Stats -->
<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon blue">📚</div><span class="stat-badge active">Current</span></div>
        <div class="stat-value"><?= $totalSections ?></div>
        <div class="stat-label">Assigned Sections</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon green">👥</div></div>
        <div class="stat-value"><?= $totalStudents ?></div>
        <div class="stat-label">Total Students</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon purple">📋</div></div>
        <div class="stat-value"><?= $totalUnits ?></div>
        <div class="stat-label">Teaching Units</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon orange">🏢</div></div>
        <div class="stat-value"><?= escape($facRow['department'] ?? 'N/A') ?></div>
        <div class="stat-label">Department</div>
    </div>
</div>

<!-- Classes Table -->
<div class="card">
    <div class="card-header">
        <span class="card-title">My Sections — AY <?= escape($ay) ?>, <?= $sem===1?'1st':'2nd' ?> Semester (<?= $totalSections ?>)</span>
        <input type="text" id="tableSearch" class="form-control" placeholder="Search classes..." style="width:220px;padding:6px 12px;">
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Section Code</th>
                    <th>Subject</th>
                    <th>Units</th>
                    <th>Room</th>
                    <th>Schedule</th>
                    <th>Enrolled / Max</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($classes)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No assigned classes for this term.</td></tr>
                <?php else: ?>
                <?php foreach ($classes as $cls): ?>
                <tr class="searchable-row">
                    <td><code style="font-size:12.5px;background:var(--bg);padding:2px 8px;border-radius:4px;"><?= escape($cls['section_code']) ?></code></td>
                    <td>
                        <div style="font-weight:600;"><?= escape($cls['subject_code']) ?></div>
                        <div style="font-size:11.5px;color:var(--text-muted);"><?= escape(substr($cls['subject_name'],0,40)) ?></div>
                    </td>
                    <td style="text-align:center;">
                        <span class="badge badge-primary"><?= (int)$cls['units'] ?><?= $cls['lab_units'] ? ' + '.(int)$cls['lab_units'].'L' : '' ?></span>
                    </td>
                    <td style="font-size:13px;"><?= escape($cls['room'] ?: 'TBA') ?></td>
                    <td style="font-size:13px;"><?= escape($cls['day_time'] ?: 'TBA') ?></td>
                    <td>
                        <?php
                        $pct = $cls['max_students'] > 0 ? ($cls['enrolled_count'] / $cls['max_students']) * 100 : 0;
                        $color = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success');
                        ?>
                        <div style="font-size:13px;font-weight:600;"><?= $cls['enrolled_count'] ?> / <?= $cls['max_students'] ?></div>
                        <div style="height:4px;background:var(--bg);border-radius:2px;margin-top:4px;overflow:hidden;">
                            <div style="height:100%;width:<?= min($pct,100) ?>%;background:var(--<?= $color ?>);border-radius:2px;"></div>
                        </div>
                    </td>
                    <td>
                        <?php
                        $sc = ['Open'=>'success','Closed'=>'danger','Cancelled'=>'secondary'][$cls['status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $sc ?>"><?= $cls['status'] ?></span>
                    </td>
                    <td>
                        <a href="grade_encoding.php?section=<?= $cls['id'] ?>" class="btn btn-sm btn-outline" title="Encode Grades">Grades</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
