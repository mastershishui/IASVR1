<?php
$pageTitle = 'Billing & Assessment';
require_once 'includes/config.php';
requireLogin();
requireRole(['accounting', 'admin']);
$db = getDB();

// Set CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$current_academic_year = ACADEMIC_YEAR;
$current_semester = CURRENT_SEMESTER;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clear all output buffers before sending JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    
    // Calculate fees for a student
    if ($_POST['action'] === 'calculate_fees') {
        $student_id = (int)$_POST['student_id'];
        $enrollment_ids = isset($_POST['enrollment_ids']) ? $_POST['enrollment_ids'] : [];
        
        try {
            // Get student details
            $stmt = $db->prepare("
                SELECT s.*, CONCAT(u.first_name, ' ', u.last_name) as student_name,
                       p.code as program_code, p.name as program_name
                FROM students s
                JOIN users u ON s.user_id = u.id
                JOIN programs p ON s.program_id = p.id
                WHERE s.id = ?
            ");
            $stmt->bind_param('i', $student_id);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            
            if (!$student) {
                throw new Exception("Student not found");
            }
            
            // Get enrolled subjects
            $subjects = [];
            $total_units = 0;
            $total_lab_units = 0;

            if (!empty($enrollment_ids)) {
                $placeholders = implode(',', array_fill(0, count($enrollment_ids), '?'));
                $types = str_repeat('i', count($enrollment_ids));
                
                $sql = "
                    SELECT e.*, sub.code as subject_code, sub.name as subject_name, 
                           sub.units, sub.lab_units, sec.section_code
                    FROM enrollments e
                    JOIN sections sec ON e.section_id = sec.id
                    JOIN subjects sub ON sec.subject_id = sub.id
                    WHERE e.id IN ($placeholders) AND e.student_id = ?
                ";
                
                $params = array_merge($enrollment_ids, [$student_id]);
                $types .= 'i';
                
                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                foreach ($subjects as $sub) {
                    // Add both regular and lab units to total units
                    $total_units += $sub['units'] + ($sub['lab_units'] ?? 0);
                    // Track lab units separately for display only
                    if (isset($sub['lab_units']) && $sub['lab_units'] > 0) {
                        $total_lab_units += $sub['lab_units'];
                    }
                }
            }

            // Define fee rates
            $tuition_rate_per_unit = 1500; // ₱1,500 per unit
            $misc_fee = 3000; // ₱3,000 fixed

            // Calculate tuition fee (includes lab units)
            $tuition_fee = $total_units * $tuition_rate_per_unit;
            
            // Get active scholarship if any
            $stmt = $db->prepare("
                SELECT ss.*, s2.scholarship_name, s2.discount_percent, s2.amount as scholarship_amount
                FROM student_scholarships ss
                JOIN scholarships s2 ON ss.scholarship_id = s2.id
                WHERE ss.student_id = ? AND ss.academic_year = ? AND ss.status = 'Active'
                LIMIT 1
            ");
            $stmt->bind_param('is', $student_id, $current_academic_year);
            $stmt->execute();
            $scholarship = $stmt->get_result()->fetch_assoc();
            
            // Calculate discount
            $discount = 0;
            $total_before_discount = $tuition_fee + $misc_fee;
            
            if ($scholarship) {
                if ($scholarship['discount_percent'] > 0) {
                    $discount = $total_before_discount * ($scholarship['discount_percent'] / 100);
                } else {
                    $discount = $scholarship['scholarship_amount'] ?? 0;
                }
            }
            
            $total_amount = $total_before_discount - $discount;
            
            // Check if billing already exists
            $existing = null;
            
            if (!empty($enrollment_ids)) {
                $stmt = $db->prepare("
                    SELECT b.* FROM billing b
                    JOIN enrollments e ON b.student_id = e.student_id
                    WHERE e.id IN ($placeholders) AND b.academic_year = ? AND b.semester = ?
                    LIMIT 1
                ");
                $params = array_merge($enrollment_ids, [$current_academic_year, $current_semester]);
                $types = str_repeat('i', count($enrollment_ids)) . 'si';
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
            }
            
            // Log the data for debugging
            error_log("Total units: $total_units, Tuition fee: $tuition_fee");
            
            echo json_encode([
                'success' => true,
                'student' => $student,
                'subjects' => $subjects,
                'total_units' => $total_units,
                'total_lab_units' => $total_lab_units,
                'fees' => [
                    'tuition_rate' => $tuition_rate_per_unit,
                    'tuition_fee' => $tuition_fee,
                    'misc_fee' => $misc_fee,
                    'discount' => $discount,
                    'total_before_discount' => $total_before_discount,
                    'total_amount' => $total_amount
                ],
                'scholarship' => $scholarship,
                'existing_billing' => $existing
            ]);
            
        } catch (Exception $e) {
            error_log("Calculate fees error: " . $e->getMessage());
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Save billing
    if ($_POST['action'] === 'save_billing') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        $student_id = (int)$_POST['student_id'];
        $enrollment_ids = isset($_POST['enrollment_ids']) ? json_decode($_POST['enrollment_ids'], true) : [];
        $tuition_fee = (float)$_POST['tuition_fee'];
        $misc_fee = (float)$_POST['misc_fee'];
        $other_fees = (float)$_POST['other_fees'];
        $discount = (float)$_POST['discount'];
        $total_amount = (float)$_POST['total_amount'];
        
        // Validate required fields
        if (!$student_id || empty($enrollment_ids)) {
            echo json_encode(['error' => 'Missing required fields']);
            exit;
        }
        
        try {
            $db->begin_transaction();
            
            // Check if billing already exists for this student in current term
            $stmt = $db->prepare("
                SELECT id, amount_paid FROM billing 
                WHERE student_id = ? AND academic_year = ? AND semester = ?
            ");
            $stmt->bind_param('isi', $student_id, $current_academic_year, $current_semester);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing = $result->fetch_assoc();
            
            if ($existing) {
                // Update existing billing - preserve amount_paid
                $amount_paid = $existing['amount_paid'] ?? 0;
                $balance = $total_amount - $amount_paid;
                
                $stmt = $db->prepare("
                    UPDATE billing SET 
                        tuition_fee = ?,
                        misc_fees = ?,
                        other_fees = ?,
                        discount = ?,
                        total_amount = ?,
                        balance = ?,
                        status = CASE 
                            WHEN ? <= 0 THEN 'Paid'
                            WHEN ? < ? THEN 'Partial'
                            ELSE 'Unpaid'
                        END
                    WHERE id = ?
                ");
                
                $stmt->bind_param(
                    'dddddddddi', 
                    $tuition_fee, $misc_fee, $other_fees, $discount, 
                    $total_amount, $balance, $balance, $balance, $total_amount,
                    $existing['id']
                );
                $stmt->execute();
                $billing_id = $existing['id'];
                $message = 'Billing updated successfully';
            } else {
                // Create new billing
                $balance = $total_amount;
                
                $stmt = $db->prepare("
                    INSERT INTO billing (
                        student_id, academic_year, semester, 
                        tuition_fee, misc_fees, other_fees, discount, 
                        total_amount, amount_paid, balance, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'Unpaid')
                ");
                
                $stmt->bind_param(
                    'isidddddd', 
                    $student_id, $current_academic_year, $current_semester,
                    $tuition_fee, $misc_fee, $other_fees, $discount, 
                    $total_amount, $balance
                );
                $stmt->execute();
                $billing_id = $db->insert_id;
                $message = 'Billing created successfully';
            }
            
            // Update enrollment status to 'Validated'
            if (!empty($enrollment_ids)) {
                $placeholders = implode(',', array_fill(0, count($enrollment_ids), '?'));
                $types = str_repeat('i', count($enrollment_ids));
                
                $sql = "UPDATE enrollments SET status = 'Validated', validated_by = ?, validated_at = NOW() WHERE id IN ($placeholders)";
                $params = array_merge([$_SESSION['user_id']], $enrollment_ids);
                $types = 'i' . $types;
                
                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'billing_id' => $billing_id
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            error_log("Billing save error: " . $e->getMessage());
            echo json_encode(['error' => 'Failed to save billing: ' . $e->getMessage()]);
        }
        exit;
    }
}


// Get pending enrollments that need assessment - FIXED
$pending_enrollments = $db->query("
    SELECT 
        e.id as enrollment_id,
        e.student_id,
        e.section_id,
        e.status as enrollment_status,
        e.enrolled_at,
        s.id as student_id,
        s.student_number,
        CONCAT(u.first_name, ' ', u.last_name) AS student_name,
        p.code as program_code,
        s.year_level,
        sec.section_code,
        sub.code as subject_code,
        sub.name as subject_name,
        sub.units,
        sub.lab_units,
        b.id as billing_id,
        b.total_amount,
        b.status as billing_status
    FROM enrollments e
    JOIN students s ON e.student_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN programs p ON s.program_id = p.id
    JOIN sections sec ON e.section_id = sec.id
    JOIN subjects sub ON sec.subject_id = sub.id
    LEFT JOIN billing b ON s.id = b.student_id AND b.academic_year = e.academic_year AND b.semester = e.semester
    WHERE e.academic_year = '$current_academic_year' AND e.semester = $current_semester
        AND e.status IN ('Pending', 'Validated')
    ORDER BY s.id, e.enrolled_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Group enrollments by student
$pending_by_student = [];
foreach ($pending_enrollments as $enroll) {
    $student_id = $enroll['student_id'];
    
    if (!isset($pending_by_student[$student_id])) {
        $pending_by_student[$student_id] = [
            'student_id' => $student_id,
            'student_number' => $enroll['student_number'],
            'student_name' => $enroll['student_name'],
            'program_code' => $enroll['program_code'],
            'year_level' => $enroll['year_level'],
            'enrollments' => [],
            'total_units' => 0,
            'billing_id' => $enroll['billing_id'],
            'billing_status' => $enroll['billing_status'],
            'total_amount' => $enroll['total_amount']
        ];
    }
    
    // Add this enrollment to the student's list
    $pending_by_student[$student_id]['enrollments'][] = [
        'enrollment_id' => $enroll['enrollment_id'],
        'section_id' => $enroll['section_id'],
        'section_code' => $enroll['section_code'],
        'subject_code' => $enroll['subject_code'],
        'subject_name' => $enroll['subject_name'],
        'units' => $enroll['units'],
        'lab_units' => $enroll['lab_units'],
        'status' => $enroll['enrollment_status']
    ];
    
    // Add to total units
    $pending_by_student[$student_id]['total_units'] += $enroll['units'] + ($enroll['lab_units'] ?? 0);
}

// Get recent assessments
$recent_assessments = $db->query("
    SELECT 
        b.*,
        s.student_number,
        CONCAT(u.first_name, ' ', u.last_name) AS student_name,
        p.code as program_code,
        DATE(b.created_at) as assessment_date
    FROM billing b
    JOIN students s ON b.student_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN programs p ON s.program_id = p.id
    WHERE b.academic_year = '$current_academic_year' AND b.semester = $current_semester
    ORDER BY b.created_at DESC
    LIMIT 10
");

if (!$recent_assessments) {
    error_log("Recent assessments query failed: " . $db->error);
    $recent_assessments = [];
} else {
    $recent_assessments = $recent_assessments->fetch_all(MYSQLI_ASSOC);
}

// Calculate stats
$stats = [
    'pending_count' => count(array_filter($pending_enrollments, fn($e) => !$e['billing_id'])),
    'assessed_count' => count(array_filter($pending_enrollments, fn($e) => $e['billing_id'])),
    'total_assessed' => !empty($recent_assessments) ? array_sum(array_column($recent_assessments, 'total_amount')) : 0,
    'avg_assessment' => !empty($recent_assessments) ? array_sum(array_column($recent_assessments, 'total_amount')) / count($recent_assessments) : 0
];

include 'includes/header.php';
?>

<!-- Hidden CSRF token for AJAX requests -->
<input type="hidden" id="global_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

<style>
/* Additional styles for billing */
.enrollment-group {
    background: var(--bg-light);
    border-radius: var(--radius-sm);
    padding: 8px;
    margin-top: 8px;
}

.enrollment-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 8px;
    border-bottom: 1px dashed var(--border);
    font-size: 12px;
}

.enrollment-item:last-child {
    border-bottom: none;
}

.fee-breakdown {
    background: var(--bg-light);
    border-radius: var(--radius);
    padding: 16px;
}

.fee-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
}

.fee-row.total {
    border-top: 2px solid var(--primary);
    border-bottom: none;
    margin-top: 8px;
    padding-top: 12px;
    font-weight: 700;
    font-size: 16px;
}

.subject-list {
    max-height: 250px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}

.subject-header {
    background: var(--bg);
    padding: 8px 12px;
    font-weight: 600;
    font-size: 12px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
}

.stats-value-small {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
}

.stats-label-small {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
}
</style>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 class="page-title">Billing & Assessment</h1>
            <p class="page-subtitle">Calculate and manage student fees for AY <?= $current_academic_year ?> - Sem <?= $current_semester ?></p>
        </div>
        <div style="display: flex; gap: 8px;">
            <span class="badge badge-primary">Academic Year: <?= $current_academic_year ?></span>
            <span class="badge badge-primary">Semester: <?= $current_semester ?></span>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">📋</div>
        </div>
        <div class="stat-value"><?= $stats['pending_count'] ?></div>
        <div class="stat-label">Pending Assessment</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">✅</div>
        </div>
        <div class="stat-value"><?= $stats['assessed_count'] ?></div>
        <div class="stat-label">Assessed</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon purple">💰</div>
        </div>
        <div class="stat-value">₱<?= number_format($stats['total_assessed'], 2) ?></div>
        <div class="stat-label">Total Assessments</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon amber">📊</div>
        </div>
        <div class="stat-value">₱<?= number_format($stats['avg_assessment'], 2) ?></div>
        <div class="stat-label">Average per Student</div>
    </div>
</div>

<!-- Pending Assessments -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3 class="card-title">Pending Assessments</h3>
        <div style="display: flex; gap: 8px;">
            <input type="text" id="searchPending" class="form-control" placeholder="Search student..." style="width: 250px;">
            <select id="filterStatus" class="form-control" style="width: 150px;">
                <option value="all">All</option>
                <option value="pending">Not Assessed</option>
                <option value="assessed">Assessed</option>
            </select>
        </div>
    </div>
    
    <div class="table-wrap">
        <table id="pendingTable">
            <thead>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" id="selectAll">
                    </th>
                    <th>Student</th>
                    <th>Program</th>
                    <th>Year</th>
                    <th>Enrolled Subjects</th>
                    <th>Total Units</th>
                    <th>Status</th>
                    <th>Assessment</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pending_by_student)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted" style="padding: 40px;">
                            <div style="font-size: 48px; margin-bottom: 16px;">📋</div>
                            <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">No pending assessments</h3>
                            <p>All enrollment requests have been processed.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pending_by_student as $student): ?>
                        <tr data-student-id="<?= $student['student_id'] ?>" data-status="<?= $student['billing_id'] ? 'assessed' : 'pending' ?>">
                            <td>
                                <input type="checkbox" class="student-select" 
                                       data-student-id="<?= $student['student_id'] ?>"
                                       data-enrollment-ids='<?= json_encode(array_column($student['enrollments'], 'enrollment_id')) ?>'
                                       <?= $student['billing_id'] ? 'disabled' : '' ?>>
                            </td>
                            <td>
                                <div style="font-weight: 700;"><?= htmlspecialchars($student['student_name']) ?></div>
                                <div class="text-muted" style="font-size: 11px;"><?= htmlspecialchars($student['student_number']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($student['program_code']) ?></td>
                            <td><?= $student['year_level'] ?></td>
                            <td>
    <div class="enrollment-group">
        <?php foreach ($student['enrollments'] as $enroll): ?>
            <div class="enrollment-item">
                <div>
                    <strong><?= htmlspecialchars($enroll['subject_code']) ?></strong>
                    <span class="text-muted" style="font-size: 11px; margin-left: 4px;">
                        (<?= htmlspecialchars($enroll['section_code']) ?>)
                    </span>
                    <div style="font-size: 11px; color: var(--text-muted);">
                        <?= htmlspecialchars($enroll['subject_name']) ?> - <?= $enroll['units'] ?> units
                        <?php if ($enroll['lab_units'] > 0): ?>
                            <span class="badge badge-primary" style="margin-left: 4px;">Lab</span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge badge-<?= $enroll['status'] === 'Validated' ? 'success' : 'warning' ?>">
                    <?= $enroll['status'] ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</td>
                            <td class="text-center"><?= $student['total_units'] ?></td>
                            <td>
                                <span class="badge badge-<?= $student['billing_id'] ? 'success' : 'warning' ?>">
                                    <?= $student['billing_id'] ? 'Assessed' : 'Pending' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($student['billing_id']): ?>
                                    <span class="badge badge-primary">₱<?= number_format($student['total_amount'], 2) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Not assessed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$student['billing_id']): ?>
                                    <button class="btn btn-primary btn-sm assess-btn" 
                                            data-student-id="<?= $student['student_id'] ?>"
                                            data-enrollment-ids='<?= json_encode(array_column($student['enrollments'], 'enrollment_id')) ?>'>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                            <polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>
                                            <line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
                                        </svg>
                                        Assess
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-outline btn-sm view-btn" 
                                            data-billing-id="<?= $student['billing_id'] ?>">
                                        View
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if (!empty($pending_by_student)): ?>
        <div style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <span class="text-muted">Selected: <span id="selectedCount">0</span> students</span>
            </div>
            <button class="btn btn-primary" id="bulkAssessBtn" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
                </svg>
                Bulk Assess Selected
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Recent Assessments -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Recent Assessments</h3>
        <a href="accounting_reports.php?type=billing" class="btn btn-outline btn-sm">View All Reports</a>
    </div>
    
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Program</th>
                    <th>Tuition</th>
                    <th>Misc</th>
                    <th>Other Fees</th>
                    <th>Discount</th>
                    <th>Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_assessments)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted">No assessments yet</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_assessments as $row): ?>
                        <tr>
                            <td class="mono text-sm"><?= date('M d, Y', strtotime($row['assessment_date'])) ?></td>
                            <td>
                                <div style="font-weight: 700;"><?= htmlspecialchars($row['student_name']) ?></div>
                                <div class="text-muted" style="font-size: 11px;"><?= htmlspecialchars($row['student_number']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($row['program_code']) ?></td>
                            <td class="mono">₱<?= number_format($row['tuition_fee'], 2) ?></td>
                            <td class="mono">₱<?= number_format($row['misc_fees'], 2) ?></td>
                            <td class="mono">₱<?= number_format($row['other_fees'] ?? 0, 2) ?></td>
                            <td class="mono" style="color: var(--success);">-₱<?= number_format($row['discount'], 2) ?></td>
                            <td class="mono" style="font-weight: 700; color: var(--primary);">₱<?= number_format($row['total_amount'], 2) ?></td>
                            <td>
                                <span class="badge badge-<?= 
                                    $row['balance'] <= 0 ? 'success' : 
                                    ($row['balance'] < $row['total_amount'] ? 'warning' : 'secondary') 
                                ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Assessment Modal -->
<div class="modal-overlay" id="assessmentModal">
    <div class="modal" style="max-width: 800px;">
        <div class="modal-header">
            <h3 class="modal-title">Fee Assessment</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        
        <div class="modal-body" id="assessmentContent">
            <div class="text-center" style="padding: 48px;">
                <div class="spinner"></div>
                <p class="text-muted mt-16">Loading student enrollment...</p>
            </div>
        </div>
    </div>
</div>

<!-- View Assessment Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Assessment Details</h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="viewContent">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<script>
let selectedStudents = new Set();

// Select All functionality
document.getElementById('selectAll')?.addEventListener('change', function(e) {
    const checkboxes = document.querySelectorAll('.student-select:not(:disabled)');
    checkboxes.forEach(cb => {
        cb.checked = e.target.checked;
        if (e.target.checked) {
            selectedStudents.add(cb.dataset.studentId);
        } else {
            selectedStudents.delete(cb.dataset.studentId);
        }
    });
    updateSelectedCount();
});

// Individual checkboxes
document.querySelectorAll('.student-select').forEach(cb => {
    cb.addEventListener('change', function(e) {
        const studentId = this.dataset.studentId;
        if (this.checked) {
            selectedStudents.add(studentId);
        } else {
            selectedStudents.delete(studentId);
        }
        updateSelectedCount();
    });
});

function updateSelectedCount() {
    const count = selectedStudents.size;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('bulkAssessBtn').disabled = count === 0;
}

// Filter functionality
document.getElementById('filterStatus')?.addEventListener('change', filterTable);
document.getElementById('searchPending')?.addEventListener('input', filterTable);

function filterTable() {
    const filter = document.getElementById('filterStatus').value;
    const search = document.getElementById('searchPending').value.toLowerCase();
    const rows = document.querySelectorAll('#pendingTable tbody tr');
    
    rows.forEach(row => {
        if (row.children.length < 2) return; // Skip empty state row
        
        const status = row.dataset.status;
        const studentName = row.cells[1]?.textContent.toLowerCase() || '';
        const studentNum = row.cells[1]?.querySelector('.text-muted')?.textContent.toLowerCase() || '';
        
        const matchesFilter = filter === 'all' || status === filter;
        const matchesSearch = search === '' || studentName.includes(search) || studentNum.includes(search);
        
        row.style.display = matchesFilter && matchesSearch ? '' : 'none';
    });
}

// Bulk assess
document.getElementById('bulkAssessBtn')?.addEventListener('click', function() {
    const selectedData = [];
    document.querySelectorAll('.student-select:checked').forEach(cb => {
        selectedData.push({
            studentId: cb.dataset.studentId,
            enrollmentIds: JSON.parse(cb.dataset.enrollmentIds)
        });
    });
    
    if (selectedData.length === 0) {
        alert('Please select at least one student');
        return;
    }
    
    // For now, just assess the first selected student
    // You can enhance this to handle multiple students
    assessStudent(selectedData[0].studentId, selectedData[0].enrollmentIds);
});

// Assess button
document.querySelectorAll('.assess-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const studentId = this.dataset.studentId;
        const enrollmentIds = JSON.parse(this.dataset.enrollmentIds);
        assessStudent(studentId, enrollmentIds);
    });
});

function assessStudent(studentId, enrollmentIds) {
    console.log('assessStudent called with studentId:', studentId);
    console.log('Type of studentId:', typeof studentId);
    
    openModal();
    
    const formData = new FormData();
    formData.append('action', 'calculate_fees');
    formData.append('student_id', studentId);
    enrollmentIds.forEach(id => formData.append('enrollment_ids[]', id));
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            alert('Error: ' + data.error);
            closeModal();
            return;
        }
        renderAssessmentForm(data, enrollmentIds);
    })
    .catch(err => {
        console.error(err);
        alert('Failed to load assessment data');
        closeModal();
    });
}

function renderAssessmentForm(data, enrollmentIds) {
    const fees = data.fees;
    const student = data.student;
    const subjects = data.subjects;
    
    let subjectsHtml = '';
    subjects.forEach(sub => {
        subjectsHtml += `
            <tr>
                <td style="padding: 6px 8px;">${sanitize(sub.subject_code)}</td>
                <td style="padding: 6px 8px;">${sanitize(sub.subject_name)}</td>
                <td style="padding: 6px 8px;">${sanitize(sub.section_code)}</td>
                <td style="padding: 6px 8px; text-align: center;">${sub.units}</td>
                <td style="padding: 6px 8px; text-align: center;">${sub.lab_units || 0}</td>
            </tr>
        `;
    });
    
    let scholarshipHtml = '';
    if (data.scholarship) {
        scholarshipHtml = `
            <div class="fee-row" style="color: var(--success);">
                <span>Discount (${sanitize(data.scholarship.scholarship_name)})</span>
                <span class="mono">-₱${fees.discount.toFixed(2)}</span>
            </div>
        `;
    }
    
    const labText = data.total_lab_units > 0 ? 
        ` • ${data.total_lab_units} lab unit${data.total_lab_units > 1 ? 's' : ''}` : '';
    
    const html = `
        <div style="margin-bottom: 20px; padding: 12px; background: var(--primary-light); border-radius: var(--radius-sm);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-weight: 800; font-size: 16px;">${sanitize(student.student_name)}</div>
                    <div class="text-muted" style="font-size: 12px;">${sanitize(student.student_number)}</div>
                </div>
                <div>
                    <span class="badge badge-${data.existing_billing ? 'success' : 'primary'}">
                        ${data.existing_billing ? 'Update Assessment' : 'New Assessment'}
                    </span>
                </div>
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <div class="subject-header">Enrolled Subjects (${subjects.length})</div>
            <div class="subject-list">
                <table style="width: 100%; font-size: 12px;">
                    <thead>
                        <tr>
                            <th style="padding: 8px;">Code</th>
                            <th style="padding: 8px;">Subject</th>
                            <th style="padding: 8px;">Section</th>
                            <th style="padding: 8px;">Units</th>
                            <th style="padding: 8px;">Lab</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${subjectsHtml}
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="fee-breakdown" style="margin-bottom: 20px;">
            <h4 style="font-weight: 600; margin-bottom: 12px;">Fee Breakdown</h4>
            
            <div class="fee-row">
                <span>
                    Tuition Fee 
                    (${data.total_units} total units${labText} × ₱${fees.tuition_rate.toFixed(2)})
                </span>
                <span class="mono">₱${fees.tuition_fee.toFixed(2)}</span>
            </div>
            
            <div class="fee-row">
                <span>Miscellaneous Fee</span>
                <span class="mono">₱${fees.misc_fee.toFixed(2)}</span>
            </div>
            
            ${scholarshipHtml}
            
            <div class="fee-row total">
                <span>Total Amount Due</span>
                <span class="mono" style="color: var(--primary);">₱${fees.total_amount.toFixed(2)}</span>
            </div>
        </div>
        
        <form id="billingForm" onsubmit="saveBilling(event)">
            <input type="hidden" name="csrf_token" value="${document.getElementById('global_csrf_token').value}">
            <input type="hidden" name="action" value="save_billing">
            <input type="hidden" name="student_id" value="${student.id}">
            <input type="hidden" name="enrollment_ids" value='${JSON.stringify(enrollmentIds)}'>
            <input type="hidden" name="tuition_fee" value="${fees.tuition_fee}">
            <input type="hidden" name="misc_fee" value="${fees.misc_fee}">
            <input type="hidden" name="other_fees" value="0">
            <input type="hidden" name="discount" value="${fees.discount}">
            <input type="hidden" name="total_amount" value="${fees.total_amount}">
            
            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                    </svg>
                    ${data.existing_billing ? 'Update Assessment' : 'Save Assessment'}
                </button>
            </div>
        </form>
    `;
    
    document.getElementById('assessmentContent').innerHTML = html;
}

function saveBilling(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    
    // Log the data being sent
    console.log('Saving billing with data:');
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + pair[1]);
    }
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(async res => {
        const text = await res.text();
        console.log('Raw server response:', text);
        
        // Try to extract JSON if there's HTML prefix
        const jsonMatch = text.match(/\{.*\}/s);
        if (jsonMatch) {
            try {
                const data = JSON.parse(jsonMatch[0]);
                if (data.error) {
                    alert('Error: ' + data.error);
                } else {
                    alert('Success: ' + data.message);
                    setTimeout(() => window.location.reload(), 1500);
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                alert('Server returned: ' + text.substring(0, 100) + '...');
            }
        } else {
            console.error('No JSON found in response');
            alert('Server returned invalid response. Check console.');
        }
    })
    .catch(err => {
        console.error('Fetch error:', err);
        alert('Failed to save billing');
    });
}

// View assessment
document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const billingId = this.dataset.billingId;
        viewAssessment(billingId);
    });
});

function viewAssessment(billingId) {
    // You can implement this to show assessment details
    alert('View assessment: ' + billingId);
}

// Modal functions
function openModal() {
    document.getElementById('assessmentModal').classList.add('open');
}

function closeModal() {
    document.getElementById('assessmentModal').classList.remove('open');
    document.getElementById('assessmentContent').innerHTML = `
        <div class="text-center" style="padding: 48px;">
            <div class="spinner"></div>
            <p class="text-muted mt-16">Loading student enrollment...</p>
        </div>
    `;
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('open');
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

// Spinner CSS
const style = document.createElement('style');
style.textContent = `
.spinner {
    width: 40px;
    height: 40px;
    margin: 0 auto;
    border: 3px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
.mt-16 { margin-top: 16px; }
`;
document.head.appendChild(style);
</script>

<?php include 'includes/footer.php'; ?>