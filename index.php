<?php
require_once 'includes/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active' LIMIT 1");
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            // Check role match if role selector used
            if (!empty($role) && $user['role'] !== $role) {
                $error = 'Invalid role selected for this account.';
            } else {
                // Set session
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['role']       = $user['role'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name']  = $user['last_name'];
                $_SESSION['email']      = $user['email'];

                // Update last login
                $stmt2 = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt2->bind_param('i', $user['id']);
                $stmt2->execute();
                
                logActivity('login', 'Auth', 'User logged in');
                redirect('dashboard.php');
            }
        } else {
            $error = 'Invalid credentials. Please try again.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - BCP University Management System</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
/* ── Full-page background ── */
.login-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
    background: #0f2460;
}

/* background image for login page - place your picture at `bg/login-bg.jpg` */
    .login-bg {
        position: fixed;
        inset: 0;
        /* fallback color in case image is missing */
        background-image: url('bg/bcp.jpg') center center / cover no-repeat;
    z-index: 0;
}

/* Dark gradient overlay for readability */
.login-bg::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        135deg,
        rgba(10, 30, 90, 0.35) 0%,
        rgba(15, 50, 140, 0.25) 50%,
        rgba(5, 20, 70, 0.35) 100%
    );
}

/* ── Centered glassmorphism card ── */
.login-card {
    position: relative;
    z-index: 10;
    width: 100%;
    max-width: 460px;
    margin: 24px;
    background: rgba(255, 255, 255, 0.10);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.20);
    border-radius: 24px;
    padding: 44px 40px;
    box-shadow:
        0 32px 80px rgba(0, 0, 0, 0.45),
        0 0 0 1px rgba(255,255,255,0.08) inset;
    animation: fadeUp .5s ease both;
}

@keyframes fadeUp {
    from { opacity:0; transform:translateY(28px); }
    to   { opacity:1; transform:translateY(0); }
}

/* ── Logo / header ── */
.login-logo-wrap {
    text-align: center;
    margin-bottom: 30px;
}
.login-logo-icon {
    width: 64px; height: 64px;
    background: linear-gradient(135deg, #2563EB, #1D4ED8);
    border-radius: 18px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    font-weight: 900; font-size: 26px; color: white;
    box-shadow: 0 10px 30px rgba(37,99,235,0.5);
}
.login-logo-title {
    font-size: 24px; font-weight: 800; color: #fff;
    letter-spacing: -.3px; margin-bottom: 4px;
}
.login-logo-sub {
    font-size: 13px; color: rgba(255,255,255,0.65);
}

/* ── Form labels & inputs ── */
.login-card .form-label {
    color: rgba(255,255,255,0.85) !important;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    display: block;
}
.login-card .form-control {
    background: rgba(255,255,255,0.12) !important;
    border: 1px solid rgba(255,255,255,0.22) !important;
    color: #fff !important;
    border-radius: 10px !important;
    height: 44px;
    font-size: 14px;
    transition: border .2s, background .2s;
}
.login-card .form-control::placeholder { color: rgba(255,255,255,0.40) !important; }
.login-card .form-control:focus {
    background: rgba(255,255,255,0.18) !important;
    border-color: rgba(99,149,255,0.8) !important;
    outline: none;
    box-shadow: 0 0 0 3px rgba(99,149,255,0.20) !important;
}
.login-card select.form-control option { background: #1e3a8a; color: #fff; }

/* ── Password toggle ── */
.pw-toggle {
    position: absolute; right: 12px; top: 50%;
    transform: translateY(-50%);
    cursor: pointer; color: rgba(255,255,255,0.55);
    background: none; border: none; font-size: 16px;
}
.pw-toggle:hover { color: rgba(255,255,255,0.9); }
.form-control-wrap { position: relative; }

/* ── Sign in button ── */
.login-card .btn-primary {
    background: linear-gradient(135deg, #2563EB, #1D4ED8) !important;
    border: none !important;
    border-radius: 10px !important;
    height: 46px;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: .3px;
    box-shadow: 0 6px 20px rgba(37,99,235,0.45);
    transition: transform .15s, box-shadow .15s;
}
.login-card .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 28px rgba(37,99,235,0.55);
}

/* ── Alert ── */
.login-card .alert-danger {
    background: rgba(239,68,68,0.18);
    border: 1px solid rgba(239,68,68,0.35);
    color: #fca5a5;
    border-radius: 10px;
    font-size: 13px;
    padding: 10px 14px;
    margin-bottom: 16px;
}

/* ── Footer note ── */
.login-footer-note {
    text-align: center;
    margin-top: 18px;
    font-size: 12px;
    color: rgba(255,255,255,0.45);
    line-height: 1.7;
}
.login-footer-note strong { color: rgba(255,255,255,0.7); }

/* ── School name watermark at bottom ── */
.login-watermark {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    z-index: 5;
    text-align: center;
    padding: 16px;
    background: linear-gradient(to top, rgba(0,0,0,0.55), transparent);
    color: rgba(255,255,255,0.55);
    font-size: 12px;
    letter-spacing: .5px;
}

/* ── Responsive ── */
@media(max-width:480px){
    .login-card { padding: 32px 24px; margin: 16px; }
}
</style>
</head>
<body>

<!-- Background layer -->
<div class="login-bg"></div>

<!-- Centered login card -->
<div class="login-page">
    <div class="login-card">

        <!-- Logo / Header -->
        <div class="login-logo-wrap">
            <div class="login-logo-icon">B</div>
            <div class="login-logo-title">🎓 BCP-UMS</div>
            <div class="login-logo-sub">BCP University Management System<br>Sign in to your account</div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger">⚠️ <?= escape($error) ?></div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">Username or Email</label>
                <input type="text" name="username" class="form-control"
                       placeholder="Enter your username or email" required
                       value="<?= escape($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="form-control-wrap">
                    <input type="password" name="password" class="form-control"
                           placeholder="Enter your password" required
                           id="loginPw" style="padding-right:42px;">
                    <button type="button" class="pw-toggle" onclick="togglePw('loginPw')">&#128065;</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Login As</label>
                <select name="role" class="form-control">
                    <option value="">-- Any Role (Auto-detect) --</option>
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                    <option value="registrar">Registrar</option>
                    <option value="accounting">Accounting</option>
                    <option value="admin">Administrator</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-full btn-lg"
                    style="margin-top:10px;justify-content:center;width:100%;">
                Sign In &rarr;
            </button>
        </form>

        

    </div>
</div>

<!-- Bottom watermark -->
<div class="login-watermark">
    © <?= date('Y') ?> BCP University · All Rights Reserved
</div>

<script>
function togglePw(id) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>