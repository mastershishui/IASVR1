<?php
$pageTitle = 'Billing & Payments';
require_once 'includes/config.php';
requireLogin();
requireRole(['admin','accounting','student']);
$db = getDB();
$role = $_SESSION['role'];

// Handle payment posting
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'post_payment' && in_array($role,['accounting','admin'])) {
        $bid = (int)$_POST['billing_id'];
        $amount = (float)$_POST['amount'];
        $method = $_POST['payment_method'];
        $ref    = trim($_POST['reference_number'] ?? '');
        $uid    = $_SESSION['user_id'];
        
        // Insert payment
        $stmt = $db->prepare("INSERT INTO payments (billing_id,amount,payment_method,reference_number,received_by) VALUES (?,?,?,?,?)");
        $stmt->bind_param('idssi',$bid,$amount,$method,$ref,$uid);
        $stmt->execute();
        
        // Update billing
        $stmt2 = $db->prepare("UPDATE billing SET amount_paid=amount_paid+?, balance=balance-? WHERE id=?");
        $stmt2->bind_param('ddi',$amount,$amount,$bid);
        $stmt2->execute();
        
        // Update status
        $billing = $db->query("SELECT * FROM billing WHERE id=$bid")->fetch_assoc();
        if ($billing['balance'] <= 0) {
            $db->query("UPDATE billing SET status='Paid' WHERE id=$bid");
            // Update enrollment status to Paid
            $db->query("UPDATE enrollments e JOIN students s ON e.student_id=s.id SET e.status='Paid' WHERE s.id={$billing['student_id']} AND e.status='Validated'");
        } elseif ($billing['amount_paid'] > 0) {
            $db->query("UPDATE billing SET status='Partial' WHERE id=$bid");
        }
        
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Payment of ₱".number_format($amount,2)." posted successfully."];
        logActivity('post_payment','Billing',"Payment of ₱$amount posted for billing #$bid");
        redirect('billing.php');
    }
    
    if ($action === 'create_billing') {
        $sid    = (int)$_POST['student_id'];
        $tuition = (float)$_POST['tuition_fee'];
        $misc    = (float)$_POST['misc_fees'];
        $other   = (float)$_POST['other_fees'];
        $discount = (float)$_POST['discount'];
        $total  = $tuition + $misc + $other - $discount;
        $ay     = ACADEMIC_YEAR;
        $sem    = CURRENT_SEMESTER;
        
        $stmt = $db->prepare("INSERT INTO billing (student_id,academic_year,semester,tuition_fee,misc_fees,other_fees,discount,total_amount,balance) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('isidddddd',$sid,$ay,$sem,$tuition,$misc,$other,$discount,$total,$total);
        $stmt->execute();
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Billing created successfully.'];
        redirect('billing.php');
    }
}

// Get billing records
$billings = [];
if ($role === 'student') {
    $sid = $db->query("SELECT id FROM students WHERE user_id={$_SESSION['user_id']}")->fetch_assoc()['id'] ?? 0;
    if ($sid) {
        $billings = $db->query("SELECT b.*, u.first_name, u.last_name, s.student_number, p.name as program_name FROM billing b JOIN students s ON b.student_id=s.id JOIN users u ON s.user_id=u.id LEFT JOIN programs p ON s.program_id=p.id WHERE b.student_id=$sid ORDER BY b.id DESC")->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $billings = $db->query("SELECT b.*, u.first_name, u.last_name, s.student_number, p.name as program_name FROM billing b JOIN students s ON b.student_id=s.id JOIN users u ON s.user_id=u.id LEFT JOIN programs p ON s.program_id=p.id ORDER BY b.id DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
}

$students = $db->query("SELECT s.id, s.student_number, u.first_name, u.last_name FROM students s JOIN users u ON s.user_id=u.id ORDER BY u.last_name")->fetch_all(MYSQLI_ASSOC);

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title"><?= $role === 'student' ? 'My Billing' : 'Billing & Payments' ?></h1>
            <p class="page-subtitle"><?= $role === 'student' ? 'View your tuition fees, payments, and balance.' : 'Manage student billing, payments, and financial records.' ?></p>
        </div>
        <?php if (in_array($role,['accounting','admin'])): ?>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-primary" data-modal="modal-create-billing">+ Create Billing</button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Summary Stats (accounting) -->
<?php if (in_array($role,['accounting','admin'])): ?>
<?php
$summary = $db->query("SELECT SUM(total_amount) as total, SUM(amount_paid) as paid, SUM(balance) as bal, COUNT(*) as cnt FROM billing")->fetch_assoc();
?>
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);">
    <div class="stat-card"><div class="stat-header"><div class="stat-icon blue">💰</div></div><div class="stat-value">₱<?= number_format(($summary['total']??0)/1000,1) ?>K</div><div class="stat-label">Total Billed</div></div>
    <div class="stat-card"><div class="stat-header"><div class="stat-icon green">✅</div></div><div class="stat-value">₱<?= number_format(($summary['paid']??0)/1000,1) ?>K</div><div class="stat-label">Total Collected</div></div>
    <div class="stat-card"><div class="stat-header"><div class="stat-icon orange">⏳</div></div><div class="stat-value">₱<?= number_format(($summary['bal']??0)/1000,1) ?>K</div><div class="stat-label">Outstanding</div></div>
    <div class="stat-card"><div class="stat-header"><div class="stat-icon purple">📋</div></div><div class="stat-value"><?= $summary['cnt'] ?></div><div class="stat-label">Billing Records</div></div>
</div>
<?php endif; ?>

<!-- Billing Table -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Billing Records</span>
        <input type="text" id="tableSearch" class="form-control" placeholder="Search..." style="width:200px;padding:6px 12px;">
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <?php if ($role !== 'student'): ?><th>Student</th><?php endif; ?>
                    <th>Academic Year</th>
                    <th>Sem</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <?php if (in_array($role,['accounting','admin'])): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($billings)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No billing records found.</td></tr>
                <?php else: ?>
                <?php foreach ($billings as $b): ?>
                <tr class="searchable-row">
                    <?php if ($role !== 'student'): ?>
                    <td>
                        <div style="font-weight:600;"><?= escape($b['first_name'].' '.$b['last_name']) ?></div>
                        <div style="font-size:11.5px;color:var(--text-muted);"><?= escape($b['student_number']) ?></div>
                    </td>
                    <?php endif; ?>
                    <td><?= escape($b['academic_year']) ?></td>
                    <td>Sem <?= $b['semester'] ?></td>
                    <td style="font-weight:600;">₱<?= number_format($b['total_amount'],2) ?></td>
                    <td style="color:var(--success);font-weight:600;">₱<?= number_format($b['amount_paid'],2) ?></td>
                    <td style="font-weight:700;color:<?= $b['balance']>0?'var(--danger)':'var(--success)' ?>;">
                        ₱<?= number_format($b['balance'],2) ?>
                    </td>
                    <td>
                        <?php
                        $bc = ['Paid'=>'success','Partial'=>'warning','Unpaid'=>'danger'][$b['status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $bc ?>"><?= $b['status'] ?></span>
                    </td>
                    <?php if (in_array($role,['accounting','admin'])): ?>
                    <td>
                        <?php if ($b['balance'] > 0): ?>
                        <button class="btn btn-sm btn-primary" data-modal="modal-payment-<?= $b['id'] ?>">+ Post Payment</button>
                        <?php else: ?>
                        <span class="badge badge-success">Fully Paid</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                
                <!-- Payment Modal per billing -->
                <?php if (in_array($role,['accounting','admin']) && $b['balance'] > 0): ?>
                <tr style="display:none;"><td colspan="8">
                <div class="modal-overlay" id="modal-payment-<?= $b['id'] ?>">
                    <div class="modal" style="max-width:460px;">
                        <div class="modal-header">
                            <h3 class="modal-title">Post Payment</h3>
                            <button class="modal-close">×</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="post_payment">
                            <input type="hidden" name="billing_id" value="<?= $b['id'] ?>">
                            <div class="modal-body">
                                <div style="padding:12px;background:var(--bg);border-radius:8px;margin-bottom:16px;">
                                    <div style="font-size:13px;color:var(--text-muted);">Student</div>
                                    <div style="font-weight:700;"><?= escape($b['first_name'].' '.$b['last_name']) ?></div>
                                    <div style="display:flex;justify-content:space-between;margin-top:8px;">
                                        <div><div style="font-size:12px;color:var(--text-muted);">Balance</div><div style="font-weight:700;color:var(--danger);">₱<?= number_format($b['balance'],2) ?></div></div>
                                        <div><div style="font-size:12px;color:var(--text-muted);">Already Paid</div><div style="font-weight:700;color:var(--success);">₱<?= number_format($b['amount_paid'],2) ?></div></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Amount *</label>
                                    <input type="number" name="amount" class="form-control" required min="1" max="<?= $b['balance'] ?>" step="0.01" placeholder="₱0.00">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Payment Method *</label>
                                    <select name="payment_method" class="form-control" required>
                                        <option value="Cash">Cash</option>
                                        <option value="GCash">GCash</option>
                                        <option value="Online Banking">Online Banking</option>
                                        <option value="Check">Check</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Reference Number</label>
                                    <input type="text" name="reference_number" class="form-control" placeholder="Optional">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                                <button type="submit" class="btn btn-success">Post Payment</button>
                            </div>
                        </form>
                    </div>
                </div>
                </td></tr>
                <?php endif; ?>
                
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Billing Modal -->
<?php if (in_array($role,['accounting','admin'])): ?>
<div class="modal-overlay" id="modal-create-billing">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Create Billing</h3>
            <button class="modal-close">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_billing">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Student *</label>
                    <select name="student_id" class="form-control" required>
                        <option value="">Select Student</option>
                        <?php foreach ($students as $st): ?>
                        <option value="<?= $st['id'] ?>"><?= escape($st['student_number'].' - '.$st['first_name'].' '.$st['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Tuition Fee (₱) *</label>
                        <input type="number" name="tuition_fee" class="form-control" required min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Miscellaneous Fees (₱)</label>
                        <input type="number" name="misc_fees" class="form-control" min="0" step="0.01" placeholder="0.00" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Other Fees (₱)</label>
                        <input type="number" name="other_fees" class="form-control" min="0" step="0.01" placeholder="0.00" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Discount / Scholarship (₱)</label>
                        <input type="number" name="discount" class="form-control" min="0" step="0.01" placeholder="0.00" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Billing</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
