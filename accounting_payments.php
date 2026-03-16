<?php
// ============================================================
//  Accounting Payment Records (Complete Payment Management)
//  File: accounting_payments.php
//  Description: Complete payment management with recording, history, and reports
// ============================================================

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once 'includes/config.php';

// Check if user is logged in and has accounting role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'accounting') {
    header('Location: login.php');
    exit;
}

// Get database connection
$db = getDB();

$page_title = 'Accounting Payments';
$active_nav = 'accounting_payment';

// Get current academic year/semester
$current_academic_year = '2024-2025';
$current_semester = 1;

// Set CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clear output buffers
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    // ========================================================
    // Record new payment (Cashier Walk-in Payment)
    // ========================================================
    if ($_POST['action'] === 'record_payment') {
        // Simple CSRF check
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        $student_id = (int)$_POST['student_id'];
        $billing_id = (int)$_POST['billing_id'];
        $amount = (float)$_POST['amount'];
        $payment_method = $db->real_escape_string($_POST['payment_method']);
        $reference_no = $db->real_escape_string($_POST['reference_no'] ?? '');
        $or_number = generate_or_number($db);
        $notes = $db->real_escape_string($_POST['notes'] ?? '');
        $received_by = (int)$_SESSION['user_id'];
        
        try {
            $db->begin_transaction();
            
            // Get billing details
            $billing_query = "SELECT * FROM billing WHERE id = $billing_id AND student_id = $student_id";
            $billing_result = $db->query($billing_query);
            $billing = $billing_result->fetch_assoc();
            
            if (!$billing) {
                echo json_encode(['error' => 'Billing record not found']);
                exit;
            }
            
            // Check if amount is valid
            if ($amount <= 0) {
                echo json_encode(['error' => 'Invalid payment amount']);
                exit;
            }
            
            if ($amount > $billing['balance']) {
                echo json_encode(['error' => 'Amount exceeds balance']);
                exit;
            }
            
            // Insert payment record - WITH PENDING STATUS
            $insert_query = "
                INSERT INTO payments (
                    student_id, billing_id, amount, payment_method, 
                    reference_number, or_number, status, payment_date, 
                    notes, received_by, created_at
                ) VALUES (
                    $student_id, $billing_id, $amount, '$payment_method', 
                    '$reference_no', '$or_number', 'Pending', NOW(), 
                    '$notes', $received_by, NOW()
                )
            ";

            if (!$db->query($insert_query)) {
                throw new Exception($db->error);
            }

            $payment_id = $db->insert_id;

            // DON'T update billing or enrollment here - they will be updated when approved

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Payment submitted for approval',
                'or_number' => $or_number,
                'payment_id' => $payment_id
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['error' => 'Failed to record payment: ' . $e->getMessage()]);
        }
        exit;
    }

    // ========================================================
    // Approve payment
    // ========================================================
    if ($_POST['action'] === 'approve_payment') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        $payment_id = (int)$_POST['payment_id'];
        
        try {
            $db->begin_transaction();
            
            // Get payment details
            $query = "SELECT p.*, b.id AS billing_id, b.amount_paid, b.balance 
                      FROM payments p
                      JOIN billing b ON p.billing_id = b.id
                      WHERE p.id = $payment_id AND p.status = 'Pending'";
            $result = $db->query($query);
            $payment = $result->fetch_assoc();
            
            if (!$payment) {
                throw new Exception('Payment not found');
            }
            
            // Generate OR number
            $year = date('Y');
            $or_query = "SELECT or_number FROM payments WHERE or_number LIKE 'OR-$year-%' ORDER BY id DESC LIMIT 1";
            $or_result = $db->query($or_query);
            $or_row = $or_result->fetch_assoc();
            
            if ($or_row && $or_row['or_number']) {
                $last_num = intval(substr($or_row['or_number'], -5));
                $new_num = str_pad($last_num + 1, 5, '0', STR_PAD_LEFT);
            } else {
                $new_num = '00001';
            }
            $or_number = "OR-$year-$new_num";
            
            // Update payment
            $update = "UPDATE payments SET status = 'Confirmed', or_number = '$or_number' WHERE id = $payment_id";
            if (!$db->query($update)) {
                throw new Exception('Failed to update payment');
            }
            
            // Update billing
            $new_paid = $payment['amount_paid'] + $payment['amount'];
            $new_balance = $payment['balance'] - $payment['amount'];
            $new_status = $new_balance <= 0 ? 'Paid' : 'Partial';
            
            $billing_update = "UPDATE billing SET amount_paid = $new_paid, balance = $new_balance, status = '$new_status' WHERE id = {$payment['billing_id']}";
            if (!$db->query($billing_update)) {
                throw new Exception('Failed to update billing');
            }
            
            $db->commit();
            
            echo json_encode(['success' => true]);
            
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ========================================================
    // Reject payment
    // ========================================================
    if ($_POST['action'] === 'reject_payment') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        $payment_id = (int)$_POST['payment_id'];
        $reason = $db->real_escape_string($_POST['reason'] ?? '');
        
        $update = "UPDATE payments SET status = 'Cancelled', notes = CONCAT(IFNULL(notes, ''), ' | Rejected: $reason') WHERE id = $payment_id AND status = 'Pending'";
        
        if ($db->query($update)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Failed to reject payment']);
        }
        exit;
    }
    
    // ========================================================
    // Search students with billing info
    // ========================================================
    if ($_POST['action'] === 'search_students_billing') {
        $search = $db->real_escape_string($_POST['query']);
        $search_term = "%$search%";
        
        $query = "
            SELECT DISTINCT
                s.id AS student_id,
                s.student_number,
                CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                p.code AS program_code,
                s.year_level,
                b.id AS billing_id,
                b.total_amount,
                b.amount_paid AS paid_amount,
                b.balance,
                b.status AS billing_status
            FROM students s
            JOIN users u ON s.user_id = u.id
            JOIN programs p ON s.program_id = p.id
            LEFT JOIN billing b ON s.id = b.student_id AND b.balance > 0
            WHERE (s.student_number LIKE '$search_term' OR 
                   CONCAT(u.first_name, ' ', u.last_name) LIKE '$search_term')
            ORDER BY 
                CASE WHEN b.balance > 0 THEN 0 ELSE 1 END,
                b.balance DESC
            LIMIT 15
        ";
        
        $result = $db->query($query);
        $students = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $students[] = $row;
            }
        }
        
        echo json_encode(['students' => $students]);
        exit;
    }
    
    // ========================================================
    // Void payment (with reason)
    // ========================================================
    if ($_POST['action'] === 'void_payment') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        $payment_id = (int)$_POST['payment_id'];
        $reason = $db->real_escape_string($_POST['reason'] ?? 'No reason provided');
        
        try {
            $db->begin_transaction();
            
            // Get payment details
            $payment_query = "
                SELECT p.*, b.id AS billing_id, b.balance, b.amount_paid 
                FROM payments p
                JOIN billing b ON p.billing_id = b.id
                WHERE p.id = $payment_id AND p.status = 'Confirmed'
            ";
            $payment_result = $db->query($payment_query);
            $payment = $payment_result->fetch_assoc();
            
            if (!$payment) {
                echo json_encode(['error' => 'Payment not found or already voided']);
                exit;
            }
            
            // Update payment status to void
            $notes = $payment['notes'] . " | VOID: $reason";
            $void_query = "
                UPDATE payments SET 
                    status = 'Void', 
                    notes = '$notes'
                WHERE id = $payment_id
            ";
            
            if (!$db->query($void_query)) {
                throw new Exception($db->error);
            }
            
            // Reverse billing amounts
            $new_paid = $payment['amount_paid'] - $payment['amount'];
            $new_balance = $payment['balance'] + $payment['amount'];
            $new_status = $new_balance > 0 ? ($new_paid > 0 ? 'Partial' : 'Unpaid') : 'Paid';
            
            $update_query = "
                UPDATE billing SET 
                    amount_paid = $new_paid,
                    balance = $new_balance,
                    status = '$new_status'
                WHERE id = {$payment['billing_id']}
            ";
            
            if (!$db->query($update_query)) {
                throw new Exception($db->error);
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Payment voided successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['error' => 'Failed to void payment: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Generate OR number function
function generate_or_number($db) {
    $year = date('Y');
    $query = "SELECT or_number FROM payments WHERE or_number LIKE 'OR-$year-%' ORDER BY id DESC LIMIT 1";
    $result = $db->query($query);
    $row = $result->fetch_assoc();
    
    if ($row && $row['or_number']) {
        $last_num = intval(substr($row['or_number'], -5));
        $new_num = str_pad($last_num + 1, 5, '0', STR_PAD_LEFT);
    } else {
        $new_num = '00001';
    }
    
    return "OR-$year-$new_num";
}

// Get comprehensive statistics
$today_collections = ['total_amount' => 0, 'transaction_count' => 0];
$query = "
    SELECT 
        COUNT(*) AS transaction_count,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM payments 
    WHERE DATE(payment_date) = CURDATE() 
        AND status = 'Confirmed'
";
$result = $db->query($query);
if ($result) {
    $today_collections = $result->fetch_assoc();
}

$week_collections = ['total_amount' => 0, 'transaction_count' => 0];
$query = "
    SELECT 
        COUNT(*) AS transaction_count,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM payments 
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND status = 'Confirmed'
";
$result = $db->query($query);
if ($result) {
    $week_collections = $result->fetch_assoc();
}

$month_collections = ['total_amount' => 0, 'transaction_count' => 0];
$query = "
    SELECT 
        COUNT(*) AS transaction_count,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM payments 
    WHERE MONTH(payment_date) = MONTH(CURDATE()) 
        AND YEAR(payment_date) = YEAR(CURDATE())
        AND status = 'Confirmed'
";
$result = $db->query($query);
if ($result) {
    $month_collections = $result->fetch_assoc();
}

$pending_approvals = 0;
$query = "SELECT COUNT(*) AS count FROM payments WHERE status = 'Pending'";
$result = $db->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $pending_approvals = $row['count'] ?? 0;
}

$total_outstanding = 0;
$query = "SELECT COALESCE(SUM(balance), 0) AS total FROM billing WHERE balance > 0";
$result = $db->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $total_outstanding = $row['total'] ?? 0;
}

// Get recent payments
$recent_payments = [];
$query = "
    SELECT 
        p.*,
        CONCAT(u.first_name, ' ', u.last_name) AS student_name,
        s.student_number,
        prog.code AS program_code,
        u2.username AS collected_by
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN programs prog ON s.program_id = prog.id
    LEFT JOIN users u2 ON p.received_by = u2.id
    ORDER BY p.payment_date DESC
    LIMIT 100
";
$result = $db->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_payments[] = $row;
    }
}

// Get payment methods summary
$payment_methods = [];
$query = "
    SELECT 
        payment_method,
        COUNT(*) AS count,
        COALESCE(SUM(amount), 0) AS total
    FROM payments
    WHERE MONTH(payment_date) = MONTH(CURDATE())
        AND YEAR(payment_date) = YEAR(CURDATE())
        AND status = 'Confirmed'
    GROUP BY payment_method
    ORDER BY total DESC
";
$result = $db->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $payment_methods[] = $row;
    }
}

include 'includes/header.php';
?>

<!-- Hidden CSRF token for AJAX requests -->
<input type="hidden" id="global_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 class="page-title">Accounting Payments</h1>
            <p class="page-subtitle">Complete payment management for AY <?= $current_academic_year ?> - Sem <?= $current_semester ?></p>
        </div>
        <div style="display: flex; gap: 8px;">
            <button class="btn btn-primary" onclick="openRecordPaymentModal()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="margin-right: 4px;">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Record New Payment
            </button>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">💰</div>
            <span class="stat-badge up">Today</span>
        </div>
        <div class="stat-value">₱<?= number_format($today_collections['total_amount'] ?? 0, 2) ?></div>
        <div class="stat-label">Today's Collections • <?= ($today_collections['transaction_count'] ?? 0) ?> transactions</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">📊</div>
            <span class="stat-badge up">This Week</span>
        </div>
        <div class="stat-value">₱<?= number_format($week_collections['total_amount'] ?? 0, 2) ?></div>
        <div class="stat-label">Weekly Collections • <?= ($week_collections['transaction_count'] ?? 0) ?> transactions</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon purple">📅</div>
            <span class="stat-badge up">This Month</span>
        </div>
        <div class="stat-value">₱<?= number_format($month_collections['total_amount'] ?? 0, 2) ?></div>
        <div class="stat-label">Monthly Collections • <?= ($month_collections['transaction_count'] ?? 0) ?> transactions</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon amber">⏳</div>
            <span class="stat-badge urgent">Pending</span>
        </div>
        <div class="stat-value"><?= $pending_approvals ?></div>
        <div class="stat-label">Awaiting Approval</div>
    </div>
</div>

<!-- Payment Methods Summary -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3 class="card-title">Payment Methods Summary (This Month)</h3>
    </div>
    <div class="card-body">
        <?php if (!empty($payment_methods)): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <?php foreach ($payment_methods as $method): ?>
                <div style="background: var(--bg); padding: 16px; border-radius: var(--radius); border: 1px solid var(--border);">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <div style="width: 40px; height: 40px; background: var(--primary-light); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                            <?php
                            $icon = '💰';
                            if ($method['payment_method'] === 'Cash') $icon = '💵';
                            elseif ($method['payment_method'] === 'GCash') $icon = '📱';
                            elseif ($method['payment_method'] === 'Online Banking') $icon = '🏦';
                            elseif ($method['payment_method'] === 'Check') $icon = '📝';
                            echo $icon;
                            ?>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?= htmlspecialchars($method['payment_method']) ?></div>
                            <div style="font-size: 12px; color: var(--text-muted);"><?= $method['count'] ?> transactions</div>
                        </div>
                    </div>
                    <div style="font-weight: 700; font-size: 18px; color: var(--primary);">₱<?= number_format($method['total'], 2) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 24px; color: var(--text-muted);">
                No payment methods data available for this month
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Payments Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Recent Payment Transactions</h3>
        <div style="display: flex; gap: 8px;">
            <input type="text" id="paymentSearch" class="form-control" placeholder="Search payments..." style="width: 250px;">
            <select id="statusFilter" class="form-control" style="width: 150px;">
                <option value="">All Status</option>
                <option value="Confirmed">Confirmed</option>
                <option value="Pending">Pending</option>
                <option value="Cancelled">Cancelled</option>
                <option value="Void">Void</option>
            </select>
        </div>
    </div>
    
    <div class="table-wrap">
        <table id="paymentsTable">
            <thead>
                <tr>
                    <th>OR #</th>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Status</th>
                    <th>Collected By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_payments)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No payment records found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_payments as $payment): ?>
                    <tr>
                        <td><span style="font-weight: 600;"><?= htmlspecialchars($payment['or_number'] ?? '—') ?></span></td>
                        <td>
                            <div><?= date('M d, Y', strtotime($payment['payment_date'])) ?></div>
                            <div style="font-size: 11px; color: var(--text-muted);"><?= date('h:i A', strtotime($payment['payment_date'])) ?></div>
                        </td>
                        <td>
                            <div style="font-weight: 600;"><?= htmlspecialchars($payment['student_name'] ?? '') ?></div>
                            <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($payment['student_number'] ?? '') ?></div>
                            <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($payment['program_code'] ?? '') ?></div>
                        </td>
                        <td><span style="font-weight: 700;">₱<?= number_format($payment['amount'] ?? 0, 2) ?></span></td>
                        <td>
                            <span class="badge badge-secondary">
                                <?php
                                $icon = '';
                                if (($payment['payment_method'] ?? '') === 'Cash') $icon = '💵 ';
                                elseif (($payment['payment_method'] ?? '') === 'GCash') $icon = '📱 ';
                                elseif (($payment['payment_method'] ?? '') === 'Online Banking') $icon = '🏦 ';
                                elseif (($payment['payment_method'] ?? '') === 'Check') $icon = '📝 ';
                                echo $icon . htmlspecialchars($payment['payment_method'] ?? '');
                                ?>
                            </span>
                        </td>
                        <td><span style="font-size: 12px;"><?= htmlspecialchars($payment['reference_number'] ?? '—') ?></span></td>
                        <td>
                            <?php
                            $status = $payment['status'] ?? 'Pending';
                            $badgeClass = 'secondary';
                            if ($status === 'Confirmed') $badgeClass = 'success';
                            elseif ($status === 'Pending') $badgeClass = 'warning';
                            elseif ($status === 'Cancelled') $badgeClass = 'danger';
                            elseif ($status === 'Void') $badgeClass = 'secondary';
                            ?>
                            <span class="badge badge-<?= $badgeClass ?>"><?= $status ?></span>
                        </td>
                        <td><span style="font-size: 12px;"><?= htmlspecialchars($payment['collected_by'] ?? 'System') ?></span></td>
                        <td>
                            <div style="display: flex; gap: 4px;">
                               
                                
                                <?php if (($payment['status'] ?? '') === 'Pending'): ?>
                                    <button class="btn btn-sm btn-success" onclick="approvePayment(<?= $payment['id'] ?? 0 ?>)" title="Approve Payment">✓</button>
                                    <button class="btn btn-sm btn-danger" onclick="rejectPayment(<?= $payment['id'] ?? 0 ?>)" title="Reject Payment">✗</button>
                                <?php endif; ?>
                                
                                <?php if (($payment['status'] ?? '') === 'Confirmed'): ?>
                                    <button class="btn btn-sm btn-danger" onclick="voidPayment(<?= $payment['id'] ?? 0 ?>)" title="Void Payment">⚠️</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal-overlay" id="recordPaymentModal">
    <div class="modal" style="max-width: 700px;">
        <div class="modal-header">
            <h3 class="modal-title">Record New Payment</h3>
            <button class="modal-close" onclick="closeRecordPaymentModal()">&times;</button>
        </div>
        
        <div class="modal-body">
            <!-- Step 1: Search Student -->
            <div id="step1">
                <div class="form-group">
                    <label class="form-label">Search Student</label>
                    <input type="text" id="studentSearch" class="form-control" placeholder="Enter student name or ID number...">
                </div>
                <div id="studentSearchResults" style="max-height: 300px; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); margin-top: 8px; display: none;"></div>
            </div>

            <!-- Step 2: Payment Details (hidden initially) -->
            <div id="step2" style="display: none;">
                <div id="selectedStudentInfo" style="background: var(--primary-light); padding: 16px; border-radius: var(--radius-sm); margin-bottom: 20px;"></div>
                
                <form id="paymentForm">
                    <input type="hidden" id="selectedStudentId" name="student_id">
                    <input type="hidden" id="selectedBillingId" name="billing_id">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Billing Information</label>
                        <div id="billingInfo" style="background: var(--bg); padding: 12px; border-radius: var(--radius-sm); border: 1px solid var(--border);"></div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <input type="number" id="paymentAmount" name="amount" class="form-control" step="0.01" min="0.01" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Method</label>
                            <select id="paymentMethod" name="payment_method" class="form-control" required>
                                <option value="">Select method</option>
                                <option value="Cash">💵 Cash</option>
                                <option value="GCash">📱 GCash</option>
                                <option value="Online Banking">🏦 Online Banking</option>
                                <option value="Check">📝 Check</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label class="form-label">Reference Number (Optional)</label>
                            <input type="text" id="referenceNo" name="reference_no" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">OR Number</label>
                            <input type="text" class="form-control" readonly value="<?= generate_or_number($db) ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="goToStep1()" id="backBtn" style="display: none;">Back</button>
            <button type="button" class="btn btn-outline" onclick="closeRecordPaymentModal()">Cancel</button>
            <button type="submit" form="paymentForm" class="btn btn-success" id="submitPaymentBtn">Record Payment</button>
        </div>
    </div>
</div>

<!-- Void Payment Modal -->
<div class="modal-overlay" id="voidPaymentModal">
    <div class="modal" style="max-width: 400px;">
        <div class="modal-header">
            <h3 class="modal-title">Void Payment</h3>
            <button class="modal-close" onclick="closeVoidModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; margin-bottom: 16px;">
                <div style="font-size: 48px; color: var(--danger);">⚠️</div>
                <h4 style="font-size: 18px; font-weight: 700; margin-bottom: 8px;">Void this payment?</h4>
                <p style="color: var(--text-muted);">This action cannot be undone. The amount will be reversed from the student's account.</p>
            </div>
            <div class="form-group">
                <label class="form-label">Reason for voiding</label>
                <textarea id="voidReason" class="form-control" rows="3" placeholder="Enter reason..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeVoidModal()">Cancel</button>
            <button class="btn btn-danger" id="confirmVoidBtn">Confirm Void</button>
        </div>
    </div>
</div>

<!-- View Payment Details Modal -->
<div class="modal-overlay" id="viewPaymentModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">Payment Details</h3>
            <button class="modal-close" onclick="closeViewPaymentModal()">&times;</button>
        </div>
        <div class="modal-body" id="paymentDetailsContent">
            <!-- Content loaded dynamically -->
        </div>
    </div>
</div>

<!-- Reject Payment Modal -->
<div class="modal-overlay" id="rejectPaymentModal">
    <div class="modal" style="max-width: 400px;">
        <div class="modal-header">
            <h3 class="modal-title">Reject Payment</h3>
            <button class="modal-close" onclick="closeRejectModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; margin-bottom: 16px;">
                <div style="font-size: 48px; color: var(--danger);">❌</div>
                <h4 style="font-size: 18px; font-weight: 700; margin-bottom: 8px;">Reject this payment?</h4>
                <p style="color: var(--text-muted);">Please provide a reason for rejection.</p>
            </div>
            <div class="form-group">
                <label class="form-label">Reason for rejection</label>
                <textarea id="rejectReason" class="form-control" rows="3" placeholder="Enter reason..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeRejectModal()">Cancel</button>
            <button class="btn btn-danger" id="confirmRejectBtn">Confirm Reject</button>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<style>
/* Student search result styles */
.student-result {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background 0.2s;
}

.student-result:hover {
    background: var(--bg);
}

.student-result:last-child {
    border-bottom: none;
}

.billing-info-box {
    background: var(--bg);
    padding: 12px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
}

.billing-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px dashed var(--border);
}

.billing-row:last-child {
    border-bottom: none;
    font-weight: 700;
    color: var(--primary);
}

/* Toast container */
.toast-container {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.toast {
    padding: 12px 18px;
    border-radius: var(--radius-sm);
    color: white;
    font-size: 14px;
    font-weight: 500;
    box-shadow: var(--shadow-lg);
    animation: slideIn 0.3s ease;
}

.toast.success { background: var(--success); }
.toast.error { background: var(--danger); }
.toast.warning { background: var(--warning); }
.toast.info { background: var(--primary); }

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}
</style>

<script>
// Toast notification
window.showToast = function(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s reverse';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
};

// Modal functions
let currentPaymentId = null;
let currentRejectPaymentId = null;
let studentSearchTimer = null;

// Record payment modal
function openRecordPaymentModal() {
    document.getElementById('recordPaymentModal').classList.add('open');
    document.getElementById('step1').style.display = 'block';
    document.getElementById('step2').style.display = 'none';
    document.getElementById('backBtn').style.display = 'none';
    document.getElementById('studentSearch').value = '';
    document.getElementById('studentSearchResults').style.display = 'none';
}

function closeRecordPaymentModal() {
    document.getElementById('recordPaymentModal').classList.remove('open');
    resetPaymentForm();
}

function resetPaymentForm() {
    document.getElementById('step1').style.display = 'block';
    document.getElementById('step2').style.display = 'none';
    document.getElementById('backBtn').style.display = 'none';
    document.getElementById('studentSearch').value = '';
    document.getElementById('studentSearchResults').innerHTML = '';
    document.getElementById('studentSearchResults').style.display = 'none';
    document.getElementById('paymentForm').reset();
    document.getElementById('selectedStudentId').value = '';
    document.getElementById('selectedBillingId').value = '';
}

function goToStep1() {
    document.getElementById('step1').style.display = 'block';
    document.getElementById('step2').style.display = 'none';
    document.getElementById('backBtn').style.display = 'none';
}

// Student search
document.getElementById('studentSearch').addEventListener('input', function() {
    clearTimeout(studentSearchTimer);
    const query = this.value.trim();
    
    if (query.length < 2) {
        document.getElementById('studentSearchResults').style.display = 'none';
        return;
    }
    
    studentSearchTimer = setTimeout(() => {
        const formData = new FormData();
        formData.append('action', 'search_students_billing');
        formData.append('query', query);
        formData.append('csrf_token', document.getElementById('global_csrf_token').value);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            displayStudentResults(data.students || []);
        })
        .catch(err => {
            console.error('Search error:', err);
            showToast('Failed to search students', 'error');
        });
    }, 300);
});

function displayStudentResults(students) {
    const resultsDiv = document.getElementById('studentSearchResults');
    
    if (!students.length) {
        resultsDiv.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--text-muted);">No students found with outstanding balance</div>';
        resultsDiv.style.display = 'block';
        return;
    }
    
    let html = '';
    students.forEach(student => {
        const balanceClass = student.balance > 0 ? '' : 'text-muted';
        const balanceText = student.balance ? formatMoney(student.balance) : 'No billing';
        
        html += `
            <div class="student-result" onclick="selectStudent(${student.student_id}, ${student.billing_id || 0})">
                <div style="font-weight: 600;">${sanitize(student.student_name)}</div>
                <div style="display: flex; gap: 16px; font-size: 12px; color: var(--text-muted);">
                    <span>${sanitize(student.student_number)}</span>
                    <span>${sanitize(student.program_code)} - Year ${student.year_level}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 8px;">
                    <span class="badge badge-${student.billing_status === 'Paid' ? 'success' : 'warning'}">
                        ${student.billing_status || 'No Bill'}
                    </span>
                    <span class="${balanceClass}" style="font-weight: 600;">${balanceText}</span>
                </div>
            </div>
        `;
    });
    
    resultsDiv.innerHTML = html;
    resultsDiv.style.display = 'block';
}

function selectStudent(studentId, billingId) {
    if (!billingId) {
        showToast('Student has no active billing', 'warning');
        return;
    }
    
    // Fetch student details and billing info
    const formData = new FormData();
    formData.append('action', 'search_students_billing');
    formData.append('query', document.getElementById('studentSearch').value);
    formData.append('csrf_token', document.getElementById('global_csrf_token').value);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        const student = data.students.find(s => s.student_id == studentId);
        if (student) {
            document.getElementById('selectedStudentId').value = studentId;
            document.getElementById('selectedBillingId').value = billingId;
            
            // Display selected student info
            const infoHtml = `
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-weight: 700;">${sanitize(student.student_name)}</div>
                        <div style="font-size: 12px; color: var(--text-muted);">${sanitize(student.student_number)}</div>
                        <div style="font-size: 12px; color: var(--text-muted);">${sanitize(student.program_code)} - Year ${student.year_level}</div>
                    </div>
                </div>
            `;
            document.getElementById('selectedStudentInfo').innerHTML = infoHtml;
            
            // Display billing info
            const billingHtml = `
                <div class="billing-row">
                    <span>Total Amount Due:</span>
                    <span>${formatMoney(student.total_amount)}</span>
                </div>
                <div class="billing-row">
                    <span>Paid Amount:</span>
                    <span>${formatMoney(student.paid_amount)}</span>
                </div>
                <div class="billing-row">
                    <span>Current Balance:</span>
                    <span style="color: ${student.balance > 0 ? 'var(--danger)' : 'var(--success)'};">${formatMoney(student.balance)}</span>
                </div>
            `;
            document.getElementById('billingInfo').innerHTML = billingHtml;
            
            // Set max amount
            document.getElementById('paymentAmount').max = student.balance;
            document.getElementById('paymentAmount').value = student.balance;
            
            // Move to step 2
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'block';
            document.getElementById('backBtn').style.display = 'inline-block';
            document.getElementById('studentSearchResults').style.display = 'none';
        }
    });
}

// Handle payment form submission
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const amount = parseFloat(document.getElementById('paymentAmount').value);
    const balanceText = document.querySelector('#billingInfo .billing-row:last-child span:last-child').textContent;
    const balance = parseFloat(balanceText.replace(/[₱,]/g, ''));
    
    if (amount > balance) {
        showToast('Payment amount cannot exceed outstanding balance', 'warning');
        return;
    }
    
    const formData = new FormData(this);
    formData.append('action', 'record_payment');
    formData.append('csrf_token', document.getElementById('global_csrf_token').value);
    
    document.getElementById('submitPaymentBtn').disabled = true;
    document.getElementById('submitPaymentBtn').textContent = '⏳ Processing...';
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            showToast('Error: ' + data.error, 'error');
        } else {
            showToast('✅ Payment recorded successfully. OR #: ' + data.or_number, 'success');
            closeRecordPaymentModal();
            setTimeout(() => window.location.reload(), 1500);
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showToast('Failed to record payment', 'error');
    })
    .finally(() => {
        document.getElementById('submitPaymentBtn').disabled = false;
        document.getElementById('submitPaymentBtn').textContent = '💾 Record Payment';
    });
});

// Approve payment
function approvePayment(paymentId) {
    if (!confirm('Approve this payment? This will update the student\'s balance.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'approve_payment');
    formData.append('payment_id', paymentId);
    formData.append('csrf_token', document.getElementById('global_csrf_token').value);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            showToast('Error: ' + data.error, 'error');
        } else {
            showToast('Payment approved successfully', 'success');
            setTimeout(() => window.location.reload(), 1500);
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showToast('Failed to approve payment', 'error');
    });
}

// Reject payment
function rejectPayment(paymentId) {
    currentRejectPaymentId = paymentId;
    document.getElementById('rejectPaymentModal').classList.add('open');
    document.getElementById('rejectReason').value = '';
}

function closeRejectModal() {
    document.getElementById('rejectPaymentModal').classList.remove('open');
    currentRejectPaymentId = null;
}

document.getElementById('confirmRejectBtn').addEventListener('click', function() {
    if (!currentRejectPaymentId) {
        showToast('No payment selected', 'error');
        closeRejectModal();
        return;
    }
    
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) {
        showToast('Please provide a reason for rejection', 'warning');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'reject_payment');
    formData.append('payment_id', currentRejectPaymentId);
    formData.append('reason', reason);
    formData.append('csrf_token', document.getElementById('global_csrf_token').value);
    
    this.disabled = true;
    this.textContent = '⏳ Processing...';
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        closeRejectModal();
        if (data.error) {
            showToast('Error: ' + data.error, 'error');
        } else {
            showToast('Payment rejected successfully', 'success');
            setTimeout(() => window.location.reload(), 1500);
        }
    })
    .catch(err => {
        closeRejectModal();
        console.error('Error:', err);
        showToast('Failed to reject payment', 'error');
    })
    .finally(() => {
        this.disabled = false;
        this.textContent = 'Confirm Reject';
    });
});

// Void payment
function voidPayment(paymentId) {
    currentPaymentId = paymentId;
    document.getElementById('voidPaymentModal').classList.add('open');
    document.getElementById('voidReason').value = '';
}

function closeVoidModal() {
    document.getElementById('voidPaymentModal').classList.remove('open');
    currentPaymentId = null;
}

document.getElementById('confirmVoidBtn').addEventListener('click', function() {
    if (!currentPaymentId) {
        showToast('No payment selected', 'error');
        closeVoidModal();
        return;
    }
    
    const reason = document.getElementById('voidReason').value.trim();
    if (!reason) {
        showToast('Please provide a reason for voiding', 'warning');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'void_payment');
    formData.append('payment_id', currentPaymentId);
    formData.append('reason', reason);
    formData.append('csrf_token', document.getElementById('global_csrf_token').value);
    
    this.disabled = true;
    this.textContent = '⏳ Processing...';
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        closeVoidModal();
        if (data.error) {
            showToast('Error: ' + data.error, 'error');
        } else {
            showToast('Payment voided successfully', 'success');
            setTimeout(() => window.location.reload(), 1500);
        }
    })
    .catch(err => {
        closeVoidModal();
        console.error('Error:', err);
        showToast('Failed to void payment', 'error');
    })
    .finally(() => {
        this.disabled = false;
        this.textContent = 'Confirm Void';
    });
});

// View payment details
function viewPaymentDetails(paymentId) {
    document.getElementById('viewPaymentModal').classList.add('open');
    document.getElementById('paymentDetailsContent').innerHTML = '<div style="text-align: center; padding: 24px;">Loading...</div>';
    
    // You would fetch actual payment details here
    setTimeout(() => {
        document.getElementById('paymentDetailsContent').innerHTML = `
            <div style="padding: 16px;">
                <p>Payment details for ID: ${paymentId}</p>
                <p>This feature is coming soon.</p>
            </div>
        `;
    }, 500);
}

function closeViewPaymentModal() {
    document.getElementById('viewPaymentModal').classList.remove('open');
}

// Table filtering
document.getElementById('paymentSearch').addEventListener('keyup', filterTable);
document.getElementById('statusFilter').addEventListener('change', filterTable);

function filterTable() {
    const searchText = document.getElementById('paymentSearch').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;
    const rows = document.querySelectorAll('#paymentsTable tbody tr');
    
    rows.forEach(row => {
        let show = true;
        
        if (statusFilter) {
            const status = row.querySelector('td:nth-child(7) .badge')?.textContent.trim() || '';
            if (status !== statusFilter) show = false;
        }
        
        if (show && searchText) {
            const text = row.textContent.toLowerCase();
            if (!text.includes(searchText)) show = false;
        }
        
        row.style.display = show ? '' : 'none';
    });
}

// Helper functions
function formatMoney(amount) {
    return '₱' + parseFloat(amount || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function sanitize(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<?php include 'includes/footer.php'; ?>