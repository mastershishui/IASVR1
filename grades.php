<?php
$pageTitle = 'Grades Management';
require_once 'includes/config.php';
requireLogin();
requireRole(['admin','registrar']);
$db   = getDB();
$user = currentUser();
$role = $user['role'];

$view   = $_GET['view']   ?? 'pending';
$ay     = $_GET['ay']     ?? ACADEMIC_YEAR;
$sem    = (int)($_GET['sem'] ?? CURRENT_SEMESTER);
$secId  = (int)($_GET['section'] ?? 0);

// Handle actions: approve grade, handle change requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_grade') {
        $gid = (int)$_POST['grade_id'];
        $uid = $user['user_id'];
        $stmt = $db->prepare("UPDATE grades SET approved_by=?, approved_at=NOW() WHERE id=?");
        $stmt->bind_param('ii', $uid, $gid);
        $stmt->execute();
        logActivity('Approved grade', 'Grades', "grade_id=$gid");
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Grade approved and posted.'];
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'resolve_change_request') {
        $reqId   = (int)$_POST['request_id'];
        $decision = $_POST['decision'] ?? 'rejected';
        $notes    = trim($_POST['notes'] ?? '');

        // Get request with grade reference
        $req = $db->query("SELECT * FROM grade_change_requests WHERE id={$reqId}")->fetch_assoc();
        if ($req && $req['status'] === 'Pending') {
            $resolverId = $user['user_id'];

            if ($decision === 'approved') {
                // Apply the new grade values
                $stmt = $db->prepare("UPDATE grades SET midterm=?, finals=?, final_grade=?, remarks=? WHERE id=?");
                $stmt->bind_param(
                    'dddsi',
                    $req['new_midterm'],
                    $req['new_finals'],
                    $req['new_final_grade'],
                    $req['new_remarks'],
                    $req['grade_id']
                );
                $stmt->execute();
            }

            $stmt = $db->prepare("UPDATE grade_change_requests SET status=?, resolved_by=?, resolved_at=NOW(), resolver_notes=? WHERE id=?");
            $stmt->bind_param('sisi', $decision, $resolverId, $notes, $reqId);
            $stmt->execute();

            logActivity(ucfirst($decision) . ' grade change request', 'Grades', "request_id=$reqId");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Grade change request updated.'];
        }

        redirect($_SERVER['REQUEST_URI']);
    }
}

// Common filters
$sections = $db->query("
    SELECT sec.id, sec.section_code, sub.code as subject_code, sub.name as subject_name
    FROM sections sec
    JOIN subjects sub ON sec.subject_id=sub.id
    WHERE sec.academic_year='{$db->real_escape_string($ay)}' AND sec.semester={$sem}
    ORDER BY sub.code, sec.section_code
")->fetch_all(MYSQLI_ASSOC);

// Data sources depending on view
$pendingGrades = [];
$reports       = [];
$changeReqs    = [];

if ($view === 'pending') {
    // All grades that have final_grade but no approved_at
    $extra = $secId ? " AND e.section_id={$secId}" : '';
        $pendingGrades = $db->query("
        SELECT g.id as grade_id, g.final_grade, g.remarks, g.prelim, g.midterm, g.finals, g.encoded_at,
               e.id as enrollment_id, sec.section_code, sub.code as subject_code, sub.name as subject_name,
               u.first_name, u.last_name, s.student_number
        FROM grades g
        JOIN enrollments e ON g.enrollment_id=e.id
        JOIN sections sec ON e.section_id=sec.id
        JOIN subjects sub ON sec.subject_id=sub.id
        JOIN students s ON e.student_id=s.id
        JOIN users u ON s.user_id=u.id
        WHERE g.final_grade IS NOT NULL AND g.approved_at IS NULL
          AND sec.academic_year='{$db->real_escape_string($ay)}' AND sec.semester={$sem}
          {$extra}
        ORDER BY sub.code, sec.section_code, u.last_name
    ")->fetch_all(MYSQLI_ASSOC);
} elseif ($view === 'reports') {
    // Grade summary per section
    $extra = $secId ? " AND sec.id={$secId}" : '';
    $reports = $db->query("
        SELECT sec.id as section_id, sec.section_code, sub.code as subject_code, sub.name as subject_name,
               COUNT(g.id) as graded_count,
               SUM(CASE WHEN g.remarks='Passed' THEN 1 ELSE 0 END) as passed_count,
               SUM(CASE WHEN g.remarks='Failed' THEN 1 ELSE 0 END) as failed_count,
               AVG(g.final_grade) as avg_grade
        FROM sections sec
        JOIN subjects sub ON sec.subject_id=sub.id
        JOIN enrollments e ON e.section_id=sec.id AND e.status='Enrolled'
        LEFT JOIN grades g ON g.enrollment_id=e.id AND g.final_grade IS NOT NULL
        WHERE sec.academic_year='{$db->real_escape_string($ay)}' AND sec.semester={$sem}
        {$extra}
        GROUP BY sec.id
        ORDER BY sub.code, sec.section_code
    ")->fetch_all(MYSQLI_ASSOC);
} elseif ($view === 'changes') {
    $changeReqs = $db->query("
        SELECT r.*, g.final_grade, g.remarks,
               u.first_name as fac_first, u.last_name as fac_last,
               u2.first_name as res_first, u2.last_name as res_last,
               sub.code as subject_code, sub.name as subject_name, sec.section_code
        FROM grade_change_requests r
        JOIN grades g ON r.grade_id=g.id
        JOIN enrollments e ON g.enrollment_id=e.id
        JOIN sections sec ON e.section_id=sec.id
        JOIN subjects sub ON sec.subject_id=sub.id
        JOIN users u ON r.requested_by=u.id
        LEFT JOIN users u2 ON r.resolved_by=u2.id
        ORDER BY r.requested_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">Grades & Assessment</h1>
            <p class="page-subtitle">Review, approve, and analyze academic grades.</p>
        </div>
    </div>
</div>

<!-- Filters & Tabs -->
<div class="card mb-4">
    <div class="card-body" style="padding:14px 20px;">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="view" value="<?= escape($view) ?>">
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
            <div style="min-width:220px;">
                <label class="form-label">Section (optional)</label>
                <select name="section" class="form-control">
                    <option value="0">All Sections</option>
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>" <?= $secId===$sec['id']?'selected':'' ?>>
                        <?= escape($sec['section_code'].' - '.$sec['subject_code'].' '.$sec['subject_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="grades.php?view=<?= escape($view) ?>" class="btn btn-outline">Reset</a>
        </form>

        <div style="margin-top:16px;display:flex;gap:6px;flex-wrap:wrap;">
            <a href="grades.php?view=pending" class="btn btn-sm <?= $view==='pending'?'btn-primary':'btn-outline' ?>">Pending Approval</a>
            <a href="grades.php?view=reports" class="btn btn-sm <?= $view==='reports'?'btn-primary':'btn-outline' ?>">Grade Reports</a>
            <a href="grades.php?view=changes" class="btn btn-sm <?= $view==='changes'?'btn-primary':'btn-outline' ?>">Change Requests</a>
        </div>
    </div>
</div>

<?php if ($view === 'pending'): ?>
<div class="card">
    <div class="card-header">
        <span class="card-title">Pending Grade Approvals (<?= count($pendingGrades) ?>)</span>
        <button onclick="printSection('pending-grades-table')" class="btn btn-sm btn-outline">🖨 Print</button>
    </div>
    <div id="pending-grades-table" class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Section</th>
                    <th>Subject</th>
                    <th>Student</th>
                    <th>Student No.</th>
                    <th>Prelim</th>
                    <th>Midterm</th>
                    <th>Finals</th>
                    <th>Final Grade</th>
                    <th>Remarks</th>
                    <th>Encoded At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingGrades)): ?>
                <tr><td colspan="10" style="text-align:center;padding:32px;color:var(--text-muted);">No pending grades for approval.</td></tr>
                <?php else: ?>
                <?php foreach ($pendingGrades as $g): ?>
                <tr>
                    <td><code style="font-size:12px;"><?= escape($g['section_code']) ?></code></td>
                    <td>
                        <div style="font-weight:600;"><?= escape($g['subject_code']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);"><?= escape($g['subject_name']) ?></div>
                    </td>
                    <td><?= escape($g['last_name'].', '.$g['first_name']) ?></td>
                    <td><code style="font-size:12px;"><?= escape($g['student_number']) ?></code></td>
                    <td><?= $g['prelim'] !== null ? number_format($g['prelim'],2) : '—' ?></td>
                    <td><?= $g['midterm'] !== null ? number_format($g['midterm'],2) : '—' ?></td>
                    <td><?= $g['finals'] !== null ? number_format($g['finals'],2) : '—' ?></td>
                    <td style="font-weight:700;<?php
                        if ($g['remarks'] === 'Passed') echo 'color:var(--success)';
                        elseif ($g['remarks'] === 'Failed') echo 'color:var(--danger)';
                        elseif ($g['remarks'] === 'Incomplete') echo 'color:var(--warning)';
                    ?>">
                        <?= number_format($g['final_grade'],2) ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $g['remarks']==='Passed'?'success':'danger' ?>"><?= $g['remarks'] ?></span>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);">
                        <?= $g['encoded_at'] ? date('M d, Y h:i A', strtotime($g['encoded_at'])) : '—' ?>
                    </td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Approve and post this grade?');" style="display:inline;">
                            <input type="hidden" name="action" value="approve_grade">
                            <input type="hidden" name="grade_id" value="<?= $g['grade_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success">✓ Approve</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($view === 'reports'): ?>
<div class="card">
    <div class="card-header">
        <span class="card-title">Grade Reports & Summary (<?= count($reports) ?> sections)</span>
        <button onclick="printSection('grade-reports-table')" class="btn btn-sm btn-outline">🖨 Print Summary</button>
    </div>
    <div id="grade-reports-table" class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Section</th>
                    <th>Subject</th>
                    <th>Students Graded</th>
                    <th>Passed</th>
                    <th>Failed</th>
                    <th>Pass Rate</th>
                    <th>Average Grade</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">No grade data available for the selected term.</td></tr>
                <?php else: ?>
                <?php foreach ($reports as $r): ?>
                <?php
                $total = (int)$r['graded_count'];
                $pass  = (int)$r['passed_count'];
                $fail  = (int)$r['failed_count'];
                $rate  = $total > 0 ? ($pass / $total) * 100 : 0;
                ?>
                <tr>
                    <td><code style="font-size:12px;"><?= escape($r['section_code']) ?></code></td>
                    <td>
                        <div style="font-weight:600;"><?= escape($r['subject_code']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);"><?= escape($r['subject_name']) ?></div>
                    </td>
                    <td><?= $total ?></td>
                    <td><span class="badge badge-success"><?= $pass ?></span></td>
                    <td><span class="badge badge-danger"><?= $fail ?></span></td>
                    <td>
                        <div style="font-size:13px;font-weight:600;"><?= number_format($rate,1) ?>%</div>
                        <div style="height:4px;background:var(--bg);border-radius:2px;margin-top:3px;overflow:hidden;">
                            <div style="height:100%;width:<?= min($rate,100) ?>%;background:var(--success);border-radius:2px;"></div>
                        </div>
                    </td>
                    <td><?= $r['avg_grade'] !== null ? number_format($r['avg_grade'],2) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($view === 'changes'): ?>
<div class="card">
    <div class="card-header">
        <span class="card-title">Grade Correction Requests (<?= count($changeReqs) ?>)</span>
        <button onclick="printSection('grade-changes-table')" class="btn btn-sm btn-outline">🖨 Print</button>
    </div>
    <div id="grade-changes-table" class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Requested By</th>
                    <th>Subject / Section</th>
                    <th>Current Grade</th>
                    <th>Requested Grade</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Requested At</th>
                    <th>Resolver</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($changeReqs)): ?>
                <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted);">No grade change requests.</td></tr>
                <?php else: ?>
                <?php foreach ($changeReqs as $r): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= escape($r['fac_first'].' '.$r['fac_last']) ?></div>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?= escape($r['subject_code']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);">
                            <?= escape($r['subject_name']) ?><br>
                            <code><?= escape($r['section_code']) ?></code>
                        </div>
                    </td>
                    <td>
                        <?= $r['final_grade'] !== null ? number_format($r['final_grade'],2) : '—' ?><br>
                        <span class="badge badge-<?= $r['remarks']==='Passed'?'success':'danger' ?>"><?= $r['remarks'] ?></span>
                    </td>
                    <td>
                        <?= $r['new_final_grade'] !== null ? number_format($r['new_final_grade'],2) : '—' ?><br>
                        <?php if ($r['new_remarks']): ?>
                        <span class="badge badge-<?= $r['new_remarks']==='Passed'?'success':'danger' ?>"><?= $r['new_remarks'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:260px;font-size:12px;white-space:pre-wrap;"><?= nl2br(escape($r['reason'])) ?></td>
                    <td>
                        <?php
                        $statusColor = ['Pending'=>'warning','Approved'=>'success','Rejected'=>'danger'][$r['status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $statusColor ?>"><?= $r['status'] ?></span>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);">
                        <?= date('M d, Y h:i A', strtotime($r['requested_at'])) ?>
                    </td>
                    <td style="font-size:12px;">
                        <?php if ($r['resolved_by']): ?>
                        <?= escape(($r['res_first'] ?? '').' '.($r['res_last'] ?? '')) ?><br>
                        <span style="color:var(--text-muted);font-size:11px;"><?= $r['resolved_at'] ? date('M d, Y h:i A', strtotime($r['resolved_at'])) : '' ?></span>
                        <?php else: ?>
                        <span style="color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'Pending'): ?>
                        <form method="POST" style="display:flex;flex-direction:column;gap:4px;min-width:150px;">
                            <input type="hidden" name="action" value="resolve_change_request">
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <textarea name="notes" class="form-control" rows="2" placeholder="Notes (optional)" style="font-size:11px;"></textarea>
                            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                <button type="submit" name="decision" value="approved" class="btn btn-sm btn-success">Approve</button>
                                <button type="submit" name="decision" value="rejected" class="btn btn-sm btn-danger">Reject</button>
                            </div>
                        </form>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12px;">No actions</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

