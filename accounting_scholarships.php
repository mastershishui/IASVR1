<?php 
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

// Set CSRF token if not exists
if (!isset($_SESSION)) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Scholarship Management';
require_once 'includes/config.php';
requireLogin();
requireRole(['accounting', 'admin']);
$db = getDB();

$current_academic_year = ACADEMIC_YEAR;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Save scholarship type
    if ($_POST['action'] === 'save_scholarship_type') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        $scholarship_id = (int)($_POST['scholarship_id'] ?? 0);
        $scholarship_name = $_POST['scholarship_name'];
        $scholarship_type = $_POST['scholarship_type'];
        $discount_percent = !empty($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : null;
        $discount_amount = !empty($_POST['discount_amount']) ? (float)$_POST['discount_amount'] : null;
        $status = isset($_POST['is_active']) ? 'Active' : 'Inactive';
        
        if (empty($scholarship_name)) {
            echo json_encode(['error' => 'Scholarship name is required']);
            exit;
        }
        
        if ($discount_percent === null && $discount_amount === null) {
            echo json_encode(['error' => 'Either discount percent or amount is required']);
            exit;
        }
        
        try {
            if ($scholarship_id > 0) {
                // Update existing
                $stmt = $db->prepare("
                    UPDATE scholarships SET 
                        scholarship_name = ?,
                        scholarship_type = ?,
                        discount_percent = ?,
                        discount_amount = ?,
                        status = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('ssddsi', $scholarship_name, $scholarship_type, $discount_percent, $discount_amount, $status, $scholarship_id);
                $stmt->execute();
                $message = 'Scholarship updated successfully';
            } else {
                // Insert new
                $stmt = $db->prepare("
                    INSERT INTO scholarships 
                        (scholarship_name, scholarship_type, discount_percent, discount_amount, status)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('ssdds', $scholarship_name, $scholarship_type, $discount_percent, $discount_amount, $status);
                $stmt->execute();
                $scholarship_id = $db->insert_id;
                $message = 'Scholarship added successfully';
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'scholarship_id' => $scholarship_id
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['error' => 'Failed to save scholarship: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Search students
    if ($_POST['action'] === 'search_students') {
        $search = '%' . $_POST['query'] . '%';
        
        $stmt = $db->prepare("
            SELECT 
                s.id as student_id,
                s.student_number,
                CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                p.code as program_code,
                s.year_level,
                b.id as billing_id,
                b.total_amount,
                b.amount_paid,
                b.balance,
                CASE WHEN ss.id IS NOT NULL THEN 1 ELSE 0 END AS has_scholarship,
                s2.scholarship_name AS current_scholarship
            FROM students s
            JOIN users u ON s.user_id = u.id
            JOIN programs p ON s.program_id = p.id
            LEFT JOIN billing b ON s.id = b.student_id
            LEFT JOIN student_scholarships ss ON s.id = ss.student_id AND ss.status = 'Active'
            LEFT JOIN scholarships s2 ON ss.scholarship_id = s2.id
            WHERE s.student_number LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
            ORDER BY u.last_name ASC
            LIMIT 10
        ");
        $stmt->bind_param('ss', $search, $search);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode(['students' => $students]);
        exit;
    }
    
    // Get student details
    if ($_POST['action'] === 'get_student_details') {
        $student_id = (int)$_POST['student_id'];
        
        try {
            // Get basic student info
            $stmt = $db->prepare("
                SELECT 
                    s.id as student_id,
                    s.student_number,
                    s.user_id,
                    s.program_id,
                    s.year_level,
                    s.enrollment_status,
                    u.first_name,
                    u.last_name,
                    p.code as program_code,
                    p.name as program_name
                FROM students s
                JOIN users u ON s.user_id = u.id
                JOIN programs p ON s.program_id = p.id
                WHERE s.id = ?
            ");
            
            $stmt->bind_param('i', $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $student = $result->fetch_assoc();
            
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found']);
                exit;
            }
            
            // Get billing info
            $billing_sql = "SELECT id, total_amount, amount_paid, balance 
                            FROM billing 
                            WHERE student_id = ? 
                            ORDER BY id DESC 
                            LIMIT 1";
            $stmt = $db->prepare($billing_sql);
            $stmt->bind_param('i', $student_id);
            $stmt->execute();
            $billing_result = $stmt->get_result();
            $billing = $billing_result->fetch_assoc();
            
            if ($billing) {
                $student['billing_id'] = $billing['id'];
                $student['total_amount'] = $billing['total_amount'];
                $student['amount_paid'] = $billing['amount_paid'];
                $student['balance'] = $billing['balance'];
            } else {
                $student['billing_id'] = null;
                $student['total_amount'] = 0;
                $student['amount_paid'] = 0;
                $student['balance'] = 0;
            }
            
            // Get current active scholarship
            $scholarship_sql = "
                SELECT 
                    ss.id AS scholarship_assignment_id,
                    ss.scholarship_id AS current_scholarship_id,
                    s2.scholarship_name AS current_scholarship_name,
                    s2.discount_percent,
                    s2.amount AS discount_amount
                FROM student_scholarships ss
                LEFT JOIN scholarships s2 ON ss.scholarship_id = s2.id
                WHERE ss.student_id = ? AND ss.status = 'Active'
                LIMIT 1
            ";
            $stmt = $db->prepare($scholarship_sql);
            $stmt->bind_param('i', $student_id);
            $stmt->execute();
            $scholarship_result = $stmt->get_result();
            $scholarship = $scholarship_result->fetch_assoc();
            
            if ($scholarship) {
                $student['scholarship_assignment_id'] = $scholarship['scholarship_assignment_id'];
                $student['current_scholarship_id'] = $scholarship['current_scholarship_id'];
                $student['current_scholarship_name'] = $scholarship['current_scholarship_name'];
                $student['discount_percent'] = $scholarship['discount_percent'];
                $student['discount_amount'] = $scholarship['discount_amount'];
            } else {
                $student['scholarship_assignment_id'] = null;
                $student['current_scholarship_id'] = null;
                $student['current_scholarship_name'] = null;
                $student['discount_percent'] = 0;
                $student['discount_amount'] = 0;
            }
            
            echo json_encode(['success' => true, 'student' => $student]);
            exit;
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    // Apply scholarship
    if ($_POST['action'] === 'apply_scholarship') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        $student_id = (int)$_POST['student_id'];
        $scholarship_id = (int)$_POST['scholarship_id'];
        $billing_id = (int)$_POST['billing_id'];
        
        try {
            $db->begin_transaction();
            
            // Check if student already has active scholarship
            $stmt = $db->prepare("
                SELECT id FROM student_scholarships 
                WHERE student_id = ? AND academic_year = ? AND status = 'Active'
            ");
            $stmt->bind_param('is', $student_id, $current_academic_year);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if ($existing) {
                // Revoke old scholarship
                $stmt = $db->prepare("
                    UPDATE student_scholarships 
                    SET status = 'Revoked', revoked_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->bind_param('i', $existing['id']);
                $stmt->execute();
            }
            
            // Apply new scholarship
            $stmt = $db->prepare("
                INSERT INTO student_scholarships 
                    (student_id, scholarship_id, academic_year, status, granted_by, granted_at)
                VALUES (?, ?, ?, 'Active', ?, NOW())
            ");
            $stmt->bind_param('iisi', $student_id, $scholarship_id, $current_academic_year, $_SESSION['user_id']);
            $stmt->execute();
            
            // Get scholarship details
            $stmt = $db->prepare("SELECT * FROM scholarships WHERE id = ?");
            $stmt->bind_param('i', $scholarship_id);
            $stmt->execute();
            $scholarship = $stmt->get_result()->fetch_assoc();
            
            // Get billing details
            if ($billing_id) {
                $stmt = $db->prepare("SELECT * FROM billing WHERE id = ?");
                $stmt->bind_param('i', $billing_id);
                $stmt->execute();
                $billing = $stmt->get_result()->fetch_assoc();
                
                if ($billing) {
                    // Calculate discount
                    $discount = 0;
                    $total_fees = $billing['tuition_fee'] + $billing['misc_fees'] + $billing['other_fees'];
                    
                    if ($scholarship['discount_percent'] > 0) {
                        $discount = $total_fees * ($scholarship['discount_percent'] / 100);
                    } else {
                        $discount = $scholarship['discount_amount'] ?? 0;
                    }
                    
                    // Update billing
$new_total = $total_fees - $discount;
$new_balance = $new_total - $billing['amount_paid'];

$stmt = $db->prepare("
    UPDATE billing SET 
        discount = ?,
        total_amount = ?,
        balance = ?
    WHERE id = ?
");
$stmt->bind_param('dddi', $discount, $new_total, $new_balance, $billing_id);
$stmt->execute();
                }
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Scholarship applied successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['error' => 'Failed to apply scholarship: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Remove scholarship
if ($_POST['action'] === 'remove_scholarship') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    
    $assignment_id = (int)$_POST['assignment_id'];
    $student_id = (int)$_POST['student_id'];
    $billing_id = (int)$_POST['billing_id'];
    
    try {
        $db->begin_transaction();
        
        // First, get the scholarship details to know the discount amount
        $stmt = $db->prepare("
            SELECT s2.discount_percent, s2.amount, ss.scholarship_id
            FROM student_scholarships ss
            JOIN scholarships s2 ON ss.scholarship_id = s2.id
            WHERE ss.id = ?
        ");
        $stmt->bind_param('i', $assignment_id);
        $stmt->execute();
        $scholarship = $stmt->get_result()->fetch_assoc();
        
        if (!$scholarship) {
            throw new Exception("Scholarship assignment not found");
        }
        
        // Update scholarship status to Revoked
        $stmt = $db->prepare("UPDATE student_scholarships SET status = 'Revoked', revoked_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $assignment_id);
        $stmt->execute();
        
        // Update billing if exists
        if ($billing_id && $billing_id > 0) {
            // Get current billing
            $stmt = $db->prepare("SELECT * FROM billing WHERE id = ?");
            $stmt->bind_param('i', $billing_id);
            $stmt->execute();
            $billing = $stmt->get_result()->fetch_assoc();
            
            if ($billing) {
                // Calculate original total without discount
                $total_fees = $billing['tuition_fee'] + $billing['misc_fees'] + $billing['other_fees'];
                
                // Update billing - remove discount
                $stmt = $db->prepare("
                    UPDATE billing SET 
                        discount = 0,
                        total_amount = ?,
                        balance = ?
                    WHERE id = ?
                ");
                $new_balance = $total_fees - $billing['amount_paid'];
                $stmt->bind_param('ddi', $total_fees, $new_balance, $billing_id);
                $stmt->execute();
            }
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Scholarship removed successfully'
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['error' => 'Failed to remove scholarship: ' . $e->getMessage()]);
    }
    exit;
}
    
    // Preview scholarship
    if ($_POST['action'] === 'preview_scholarship') {
        $scholarship_id = (int)$_POST['scholarship_id'];
        $billing_id = (int)$_POST['billing_id'];
        
        $stmt = $db->prepare("SELECT * FROM scholarships WHERE id = ?");
        $stmt->bind_param('i', $scholarship_id);
        $stmt->execute();
        $scholarship = $stmt->get_result()->fetch_assoc();
        
        $stmt = $db->prepare("SELECT * FROM billing WHERE id = ?");
        $stmt->bind_param('i', $billing_id);
        $stmt->execute();
        $billing = $stmt->get_result()->fetch_assoc();
        
        if (!$scholarship || !$billing) {
            echo json_encode(['error' => 'Invalid data']);
            exit;
        }
        
        $total_fees = $billing['tuition_fee'] + $billing['misc_fees'] + $billing['other_fees'];
        
        $discount = 0;
        if ($scholarship['discount_percent'] > 0) {
            $discount = $total_fees * ($scholarship['discount_percent'] / 100);
        } else {
            $discount = $scholarship['discount_amount'] ?? 0;
        }
        
        $new_total = $total_fees - $discount;
        
        echo json_encode([
            'success' => true,
            'original_total' => $total_fees,
            'discount' => $discount,
            'new_total' => $new_total,
            'formatted' => [
                'original' => '₱' . number_format($total_fees, 2),
                'discount' => '₱' . number_format($discount, 2),
                'new' => '₱' . number_format($new_total, 2)
            ]
        ]);
        exit;
    }
}

// Get all scholarships
$scholarships = $db->query("SELECT * FROM scholarships ORDER BY status DESC, scholarship_name ASC")->fetch_all(MYSQLI_ASSOC);

// Get active scholarship assignments
$active_assignments = $db->query("
    SELECT 
        ss.*,
        s.student_number,
        CONCAT(u.first_name, ' ', u.last_name) AS student_name,
        p.code AS program_code,
        s2.scholarship_name,
        s2.scholarship_type,
        s2.discount_percent,
        s2.amount AS discount_amount,
        u2.username AS granted_by_name,
        b.id AS billing_id,
        b.total_amount,
        b.balance
    FROM student_scholarships ss
    JOIN students s ON ss.student_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN programs p ON s.program_id = p.id
    JOIN scholarships s2 ON ss.scholarship_id = s2.id
    LEFT JOIN users u2 ON ss.granted_by = u2.id
    LEFT JOIN billing b ON s.id = b.student_id AND b.academic_year = ss.academic_year
    WHERE ss.academic_year = '$current_academic_year' AND ss.status = 'Active'
    ORDER BY ss.granted_at DESC
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats = [
    'total_scholarships' => $db->query("SELECT COUNT(*) as c FROM scholarships WHERE status = 'Active'")->fetch_assoc()['c'] ?? 0,
    'active_students' => $db->query("
        SELECT COUNT(*) as c FROM student_scholarships 
        WHERE academic_year = '$current_academic_year' AND status = 'Active'
    ")->fetch_assoc()['c'] ?? 0,
    'total_discount' => $db->query("
        SELECT COALESCE(SUM(discount), 0) as total 
        FROM billing 
        WHERE academic_year = '$current_academic_year' AND discount > 0
    ")->fetch_assoc()['total'] ?? 0
];

include 'includes/header.php';
?>

<!-- Hidden CSRF token for JavaScript -->
<input type="hidden" id="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 class="page-title">Scholarship Management</h1>
            <p class="page-subtitle">Manage scholarship types and apply discounts to students</p>
        </div>
        <div class="d-flex gap-8">
            <span class="badge badge-primary">AY <?= $current_academic_year ?></span>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">🏆</div>
        </div>
        <div class="stat-value"><?= $stats['total_scholarships'] ?></div>
        <div class="stat-label">Active Scholarships</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">👥</div>
        </div>
        <div class="stat-value"><?= $stats['active_students'] ?></div>
        <div class="stat-label">Students with Scholarship</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon purple">💰</div>
        </div>
        <div class="stat-value">₱<?= number_format($stats['total_discount'], 2) ?></div>
        <div class="stat-label">Total Discounts Given</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon amber">📊</div>
        </div>
        <div class="stat-value">
            <?= $stats['active_students'] > 0 ? '₱' . number_format($stats['total_discount'] / $stats['active_students'], 2) : '₱0.00' ?>
        </div>
        <div class="stat-label">Avg Discount/Student</div>
    </div>
</div>

<div class="grid-2" style="margin-bottom: 24px;">
    <!-- Scholarship Types -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Scholarship Types</h3>
        </div>
        
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Discount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scholarships as $s): ?>
                        <tr>
                            <td>
                                <div style="font-weight:700"><?= htmlspecialchars($s['scholarship_name']) ?></div>
                            </td>
                            <td>
                                <span class="badge badge-<?= 
                                    $s['scholarship_type'] === 'Academic' ? 'primary' :
                                    ($s['scholarship_type'] === 'Government' ? 'purple' : 'secondary')
                                ?>">
                                    <?= $s['scholarship_type'] ?>
                                </span>
                            </td>
                            <td class="mono">
                                <?php if ($s['discount_percent']): ?>
                                    <?= $s['discount_percent'] ?>%
                                <?php else: ?>
                                    ₱<?= number_format($s['discount_amount'], 2) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $s['status'] === 'Active' ? 'success' : 'secondary' ?>">
                                    <?= $s['status'] ?? 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline" 
                                        onclick="editScholarship(<?= htmlspecialchars(json_encode($s)) ?>)">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($scholarships)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">No scholarships defined</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Apply Scholarship -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Apply Scholarship to Student</h3>
        </div>
        
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Search Student</label>
                <input type="text" class="form-control" id="studentSearch" 
                       placeholder="Enter name or student number...">
                <div id="studentResults" class="mt-8" style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;display:none;"></div>
            </div>
            
            <div id="selectedStudentInfo" style="display:none;" class="p-12" style="background:var(--primary-light);border-radius:8px;margin-bottom:16px;"></div>
            
            <div id="scholarshipForm" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Select Scholarship</label>
                    <select class="form-control" id="scholarshipSelect">
                        <option value="">-- Select Scholarship --</option>
                        <?php foreach ($scholarships as $s): ?>
                            <?php if ($s['status'] === 'Active'): ?>
                                <option value="<?= $s['id'] ?>" 
                                        data-percent="<?= $s['discount_percent'] ?>"
                                        data-amount="<?= $s['discount_amount'] ?>">
                                    <?= htmlspecialchars($s['scholarship_name']) ?> - 
                                    <?= $s['discount_percent'] ? $s['discount_percent'] . '%' : '₱' . number_format($s['discount_amount'], 2) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="previewSection" style="display:none;" class="p-12" style="background:var(--bg);border-radius:8px;margin-bottom:16px;"></div>
                
                <div class="d-flex gap-8">
                    <button class="btn btn-primary" id="applyBtn" onclick="openConfirmModal()">
                        Apply Scholarship
                    </button>
                    <button class="btn btn-outline" onclick="clearSelection()">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Active Scholarship Assignments -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Active Scholarship Assignments - AY <?= $current_academic_year ?></h3>
    </div>
    
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Program</th>
                    <th>Scholarship</th>
                    <th>Type</th>
                    <th>Discount</th>
                    <th>Granted By</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($active_assignments)): ?>
                    <?php foreach ($active_assignments as $a): ?>
                        <tr>
                            <td>
                                <div style="font-weight:700"><?= htmlspecialchars($a['student_name']) ?></div>
                                <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($a['student_number']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($a['program_code']) ?></td>
                            <td><?= htmlspecialchars($a['scholarship_name']) ?></td>
                            <td>
                                <span class="badge badge-<?= 
                                    $a['scholarship_type'] === 'Academic' ? 'primary' :
                                    ($a['scholarship_type'] === 'Government' ? 'purple' : 'secondary')
                                ?>">
                                    <?= $a['scholarship_type'] ?>
                                </span>
                            </td>
                            <td class="mono">
                                <?php if ($a['discount_percent']): ?>
                                    <?= $a['discount_percent'] ?>%
                                <?php else: ?>
                                    ₱<?= number_format($a['discount_amount'], 2) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm"><?= htmlspecialchars($a['granted_by_name'] ?? '—') ?></td>
                            <td class="mono text-sm"><?= date('M d, Y', strtotime($a['granted_at'])) ?></td>
                            <td>
                                <button class="btn btn-sm btn-danger remove-btn" 
                                        data-assignment-id="<?= $a['id'] ?>"
                                        data-student-id="<?= $a['student_id'] ?>"
                                        data-billing-id="<?= $a['billing_id'] ?? 0 ?>">
                                    Remove
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">No active scholarship assignments</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Scholarship Modal (Edit Only) -->
<div class="modal-overlay" id="scholarshipModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Edit Scholarship</h3>
            <button class="modal-close" onclick="closeScholarshipModal()">&times;</button>
        </div>
        
        <form id="scholarshipEditForm" onsubmit="saveScholarship(event)">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <input type="hidden" name="action" value="save_scholarship_type">
            <input type="hidden" name="scholarship_id" id="scholarshipId" value="0">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Scholarship Name</label>
                    <input type="text" class="form-control" name="scholarship_name" id="scholarshipName" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Scholarship Type</label>
                    <select class="form-control" name="scholarship_type" id="scholarshipType" required>
                        <option value="Academic">Academic</option>
                        <option value="Athletic">Athletic</option>
                        <option value="Government">Government</option>
                        <option value="Financial Aid">Financial Aid</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Discount Percent (%)</label>
                        <input type="number" class="form-control" name="discount_percent" id="discountPercent" 
                               step="0.01" min="0" max="100">
                        <div class="form-hint">Leave empty if using fixed amount</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Discount Amount (₱)</label>
                        <input type="number" class="form-control" name="discount_amount" id="discountAmount" 
                               step="0.01" min="0">
                        <div class="form-hint">Leave empty if using percentage</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" name="is_active" id="isActive" checked>
                        <span>Active</span>
                    </label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeScholarshipModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Scholarship</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm Scholarship Modal -->
<div class="modal-overlay" id="confirmScholarshipModal">
    <div class="modal" style="max-width:450px;">
        <div class="modal-header">
            <h3 class="modal-title">Confirm Scholarship Application</h3>
            <button class="modal-close" onclick="closeConfirmModal()">&times;</button>
        </div>
        <div class="modal-body text-center">
            <h4 style="font-size: 18px; font-weight: 700; margin-bottom: 8px;">Apply Scholarship?</h4>
            <p class="text-muted" style="margin-bottom: 24px;">
                This will recalculate the student's billing and apply the discount.
            </p>
            
            <div id="scholarshipSummary" style="background: var(--bg); padding: 16px; border-radius: 8px; margin-bottom: 24px; text-align: left;"></div>
            
            <div class="d-flex gap-12" style="justify-content: center;">
                <button class="btn btn-outline" onclick="closeConfirmModal()" style="min-width: 100px;">
                    Cancel
                </button>
                <button class="btn btn-primary" onclick="executeApplyScholarship()" style="min-width: 100px;">
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Remove Scholarship Modal -->
<div class="modal-overlay" id="removeScholarshipModal">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <h3 class="modal-title">Confirm Removal</h3>
            <button class="modal-close" onclick="closeRemoveModal()">&times;</button>
        </div>
        <div class="modal-body text-center">
            <div style="font-size: 48px; margin-bottom: 16px; color: var(--danger);">⚠️</div>
            <h4 style="font-size: 18px; font-weight: 700; margin-bottom: 8px;">Remove Scholarship?</h4>
            <p class="text-muted" style="margin-bottom: 24px;">
                This will remove the scholarship and recalculate the student's billing.
            </p>
            <div class="d-flex gap-12" style="justify-content: center;">
                <button class="btn btn-outline" onclick="closeRemoveModal()" style="min-width: 100px;">
                    Cancel
                </button>
                <button class="btn btn-danger" id="confirmRemoveBtn" style="min-width: 100px;">
                    Remove
                </button>
            </div>
        </div>
    </div>
</div>

<style>
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
</style>

<script>
let selectedStudent = null;
let selectedBillingId = null;
let pendingRemoveData = null;
let pendingScholarship = {
    studentId: null,
    scholarshipId: null,
    billingId: null,
    studentName: '',
    scholarshipName: '',
    discount: '',
    newBalance: ''
};

// Student search
let searchTimeout;
document.getElementById('studentSearch').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value;
    
    if (query.length < 2) {
        document.getElementById('studentResults').style.display = 'none';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        const formData = new FormData();
        formData.append('action', 'search_students');
        formData.append('query', query);
        
        fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            let html = '';
            if (data.students.length === 0) {
                html = '<div class="text-muted p-12">No students found</div>';
            } else {
                data.students.forEach(s => {
                    html += `
                        <div class="student-result" onclick="selectStudent(${s.student_id})">
                            <div style="font-weight:700">${sanitize(s.student_name)}</div>
                            <div class="d-flex justify-between">
                                <span class="mono text-sm">${sanitize(s.student_number)}</span>
                                <span>
                                    ${s.has_scholarship ? 
                                        `<span class="badge badge-success">Has: ${sanitize(s.current_scholarship)}</span>` : 
                                        '<span class="badge badge-secondary">No scholarship</span>'}
                                </span>
                            </div>
                        </div>
                    `;
                });
            }
            document.getElementById('studentResults').innerHTML = html;
            document.getElementById('studentResults').style.display = 'block';
        })
        .catch(err => {
            console.error('Search error:', err);
            alert('Failed to search students');
        });
    }, 300);
});

// Select student
function selectStudent(studentId) {
    const formData = new FormData();
    formData.append('action', 'get_student_details');
    formData.append('student_id', studentId);
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            selectedStudent = data.student;
            selectedBillingId = data.student.billing_id;
            
            let scholarshipHtml = '';
            if (data.student.current_scholarship_name) {
                scholarshipHtml = `<div class="mt-8">
                    <span class="badge badge-success">
                        Current: ${sanitize(data.student.current_scholarship_name)}
                    </span>
                </div>`;
            }
            
            const balance = parseFloat(data.student.balance) || 0;
            const balanceColor = balance > 0 ? 'var(--danger)' : 'var(--success)';
            
            document.getElementById('selectedStudentInfo').innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-weight:800">${sanitize(data.student.first_name)} ${sanitize(data.student.last_name)}</div>
                        <div class="mono text-sm">${sanitize(data.student.student_number)}</div>
                        <div>${data.student.program_code || 'N/A'} - Year ${data.student.year_level || 1}</div>
                        ${scholarshipHtml}
                    </div>
                    <div style="text-align:right">
                        <div class="text-sm">Current Balance</div>
                        <div style="font-weight:800; font-size:18px; color: ${balanceColor};">
                            ₱${balance.toFixed(2)}
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('selectedStudentInfo').style.display = 'block';
            document.getElementById('scholarshipForm').style.display = 'block';
            document.getElementById('studentResults').style.display = 'none';
            document.getElementById('studentSearch').value = '';
        } else {
            alert('Error: ' + (data.message || 'Failed to load student data'));
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('Failed to load student data. Check console for details.');
    });
}

// Preview scholarship
document.getElementById('scholarshipSelect').addEventListener('change', function() {
    const scholarshipId = this.value;
    if (!scholarshipId || !selectedBillingId) return;
    
    const formData = new FormData();
    formData.append('action', 'preview_scholarship');
    formData.append('scholarship_id', scholarshipId);
    formData.append('billing_id', selectedBillingId);
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('previewSection').innerHTML = `
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span>Original Total:</span>
                    <span class="mono">${data.formatted.original}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px; color:var(--success);">
                    <span>Discount:</span>
                    <span class="mono">-${data.formatted.discount}</span>
                </div>
                <div style="display:flex; justify-content:space-between; font-weight:700; border-top:1px solid var(--border); padding-top:8px; margin-top:8px;">
                    <span>New Total:</span>
                    <span class="mono" style="color:var(--primary);">${data.formatted.new}</span>
                </div>
                <div style="margin-top:8px; font-size:13px; color:var(--success);">
                    ✨ Savings: ${data.formatted.discount}
                </div>
            `;
            document.getElementById('previewSection').style.display = 'block';
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('Failed to preview scholarship');
    });
});

// Open confirmation modal
function openConfirmModal() {
    const scholarshipId = document.getElementById('scholarshipSelect').value;
    if (!scholarshipId) {
        alert('Please select a scholarship');
        return;
    }
    
    if (!selectedStudent) {
        alert('Please select a student first');
        return;
    }
    
    const select = document.getElementById('scholarshipSelect');
    const selectedOption = select.options[select.selectedIndex];
    const scholarshipName = selectedOption.text.split(' - ')[0];
    
    const previewDiv = document.getElementById('previewSection');
    const monoElements = previewDiv.querySelectorAll('.mono');
    
    if (monoElements.length < 3) {
        alert('Please preview the scholarship first');
        return;
    }
    
    let discountValue = monoElements[1].textContent;
    const newTotalValue = monoElements[2].textContent;
    
    if (discountValue.startsWith('-')) {
        discountValue = discountValue.substring(1);
    }
    
    const studentName = (selectedStudent.first_name && selectedStudent.last_name) 
        ? selectedStudent.first_name + ' ' + selectedStudent.last_name 
        : 'Unknown Student';
    
    pendingScholarship = {
        studentId: selectedStudent.student_id,
        scholarshipId: scholarshipId,
        billingId: selectedBillingId,
        studentName: studentName,
        scholarshipName: scholarshipName,
        discount: discountValue,
        newBalance: newTotalValue
    };
    
    document.getElementById('scholarshipSummary').innerHTML = `
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
            <span>Student:</span>
            <span class="font-bold">${sanitize(pendingScholarship.studentName)}</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
            <span>Scholarship:</span>
            <span class="font-bold">${sanitize(pendingScholarship.scholarshipName)}</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:8px; color:var(--success);">
            <span>Discount:</span>
            <span class="font-bold">${pendingScholarship.discount}</span>
        </div>
        <div style="display:flex; justify-content:space-between; padding-top:8px; border-top:1px solid var(--border);">
            <span>New Balance:</span>
            <span class="font-bold" style="color:var(--primary);">${pendingScholarship.newBalance}</span>
        </div>
    `;
    
    document.getElementById('confirmScholarshipModal').classList.add('open');
}

function executeApplyScholarship() {
    const formData = new FormData();
    formData.append('action', 'apply_scholarship');
    formData.append('csrf_token', document.getElementById('csrf_token').value);
    formData.append('student_id', pendingScholarship.studentId);
    formData.append('scholarship_id', pendingScholarship.scholarshipId);
    formData.append('billing_id', pendingScholarship.billingId);
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        closeConfirmModal();
        if (data.success) {
            alert(data.message);
            setTimeout(() => window.location.reload(), 1500);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        closeConfirmModal();
        console.error('Error:', err);
        alert('Failed to apply scholarship');
    });
}

function closeConfirmModal() {
    document.getElementById('confirmScholarshipModal').classList.remove('open');
}

// Remove scholarship
document.querySelectorAll('.remove-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        pendingRemoveData = {
            assignmentId: this.dataset.assignmentId,
            studentId: this.dataset.studentId,
            billingId: this.dataset.billingId
        };
        document.getElementById('removeScholarshipModal').classList.add('open');
    });
});

document.getElementById('confirmRemoveBtn').addEventListener('click', function() {
    if (!pendingRemoveData) {
        alert('No scholarship selected');
        closeRemoveModal();
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'remove_scholarship');
    formData.append('csrf_token', document.getElementById('csrf_token').value);
    formData.append('assignment_id', pendingRemoveData.assignmentId);
    formData.append('student_id', pendingRemoveData.studentId);
    formData.append('billing_id', pendingRemoveData.billingId);
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        closeRemoveModal();
        if (data.success) {
            alert(data.message);
            setTimeout(() => window.location.reload(), 1500);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        closeRemoveModal();
        console.error('Error:', err);
        alert('Failed to remove scholarship');
    });
});

function closeRemoveModal() {
    document.getElementById('removeScholarshipModal').classList.remove('open');
    pendingRemoveData = null;
}

// Edit scholarship
function editScholarship(scholarship) {
    document.getElementById('modalTitle').textContent = 'Edit Scholarship';
    document.getElementById('scholarshipId').value = scholarship.id;
    document.getElementById('scholarshipName').value = scholarship.scholarship_name;
    document.getElementById('scholarshipType').value = scholarship.scholarship_type;
    document.getElementById('discountPercent').value = scholarship.discount_percent || '';
    document.getElementById('discountAmount').value = scholarship.discount_amount || '';
    document.getElementById('isActive').checked = scholarship.status === 'Active';
    document.getElementById('scholarshipModal').classList.add('open');
}

function closeScholarshipModal() {
    document.getElementById('scholarshipModal').classList.remove('open');
}

function saveScholarship(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            alert('Error: ' + data.error);
        } else {
            alert('Success: ' + data.message);
            setTimeout(() => window.location.reload(), 1500);
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('Failed to save scholarship');
    });
}

function clearSelection() {
    selectedStudent = null;
    selectedBillingId = null;
    document.getElementById('selectedStudentInfo').style.display = 'none';
    document.getElementById('scholarshipForm').style.display = 'none';
    document.getElementById('scholarshipSelect').value = '';
    document.getElementById('previewSection').style.display = 'none';
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

<?php 
ob_end_flush();
include 'includes/footer.php'; 
?>