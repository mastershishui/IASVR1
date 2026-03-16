<?php
$pageTitle = 'Document Requests';
require_once 'includes/config.php';
requireLogin();
$db = getDB();
$role = $_SESSION['role'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'request_document' && $role === 'student') {
        $sid = $db->query("SELECT id FROM students WHERE user_id={$_SESSION['user_id']}")->fetch_assoc()['id'] ?? 0;
        if ($sid) {
            $docType = $_POST['document_type'];
            $purpose = trim($_POST['purpose']);
            $copies  = (int)$_POST['copies'];
            $stmt = $db->prepare("INSERT INTO document_requests (student_id,document_type,purpose,copies) VALUES (?,?,?,?)");
            $stmt->bind_param('issi',$sid,$docType,$purpose,$copies);
            $stmt->execute();
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Document request submitted!'];
        }
        redirect('documents.php');
    }
    
    if ($action === 'update_status' && in_array($role,['registrar','admin'])) {
        $rid = (int)$_POST['request_id'];
        $status = $_POST['status'];
        $uid = $_SESSION['user_id'];
        if ($status === 'Released') {
            $stmt = $db->prepare("UPDATE document_requests SET status=?,processed_by=?,released_at=NOW() WHERE id=?");
            $stmt->bind_param('sii',$status,$uid,$rid);
        } else {
            $stmt = $db->prepare("UPDATE document_requests SET status=?,processed_by=? WHERE id=?");
            $stmt->bind_param('sii',$status,$uid,$rid);
        }
        $stmt->execute();
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Request status updated.'];
        redirect('documents.php');
    }
}

// Get requests
if ($role === 'student') {
    $sid = $db->query("SELECT id FROM students WHERE user_id={$_SESSION['user_id']}")->fetch_assoc()['id'] ?? 0;
    $requests = $sid ? $db->query("SELECT * FROM document_requests WHERE student_id=$sid ORDER BY requested_at DESC")->fetch_all(MYSQLI_ASSOC) : [];
} else {
    $requests = $db->query("
        SELECT dr.*, u.first_name, u.last_name, s.student_number, p2.first_name as proc_fn, p2.last_name as proc_ln
        FROM document_requests dr
        JOIN students s ON dr.student_id=s.id
        JOIN users u ON s.user_id=u.id
        LEFT JOIN users p2 ON dr.processed_by=p2.id
        ORDER BY dr.requested_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">Document Requests</h1>
            <p class="page-subtitle"><?= $role === 'student' ? 'Request official academic documents and credentials.' : 'Process and release student document requests.' ?></p>
        </div>
        <?php if ($role === 'student'): ?>
        <button class="btn btn-primary" data-modal="modal-request">+ Request Document</button>
        <?php endif; ?>
    </div>
</div>

<!-- Process Flow -->
<div class="card mb-4">
    <div class="card-body" style="padding:16px 20px;">
        <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:0.05em;">Document Request Workflow</div>
        <div style="display:flex;align-items:center;gap:12px;overflow-x:auto;flex-wrap:wrap;">
            <?php
            $dflow = [
                ['📝','Student Requests','Student submits document request online'],
                ['⚙️','Processing','Admin verifies and prepares the document'],
                ['📄','Generation','Document is digitally created or printed'],
                ['✅','Release','Document released to student with tracking'],
            ];
            foreach ($dflow as $i => $step):
            ?>
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="text-align:center;min-width:90px;">
                    <div style="font-size:22px;margin-bottom:4px;"><?= $step[0] ?></div>
                    <div style="font-size:12px;font-weight:700;"><?= $step[1] ?></div>
                    <div style="font-size:10.5px;color:var(--text-muted);margin-top:2px;"><?= $step[2] ?></div>
                </div>
                <?php if ($i < count($dflow)-1): ?><div style="color:var(--text-muted);font-size:20px;padding:0 4px;">→</div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Requests Table -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Document Requests (<?= count($requests) ?>)</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <?php if ($role !== 'student'): ?><th>Student</th><?php endif; ?>
                    <th>Document Type</th>
                    <th>Purpose</th>
                    <th>Copies</th>
                    <th>Date Requested</th>
                    <th>Status</th>
                    <?php if (in_array($role,['registrar','admin'])): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No document requests found.</td></tr>
                <?php else: ?>
                <?php foreach ($requests as $req): ?>
                <tr>
                    <?php if ($role !== 'student'): ?>
                    <td>
                        <div style="font-weight:600;"><?= escape($req['first_name'].' '.$req['last_name']) ?></div>
                        <div style="font-size:11.5px;color:var(--text-muted);"><?= escape($req['student_number']) ?></div>
                    </td>
                    <?php endif; ?>
                    <td>
                        <?php
                        $docIcons = ['TOR'=>'📜','Good Moral'=>'🤝','Registration Form'=>'📋','Diploma'=>'🎓','Certification'=>'📄'];
                        $icon = $docIcons[$req['document_type']] ?? '📄';
                        ?>
                        <span style="display:flex;align-items:center;gap:6px;">
                            <span><?= $icon ?></span>
                            <strong><?= escape($req['document_type']) ?></strong>
                        </span>
                    </td>
                    <td style="font-size:13px;"><?= escape(substr($req['purpose']??'',0,50)) ?></td>
                    <td style="text-align:center;"><?= $req['copies'] ?></td>
                    <td style="font-size:12px;color:var(--text-muted);"><?= date('M d, Y', strtotime($req['requested_at'])) ?></td>
                    <td>
                        <?php
                        $sc = ['Pending'=>'warning','Processing'=>'primary','Ready'=>'purple','Released'=>'success','Rejected'=>'danger'][$req['status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $sc ?>"><?= $req['status'] ?></span>
                    </td>
                    <?php if (in_array($role,['registrar','admin'])): ?>
                    <td>
                        <form method="POST" style="display:flex;gap:6px;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                            <select name="status" class="form-control" style="width:130px;padding:5px 8px;font-size:12px;">
                                <?php foreach (['Pending','Processing','Ready','Released','Rejected'] as $st): ?>
                                <option value="<?= $st ?>" <?= $req['status']===$st?'selected':'' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary">Update</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Request Modal (student) -->
<?php if ($role === 'student'): ?>
<div class="modal-overlay" id="modal-request">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Request Document</h3>
            <button class="modal-close">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="request_document">
            <div class="modal-body">
                <div class="alert alert-info">
                    📌 Processing time: 3–5 working days. You will be notified when ready for release.
                </div>
                <div class="form-group">
                    <label class="form-label">Document Type *</label>
                    <select name="document_type" class="form-control" required>
                        <option value="">Select Document</option>
                        <option value="TOR">Transcript of Records (TOR)</option>
                        <option value="Good Moral">Good Moral Certificate</option>
                        <option value="Registration Form">Official Registration Form</option>
                        <option value="Diploma">Diploma</option>
                        <option value="Certification">Enrollment Certification</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Number of Copies *</label>
                    <input type="number" name="copies" class="form-control" required min="1" max="10" value="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Purpose *</label>
                    <textarea name="purpose" class="form-control" rows="3" required placeholder="State the purpose of this document request..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Request</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
