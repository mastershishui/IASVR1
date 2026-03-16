<?php
$pageTitle = 'User Management';
require_once 'includes/config.php';
requireLogin();
requireRole('admin');
$db = getDB();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_user') {
        $fname = trim($_POST['first_name']); $lname = trim($_POST['last_name']);
        $email = trim($_POST['email']); $uname = trim($_POST['username']);
        $pass  = $_POST['password']; $role = $_POST['role'];
        $hashed = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username,password,email,role,first_name,last_name,status,created_by) VALUES (?,?,?,?,?,?,'active',?)");
        $uid = $_SESSION['user_id'];
        $stmt->bind_param('ssssssi', $uname,$hashed,$email,$role,$fname,$lname,$uid);
        if ($stmt->execute()) {
            $_SESSION['flash'] = ['type'=>'success','msg'=>'User created successfully!'];
            logActivity('create_user','User Management',"Created user: $uname ($role)");
        } else {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Error: '.$db->error];
        }
        redirect('users.php');
    }
    
    if ($action === 'update_status') {
        $uid = (int)$_POST['user_id']; $status = $_POST['status'];
        $stmt = $db->prepare("UPDATE users SET status=? WHERE id=?");
        $stmt->bind_param('si', $status, $uid);
        $stmt->execute();
        $_SESSION['flash'] = ['type'=>'success','msg'=>'User status updated.'];
        logActivity('update_status','User Management',"User #$uid status → $status");
        redirect('users.php');
    }
    
    if ($action === 'delete_user') {
        $uid = (int)$_POST['user_id'];
        $stmt = $db->prepare("DELETE FROM users WHERE id=? AND id != 1");
        $stmt->bind_param('i',$uid);
        $stmt->execute();
        $_SESSION['flash'] = ['type'=>'success','msg'=>'User deleted.'];
        redirect('users.php');
    }
}

$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = $_GET['q'] ?? '';

$where = "WHERE 1=1";
$params = [];
if ($filterRole) { $where .= " AND role='".escape($filterRole)."'"; }
if ($filterStatus) { $where .= " AND status='".escape($filterStatus)."'"; }
if ($search) { $where .= " AND (username LIKE '%".escape($search)."%' OR first_name LIKE '%".escape($search)."%' OR last_name LIKE '%".escape($search)."%' OR email LIKE '%".escape($search)."%')"; }

$users = $db->query("SELECT * FROM users $where ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$counts = $db->query("SELECT role, COUNT(*) as c FROM users GROUP BY role")->fetch_all(MYSQLI_ASSOC);
$countMap = [];
foreach ($counts as $c) $countMap[$c['role']] = $c['c'];

require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">User Management</h1>
            <p class="page-subtitle">Create and manage all system users — students, faculty, registrar, accounting staff.</p>
        </div>
        <button class="btn btn-primary" data-modal="modal-create-user">+ Create User</button>
    </div>
</div>

<!-- Role Filter -->
<div class="flex gap-2 mb-4" style="flex-wrap:wrap;">
    <?php
    $roles = ['all','admin','registrar','faculty','student','accounting'];
    foreach ($roles as $r):
    $cnt = $r==='all' ? array_sum(array_column($counts,'c')) : ($countMap[$r]??0);
    ?>
    <a href="?role=<?= $r==='all'?'':$r ?>&status=<?= $filterStatus ?>&q=<?= urlencode($search) ?>" 
       class="badge <?= ($filterRole===$r || ($r==='all'&&!$filterRole)) ? 'badge-primary' : 'badge-secondary' ?>"
       style="padding:6px 14px;font-size:12px;cursor:pointer;text-decoration:none;">
        <?= ucfirst($r) ?> (<?= $cnt ?>)
    </a>
    <?php endforeach; ?>
</div>

<!-- Search & Filters -->
<div class="card mb-4">
    <div class="card-body" style="padding:16px 20px;">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Name, username, email..." value="<?= escape($search) ?>">
            </div>
            <div style="min-width:140px;">
                <label class="form-label">Role</label>
                <select name="role" class="form-control">
                    <option value="">All Roles</option>
                    <option value="admin" <?= $filterRole==='admin'?'selected':'' ?>>Admin</option>
                    <option value="registrar" <?= $filterRole==='registrar'?'selected':'' ?>>Registrar</option>
                    <option value="faculty" <?= $filterRole==='faculty'?'selected':'' ?>>Faculty</option>
                    <option value="student" <?= $filterRole==='student'?'selected':'' ?>>Student</option>
                    <option value="accounting" <?= $filterRole==='accounting'?'selected':'' ?>>Accounting</option>
                </select>
            </div>
            <div style="min-width:140px;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Active</option>
                    <option value="pending" <?= $filterStatus==='pending'?'selected':'' ?>>Pending</option>
                    <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="users.php" class="btn btn-outline">Clear</a>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Users (<?= count($users) ?>)</span>
        <span class="text-muted text-sm"><?= date('M d, Y h:i A') ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No users found.</td></tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr class="searchable-row">
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="avatar-initials" style="width:34px;height:34px;font-size:12px;flex-shrink:0;">
                                <?= strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1)) ?>
                            </div>
                            <div>
                                <div style="font-weight:600;"><?= escape($u['first_name'].' '.$u['last_name']) ?></div>
                                <div style="font-size:11px;color:var(--text-muted);">#<?= $u['id'] ?></div>
                            </div>
                        </div>
                    </td>
                    <td><code style="font-size:12.5px;"><?= escape($u['username']) ?></code></td>
                    <td style="font-size:13px;"><?= escape($u['email']) ?></td>
                    <td>
                        <?php
                        $roleColors = ['admin'=>'danger','registrar'=>'purple','faculty'=>'primary','student'=>'success','accounting'=>'warning'];
                        $rc = $roleColors[$u['role']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $rc ?>"><?= ucfirst($u['role']) ?></span>
                    </td>
                    <td>
                        <?php
                        $sc = ['active'=>'success','pending'=>'warning','inactive'=>'secondary'][$u['status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $sc ?>"><?= ucfirst($u['status']) ?></span>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <?php if ($u['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="status" value="active">
                                <button class="btn btn-sm btn-success" data-confirm="Activate this user?" type="submit">✓ Activate</button>
                            </form>
                            <?php elseif ($u['status'] === 'active'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="status" value="inactive">
                                <button class="btn btn-sm btn-outline" data-confirm="Deactivate this user?" type="submit">Deactivate</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($u['id'] != 1): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button class="btn btn-sm btn-danger" data-confirm="Delete user <?= escape($u['username']) ?>? This cannot be undone." type="submit">Delete</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal-overlay" id="modal-create-user">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h3 class="modal-title">Create New User</h3>
            <button class="modal-close">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            <div class="modal-body">
                <div class="alert alert-info">
                    📌 Admin can create accounts for any role. Students & Faculty can also self-register (pending activation).
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control" required placeholder="Juan">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required placeholder="Dela Cruz">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-control" required placeholder="juan@bcp.edu.ph">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-control" required placeholder="jdelacruz">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role *</label>
                        <select name="role" class="form-control" required>
                            <option value="">Select Role</option>
                            <option value="student">Student</option>
                            <option value="faculty">Faculty</option>
                            <option value="registrar">Registrar</option>
                            <option value="accounting">Accounting</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" required placeholder="Min. 6 characters" minlength="6">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
