-- BCP University Management System Database
-- Run this SQL in phpMyAdmin or MySQL CLI

CREATE DATABASE IF NOT EXISTS bcp_ums CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bcp_ums;

-- Users Table (All roles)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    role ENUM('admin','registrar','faculty','student','accounting') NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    status ENUM('active','inactive','pending') DEFAULT 'pending',
    profile_photo VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    created_by INT NULL
);

-- Programs / Courses
CREATE TABLE IF NOT EXISTS programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    department VARCHAR(150),
    duration_years INT DEFAULT 4,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Students (extends users)
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_number VARCHAR(20) UNIQUE NOT NULL,
    program_id INT,
    year_level INT DEFAULT 1,
    semester INT DEFAULT 1,
    academic_year VARCHAR(20),
    birthdate DATE,
    gender ENUM('Male','Female','Other'),
    address TEXT,
    contact_number VARCHAR(20),
    guardian_name VARCHAR(200),
    guardian_contact VARCHAR(20),
    enrollment_status ENUM('Active','Dropped','Graduated','On Leave','Pending') DEFAULT 'Pending',
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (program_id) REFERENCES programs(id)
);

-- Faculty (extends users)
CREATE TABLE IF NOT EXISTS faculty (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    employee_id VARCHAR(20) UNIQUE NOT NULL,
    department VARCHAR(150),
    specialization VARCHAR(200),
    employment_type ENUM('Regular','Part-time','Probationary') DEFAULT 'Regular',
    employment_status ENUM('Active','Inactive','On Leave') DEFAULT 'Active',
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Subjects
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    units INT DEFAULT 3,
    lab_units INT DEFAULT 0,
    program_id INT,
    year_level INT DEFAULT 1,
    semester INT DEFAULT 1,
    prerequisite_id INT NULL,
    FOREIGN KEY (program_id) REFERENCES programs(id),
    FOREIGN KEY (prerequisite_id) REFERENCES subjects(id)
);

-- Class Sections
CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_code VARCHAR(30) UNIQUE NOT NULL,
    subject_id INT NOT NULL,
    faculty_id INT,
    room VARCHAR(50),
    day_time VARCHAR(100),
    max_students INT DEFAULT 40,
    academic_year VARCHAR(20),
    semester INT DEFAULT 1,
    status ENUM('Open','Closed','Cancelled') DEFAULT 'Open',
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    FOREIGN KEY (faculty_id) REFERENCES faculty(id)
);

-- Enrollment
CREATE TABLE IF NOT EXISTS enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    academic_year VARCHAR(20),
    semester INT DEFAULT 1,
    status ENUM('Pending','Validated','Paid','Enrolled','Dropped') DEFAULT 'Pending',
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    validated_by INT NULL,
    validated_at TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (section_id) REFERENCES sections(id)
);

-- Grades
CREATE TABLE IF NOT EXISTS grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    prelim DECIMAL(5,2) NULL,
    midterm DECIMAL(5,2) NULL,
    finals DECIMAL(5,2) NULL,
    final_grade DECIMAL(5,2) NULL,
    remarks ENUM('Passed','Failed','Incomplete','Dropped') NULL,
    encoded_by INT,
    encoded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(id)
);

-- Grade Components (per-period breakdown)
CREATE TABLE IF NOT EXISTS grade_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_id INT NOT NULL,
    period ENUM('prelim','midterm','finals') NOT NULL,
    quizzes DECIMAL(5,2) NULL,
    assignments DECIMAL(5,2) NULL,
    recitation DECIMAL(5,2) NULL,
    projects DECIMAL(5,2) NULL,
    midterm_exam DECIMAL(5,2) NULL,
    final_exam DECIMAL(5,2) NULL,
    UNIQUE KEY unique_grade_period (grade_id, period),
    FOREIGN KEY (grade_id) REFERENCES grades(id)
);

-- Fees & Billing
CREATE TABLE IF NOT EXISTS billing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    academic_year VARCHAR(20),
    semester INT DEFAULT 1,
    tuition_fee DECIMAL(10,2) DEFAULT 0,
    misc_fees DECIMAL(10,2) DEFAULT 0,
    other_fees DECIMAL(10,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) DEFAULT 0,
    amount_paid DECIMAL(10,2) DEFAULT 0,
    balance DECIMAL(10,2) DEFAULT 0,
    status ENUM('Unpaid','Partial','Paid') DEFAULT 'Unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id)
);

-- Payments
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    billing_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash','GCash','Online Banking','Check') DEFAULT 'Cash',
    reference_number VARCHAR(100),
    received_by INT,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (billing_id) REFERENCES billing(id)
);

-- Document Requests
CREATE TABLE IF NOT EXISTS document_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    document_type ENUM('TOR','Good Moral','Registration Form','Diploma','Certification') NOT NULL,
    purpose TEXT,
    copies INT DEFAULT 1,
    status ENUM('Pending','Processing','Ready','Released','Rejected') DEFAULT 'Pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_by INT NULL,
    released_at TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES students(id)
);

-- Activity Logs
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255),
    module VARCHAR(100),
    details TEXT,
    ip_address VARCHAR(50),
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200),
    message TEXT,
    type ENUM('info','success','warning','urgent') DEFAULT 'info',
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Announcements
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    target_role VARCHAR(50) DEFAULT 'all',
    posted_by INT,
    posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATE NULL
);

-- Insert default admin
INSERT INTO users (username, password, email, role, first_name, last_name, status) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@bcp.edu.ph', 'admin', 'System', 'Administrator', 'active');
-- Default password: 'password'

-- Insert sample programs
INSERT INTO programs (code, name, department, duration_years) VALUES
('BSCS', 'Bachelor of Science in Computer Science', 'College of Computing', 4),
('BSIT', 'Bachelor of Science in Information Technology', 'College of Computing', 4),
('BSN', 'Bachelor of Science in Nursing', 'College of Nursing', 4),
('BSBA', 'Bachelor of Science in Business Administration', 'College of Business', 4),
('BSED', 'Bachelor of Secondary Education', 'College of Education', 4);

-- Sample announcements
INSERT INTO announcements (title, content, target_role, posted_by) VALUES
('Enrollment Period Open', 'Enrollment for 2nd Semester AY 2024-2025 is now open. Please proceed to the registrar office or enroll online.', 'all', 1),
('System Maintenance', 'The system will undergo maintenance on Saturday, 12:00 AM - 6:00 AM.', 'all', 1);

-- =============================================
-- SAMPLE DATA: Subjects & Sections for Enrollment
-- Run this after initial database setup
-- =============================================

-- Sample subjects (BSCS Program)
INSERT IGNORE INTO subjects (code, name, units, lab_units, program_id, year_level, semester) VALUES
('CS101',   'Introduction to Computing',        3, 1, 1, 1, 1),
('CS102',   'Computer Programming 1',           3, 1, 1, 1, 1),
('MATH101', 'Mathematics in the Modern World',  3, 0, 1, 1, 1),
('ENG101',  'Purposive Communication',          3, 0, 1, 1, 1),
('PE101',   'Physical Education 1',             2, 0, 1, 1, 1),
('CS103',   'Computer Programming 2',           3, 1, 1, 1, 2),
('CS104',   'Data Structures and Algorithms',   3, 1, 1, 1, 2),
('MATH102', 'Discrete Mathematics',             3, 0, 1, 1, 2),
('ENG102',  'Technical Writing',                3, 0, 1, 1, 2);

-- Sample open sections
INSERT IGNORE INTO sections (section_code, subject_id, room, day_time, max_students, academic_year, semester, status) VALUES
('BSCS1A-CS101',    1, 'Room 101',    'MWF 7:30-9:00 AM',   40, '2024-2025', 1, 'Open'),
('BSCS1B-CS101',    1, 'Room 102',    'TTH 7:30-9:30 AM',   40, '2024-2025', 1, 'Open'),
('BSCS1A-CS102',    2, 'Lab 201',     'MWF 9:00-10:30 AM',  35, '2024-2025', 1, 'Open'),
('BSCS1B-CS102',    2, 'Lab 202',     'TTH 9:30-11:30 AM',  35, '2024-2025', 1, 'Open'),
('BSCS1A-MATH101',  3, 'Room 103',    'MWF 10:30-12:00 PM', 45, '2024-2025', 1, 'Open'),
('BSCS1A-ENG101',   4, 'Room 104',    'TTH 1:00-2:30 PM',   45, '2024-2025', 1, 'Open'),
('BSCS1A-PE101',    5, 'Gymnasium',   'SAT 7:00-9:00 AM',   50, '2024-2025', 1, 'Open'),
('BSCS1A-CS103',    6, 'Lab 203',     'MWF 7:30-9:00 AM',   35, '2024-2025', 1, 'Open'),
('BSCS1A-MATH102',  8, 'Room 105',    'TTH 10:00-11:30 AM', 40, '2024-2025', 1, 'Open');
