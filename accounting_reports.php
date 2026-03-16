<?php
$pageTitle = 'Financial Transactions Log';
require_once 'includes/config.php';
requireLogin();
requireRole(['accounting', 'admin']);
$db = getDB();

// Get filter values
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$method = $_GET['method'] ?? '';
$status = $_GET['status'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$per_page = 50;
$offset = ($page - 1) * $per_page;

// ============================================
// PAYMENTS TABLE QUERIES (All Payment History)
// ============================================

// Build query conditions for payments
$where = ["1=1"];
$params = [];
$types = "";

if (!empty($date_from)) {
    $where[] = "p.payment_date >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types .= "s";
}
if (!empty($date_to)) {
    $where[] = "p.payment_date <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= "s";
}
if (!empty($method)) {
    $where[] = "p.payment_method = ?";
    $params[] = $method;
    $types .= "s";
}
if (!empty($status)) {
    $where[] = "p.status = ?";
    $params[] = $status;
    $types .= "s";
}

$where_clause = implode(" AND ", $where);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN users u ON s.user_id = u.id
    WHERE $where_clause
";
$stmt = $db->prepare($count_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_count = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_count / $per_page);

// Get payment transactions
$sql = "
    SELECT 
        p.id,
        p.payment_date,
        p.amount,
        p.payment_method,
        p.status,
        p.reference_number,
        p.or_number,
        p.notes,
        p.created_at,
        p.billing_id,
        CONCAT(u.first_name, ' ', u.last_name) AS student_name,
        s.student_number,
        prog.code AS program_code,
        CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name,
        u2.username AS received_by_name,
        b.total_amount AS billing_total,
        b.balance AS remaining_balance
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN programs prog ON s.program_id = prog.id
    LEFT JOIN users u2 ON p.received_by = u2.id
    LEFT JOIN users au ON p.created_by = au.id
    LEFT JOIN billing b ON p.billing_id = b.id
    WHERE $where_clause
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);
$all_params = array_merge($params, [$per_page, $offset]);
$all_types = $types . "ii";
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get summary statistics
$summary_sql = "
    SELECT 
        COUNT(*) AS total_transactions,
        COALESCE(SUM(p.amount), 0) AS total_amount,
        COALESCE(AVG(p.amount), 0) AS average_amount,
        COUNT(DISTINCT p.student_id) AS unique_students,
        SUM(CASE WHEN p.status = 'Confirmed' THEN p.amount ELSE 0 END) AS confirmed_amount,
        COUNT(CASE WHEN p.status = 'Confirmed' THEN 1 END) AS confirmed_count,
        SUM(CASE WHEN p.status = 'Pending' THEN p.amount ELSE 0 END) AS pending_amount,
        COUNT(CASE WHEN p.status = 'Pending' THEN 1 END) AS pending_count,
        SUM(CASE WHEN p.status = 'Cancelled' THEN p.amount ELSE 0 END) AS cancelled_amount,
        COUNT(CASE WHEN p.status = 'Cancelled' THEN 1 END) AS cancelled_count,
        SUM(CASE WHEN p.status = 'Void' THEN p.amount ELSE 0 END) AS void_amount,
        COUNT(CASE WHEN p.status = 'Void' THEN 1 END) AS void_count
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN users u ON s.user_id = u.id
    WHERE $where_clause
";
$stmt = $db->prepare($summary_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// Get payment method breakdown
$method_sql = "
    SELECT 
        p.payment_method,
        COUNT(*) AS count,
        COALESCE(SUM(p.amount), 0) AS total
    FROM payments p
    JOIN students s ON p.student_id = s.id
    WHERE $where_clause
    GROUP BY p.payment_method
    ORDER BY total DESC
";
$stmt = $db->prepare($method_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$method_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get status breakdown
$status_sql = "
    SELECT 
        p.status,
        COUNT(*) AS count,
        COALESCE(SUM(p.amount), 0) AS total
    FROM payments p
    WHERE $where_clause
    GROUP BY p.status
    ORDER BY 
        CASE p.status
            WHEN 'Confirmed' THEN 1
            WHEN 'Pending' THEN 2
            WHEN 'Cancelled' THEN 3
            WHEN 'Void' THEN 4
            ELSE 5
        END
";
$stmt = $db->prepare($status_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$status_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get monthly summary for charts (this should NOT use filters)
$monthly_sql = "
    SELECT 
        DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
        COUNT(*) AS transaction_count,
        COALESCE(SUM(p.amount), 0) AS monthly_total
    FROM payments p
    WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
    ORDER BY month DESC
";
$monthly = $db->query($monthly_sql)->fetch_all(MYSQLI_ASSOC);

// Function to build query string for pagination
function buildQueryString($params, $page = null) {
    $queryParams = [];
    foreach ($params as $key => $value) {
        if ($key !== 'page' && !empty($value)) {
            $queryParams[$key] = $value;
        }
    }
    if ($page !== null) {
        $queryParams['page'] = $page;
    }
    return http_build_query($queryParams);
}

include 'includes/header.php';
?>

<style>
.tab-container {
    border-bottom: 1px solid var(--border);
    margin-bottom: 24px;
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.tab {
    padding: 12px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-weight: 600;
    color: var(--text-muted);
    transition: all 0.2s;
}
.tab:hover {
    color: var(--primary);
}
.tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}
.summary-box {
    background: var(--bg-light);
    border-radius: 8px;
    padding: 16px;
    border: 1px solid var(--border);
}
.summary-label {
    font-size: 12px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.summary-value {
    font-size: 24px;
    font-weight: 700;
    margin-top: 4px;
}
.summary-value small {
    font-size: 14px;
    font-weight: normal;
    color: var(--text-muted);
}
.audit-badge {
    font-family: 'DM Mono', monospace;
    font-size: 11px;
    background: var(--bg);
    padding: 2px 6px;
    border-radius: 4px;
    color: var(--text-muted);
    border: 1px solid var(--border);
}
.filter-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--primary-light);
    color: var(--primary);
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.filter-badge button {
    background: none;
    border: none;
    color: var(--primary);
    cursor: pointer;
    font-size: 14px;
    padding: 0 2px;
}
.filter-badge button:hover {
    color: var(--primary-dark);
}
</style>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 class="page-title">Financial Transactions Log</h1>
            <p class="page-subtitle">Complete payment history and audit trail</p>
        </div>
        <div style="display: flex; gap: 8px;">
            <button class="btn btn-outline btn-sm" onclick="printReport()">
                🖨️ Print
            </button>
            <button class="btn btn-outline btn-sm" onclick="exportCSV()">
                📥 Export CSV
            </button>
        </div>
    </div>
</div>

<!-- Active Filters Display -->
<?php if (!empty($date_from) || !empty($date_to) || !empty($method) || !empty($status)): ?>
<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
    <span class="filter-badge">
        Active Filters:
    </span>
    <?php if (!empty($date_from)): ?>
    <span class="filter-badge">
        From: <?= $date_from ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['date_from' => '', 'page' => 1])) ?>">&times;</a>
    </span>
    <?php endif; ?>
    <?php if (!empty($date_to)): ?>
    <span class="filter-badge">
        To: <?= $date_to ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['date_to' => '', 'page' => 1])) ?>">&times;</a>
    </span>
    <?php endif; ?>
    <?php if (!empty($method)): ?>
    <span class="filter-badge">
        Method: <?= $method ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['method' => '', 'page' => 1])) ?>">&times;</a>
    </span>
    <?php endif; ?>
    <?php if (!empty($status)): ?>
    <span class="filter-badge">
        Status: <?= $status ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['status' => '', 'page' => 1])) ?>">&times;</a>
    </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3 class="card-title">Filter Transactions</h3>
    </div>
    
    <form method="GET" class="card-body" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
        <div class="form-group">
            <label class="form-label">Date From</label>
            <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Date To</label>
            <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Payment Method</label>
            <select name="method" class="form-control">
                <option value="">All Methods</option>
                <option value="Cash" <?= $method === 'Cash' ? 'selected' : '' ?>>Cash</option>
                <option value="GCash" <?= $method === 'GCash' ? 'selected' : '' ?>>GCash</option>
                <option value="Online Banking" <?= $method === 'Online Banking' ? 'selected' : '' ?>>Online Banking</option>
                <option value="Check" <?= $method === 'Check' ? 'selected' : '' ?>>Check</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">All Status</option>
                <option value="Confirmed" <?= $status === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Cancelled" <?= $status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                <option value="Void" <?= $status === 'Void' ? 'selected' : '' ?>>Void</option>
            </select>
        </div>
        <div style="display: flex; align-items: flex-end; gap: 8px;">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="accounting_reports.php" class="btn btn-outline">Reset</a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">💰</div>
        </div>
        <div class="stat-value">₱<?= number_format($summary['total_amount'] ?? 0, 2) ?></div>
        <div class="stat-label">Total Collections</div>
        <div class="text-muted text-sm"><?= $summary['total_transactions'] ?? 0 ?> transactions</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">✅</div>
        </div>
        <div class="stat-value">₱<?= number_format($summary['confirmed_amount'] ?? 0, 2) ?></div>
        <div class="stat-label">Confirmed</div>
        <div class="text-muted text-sm"><?= $summary['confirmed_count'] ?? 0 ?> payments</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon orange">⏳</div>
        </div>
        <div class="stat-value">₱<?= number_format($summary['pending_amount'] ?? 0, 2) ?></div>
        <div class="stat-label">Pending</div>
        <div class="text-muted text-sm"><?= $summary['pending_count'] ?? 0 ?> payments</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon purple">📊</div>
        </div>
        <div class="stat-value"><?= $summary['unique_students'] ?? 0 ?></div>
        <div class="stat-label">Unique Students</div>
        <div class="text-muted text-sm">Avg: ₱<?= number_format($summary['average_amount'] ?? 0, 2) ?></div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="tab-container">
    <button class="tab active" onclick="switchTab('transactions')">📋 Transaction Log</button>
    <button class="tab" onclick="switchTab('summary')">📊 Summary Reports</button>
    <button class="tab" onclick="switchTab('audit')">🔍 Audit Trail</button>
</div>

<!-- Transactions Tab -->
<div id="transactions-tab" class="tab-content active">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Payment Transaction Log</h3>
            <div class="d-flex gap-8">
                <span class="badge badge-primary"><?= number_format($total_count) ?> records</span>
                <span class="badge badge-secondary">Page <?= $page ?> of <?= $total_pages ?: 1 ?></span>
            </div>
        </div>
        
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>OR #</th>
                        <th>Student</th>
                        <th>Student #</th>
                        <th>Program</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Status</th>
                        <th>Received By</th>
                        <th>Billing ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td class="mono" style="font-size:12px;">
                                    <?= date('M d, Y', strtotime($t['payment_date'])) ?><br>
                                    <span style="color:var(--text-muted);"><?= date('h:i A', strtotime($t['payment_date'])) ?></span>
                                </td>
                                <td>
                                    <span class="mono" style="font-weight:600; background:var(--bg); padding:2px 6px; border-radius:4px;">
                                        <?= htmlspecialchars($t['or_number'] ?? '—') ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight:600"><?= htmlspecialchars($t['student_name'] ?? 'Unknown') ?></div>
                                </td>
                                <td><span class="mono"><?= htmlspecialchars($t['student_number'] ?? 'N/A') ?></span></td>
                                <td><?= htmlspecialchars($t['program_code'] ?? 'N/A') ?></td>
                                <td style="font-weight:700; color:var(--success);">₱<?= number_format($t['amount'], 2) ?></td>
                                <td>
                                    <?php
                                    $methodIcon = match($t['payment_method']) {
                                        'Cash' => '💵',
                                        'GCash' => '📱',
                                        'Online Banking' => '🏦',
                                        'Check' => '📝',
                                        default => '💰'
                                    };
                                    ?>
                                    <span class="badge badge-secondary"><?= $methodIcon ?> <?= htmlspecialchars($t['payment_method'] ?? 'N/A') ?></span>
                                </td>
                                <td><span class="mono" style="font-size:11px;"><?= htmlspecialchars($t['reference_number'] ?? '—') ?></span></td>
                                <td>
                                    <?php
                                    $badgeClass = match($t['status']) {
                                        'Confirmed' => 'success',
                                        'Pending' => 'warning',
                                        'Cancelled' => 'danger',
                                        'Void' => 'secondary',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge badge-<?= $badgeClass ?>"><?= $t['status'] ?></span>
                                </td>
                                <td><small><?= htmlspecialchars($t['received_by_name'] ?? 'System') ?></small></td>
                                <td><span class="audit-badge">#<?= $t['billing_id'] ?? '—' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No payment transactions found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 5px; margin-top: 20px;">
                <?php if ($page > 1): ?>
                    <a href="?<?= buildQueryString($_GET, $page - 1) ?>" class="btn btn-sm btn-outline">‹</a>
                <?php endif; ?>
                
                <?php 
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++): 
                ?>
                    <a href="?<?= buildQueryString($_GET, $i) ?>" 
                       class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>"><?= $i ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?<?= buildQueryString($_GET, $page + 1) ?>" class="btn btn-sm btn-outline">›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Summary Tab -->
<div id="summary-tab" class="tab-content">
    <div class="grid-2" style="margin-bottom: 24px;">
        <!-- Payment Method Breakdown -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Payment Method Breakdown</h3>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Transactions</th>
                            <th>Total Amount</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_total = array_sum(array_column($method_breakdown, 'total'));
                        foreach ($method_breakdown as $m): 
                            $percentage = $grand_total > 0 ? ($m['total'] / $grand_total) * 100 : 0;
                            $methodIcon = match($m['payment_method']) {
                                'Cash' => '💵',
                                'GCash' => '📱',
                                'Online Banking' => '🏦',
                                'Check' => '📝',
                                default => '💰'
                            };
                        ?>
                            <tr>
                                <td>
                                    <span class="badge badge-secondary">
                                        <?= $methodIcon ?> <?= $m['payment_method'] ?>
                                    </span>
                                </td>
                                <td class="text-center"><?= $m['count'] ?></td>
                                <td class="mono">₱<?= number_format($m['total'], 2) ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span><?= number_format($percentage, 1) ?>%</span>
                                        <div style="height: 6px; width: 100px; background: var(--border); border-radius: 3px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= $percentage ?>%; background: var(--primary);"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($method_breakdown)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">No payment data</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Status Breakdown -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Status Breakdown</h3>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Transactions</th>
                            <th>Total Amount</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $status_grand_total = array_sum(array_column($status_breakdown, 'total'));
                        foreach ($status_breakdown as $s): 
                            $percentage = $status_grand_total > 0 ? ($s['total'] / $status_grand_total) * 100 : 0;
                            $badgeClass = match($s['status']) {
                                'Confirmed' => 'success',
                                'Pending' => 'warning',
                                'Cancelled' => 'danger',
                                'Void' => 'secondary',
                                default => 'secondary'
                            };
                        ?>
                            <tr>
                                <td><span class="badge badge-<?= $badgeClass ?>"><?= $s['status'] ?></span></td>
                                <td class="text-center"><?= $s['count'] ?></td>
                                <td class="mono">₱<?= number_format($s['total'], 2) ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span><?= number_format($percentage, 1) ?>%</span>
                                        <div style="height: 6px; width: 100px; background: var(--border); border-radius: 3px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= $percentage ?>%; background: var(--primary);"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Monthly Summary -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Monthly Collection Summary (Last 12 Months)</h3>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Transactions</th>
                        <th>Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly as $m): ?>
                        <tr>
                            <td class="mono"><?= date('F Y', strtotime($m['month'] . '-01')) ?></td>
                            <td class="text-center"><?= $m['transaction_count'] ?></td>
                            <td class="mono" style="font-weight: 700;">₱<?= number_format($m['monthly_total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($monthly)): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted">No monthly data available</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Audit Trail Tab -->
<div id="audit-tab" class="tab-content">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Audit Trail</h3>
            <div class="audit-badge">For Official Use Only</div>
        </div>
        
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>OR #</th>
                        <th>Student</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Created By</th>
                        <th>Approved/Rejected By</th>
                        <th>Notes</th>
                        <th>Billing ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td><span class="audit-badge">#<?= $t['id'] ?></span></td>
                                <td><span class="mono"><?= htmlspecialchars($t['or_number'] ?? '—') ?></span></td>
                                <td>
                                    <?= htmlspecialchars($t['student_name'] ?? 'Unknown') ?><br>
                                    <span class="audit-badge"><?= htmlspecialchars($t['student_number'] ?? '') ?></span>
                                </td>
                                <td class="mono" style="font-weight:700;">₱<?= number_format($t['amount'], 2) ?></td>
                                <td>
                                    <?php
                                    $badgeClass = match($t['status']) {
                                        'Confirmed' => 'success',
                                        'Pending' => 'warning',
                                        'Cancelled' => 'danger',
                                        'Void' => 'secondary',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge badge-<?= $badgeClass ?>"><?= $t['status'] ?></span>
                                </td>
                                <td class="mono" style="font-size:11px;">
                                    <?= date('Y-m-d', strtotime($t['created_at'] ?? $t['payment_date'])) ?><br>
                                    <span class="audit-badge"><?= date('h:i A', strtotime($t['created_at'] ?? $t['payment_date'])) ?></span>
                                </td>
                                <td><span class="audit-badge"><?= htmlspecialchars($t['approved_by_name'] ?? 'System') ?></span></td>
                                <td><span class="audit-badge"><?= htmlspecialchars($t['received_by_name'] ?? '—') ?></span></td>
                                <td><small><?= htmlspecialchars(substr($t['notes'] ?? '', 0, 30)) ?><?= strlen($t['notes'] ?? '') > 30 ? '...' : '' ?></small></td>
                                <td><span class="audit-badge">#<?= $t['billing_id'] ?? '—' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No audit records found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card-body">
            <div style="display: flex; gap: 24px; justify-content: center; padding: 16px; background: var(--bg); border-radius: var(--radius);">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="badge badge-success">Confirmed</span>
                    <span class="text-sm">Payment completed and posted</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="badge badge-warning">Pending</span>
                    <span class="text-sm">Awaiting approval</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="badge badge-danger">Cancelled</span>
                    <span class="text-sm">Rejected by accounting</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="badge badge-secondary">Void</span>
                    <span class="text-sm">Voided after confirmation</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabName + '-tab').classList.add('active');
    event.target.classList.add('active');
}

function exportCSV() {
    let csv = [];
    
    // Add headers
    csv.push('Transaction ID,Date,OR #,Student,Student #,Program,Amount,Method,Reference,Status,Received By,Billing ID,Notes');
    
    // Add data from current transactions
    <?php foreach ($transactions as $t): ?>
    csv.push('<?= $t['id'] ?>,<?= date('Y-m-d H:i:s', strtotime($t['payment_date'])) ?>,<?= addslashes($t['or_number'] ?? '') ?>,<?= addslashes($t['student_name'] ?? 'Unknown') ?>,<?= addslashes($t['student_number'] ?? '') ?>,<?= addslashes($t['program_code'] ?? '') ?>,<?= $t['amount'] ?>,<?= $t['payment_method'] ?>,<?= addslashes($t['reference_number'] ?? '') ?>,<?= $t['status'] ?>,<?= addslashes($t['received_by_name'] ?? 'System') ?>,<?= $t['billing_id'] ?? '' ?>,<?= addslashes($t['notes'] ?? '') ?>');
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'financial_transactions_<?= date('Y-m-d') ?>.csv';
    a.click();
}

function printReport() {
    window.print();
}
</script>

<style>
@media print {
    .sidebar, .header, .tab-container, .btn, form, .pagination {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 20px !important;
    }
    .tab-content {
        display: block !important;
    }
    .card {
        break-inside: avoid;
        page-break-inside: avoid;
        border: 1px solid #ddd !important;
        margin-bottom: 20px !important;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        border: 1px solid #ddd;
        padding: 8px;
    }
    th {
        background: #f5f5f5;
    }
}
</style>

<?php include 'includes/footer.php'; ?>