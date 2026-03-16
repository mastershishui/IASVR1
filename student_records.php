<?php
$pageTitle = 'Student Records';
require_once 'includes/config.php';
requireLogin();
requireRole(['admin','registrar']);
$db = getDB();

// Handle add student profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'')==='add_student') {
    $uid = (int)$_POST['user_id'];
    $pid = (int)$_POST['program_id'];
    $yr  = (int)$_POST['year_level'];
    $snum = trim($_POST['student_number']);
    $ay   = ACADEMIC_YEAR;
    $stmt = $db->prepare("INSERT INTO students (user_id,program_id,year_level,student_number,academic_year,enrollment_status) VALUES (?,?,?,?,?,'Active')");
    $stmt->bind_param('iiiss',$uid,$pid,$yr,$snum,$ay);
    $stmt->execute();
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Student profile created.'];
    redirect('student_records.php');
}

$search = $_GET['q'] ?? '';
$programFilter = $_GET['program'] ?? '';

$sql = "SELECT s.*, u.first_name, u.last_name, u.email, u.status as account_status, p.name as program_name, p.code as program_code
        FROM students s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN programs p ON s.program_id = p.id
        WHERE 1=1";
if ($search) $sql .= " AND (u.first_name LIKE '%".escape($search)."%' OR u.last_name LIKE '%".escape($search)."%' OR s.student_number LIKE '%".escape($search)."%')";
if ($programFilter) $sql .= " AND s.program_id=".((int)$programFilter);
$sql .= " ORDER BY s.id DESC";

$students = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
$programs = $db->query("SELECT * FROM programs WHERE status='active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Users without student profile
$usersWithoutProfile = $db->query("SELECT u.id, u.first_name, u.last_name, u.email FROM users u WHERE u.role='student' AND u.id NOT IN (SELECT user_id FROM students) AND u.status='active'")->fetch_all(MYSQLI_ASSOC);

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">Student Records</h1>
            <p class="page-subtitle">View and manage all student academic profiles and records.</p>
        </div>
        <?php if (!empty($usersWithoutProfile)): ?>
        <button class="btn btn-primary" data-modal="modal-add-student">+ Create Student Profile</button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($usersWithoutProfile)): ?>
<div class="alert alert-warning">
    ⚠️ <?= count($usersWithoutProfile) ?> student user(s) don't have a profile yet. Click "Create Student Profile" to set them up.
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body" style="padding:14px 20px;">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <label class="form-label">Search Student</label>
                <input type="text" name="q" class="form-control" placeholder="Name or student number..." value="<?= escape($search) ?>">
            </div>
            <div style="min-width:180px;">
                <label class="form-label">Program</label>
                <select name="program" class="form-control">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $programFilter==$p['id']?'selected':'' ?>><?= escape($p['code'].' - '.$p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="student_records.php" class="btn btn-outline">Clear</a>
        </form>
    </div>
</div>

<!-- Students Table -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Students (<?= count($students) ?>)</span>
        <button onclick="printSection('students-table')" class="btn btn-sm btn-outline">🖨 Print</button>
    </div>
    <div id="students-table" class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student No.</th>
                    <th>Name</th>
                    <th>Program</th>
                    <th>Year Level</th>
                    <th>Academic Year</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No student records found.</td></tr>
                <?php else: ?>
                <?php foreach ($students as $s): ?>
                <tr class="searchable-row">
                    <td>
                        <code style="font-size:12.5px;background:var(--bg);padding:2px 8px;border-radius:4px;"><?= escape($s['student_number']) ?></code>
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="avatar-initials" style="width:32px;height:32px;font-size:11px;flex-shrink:0;">
                                <?= strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1)) ?>
                            </div>
                            <div>
                                <div style="font-weight:600;"><?= escape($s['first_name'].' '.$s['last_name']) ?></div>
                                <div style="font-size:11.5px;color:var(--text-muted);"><?= escape($s['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?= escape($s['program_code']) ?></div>
                        <div style="font-size:11.5px;color:var(--text-muted);"><?= escape(substr($s['program_name']??'',0,30)) ?></div>
                    </td>
                    <td style="text-align:center;">
                        <span class="badge badge-primary">Year <?= $s['year_level'] ?></span>
                    </td>
                    <td style="font-size:13px;"><?= escape($s['academic_year']) ?></td>
                    <td>
                        <?php
                        $sc2 = ['Active'=>'success','Pending'=>'warning','Dropped'=>'danger','Graduated'=>'purple','On Leave'=>'secondary'][$s['enrollment_status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $sc2 ?>"><?= $s['enrollment_status'] ?></span>
                    </td>
                    <td>
                        <a href="student_detail.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Student Profile Modal -->
<div class="modal-overlay" id="modal-add-student">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Create Student Profile</h3>
            <button class="modal-close">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_student">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Select Student Account *</label>
                    <select name="user_id" class="form-control" required>
                        <option value="">-- Select Student --</option>
                        <?php foreach ($usersWithoutProfile as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= escape($u['first_name'].' '.$u['last_name']) ?> (<?= escape($u['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Student Number *</label>
                    <input type="text" name="student_number" class="form-control" required placeholder="e.g., 2024-0001" value="<?= date('Y') ?>-<?= str_pad(rand(1,9999),4,'0',STR_PAD_LEFT) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Program *</label>
                    <select name="program_id" class="form-control" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= escape($p['code'].' - '.$p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Year Level *</label>
                    <select name="year_level" class="form-control" required>
                        <option value="1">1st Year</option>
                        <option value="2">2nd Year</option>
                        <option value="3">3rd Year</option>
                        <option value="4">4th Year</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Profile</button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>