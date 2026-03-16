<?php
$pageTitle = 'My Profile';
require_once 'includes/config.php';
requireLogin();
$db = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $fname = trim($_POST['first_name']); $lname = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $stmt  = $db->prepare("UPDATE users SET first_name=?,last_name=?,email=? WHERE id=?");
        $stmt->bind_param('sssi',$fname,$lname,$email,$user['user_id']);
        $stmt->execute();
        $_SESSION['first_name'] = $fname;
        $_SESSION['last_name']  = $lname;
        $_SESSION['email']      = $email;
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Profile updated.'];
        redirect('profile.php');
    }
    
    if ($action === 'change_password') {
        $cur = $_POST['current_password'];
        $new = $_POST['new_password'];
        $con = $_POST['confirm_password'];
        
        $dbUser = $db->query("SELECT password FROM users WHERE id={$user['user_id']}")->fetch_assoc();
        if (!password_verify($cur, $dbUser['password'])) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Current password is incorrect.'];
        } elseif ($new !== $con) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'New passwords do not match.'];
        } elseif (strlen($new) < 6) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Password must be at least 6 characters.'];
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->bind_param('si',$hashed,$user['user_id']);
            $stmt->execute();
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Password changed successfully.'];
        }
        redirect('profile.php');
    }
}

$dbUser = $db->query("SELECT * FROM users WHERE id={$user['user_id']}")->fetch_assoc();
$initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">My Profile</h1>
    <p class="page-subtitle">View and update your account information.</p>
</div>

<div class="grid-2">
    <!-- Profile Card -->
    <div class="card">
        <div class="card-header"><span class="card-title">Account Information</span></div>
        <div class="card-body">
            <!-- Avatar -->
            <div style="text-align:center;margin-bottom:24px;">
                <div style="width:80px;height:80px;border-radius:50%;background:var(--primary);color:white;font-weight:800;font-size:28px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;box-shadow:0 8px 24px rgba(37,99,235,0.25);">
                    <?= $initials ?>
                </div>
                <div style="font-size:18px;font-weight:700;"><?= escape($user['first_name'].' '.$user['last_name']) ?></div>
                <div>
                    <?php
                    $roleColors = ['admin'=>'danger','registrar'=>'purple','faculty'=>'primary','student'=>'success','accounting'=>'warning'];
                    $rc = $roleColors[$user['role']] ?? 'secondary';
                    ?>
                    <span class="badge badge-<?= $rc ?>" style="margin-top:4px;"><?= ucfirst($user['role']) ?></span>
                </div>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control" required value="<?= escape($dbUser['first_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required value="<?= escape($dbUser['last_name']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-control" required value="<?= escape($dbUser['email']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" value="<?= escape($dbUser['username']) ?>" readonly style="background:var(--bg);color:var(--text-muted);">
                </div>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <input type="text" class="form-control" value="<?= ucfirst($dbUser['role']) ?>" readonly style="background:var(--bg);color:var(--text-muted);">
                </div>
                <div class="form-group">
                    <label class="form-label">Account Status</label>
                    <input type="text" class="form-control" value="<?= ucfirst($dbUser['status']) ?>" readonly style="background:var(--bg);color:var(--text-muted);">
                </div>
                <div class="form-group">
                    <label class="form-label">Last Login</label>
                    <input type="text" class="form-control" value="<?= $dbUser['last_login'] ? date('M d, Y h:i A', strtotime($dbUser['last_login'])) : 'First Login' ?>" readonly style="background:var(--bg);color:var(--text-muted);">
                </div>
                <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Save Changes</button>
            </form>
        </div>
    </div>
    
    <!-- Change Password -->
    <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="card">
            <div class="card-header"><span class="card-title">Change Password</span></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label class="form-label">Current Password *</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password *</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6" placeholder="Min. 6 characters">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-outline w-full" style="justify-content:center;">Change Password</button>
                </form>
            </div>
        </div>
        
        <!-- System Info -->
        <div class="card">
            <div class="card-header"><span class="card-title">System Info</span></div>
            <div class="card-body">
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <div style="display:flex;justify-content:space-between;padding-bottom:10px;border-bottom:1px solid var(--border);">
                        <span style="font-size:13px;color:var(--text-muted);">System</span>
                        <span style="font-size:13px;font-weight:600;">BCP-UMS v1.0</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding-bottom:10px;border-bottom:1px solid var(--border);">
                        <span style="font-size:13px;color:var(--text-muted);">Academic Year</span>
                        <span style="font-size:13px;font-weight:600;"><?= ACADEMIC_YEAR ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding-bottom:10px;border-bottom:1px solid var(--border);">
                        <span style="font-size:13px;color:var(--text-muted);">Semester</span>
                        <span style="font-size:13px;font-weight:600;"><?= CURRENT_SEMESTER ?>st Semester</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="font-size:13px;color:var(--text-muted);">Institution</span>
                        <span style="font-size:13px;font-weight:600;">BCP University</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
