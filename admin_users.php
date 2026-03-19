<?php
// admin_users.php - USER MANAGEMENT WITH PREMIUM UI/UX
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) { header('location:login.php'); exit; }

$message = [];

// --- 1. ADD NEW USER ---
if (isset($_POST['add_user'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $pass = mysqli_real_escape_string($conn, md5($_POST['password'])); // Assuming you use MD5 for login
    $user_type = mysqli_real_escape_string($conn, $_POST['user_type']);

    $check_email = mysqli_query($conn, "SELECT email FROM `users` WHERE email = '$email'") or die('query failed');

    if (mysqli_num_rows($check_email) > 0) {
        $message[] = "❌ Error: Email already exists!";
    } else {
        mysqli_query($conn, "INSERT INTO `users` (name, email, password, user_type) VALUES ('$name', '$email', '$pass', '$user_type')") or die('query failed');
        header('location:admin_users.php?msg=added'); exit;
    }
}

// --- 2. UPDATE USER ---
if (isset($_POST['update_user'])) {
    $update_id = $_POST['user_id'];
    $update_name = mysqli_real_escape_string($conn, $_POST['name']);
    $update_email = mysqli_real_escape_string($conn, $_POST['email']);
    $update_type = mysqli_real_escape_string($conn, $_POST['user_type']);

    mysqli_query($conn, "UPDATE `users` SET name = '$update_name', email = '$update_email', user_type = '$update_type' WHERE id = '$update_id'") or die('query failed');
    header('location:admin_users.php?msg=updated'); exit;
}

// --- 3. DELETE USER ---
if (isset($_GET['delete'])) {
    $delete_id = $_GET['delete'];
    // Protection: Admin cannot delete themselves
    if ($delete_id == $admin_id) {
        header('location:admin_users.php?msg=self_delete'); exit;
    } else {
        mysqli_query($conn, "DELETE FROM `users` WHERE id = '$delete_id'") or die('query failed');
        header('location:admin_users.php?msg=deleted'); exit;
    }
}

// --- 4. SEARCH & FILTER ---
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';

$where_clause = "WHERE 1=1";
if ($search) {
    $search = mysqli_real_escape_string($conn, $search);
    $where_clause .= " AND (name LIKE '%$search%' OR email LIKE '%$search%')";
}
if ($role_filter) {
    $role_filter = mysqli_real_escape_string($conn, $role_filter);
    $where_clause .= " AND user_type = '$role_filter'";
}

// --- 5. FETCH STATS ---
$total_users = mysqli_query($conn, "SELECT COUNT(*) as c FROM `users`")->fetch_assoc()['c'];
$total_admins = mysqli_query($conn, "SELECT COUNT(*) as c FROM `users` WHERE user_type = 'admin'")->fetch_assoc()['c'];
$total_staff = mysqli_query($conn, "SELECT COUNT(*) as c FROM `users` WHERE user_type != 'admin'")->fetch_assoc()['c'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management | Trishe ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --primary: #4f46e5; --primary-hover: #4338ca;
            --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --border: #e2e8f0; 
            --success: #10b981; --danger: #ef4444; --warning: #f59e0b;
            --radius: 12px; --shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        * { box-sizing: border-box; margin:0; padding:0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); padding-left: 260px; padding-bottom: 60px; }
        
        .container {  margin: 0 auto; padding: 20px; }

        /* Header & Alerts */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: var(--card); padding: 15px 20px; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); }
        .page-title { font-size: 1.3rem; font-weight: 700; display:flex; align-items:center; gap:10px; margin:0; }
        
        .alert { padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 0.9rem; display:flex; align-items:center; gap:10px;}
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: var(--card); padding: 20px; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); border-left: 4px solid var(--primary); }
        .stat-val { font-size: 1.8rem; font-weight: 800; color: var(--text); }
        .stat-lbl { font-size: 0.8rem; font-weight: 600; color: #64748b; text-transform: uppercase; margin-top:5px; }

        /* Toolbar */
        .toolbar { display: flex; justify-content: space-between; gap: 10px; background: #fff; padding: 15px; border-radius: var(--radius); border: 1px solid var(--border); margin-bottom: 20px; flex-wrap: wrap;}
        .filter-group { display: flex; gap: 10px; flex: 1; min-width: 300px;}
        .form-input { padding: 10px 15px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; outline: none; flex:1; }
        .form-input:focus { border-color: var(--primary); }
        
        .btn { padding: 10px 18px; border: none; border-radius: 6px; font-weight: 600; font-size: 0.9rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: 0.2s; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-danger { background: #fee2e2; color: #b91c1c; }
        .btn-danger:hover { background: #fca5a5; }
        .btn-edit { background: #e0e7ff; color: #4338ca; }

        /* Table Styles */
        .table-card { background: var(--card); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; text-align: left; }
        th { background: #f8fafc; padding: 15px; font-weight: 600; color: #64748b; border-bottom: 1px solid var(--border); text-transform: uppercase; font-size:0.8rem; letter-spacing:0.5px;}
        td { padding: 15px; border-bottom: 1px solid var(--border); color: #334155; vertical-align: middle; }
        tr:hover { background: #f8fafc; }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .role-admin { background: #ffedd5; color: #c2410c; }
        .role-user { background: #e0e7ff; color: #4338ca; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background: #e2e8f0; display:flex; align-items:center; justify-content:center; font-weight:bold; color:#475569; margin-right:12px; font-size:1.1rem;}

        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .modal.active { display: flex; animation: fadeIn 0.2s; }
        .modal-content { background: #fff; width: 90%; max-width: 400px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); overflow: hidden; }
        .modal-header { padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-weight: 700; }
        .modal-body { padding: 20px; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-size: 0.85rem; font-weight: 600; color: #475569; }

        @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

        /* Mobile Responsive */
        @media (max-width: 1024px) { body { padding-left: 0; } }
        @media (max-width: 768px) {
            .toolbar { flex-direction: column; }
            .filter-group { flex-direction: column; min-width: 100%; }
            
            table, thead, tbody, th, td, tr { display: block; width: 100%; }
            thead { display: none; }
            tr { margin-bottom: 15px; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fff; }
            td { display: flex; justify-content: space-between; align-items: center; padding: 10px 5px; border-bottom: 1px dashed var(--border); text-align: right; }
            td:last-child { border-bottom: none; display: flex; justify-content: flex-end; gap:5px; padding-top:15px;}
            td::before { content: attr(data-label); font-weight: 700; color: #64748b; font-size: 0.75rem; text-transform: uppercase; text-align: left; }
            td:last-child::before { display: none; }
            .td-user { flex-direction: row; justify-content: flex-end; }
        }
    </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="container">
    
    <?php 
    if(isset($_GET['msg'])){
        if($_GET['msg'] == 'added') echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> New user added successfully!</div>';
        if($_GET['msg'] == 'updated') echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> User details updated!</div>';
        if($_GET['msg'] == 'deleted') echo '<div class="alert alert-success"><i class="fas fa-trash"></i> User deleted successfully!</div>';
        if($_GET['msg'] == 'self_delete') echo '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> You cannot delete your own active account!</div>';
    }
    foreach($message as $msg){
        echo '<div class="alert alert-danger">'.$msg.'</div>';
    }
    ?>

    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-users-cog text-primary"></i> User Management</h1>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-val"><?= $total_users ?></div>
            <div class="stat-lbl">Total Accounts</div>
        </div>
        <div class="stat-card" style="border-left-color: #f59e0b;">
            <div class="stat-val" style="color: #d97706;"><?= $total_admins ?></div>
            <div class="stat-lbl">Administrators</div>
        </div>
        <div class="stat-card" style="border-left-color: #10b981;">
            <div class="stat-val" style="color: #059669;"><?= $total_staff ?></div>
            <div class="stat-lbl">Staff / Users</div>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" class="filter-group">
            <input type="text" name="search" class="form-input" placeholder="Search name or email..." value="<?= htmlspecialchars($search) ?>">
            <select name="role" class="form-input" style="flex: 0.5;">
                <option value="">All Roles</option>
                <option value="admin" <?= $role_filter=='admin' ? 'selected' : '' ?>>Admin</option>
                <option value="user" <?= $role_filter=='user' ? 'selected' : '' ?>>User/Staff</option>
            </select>
            <button type="submit" class="btn btn-primary" style="width:auto;"><i class="fas fa-search"></i></button>
            <?php if($search || $role_filter): ?>
                <a href="admin_users.php" class="btn btn-danger" style="width:auto;"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
        <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-user-plus"></i> Add New User</button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>User Details</th>
                    <th>Email Address</th>
                    <th>Role</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $select_users = mysqli_query($conn, "SELECT * FROM `users` $where_clause ORDER BY id DESC") or die('query failed');
                if(mysqli_num_rows($select_users) > 0){
                    while($row = mysqli_fetch_assoc($select_users)){
                        $role_class = ($row['user_type'] == 'admin') ? 'role-admin' : 'role-user';
                ?>
                <tr>
                    <td data-label="User" class="td-user">
                        <div style="display:flex; align-items:center;">
                            <div class="avatar"><?= strtoupper(substr($row['name'], 0, 1)) ?></div>
                            <div>
                                <strong style="color:#0f172a; display:block; font-size:1rem;"><?= htmlspecialchars($row['name']) ?></strong>
                                <small style="color:#94a3b8;">ID: #<?= $row['id'] ?></small>
                            </div>
                        </div>
                    </td>
                    <td data-label="Email"><?= htmlspecialchars($row['email']) ?></td>
                    <td data-label="Role"><span class="badge <?= $role_class ?>"><?= $row['user_type'] ?></span></td>
                    <td data-label="Actions" style="text-align:right;">
                        <button class="btn btn-edit" onclick="openEditModal(<?= $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['name'])) ?>', '<?= addslashes(htmlspecialchars($row['email'])) ?>', '<?= $row['user_type'] ?>')">
                            <i class="fas fa-edit"></i>
                        </button>
                        
                        <?php if($row['id'] == $admin_id): ?>
                            <button class="btn btn-danger" style="opacity:0.5; cursor:not-allowed;" title="You cannot delete yourself"><i class="fas fa-trash"></i></button>
                        <?php else: ?>
                            <a href="admin_users.php?delete=<?= $row['id'] ?>" onclick="return confirm('Are you sure you want to delete this user?');" class="btn btn-danger"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php 
                    }
                } else {
                    echo '<tr><td colspan="4" style="text-align:center; padding:30px; color:#94a3b8;">No users found!</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span><i class="fas fa-user-plus"></i> Add New User</span>
            <button class="close-btn" onclick="document.getElementById('addModal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST" class="modal-body">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" class="form-input" required placeholder="Enter name">
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="form-input" required placeholder="Enter email">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-input" required placeholder="Create password">
            </div>
            <div class="form-group">
                <label>User Role</label>
                <select name="user_type" class="form-input" required>
                    <option value="user">Staff / User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <button type="submit" name="add_user" class="btn btn-primary" style="width:100%;">Create Account</button>
        </form>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span><i class="fas fa-user-edit"></i> Edit User</span>
            <button class="close-btn" onclick="document.getElementById('editModal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="user_id" id="edit_id">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" id="edit_name" class="form-input" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" id="edit_email" class="form-input" required>
            </div>
            <div class="form-group">
                <label>User Role</label>
                <select name="user_type" id="edit_role" class="form-input" required>
                    <option value="user">Staff / User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <button type="submit" name="update_user" class="btn btn-primary" style="width:100%;">Save Changes</button>
        </form>
    </div>
</div>

<script>
    // Remove URL parameters for clean reload
    if(window.history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.delete('msg');
        window.history.replaceState(null, '', url);
    }

    function openAddModal() {
        document.getElementById('addModal').classList.add('active');
    }

    function openEditModal(id, name, email, role) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_email').value = email;
        document.getElementById('edit_role').value = role;
        document.getElementById('editModal').classList.add('active');
    }

    // Close modals if clicked outside
    window.onclick = function(event) {
        const addM = document.getElementById('addModal');
        const editM = document.getElementById('editModal');
        if (event.target == addM) addM.classList.remove('active');
        if (event.target == editM) editM.classList.remove('active');
    }
</script>

</body>
</html>