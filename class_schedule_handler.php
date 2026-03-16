<?php
/**
 * class_schedule_handler.php
 * Handles POST requests from the Add Section modal.
 *
 * action=create_section  →  inserts a row into `sections`
 */

require_once '../config/database.php';
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'create_section') {

    $section_code  = trim($_POST['section_code']  ?? '');
    $subject_id    = intval($_POST['subject_id']   ?? 0);
    $faculty_id    = intval($_POST['faculty_id']   ?? 0) ?: null;   // null = TBA
    $room          = trim($_POST['room']           ?? '');
    $day_time      = trim($_POST['day_time']       ?? '');
    $max_students  = intval($_POST['max_students'] ?? 40);

    // Use session-stored academic year/semester, or fall back to POST values
    $academic_year = $_SESSION['academic_year'] ?? trim($_POST['academic_year'] ?? '');
    $semester      = $_SESSION['semester']      ?? intval($_POST['semester']    ?? 1);

    // ── Validation ──────────────────────────────────────────────────────────
    if (!$section_code) {
        echo json_encode(['success' => false, 'message' => 'Section code is required.']);
        exit;
    }
    if (!$subject_id) {
        echo json_encode(['success' => false, 'message' => 'Please select a subject.']);
        exit;
    }

    try {
        // Check for duplicate section code in the same academic period
        $chk = $pdo->prepare("
            SELECT id FROM sections
            WHERE section_code = ? AND academic_year = ? AND semester = ?
        ");
        $chk->execute([$section_code, $academic_year, $semester]);
        if ($chk->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => "Section code '{$section_code}' already exists for this semester."
            ]);
            exit;
        }

        // Insert new section
        $stmt = $pdo->prepare("
            INSERT INTO sections
                (section_code, subject_id, faculty_id, room, day_time,
                 max_students, academic_year, semester, status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 'Open')
        ");
        $stmt->execute([
            $section_code,
            $subject_id,
            $faculty_id,
            $room ?: null,
            $day_time ?: null,
            $max_students,
            $academic_year ?: null,
            $semester,
        ]);

        $newId = $pdo->lastInsertId();

        // Optional: log the activity
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, module, details, ip_address)
            VALUES (?, 'create_section', 'Class Schedule', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            "Created section: {$section_code} (ID: {$newId})",
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        echo json_encode([
            'success'    => true,
            'message'    => 'Section created successfully.',
            'section_id' => $newId,
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}