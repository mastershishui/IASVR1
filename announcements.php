<?php
$pageTitle = 'Announcements';
require_once 'includes/config.php';
requireLogin();
requireRole(['admin','registrar']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'post') {
        $title   = trim($_POST['title']);
        $content = trim($_POST['content']);
        $target  = $_POST['target_role'];
        $expires = $_POST['expires_at'] ?: null;
        $uid     = $_SESSION['user_id'];
        $stmt    = $db->prepare("INSERT INTO announcements (title,content,target_role,posted_by,expires_at) VALUES (?,?,?,?,?)");
        $stmt->bind_param('sssis',$title,$content,$target,$uid,$expires);
        $stmt->execute();
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Announcement posted.'];
        redirect('announcements.php');
    }
    if ($action === 'delete') {
        $id = (int)$_POST['ann_id'];
        $db->query("DELETE FROM announcements WHERE id=$id");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Announcement deleted.'];
        redirect('announcements.php');
    }
}

$anns = $db->query("SELECT a.*, u.first_name, u.last_name FROM announcements a LEFT JOIN users u ON a.posted_by=u.id ORDER BY a.posted_at DESC")->fetch_all(MYSQLI_ASSOC);

require_once 'includes/header.php';
?>
<div class="page-header">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">Announcements</h1>
            <p class="page-subtitle">Post and manage system-wide announcements for students, faculty, and staff.</p>
        </div>
        <button class="btn btn-primary" data-modal="modal-post">+ Post Announcement</button>
    </div>
</div>

<div class="card">
    <div class="card-header"><span class="card-title">All Announcements</span></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Title</th><th>Target</th><th>Posted By</th><th>Date</th><th>Expires</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($anns as $ann): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= escape($ann['title']) ?></div>
                        <div style="font-size:12px;color:var(--text-muted);"><?= escape(substr($ann['content'],0,60)) ?>...</div>
                    </td>
                    <td><span class="badge badge-primary"><?= ucfirst($ann['target_role']) ?></span></td>
                    <td><?= escape($ann['first_name'].' '.$ann['last_name']) ?></td>
                    <td style="font-size:12px;"><?= date('M d, Y', strtotime($ann['posted_at'])) ?></td>
                    <td style="font-size:12px;"><?= $ann['expires_at'] ? date('M d, Y', strtotime($ann['expires_at'])) : '—' ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete this announcement?">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="modal-post">
    <div class="modal" style="max-width:540px;">
        <div class="modal-header"><h3 class="modal-title">Post Announcement</h3><button class="modal-close">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="post">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required placeholder="Announcement title"></div>
                <div class="form-group"><label class="form-label">Content *</label><textarea name="content" class="form-control" rows="4" required placeholder="Write the announcement here..."></textarea></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Target Audience</label>
                        <select name="target_role" class="form-control">
                            <option value="all">All Users</option>
                            <option value="student">Students Only</option>
                            <option value="faculty">Faculty Only</option>
                            <option value="registrar">Registrar Only</option>
                            <option value="accounting">Accounting Only</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Expires On (Optional)</label><input type="date" name="expires_at" class="form-control" min="<?= date('Y-m-d') ?>"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Post</button>
            </div>
        </form>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>
