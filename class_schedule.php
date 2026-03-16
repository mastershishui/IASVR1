<?php
$pageTitle = 'Class Schedule';
require_once 'includes/config.php';
requireLogin();
$db = getDB();
$role = $_SESSION['role'];

// Handle add section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role,['admin','registrar'])) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_section') {
        $code    = trim($_POST['section_code']);
        $sub_id  = (int)$_POST['subject_id'];
        $fac_id  = (int)$_POST['faculty_id'];
        $room    = trim($_POST['room']);
        $daytime = trim($_POST['day_time']);
        $max     = (int)$_POST['max_students'];
        $ay      = ACADEMIC_YEAR;
        $sem     = CURRENT_SEMESTER;
        $stmt    = $db->prepare("INSERT INTO sections (section_code,subject_id,faculty_id,room,day_time,max_students,academic_year,semester) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('siissisi',$code,$sub_id,$fac_id,$room,$daytime,$max,$ay,$sem);
        if ($stmt->execute()) {
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Section created.'];
        } else {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Error: '.$db->error];
        }
        redirect('class_schedule.php');
    }
}

// Get sections
$sections = $db->query("
    SELECT sec.*, sub.name as subject_name, sub.code as subject_code, sub.units,
           u.first_name as fac_first, u.last_name as fac_last,
           (SELECT COUNT(*) FROM enrollments e WHERE e.section_id=sec.id AND e.status='Enrolled') as enrolled_count
    FROM sections sec
    JOIN subjects sub ON sec.subject_id=sub.id
    LEFT JOIN faculty f ON sec.faculty_id=f.id
    LEFT JOIN users u ON f.user_id=u.id
    ORDER BY sub.code, sec.section_code
")->fetch_all(MYSQLI_ASSOC);

$programs = $db->query("SELECT * FROM programs WHERE status='active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$subjects = $db->query("SELECT * FROM subjects ORDER BY code")->fetch_all(MYSQLI_ASSOC);
$faculty  = $db->query("SELECT f.id, u.first_name, u.last_name, f.department FROM faculty f JOIN users u ON f.user_id=u.id WHERE u.role='faculty' AND u.status='active' ORDER BY u.last_name")->fetch_all(MYSQLI_ASSOC);

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">Class Schedule</h1>
            <p class="page-subtitle">Manage class sections, faculty assignments, and room schedules.</p>
        </div>
        <?php if (in_array($role,['admin','registrar'])): ?>
        <button class="btn btn-primary" data-modal="modal-add-section">+ Add Section</button>
        <?php endif; ?>
    </div>
</div>

<!-- Sections Table -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Class Sections (<?= count($sections) ?>)</span>
        <input type="text" id="tableSearch" class="form-control" placeholder="Search sections..." style="width:220px;padding:6px 12px;">
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Section Code</th>
                    <th>Subject</th>
                    <th>Faculty</th>
                    <th>Room</th>
                    <th>Schedule</th>
                    <th>Units</th>
                    <th>Enrolled / Max</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sections)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No sections found. Add sections to begin scheduling.</td></tr>
                <?php else: ?>
                <?php foreach ($sections as $sec): ?>
                <tr class="searchable-row">
                    <td><code style="font-size:12.5px;background:var(--bg);padding:2px 8px;border-radius:4px;"><?= escape($sec['section_code']) ?></code></td>
                    <td>
                        <div style="font-weight:600;"><?= escape($sec['subject_code']) ?></div>
                        <div style="font-size:11.5px;color:var(--text-muted);"><?= escape(substr($sec['subject_name'],0,35)) ?></div>
                    </td>
                    <td>
                        <?php if ($sec['fac_first']): ?>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="avatar-initials" style="width:28px;height:28px;font-size:10px;flex-shrink:0;">
                                <?= strtoupper(substr($sec['fac_first'],0,1).substr($sec['fac_last'],0,1)) ?>
                            </div>
                            <span style="font-size:13px;"><?= escape($sec['fac_first'].' '.$sec['fac_last']) ?></span>
                        </div>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:13px;">TBA</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px;"><?= escape($sec['room'] ?: 'TBA') ?></td>
                    <td style="font-size:13px;"><?= escape($sec['day_time'] ?: 'TBA') ?></td>
                    <td style="text-align:center;"><span class="badge badge-primary"><?= $sec['units'] ?> units</span></td>
                    <td>
                        <?php
                        $pct = $sec['max_students'] > 0 ? ($sec['enrolled_count'] / $sec['max_students']) * 100 : 0;
                        $color = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success');
                        ?>
                        <div style="font-size:13px;font-weight:600;"><?= $sec['enrolled_count'] ?> / <?= $sec['max_students'] ?></div>
                        <div style="height:4px;background:var(--bg);border-radius:2px;margin-top:4px;overflow:hidden;">
                            <div style="height:100%;width:<?= min($pct,100) ?>%;background:var(--<?= $color ?>);border-radius:2px;"></div>
                        </div>
                    </td>
                    <td>
                        <?php
                        $sc = ['Open'=>'success','Closed'=>'danger','Cancelled'=>'secondary'][$sec['status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $sc ?>"><?= $sec['status'] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Section Modal -->
<?php if (in_array($role,['admin','registrar'])): ?>
<div class="modal-overlay" id="modal-add-section">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h3 class="modal-title">Add Class Section</h3>
            <button class="modal-close">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_section">
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Section Code *</label>
                        <input type="text" name="section_code" class="form-control" required placeholder="e.g., BSCS1A-CS101">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Students</label>
                        <input type="number" name="max_students" class="form-control" value="40" min="1" max="100">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Program / Course *</label>
                    <select id="programSelect" class="form-control" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs as $prog): ?>
                        <option value="<?= $prog['id'] ?>"><?= escape($prog['code'].' - '.$prog['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Subject *</label>
                    <select name="subject_id" id="subjectSelect" class="form-control" required>
                        <option value="">Select Program First</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Faculty (Registered Teachers)</label>
                    <select name="faculty_id" id="facultySelect" class="form-control">
                        <option value="">TBA / Not Assigned</option>
                        <?php foreach ($faculty as $fac): ?>
                        <option value="<?= $fac['id'] ?>"><?= escape($fac['first_name'].' '.$fac['last_name'].' ('.$fac['department'].')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Room</label>
                        <input type="text" name="room" class="form-control" placeholder="e.g., Room 302">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Day & Time</label>
                        <input type="text" name="day_time" class="form-control" placeholder="e.g., MWF 8:00-9:30 AM">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Section</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Subject data structure organized by program
const subjectsData = {
    <?php foreach ($programs as $prog): ?>
    <?= $prog['id'] ?>: [
        <?php foreach ($subjects as $sub): ?>
        <?php if ($sub['program_id'] == $prog['id']): ?>
        { id: <?= $sub['id'] ?>, code: <?= json_encode($sub['code']) ?>, name: <?= json_encode($sub['name']) ?> },
        <?php endif; ?>
        <?php endforeach; ?>
    ],
    <?php endforeach; ?>
};

// Filter subjects when program is selected
document.getElementById('programSelect').addEventListener('change', function() {
    const programId = this.value;
    const subjectSelect = document.getElementById('subjectSelect');
    
    if (!programId) {
        subjectSelect.innerHTML = '<option value="">Select Program First</option>';
        subjectSelect.disabled = true;
        return;
    }
    
    const subjects = subjectsData[programId] || [];
    
    if (subjects.length === 0) {
        subjectSelect.innerHTML = '<option value="">No subjects available for this program</option>';
        subjectSelect.disabled = true;
        return;
    }
    
    subjectSelect.disabled = false;
    subjectSelect.innerHTML = '<option value="">Select Subject</option>';
    
    subjects.forEach(subject => {
        const opt = document.createElement('option');
        opt.value = subject.id;
        opt.textContent = subject.code + ' - ' + subject.name;
        subjectSelect.appendChild(opt);
    });
});

// Initialize on page load
window.addEventListener('load', function() {
    // Reset program selector when modal opens
    const modal = document.getElementById('modal-add-section');
    if (modal) {
        const origShow = modal.style.display;
        const observer = new MutationObserver(function() {
            if (modal.classList.contains('active') || modal.style.display === 'flex') {
                document.getElementById('programSelect').value = '';
                document.getElementById('subjectSelect').innerHTML = '<option value="">Select Program First</option>';
                document.getElementById('subjectSelect').disabled = true;
            }
        });
        observer.observe(modal, { attributes: true, attributeFilter: ['class', 'style'] });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
