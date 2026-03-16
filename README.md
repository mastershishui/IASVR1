# BCP University Management System (BCP-UMS)
## Setup Instructions

### Requirements
- PHP 7.4+ (PHP 8.x recommended)
- MySQL 5.7+ or MariaDB 10.3+
- Web server: Apache (XAMPP/WAMP/Laragon) or Nginx

### Installation Steps

1. **Copy files** to your web server's document root:
   - XAMPP: `C:/xampp/htdocs/bcpums/`
   - Laragon: `C:/laragon/www/bcpums/`

2. **Import the database**:
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Create a new database called `bcp_ums`
   - Click "Import" and upload `database.sql`

3. **Configure database** in `includes/config.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');   // your MySQL username
   define('DB_PASS', '');       // your MySQL password
   define('DB_NAME', 'bcp_ums');
   ```

4. **Access the system**:
   - URL: `http://localhost/bcpums/`
   - Default Admin Login: `admin` / `password`

### Default Login Credentials
| Role | Username | Password |
|------|----------|----------|
| Admin | admin | password |

### User Account Flow
1. **Students/Faculty** can register at the login page
2. **Admin** activates accounts in User Management
3. **Admin** can also directly create accounts for any role

### Module Pages
- `index.php` — Login / Register
- `dashboard.php` — Role-based dashboard
- `users.php` — Admin: User management
- `student_records.php` — Registrar: Student profiles
- `enrollment_queue.php` — Registrar: Enrollment approval
- `class_schedule.php` — Class sections management
- `grade_encoding.php` — Faculty/Registrar: Grades
- `billing.php` — Accounting/Student: Billing & payments
- `documents.php` — Document requests
- `announcements.php` — Post announcements
- `profile.php` — User profile settings

### System Sub-systems (from BCP-UMS spec)
1. Student Information Management
2. Enrollment & Registration
4. Class Scheduling & Section Management
5. Grades & Assessment Management
6. Payment & Accounting
7. Document & Credentials
8. Human Resource Management
10. User Management

### Tech Stack
- **Backend**: PHP (procedural with PDO-compatible mysqli)
- **Database**: MySQL
- **Frontend**: Vanilla CSS + JavaScript (no frameworks)
- **Fonts**: Google Fonts (DM Sans, DM Mono)
- **Design**: Custom USIS-inspired dashboard UI

### Customizing Login Background
The login page uses a background image located at `bg/login-bg.jpg` by default. To change it:

1. Drop your preferred picture file into the `bg` folder and name it `login-bg.jpg` (or update the CSS path in `index.php`).
2. The CSS includes a fallback color (`#0f2460`) in case the image is missing.
3. You can also edit the inline `<style>` block in `index.php` if you want a different path or additional styling.
