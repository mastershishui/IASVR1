<?php
$pageTitle = 'Submit Payment';
require_once 'includes/config.php';
requireLogin();
requireRole(['student']);
$db = getDB();

$user = currentUser();

// Get student details
$stmt = $db->prepare("
    SELECT s.*, p.name as program_name, p.code as program_code
    FROM students s
    JOIN programs p ON s.program_id = p.id
    WHERE s.user_id = ?
");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();

// Get current academic year/semester from student or use defaults
$current_academic_year = $student['academic_year'] ?? ACADEMIC_YEAR;
$current_semester = $student['semester'] ?? CURRENT_SEMESTER;

// Get current billing with balance
$billing = null;
if ($student) {
    $stmt = $db->prepare("
        SELECT b.*
        FROM billing b
        WHERE b.student_id = ? 
          AND b.academic_year = ? 
          AND b.semester = ? 
          AND b.balance > 0
        ORDER BY b.id DESC
        LIMIT 1
    ");
    $stmt->bind_param('isi', $student['id'], $current_academic_year, $current_semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $billing = $result->fetch_assoc();
}
// Get payment history
$payments = [];
if ($student) {
    $stmt = $db->prepare("
        SELECT p.*, u.username AS received_by_name
        FROM payments p
        JOIN billing b ON p.billing_id = b.id
        LEFT JOIN users u ON p.received_by = u.id
        WHERE b.student_id = ? 
        ORDER BY p.payment_date DESC
    ");
    $stmt->bind_param('i', $student['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $payments = $result->fetch_all(MYSQLI_ASSOC);
}
// Handle payment submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        $amount = (float)$_POST['amount'];
        $payment_method = $_POST['payment_method'];
        $reference_no = $_POST['reference_no'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        // Validate amount
        if ($amount <= 0) {
            $error_message = 'Please enter a valid amount.';
        } elseif (!$billing) {
            $error_message = 'No active billing found.';
        } elseif ($amount > $billing['balance']) {
            $error_message = 'Amount exceeds your remaining balance. Maximum: ₱' . number_format($billing['balance'], 2);
        } else {
            try {
                $db->begin_transaction();
                
                // Generate temporary reference number if empty
                if (empty($reference_no)) {
                    $reference_no = 'TEMP-' . date('Ymd') . '-' . uniqid();
                }
                
                // Generate temporary OR number (will be replaced when approved)
                $temp_or = 'TEMP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Insert payment with PENDING status
$stmt = $db->prepare("
    INSERT INTO payments 
        (billing_id, student_id, amount, payment_method, reference_number, or_number, 
         notes, status, payment_date, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())
");

// Count the placeholders: billing_id, student_id, amount, payment_method, reference_number, or_number, notes = 7 placeholders
$stmt->bind_param('iidssss', 
    $billing['id'],      // i (integer)
    $student['id'],      // i (integer)
    $amount,             // d (double/decimal)
    $payment_method,     // s (string)
    $reference_no,       // s (string)
    $temp_or,            // s (string)
    $notes               // s (string)
);
$stmt->execute();
                
                $db->commit();
                
                $success_message = 'Your payment has been submitted successfully! It is now pending approval from the accounting office.';
                
                // Refresh page to show new payment
                echo '<meta http-equiv="refresh" content="3">';
                
            } catch (Exception $e) {
                $db->rollback();
                $error_message = 'Failed to submit payment: ' . $e->getMessage();
            }
        }
    }
}

include 'includes/header.php';
?>

<style>
/* Additional styles for payment page */
.payment-status-pending {
    background-color: #fef3c7;
    color: #92400e;
    padding: 4px 8px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}

.payment-status-approved {
    background-color: #d1fae5;
    color: #065f46;
    padding: 4px 8px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}

.payment-status-rejected {
    background-color: #fee2e2;
    color: #991b1b;
    padding: 4px 8px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}

.upload-area {
    border: 2px dashed var(--gray-300);
    border-radius: var(--radius);
    padding: 20px;
    text-align: center;
    background: var(--gray-50);
    cursor: pointer;
    transition: all 0.2s;
}

.upload-area:hover {
    border-color: var(--primary);
    background: var(--primary-light);
}

.upload-area.has-file {
    border-color: var(--success);
    background: #d1fae5;
}

.file-info {
    font-size: 12px;
    color: var(--gray-600);
    margin-top: 8px;
}

.payment-note {
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
    padding: 12px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    font-size: 13px;
}

.payment-note strong {
    color: #92400e;
}

.alert-info {
    background: var(--primary-light);
    border-left: 4px solid var(--primary);
    padding: 12px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    font-size: 13px;
}
</style>

<?php if ($success_message): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="font-size: 24px;">✅</div>
            <div>
                <strong style="font-size: 16px;">Payment Submitted!</strong><br>
                <?= htmlspecialchars($success_message) ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger" style="margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="font-size: 24px;">❌</div>
            <div>
                <strong style="font-size: 16px;">Error</strong><br>
                <?= htmlspecialchars($error_message) ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$billing && empty($payments)): ?>
    <div class="empty-state" style="text-align: center; padding: 60px 24px;">
        <div style="font-size: 64px; margin-bottom: 16px;">💰</div>
        <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">No Outstanding Balance</h3>
        <p style="color: var(--text-muted);">You don't have any outstanding balance to pay at the moment.</p>
        <a href="student_soa.php" class="btn btn-primary" style="margin-top: 16px;">View Statement</a>
    </div>
<?php else: ?>

    <?php if ($billing): ?>
    <!-- Current Balance Card -->
    <div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, var(--primary-light), #fff);">
        <div class="card-body">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span class="badge badge-primary">Current Term: AY <?= $current_academic_year ?> - Sem <?= $current_semester ?></span>
                    <h2 style="margin-top: 16px; font-size: 28px; font-weight: 800;">₱<?= number_format($billing['balance'], 2) ?></h2>
                    <p class="text-muted">Outstanding Balance</p>
                </div>
                <div style="text-align: right;">
                    <div class="text-sm text-muted">Total Assessment</div>
                    <div style="font-size: 20px; font-weight: 700;">₱<?= number_format($billing['total_amount'], 2) ?></div>
                    <div class="text-sm text-muted mt-8">Paid Amount</div>
                    <div style="font-size: 20px; font-weight: 700; color: var(--success);">₱<?= number_format($billing['amount_paid'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Important Note -->
    <div class="payment-note">
        <strong>📌 Important:</strong> All online payments (GCash, Online Banking, Check) require proof of payment and will be pending approval. Cash payments must be made at the accounting office.
    </div>

    <div class="grid-2">
        <!-- Payment Submission Form -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Submit Payment</h3>
            </div>
            
            <form method="POST" class="card-body">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <input type="hidden" name="submit_payment" value="1">
                
                <div class="form-group">
                    <label class="form-label">Amount to Pay (₱)</label>
                    <input type="number" 
                           class="form-control" 
                           name="amount" 
                           step="0.01" 
                           min="1" 
                           max="<?= $billing['balance'] ?>"
                           placeholder="Enter amount" 
                           required>
                    <div class="form-hint">Maximum: ₱<?= number_format($billing['balance'], 2) ?></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select class="form-control" name="payment_method" id="paymentMethod" required>
                        <option value="">-- Select Payment Method --</option>
                        <option value="Cash">💵 Cash (Pay at Cashier)</option>
                        <option value="GCash">📱 GCash</option>
                        <option value="Online Banking">🏦 Online Banking</option>
                        <option value="Check">📝 Check</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Reference Number (Optional)</label>
                    <input type="text" 
                           class="form-control" 
                           name="reference_no" 
                           placeholder="GCash ref #, Check #, etc.">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="Any additional information..."></textarea>
                </div>
                
                <div class="alert alert-info" id="paymentNote">
                    <strong>Note:</strong> Your payment will be pending approval from the accounting office.
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;" id="submitBtn">
                    Submit Payment for Approval
                </button>
            </form>
        </div>
    <?php endif; ?>
        
        <!-- Payment History -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Your Payment History</h3>
    </div>
    
    <?php if (empty($payments)): ?>
        <div class="empty-state" style="text-align: center; padding: 48px 24px;">
            <div style="font-size: 48px; margin-bottom: 16px;">📋</div>
            <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 8px;">No payments yet</h3>
            <p style="color: var(--text-muted);">Your payment history will appear here.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>OR Number</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                            <td><small><?= htmlspecialchars($p['reference_number'] ?? '—') ?></small></td>
                            <td style="font-weight: 700; color: var(--success);">₱<?= number_format($p['amount'], 2) ?></td>
                            <td><span class="badge badge-secondary"><?= htmlspecialchars($p['payment_method']) ?></span></td>
                            <td>
                                <?php 
                                $status = $p['status'] ?? 'Pending';
                                $statusClass = '';
                                $statusText = '';
                                
                                if ($status === 'Pending') {
                                    $statusClass = 'payment-status-pending';
                                    $statusText = '⏳ Pending';
                                } elseif ($status === 'Confirmed') {
                                    $statusClass = 'payment-status-approved';
                                    $statusText = '✅ Approved';
                                } elseif ($status === 'Cancelled') {
                                    $statusClass = 'payment-status-rejected';
                                    $statusText = '❌ Rejected';
                                } elseif ($status === 'Void') {
                                    $statusClass = 'payment-status-rejected';
                                    $statusText = '⚠️ Void';
                                } else {
                                    $statusClass = 'badge badge-secondary';
                                    $statusText = $status;
                                }
                                ?>
                                <span class="<?= $statusClass ?>"><?= $statusText ?></span>
                            </td>
                            <td><span class="mono"><?= htmlspecialchars($p['or_number'] ?? '—') ?></span></td>
                            <td><small class="text-muted"><?= htmlspecialchars($p['notes'] ?? '—') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<script>
// Update note based on payment method
document.getElementById('paymentMethod')?.addEventListener('change', function() {
    const paymentNote = document.getElementById('paymentNote');
    const submitBtn = document.getElementById('submitBtn');
    
    if (this.value === 'Cash') {
        paymentNote.innerHTML = '<strong>Note:</strong> For cash payments, please proceed to the accounting office to pay. This is just a record of your intent to pay.';
        submitBtn.innerHTML = 'Submit Cash Payment Intent';
    } else {
        paymentNote.innerHTML = '<strong>Note:</strong> Your payment will be pending approval from the accounting office.';
        submitBtn.innerHTML = 'Submit Payment for Approval';
    }
});

// Form validation
document.querySelector('form')?.addEventListener('submit', function(e) {
    const amount = document.querySelector('input[name="amount"]')?.value;
    const method = document.querySelector('select[name="payment_method"]')?.value;
    
    if (!method) {
        e.preventDefault();
        alert('Please select a payment method');
        return false;
    }
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid amount');
        return false;
    }
    
    // Show loading state
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ Submitting...';
    
    return true;
});

// Prevent double submission
let submitted = false;
document.querySelector('form')?.addEventListener('submit', function(e) {
    if (submitted) {
        e.preventDefault();
        return false;
    }
    submitted = true;
});
</script>

<?php include 'includes/footer.php'; ?>