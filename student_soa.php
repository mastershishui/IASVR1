<?php
$pageTitle = 'Statement of Account';
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

// Get current academic year/semester from student or use system defaults
$current_academic_year = $student['academic_year'] ?? ACADEMIC_YEAR;
$current_semester = $student['semester'] ?? CURRENT_SEMESTER;

// Get current billing
$billing_data = null;
if ($student) {
    // billing is linked directly to student_id
    $stmt = $db->prepare("
        SELECT b.*
        FROM billing b
        WHERE b.student_id = ? 
          AND b.academic_year = ? 
          AND b.semester = ?
        ORDER BY b.id DESC
        LIMIT 1
    ");
    $stmt->bind_param('isi', $student['id'], $current_academic_year, $current_semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $billing_data = $result->fetch_assoc();
}

// Calculate totals
$total_due = $billing_data['total_amount'] ?? 0;
$total_paid = $billing_data['amount_paid'] ?? 0;
$balance = $billing_data['balance'] ?? 0;
$status = $billing_data['status'] ?? 'Unpaid';

include 'includes/header.php';
?>

<?php if (!$billing_data): ?>
    <div class="empty-state" style="text-align: center; padding: 60px 24px;">
        <div style="font-size: 64px; margin-bottom: 16px;">📄</div>
        <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">No Assessment Found</h3>
        <p style="color: var(--text-muted);">You don't have any billing assessment for the current term.</p>
    </div>
<?php else: ?>

<!-- Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">💰</div>
        </div>
        <div class="stat-value">₱<?= number_format($total_due, 2) ?></div>
        <div class="stat-label">Total Due</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">✅</div>
        </div>
        <div class="stat-value">₱<?= number_format($total_paid, 2) ?></div>
        <div class="stat-label">Paid Amount</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon <?= $balance > 0 ? 'red' : 'green' ?>">
                <?= $balance > 0 ? '⚠️' : '✓' ?>
            </div>
        </div>
        <div class="stat-value" style="color: <?= $balance > 0 ? 'var(--danger)' : 'var(--success)' ?>;">
            ₱<?= number_format($balance, 2) ?>
        </div>
        <div class="stat-label">Current Balance</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon <?= $status === 'Paid' ? 'green' : ($status === 'Partial' ? 'orange' : 'gray') ?>">📊</div>
        </div>
        <div class="stat-value" style="font-size: 18px">
            <span class="badge badge-<?= $status === 'Paid' ? 'success' : ($status === 'Partial' ? 'warning' : 'secondary') ?>">
                <?= $status ?>
            </span>
        </div>
        <div class="stat-label">Status</div>
    </div>
</div>

<!-- Term Info -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <span class="card-title">Current Term</span>
        <span class="badge badge-primary">AY <?= $current_academic_year ?> - Sem <?= $current_semester ?></span>
    </div>
</div>

<!-- Fee Breakdown -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3 class="card-title">Fee Assessment Breakdown</h3>
    </div>
    <div class="card-body">
        <?php if ($billing_data['tuition_fee'] > 0): ?>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border);">
                <span>Tuition Fee</span>
                <span style="font-weight: 600;">₱<?= number_format($billing_data['tuition_fee'], 2) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($billing_data['misc_fees'] > 0): ?>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border);">
                <span>Miscellaneous Fee</span>
                <span style="font-weight: 600;">₱<?= number_format($billing_data['misc_fees'], 2) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($billing_data['other_fees'] > 0): ?>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border);">
                <span>Other Fees</span>
                <span style="font-weight: 600;">₱<?= number_format($billing_data['other_fees'], 2) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($billing_data['discount'] > 0): ?>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); color: var(--success);">
                <span>Discount Applied</span>
                <span style="font-weight: 600;">-₱<?= number_format($billing_data['discount'], 2) ?></span>
            </div>
        <?php endif; ?>
        
        <div style="display: flex; justify-content: space-between; padding: 12px 0; border-top: 2px solid var(--border); margin-top: 8px;">
            <span style="font-weight: 800;">TOTAL AMOUNT DUE</span>
            <span style="font-weight: 800; font-size: 18px; color: var(--primary);">₱<?= number_format($billing_data['total_amount'], 2) ?></span>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
document.getElementById('printBtn')?.addEventListener('click', function(e) {
    e.preventDefault();
    window.print();
});
</script>

<style>
@media print {
    .sidebar, .main-content .btn, .page-header .btn, .stats-grid .stat-card:last-child {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>