<?php
/**
 * get_subjects_faculty.php
 * AJAX endpoint for Class Schedule - Create Section modal
 * 
 * Returns:
 *   action=get_subjects  → all active subjects (with program info)
 *   action=get_faculty   → all active faculty (with user info)
 *   action=get_faculty_by_subject&subject_id=X → faculty whose department
 *                          matches the subject's program department
 */

require_once '../config/database.php'; // adjust path to your DB connection file
header('Content-Type: application/json');

// Only allow logged-in admin/registrar
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ── 1. Load all active subjects ────────────────────────────────────────
        case 'get_subjects':
            $stmt = $pdo->query("
                SELECT s.id,
                       s.code,
                       s.name,
                       s.units,
                       s.lab_units,
                       s.year_level,
                       s.semester,
                       p.code  AS program_code,
                       p.name  AS program_name,
                       p.department
                FROM   subjects s
                LEFT JOIN programs p ON p.id = s.program_id
                ORDER  BY p.code, s.year_level, s.semester, s.code
            ");
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'subjects' => $subjects]);
            break;

        // ── 2. Load ALL active faculty (fallback / initial load) ──────────────
        case 'get_faculty':
            $stmt = $pdo->query("
                SELECT f.id,
                       f.employee_id,
                       f.department,
                       f.specialization,
                       f.employment_type,
                       f.employment_status,
                       u.first_name,
                       u.last_name,
                       u.email
                FROM   faculty f
                JOIN   users   u ON u.id = f.user_id
                WHERE  f.employment_status = 'Active'
                  AND  u.status            = 'active'
                ORDER  BY u.last_name, u.first_name
            ");
            $faculty = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'faculty' => $faculty]);
            break;

        // ── 3. Load faculty available for a specific subject ──────────────────
        //    "Available" = Active faculty whose department matches the subject's
        //    program department OR whose specialization contains the subject name/code.
        //    Faculty already assigned to another section at the same day/time are
        //    flagged so the UI can warn the scheduler.
        case 'get_faculty_by_subject':
            $subject_id = intval($_GET['subject_id'] ?? 0);
            if (!$subject_id) {
                echo json_encode(['success' => false, 'message' => 'Missing subject_id']);
                exit;
            }

            // Get subject + program details
            $stmt = $pdo->prepare("
                SELECT s.*, p.department AS prog_department, p.code AS prog_code
                FROM   subjects s
                LEFT JOIN programs p ON p.id = s.program_id
                WHERE  s.id = ?
            ");
            $stmt->execute([$subject_id]);
            $subject = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$subject) {
                echo json_encode(['success' => false, 'message' => 'Subject not found']);
                exit;
            }

            $department = $subject['prog_department'] ?? '';

            // ── Primary list: faculty in the same department ──────────────────
            // ── Secondary : all other active faculty (still assignable) ────────
            $stmt = $pdo->prepare("
                SELECT f.id,
                       f.employee_id,
                       f.department        AS faculty_department,
                       f.specialization,
                       f.employment_type,
                       f.employment_status,
                       u.first_name,
                       u.last_name,
                       u.email,
                       CASE
                           WHEN f.department = :dept THEN 1
                           ELSE 0
                       END AS is_preferred
                FROM   faculty f
                JOIN   users   u ON u.id = f.user_id
                WHERE  f.employment_status = 'Active'
                  AND  u.status            = 'active'
                ORDER  BY is_preferred DESC, u.last_name, u.first_name
            ");
            $stmt->execute([':dept' => $department]);
            $faculty = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'  => true,
                'subject'  => $subject,
                'faculty'  => $faculty,
                'department' => $department,
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}