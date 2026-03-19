<?php
// admin_customers.php - FIXED SALES DUE LOGIC (IGNORES CASH/UPI)
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$messages = [];
$errors = [];

// ==========================================
// 1. AJAX: FETCH HISTORIES (LEDGER, SALES, JOBS)
// ==========================================
if (isset($_GET['action'])) {
    $cid = intval($_GET['customer_id']);
    
    if ($_GET['action'] == 'get_ledger') {
        $timeline = [];
        $q_sales = $conn->query("SELECT 'sale' as type, order_no as ref, created_at as date, total as amount, payment_method as status FROM orders WHERE customer_id=$cid");
        if($q_sales) while($r = $q_sales->fetch_assoc()) $timeline[] = $r;

        $q_serv = $conn->query("SELECT 'service' as type, id as ref, service_date as date, total_amount as amount, status FROM service_orders WHERE user_id=$cid");
        if($q_serv) while($r = $q_serv->fetch_assoc()) $timeline[] = $r;

        $q_pay = $conn->query("SELECT 'payment' as type, id as ref, created_at as date, amount, payment_mode as status FROM customer_payments WHERE customer_id=$cid");
        if($q_pay) while($r = $q_pay->fetch_assoc()) $timeline[] = $r;

        usort($timeline, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
        echo json_encode($timeline); exit;
    }
    
    if ($_GET['action'] == 'get_sales') {
        $q = $conn->query("SELECT id, order_no, created_at, total, payment_method, payment_status, status FROM orders WHERE customer_id=$cid ORDER BY created_at DESC LIMIT 50");
        $data = [];
        if($q) while($r = $q->fetch_assoc()) $data[] = $r;
        echo json_encode($data); exit;
    }
    
    if ($_GET['action'] == 'get_jobs') {
        $q = $conn->query("SELECT id, seed_type, weight_kg, total_amount, payment_status, status, service_date FROM service_orders WHERE user_id=$cid ORDER BY service_date DESC LIMIT 50");
        $data = [];
        if($q) while($r = $q->fetch_assoc()) $data[] = $r;
        echo json_encode($data); exit;
    }
}

// ==========================================
// 2. HANDLE FORM ACTIONS (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['add_customer'])) {
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']) ?: null;
        $address = trim($_POST['address']);
        $opening_due = floatval($_POST['opening_due'] ?? 0);
        
        if ($name && preg_match('/^[0-9]{10}$/', $phone)) {
            $check = $conn->prepare("SELECT id FROM customers WHERE phone = ?");
            $check->bind_param("s", $phone);
            $check->execute();
            
            if ($check->get_result()->num_rows === 0) {
                $stmt = $conn->prepare("INSERT INTO customers (name, phone, email, address, total_due, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                $stmt->bind_param("ssssd", $name, $phone, $email, $address, $opening_due);
                
                if($stmt->execute()) {
                    $customer_id = $conn->insert_id;
                    $conn->query("INSERT INTO customer_loyalty (customer_id, points, tier, joined_date) VALUES ($customer_id, 100, 'Bronze', NOW())");
                    header("Location: admin_customers.php?msg=CustomerAdded"); exit;
                } else { $errors[] = "Error adding customer: " . $conn->error; }
            } else { $errors[] = "❌ Phone number already exists!"; }
        } else { $errors[] = "❌ Valid name & 10-digit phone required!"; }
    }
    
    // --- SMART RECEIVE PAYMENT (FIFO LOGIC) ---
    if (isset($_POST['receive_payment'])) {
        $cid = intval($_POST['customer_id']);
        $amount = floatval($_POST['amount']);
        $mode = $_POST['payment_mode'];
        $note = trim($_POST['note']);
        $tins = intval($_POST['empty_tins']); 

        $conn->begin_transaction();
        try {
            if($amount > 0) {
                $stmt = $conn->prepare("INSERT INTO customer_payments (customer_id, amount, payment_mode, note) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("idss", $cid, $amount, $mode, $note);
                $stmt->execute();
                $rem_amount = $amount;

                // 1. Clear Job Works
                $s_jobs = $conn->query("SELECT id, total_amount FROM service_orders WHERE user_id = $cid AND payment_status IN ('Pending', 'pending', 'Unpaid', 'unpaid', '') ORDER BY service_date ASC");
                if($s_jobs) {
                    while ($job = $s_jobs->fetch_assoc()) {
                        if ($rem_amount <= 0) break;
                        $j_id = $job['id']; 
                        $j_total = (float)$job['total_amount'];
                        if ($j_total > 0 && $rem_amount >= $j_total) {
                            $conn->query("UPDATE service_orders SET payment_status = 'Paid' WHERE id = $j_id");
                            $rem_amount -= $j_total;
                        }
                    }
                }

               // 3. Clear Sales (Virtual Column Fix)
                if ($rem_amount > 0) {
                    $s_orders = $conn->query("SELECT id, due_amount, total, paid_amount FROM orders WHERE customer_id = $cid AND payment_status != 'Paid' AND payment_method = 'Credit' ORDER BY created_at ASC");
                    
                    if($s_orders) {
                        while ($order = $s_orders->fetch_assoc()) {
                            if ($rem_amount <= 0) break;
                            
                            $o_id = $order['id']; 
                            $o_due = (float)$order['due_amount'];
                            
                            if ($o_due <= 0) continue; 

                            if ($rem_amount >= $o_due) {
                                // Full clear: update paid_amount to total, and status to Paid
                                $conn->query("UPDATE orders SET payment_status = 'Paid', paid_amount = total WHERE id = $o_id");
                                $rem_amount -= $o_due;
                            } else {
                                // Partial clear: Just add to paid_amount and set status to Partial
                                $conn->query("UPDATE orders SET payment_status = 'Partial', paid_amount = paid_amount + $rem_amount WHERE id = $o_id");
                                $rem_amount = 0;
                            }
                        }
                    }
                }

                // 3. Clear Opening Balance
                if ($rem_amount > 0) { 
                    $conn->query("UPDATE customers SET total_due = total_due - $rem_amount WHERE id = $cid"); 
                }
            }
            
            $conn->query("UPDATE customers SET empty_tins = $tins WHERE id=$cid");
            $conn->commit();
            header("Location: admin_customers.php?msg=PaymentUpdated"); exit;
        } catch(Exception $e) {
            $conn->rollback(); 
            $errors[] = "❌ Error: Payment failed.";
        }
    }

    if (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete' && !empty($_POST['selected_customers'])) {
        $ids = array_map('intval', $_POST['selected_customers']);
        $ids_str = implode(',', $ids);
        $conn->query("DELETE FROM customer_loyalty WHERE customer_id IN ($ids_str)");
        $conn->query("DELETE FROM customers WHERE id IN ($ids_str)");
        header("Location: admin_customers.php?msg=BulkDeleted"); exit;
    }
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM customer_loyalty WHERE customer_id=$id");
    $conn->query("DELETE FROM customers WHERE id=$id");
    header("Location: admin_customers.php?msg=Deleted"); exit;
}

// ==========================================
// 3. FETCH DATA (FIXED LIVE DUE CALCULATION)
// ==========================================
$search = $_GET['search'] ?? '';
$filter_group = $_GET['filter_group'] ?? '';
$due_only = $_GET['due_only'] ?? '0'; 
$sort_by = $_GET['sort_by'] ?? 'recent'; 

$where_sql = "WHERE 1=1";
$params = []; $types = "";

if ($search) {
    $where_sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $param = "%$search%";
    $params = array_merge($params, [$param, $param, $param]);
    $types .= "sss";
}
if ($filter_group) {
    $where_sql .= " AND c.group_id = ?";
    $params[] = $filter_group; $types .= "i";
}

$having_sql = "";
if ($due_only == '1') { $having_sql = "HAVING actual_due > 0"; }

$order_sql = "ORDER BY c.id DESC"; 
if ($sort_by == 'name_asc') $order_sql = "ORDER BY c.name ASC";
if ($sort_by == 'due_desc') $order_sql = "ORDER BY actual_due DESC";
if ($sort_by == 'due_asc') $order_sql = "ORDER BY actual_due ASC";

// UPDATED SQL: Ignore Cash/UPI from Sales Due
$base_query = "
    SELECT 
        c.*, cg.name as group_name, cg.discount_percent, cl.points as loyalty_points, cl.tier as loyalty_tier,
        (SELECT COUNT(id) FROM orders WHERE customer_id = c.id) as total_orders,
        (SELECT COUNT(id) FROM service_orders WHERE user_id = c.id) as total_jobs,
        (SELECT COALESCE(SUM(total), 0) FROM orders WHERE customer_id = c.id) as total_spent,
        
        (SELECT COALESCE(SUM(due_amount), 0) FROM orders WHERE customer_id = c.id AND payment_status != 'Paid' AND (payment_method IS NULL OR payment_method NOT IN ('Cash', 'cash', 'UPI', 'upi', 'Card', 'card', 'Online', 'online', 'Bank', 'bank'))) AS sales_due,
        
        (SELECT COALESCE(SUM(total_amount), 0) FROM service_orders WHERE user_id = c.id AND (payment_status IN ('Pending','pending','Unpaid','unpaid') OR payment_status IS NULL)) AS job_due,
        
        (c.total_due + 
         COALESCE((SELECT SUM(due_amount) FROM orders WHERE customer_id = c.id AND payment_status != 'Paid' AND (payment_method IS NULL OR payment_method NOT IN ('Cash', 'cash', 'UPI', 'upi', 'Card', 'card', 'Online', 'online', 'Bank', 'bank'))), 0) + 
         COALESCE((SELECT SUM(total_amount) FROM service_orders WHERE user_id = c.id AND (payment_status IN ('Pending','pending','Unpaid','unpaid') OR payment_status IS NULL)), 0)
        ) AS actual_due
        
    FROM customers c
    LEFT JOIN customer_groups cg ON c.group_id = cg.id
    LEFT JOIN customer_loyalty cl ON c.id = cl.customer_id
    $where_sql
    $having_sql
    $order_sql
";

if (!empty($params)) {
    $stmt = $conn->prepare($base_query); $stmt->bind_param($types, ...$params); $stmt->execute(); $customers_result = $stmt->get_result();
} else {
    $customers_result = $conn->query($base_query);
}

$customers_list = []; $total_market_due = 0;
if($customers_result) {
    while($row = $customers_result->fetch_assoc()) {
        $total_market_due += $row['actual_due'];
        $customers_list[] = $row;
    }
}

$groups = $conn->query("SELECT * FROM customer_groups ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$stats = [
    'total_customers' => $conn->query("SELECT COUNT(*) c FROM customers")->fetch_assoc()['c'],
    'market_due' => $total_market_due,
    'total_tins' => $conn->query("SELECT SUM(empty_tins) as t FROM customers")->fetch_assoc()['t'] ?? 0
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer CRM | Trishe Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Core Styles */
        :root { --primary: #059669; --bg: #f8fafc; --card: #fff; --text: #334155; --border: #e2e8f0; }
        * { box-sizing: border-box; margin:0; padding:0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); padding-left: 260px; padding-bottom: 80px; }
        .container {  margin: 0 auto; padding: 10px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: #fff; padding: 15px 20px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .page-title { font-size: 1.2rem; font-weight: 700; color: #0f172a; display:flex; align-items:center; gap:10px; margin:0; }
        
        .grid-layout { display: grid; grid-template-columns: 300px 1fr; gap: 20px; }

        /* Cards & Forms */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .card-head { font-weight: 700; margin-bottom: 15px; color: var(--primary); font-size: 1rem; border-bottom: 1px dashed var(--border); padding-bottom: 10px; display:flex; justify-content:space-between; }
        .form-group { margin-bottom: 15px; }
        .form-label { display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 5px; color: #475569; text-transform: uppercase; }
        .form-input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; outline:none; background: #fff; }
        .btn-primary { background: var(--primary); color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.9rem; text-decoration:none; display:inline-block; }
        .btn-outline { background: white; color: #475569; border: 1px solid #cbd5e1; padding: 10px 15px; border-radius: 6px; text-decoration: none; display:inline-block; font-weight: 600;}

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
        .stat-box { background: #f0fdf4; padding: 15px; border-radius: 8px; border: 1px solid #dcfce7; text-align: center; text-decoration: none; display: block; color: inherit; }
        .stat-box.warning { background: #fff7ed; border-color: #ffedd5; color: #c2410c; grid-column: 1 / -1; }
        .stat-val { font-size: 1.4rem; font-weight: 800; display: block; margin-bottom:5px; }
        .stat-lbl { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; opacity:0.8; }

        /* Filters */
        .filter-bar { display:flex; gap:10px; margin-bottom:15px; background:#fff; padding:15px; border-radius:8px; border:1px solid var(--border); align-items:flex-end; flex-wrap:wrap; }
        .filter-item { flex:1; min-width: 140px; }

        /* Table */
        .table-wrap { overflow-x: auto; background: #fff; border-radius: 8px; border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 900px; }
        th { background: #f8fafc; padding: 12px; text-align: left; font-weight: 600; color: #475569; border-bottom: 1px solid var(--border); }
        td { padding: 12px; border-bottom: 1px solid var(--border); color: #334155; vertical-align: middle; }
        tr:hover { background: #f8fafc; }
        
        .avatar { width: 35px; height: 35px; border-radius: 50%; background: #e0e7ff; color: #4f46e5; display:inline-flex; align-items:center; justify-content:center; font-weight:bold; margin-right:10px; flex-shrink: 0; }
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        
        /* Badges Status Colors */
        .st-pending, .st-unpaid, .st-due { background: #fff7ed; color: #c2410c; }
        .st-paid, .st-completed, .st-delivered, .st-cash, .st-upi { background: #f0fdf4; color: #15803d; }
        .st-processing, .st-shipped { background: #eff6ff; color: #1d4ed8; }

        .tier-bronze { background: #ffedd5; color: #c2410c; }
        .tier-silver { background: #f1f5f9; color: #475569; }
        .tier-gold { background: #fef9c3; color: #b45309; }
        .due-amt { font-weight: 700; color: #ef4444; }
        .due-amt.clear { color: #059669; }

        /* Actions */
        .action-btns { display: flex; gap: 5px; flex-wrap: wrap; }
        .btn-sm { padding: 6px 10px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 4px; }
        .btn-khata { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .btn-sale { background: #fdf4ff; color: #be185d; border: 1px solid #fbcfe8; }
        .btn-job { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .btn-del { color: #ef4444; background:none; padding:5px; border:none; cursor:pointer;}

        /* TABBED MODAL */
        .modal { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: #fff; width: 95%; max-width: 1100px; height: 85vh; border-radius: 12px; display:flex; flex-direction:column; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-bottom: 1px solid var(--border); padding-right:15px;}
        .modal-tabs { display: flex; overflow-x: auto; }
        .tab-btn { padding: 15px 25px; font-weight: 700; color: #64748b; background: transparent; border: none; border-bottom: 3px solid transparent; cursor: pointer; white-space: nowrap; font-size:0.95rem; }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); background: #fff; }
        
        .modal-body-container { display: flex; flex: 1; overflow: hidden; }
        .modal-left { width: 65%; padding: 20px; overflow-y: auto; background: #fff; border-right: 1px solid var(--border); }
        .modal-right { width: 35%; padding: 20px; background: #f8fafc; overflow-y: auto;}

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Timeline & Table inside Modal */
        .timeline { list-style: none; padding: 0; margin: 0; }
        .tl-item { background: #fff; padding: 12px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 10px; display:flex; justify-content:space-between; align-items:center; }
        .tl-icon { width: 32px; height: 32px; border-radius: 50%; display:flex; align-items:center; justify-content:center; color:white; font-size:0.85rem; margin-right:12px; flex-shrink: 0; }
        .bg-sale { background: #3b82f6; } .bg-job { background: #f59e0b; } .bg-pay { background: #10b981; }

        .history-table { width: 100%; border-collapse: collapse; text-align: left; }
        .history-table th { padding: 10px; background: #f1f5f9; border-bottom: 1px solid var(--border); font-size: 0.8rem; color: #475569; }
        .history-table td { padding: 10px; border-bottom: 1px dashed var(--border); font-size: 0.85rem; color: #334155; }

        .alert { padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight:500; font-size:0.9rem; }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .alert-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

        /* Mobile Adjustments */
        @media (max-width: 1024px) {
            body { padding-left: 0; }
            .grid-layout { grid-template-columns: 1fr; }
            aside { order: 1; } main { order: 2; }
            .modal-body-container { flex-direction: column; }
            .modal-left, .modal-right { width: 100%; border-right:none; height: auto; }
            .modal-right { border-top: 1px solid var(--border); }
        }

        @media (max-width: 768px) {
            .container { padding: 10px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar button, .filter-bar a { width: 100%; text-align: center; margin-top:5px; }

            /* Table to Cards */
            table { min-width: 100%; border: none; background: transparent; }
            thead { display: none; }
            .table-wrap { border: none; background: transparent; overflow: visible; }
            
            tr { display: flex; flex-direction: column; background: #fff; margin-bottom: 15px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border: 1px solid var(--border); padding: 5px; }
            td { display: flex; justify-content: space-between; align-items: center; padding: 10px 10px; border-bottom: 1px dashed var(--border); text-align: right; }
            td:last-child { border-bottom: none; display: block; padding-top: 15px;}
            
            td::before { content: attr(data-label); font-weight: 700; color: #64748b; font-size: 0.75rem; text-transform: uppercase; text-align: left; margin-right: 15px; }
            td:last-child::before { display: none; }
            
            .td-cust-info { flex-direction: row; justify-content: flex-end; }
            .td-cust-info > div { text-align: right !important; }
            .td-cust-info .avatar { display: none; }
            
            .action-btns { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; width: 100%; }
            .btn-khata { grid-column: 1 / -1; padding: 12px; font-size: 1rem;}
            .btn-sale, .btn-job { padding: 10px; font-size: 0.85rem; }
            .btn-del { position: absolute; top: 10px; right: 10px; background: #fee2e2; border-radius: 4px; padding: 5px 10px;}
        }
    </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="container">
    
    <?php if(isset($_GET['msg'])): ?>
        <div class="alert alert-success">
            <?php 
                if($_GET['msg']=='CustomerAdded') echo "✅ Customer added successfully!";
                elseif($_GET['msg']=='PaymentUpdated') echo "✅ Payment received and pending sales/jobs updated!";
                elseif($_GET['msg']=='Deleted') echo "✅ Customer deleted!";
                elseif($_GET['msg']=='BulkDeleted') echo "✅ Selected customers deleted!";
            ?>
        </div>
    <?php endif; ?>
    <?php foreach($errors as $e): ?>
        <div class="alert alert-danger"><?= $e ?></div>
    <?php endforeach; ?>

    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-address-book text-primary"></i> Customer Directory</h1>
        <div style="font-size:0.85rem; color:#64748b;">Manage contacts, dues, and view history.</div>
    </div>

    <div class="grid-layout">
        
        <aside>
            <div class="stats-grid">
                <a href="admin_customers.php" class="stat-box">
                    <span class="stat-val"><?= $stats['total_customers'] ?></span>
                    <span class="stat-lbl">Customers</span>
                </a>
                <div class="stat-box info">
                    <span class="stat-val"><?= $stats['total_tins'] ?></span>
                    <span class="stat-lbl">Tins In Mill 🛢️</span>
                </div>
                <a href="admin_customers.php?due_only=1&sort_by=due_desc" class="stat-box warning">
                    <span class="stat-val">₹ <?= number_format($stats['market_due'], 0) ?></span>
                    <span class="stat-lbl">Total Market Due <i class="fas fa-link"></i></span>
                </a>
            </div>

            <div class="card">
                <div class="card-head"><span>Add Customer</span> <i class="fas fa-user-plus"></i></div>
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone (10 Digits) *</label>
                        <input type="tel" name="phone" class="form-input" pattern="[0-9]{10}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email (Optional)</label>
                        <input type="email" name="email" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Opening Due (पुराना उधार ₹)</label>
                        <input type="number" step="0.01" name="opening_due" class="form-input" value="0">
                    </div>
                    <button type="submit" name="add_customer" class="btn btn-primary" style="width:100%;"><i class="fas fa-save"></i> Save Contact</button>
                </form>
            </div>
        </aside>

        <main>
            <form method="GET" class="filter-bar">
                <div class="filter-item">
                    <label class="form-label">Search Name/Phone</label>
                    <input type="text" name="search" class="form-input" placeholder="Type here..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-item" style="min-width: 120px;">
                    <label class="form-label">Filter</label>
                    <select name="due_only" class="form-input">
                        <option value="0">All Customers</option>
                        <option value="1" <?= $due_only=='1'?'selected':'' ?>>Has Due (उधार)</option>
                    </select>
                </div>
                <div class="filter-item" style="min-width: 160px;">
                    <label class="form-label">Sort By</label>
                    <select name="sort_by" class="form-input">
                        <option value="recent" <?= $sort_by=='recent'?'selected':'' ?>>Recently Added</option>
                        <option value="name_asc" <?= $sort_by=='name_asc'?'selected':'' ?>>Name (A to Z)</option>
                        <option value="due_desc" <?= $sort_by=='due_desc'?'selected':'' ?>>Due Amt (High to Low)</option>
                        <option value="due_asc" <?= $sort_by=='due_asc'?'selected':'' ?>>Due Amt (Low to High)</option>
                    </select>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn btn-primary" style="width:auto;"><i class="fas fa-filter"></i> Apply</button>
                    <?php if($search || $due_only=='1' || $sort_by!='recent'): ?>
                        <a href="admin_customers.php" class="btn btn-outline" style="width:auto;"><i class="fas fa-times"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <form method="POST">
                <div style="display:flex; gap:10px; margin-bottom:10px; align-items:center;">
                    <select name="bulk_action" class="form-input" style="width:auto; min-width:150px; padding:8px;">
                        <option value="">Bulk Action</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="submit" class="btn btn-outline" style="padding:8px 15px;" onclick="return confirm('Apply bulk action?')">Apply</button>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" id="selectAll"></th>
                                <th>Customer Info</th>
                                <th>Loyalty / Group</th>
                                <th>Orders / Spent</th>
                                <th>Tins / Due</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($customers_list) > 0): foreach($customers_list as $row): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="selected_customers[]" value="<?= $row['id'] ?>" class="cb-cust"></td>
                                
                                <td data-label="Customer" class="td-cust-info">
                                    <div style="display:flex; align-items:center;">
                                        <div class="avatar"><?= strtoupper(substr($row['name'],0,1)) ?></div>
                                        <div style="text-align:left;">
                                            <strong style="color:#0f172a; font-size:0.95rem;"><?= htmlspecialchars($row['name']) ?></strong><br>
                                            <span style="color:#64748b; font-size:0.8rem;"><i class="fas fa-phone fa-sm"></i> <?= htmlspecialchars($row['phone']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                
                                <td data-label="Loyalty / Group">
                                    <?php if($row['group_name']): ?>
                                        <div style="font-size:0.8rem; font-weight:600; color:#3b82f6; margin-bottom:3px;"><?= $row['group_name'] ?></div>
                                    <?php endif; ?>
                                    <?php if($row['loyalty_tier']): ?>
                                        <span class="badge tier-<?= strtolower($row['loyalty_tier']) ?>">
                                            <i class="fas fa-star"></i> <?= $row['loyalty_tier'] ?> (<?= $row['loyalty_points'] ?>)
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:0.8rem;">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td data-label="Orders / Spent">
                                    <div style="font-size:0.8rem; color:#475569; margin-bottom:3px;">
                                        Sales: <strong><?= $row['total_orders'] ?></strong> | Jobs: <strong><?= $row['total_jobs'] ?></strong>
                                    </div>
                                    <div style="color:var(--primary); font-weight:700;">₹<?= number_format($row['total_spent'], 0) ?></div>
                                </td>
                                
                                <td data-label="Tins / Due">
                                    <div style="font-size:0.8rem; color:#475569; margin-bottom:3px;"><i class="fas fa-database"></i> Tins: <strong><?= $row['empty_tins'] ?></strong></div>
                                    <div class="due-amt <?= $row['actual_due']<=0 ? 'clear':'' ?>">Due: ₹<?= number_format($row['actual_due'], 2) ?></div>
                                </td>
                                
                                <td data-label="Actions">
                                    <div class="action-btns">
                                        <a href="?delete=<?= $row['id'] ?>" class="btn-del" onclick="return confirm('Delete this customer?');"><i class="fas fa-trash"></i></a>
                                        
                                        <button type="button" class="btn-sm btn-khata" onclick="openHistory(<?= $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['name'])) ?>', <?= $row['actual_due'] ?>, <?= $row['empty_tins'] ?>, 'ledger')">
                                            <i class="fas fa-book-open"></i> Khata
                                        </button>
                                        <button type="button" class="btn-sm btn-sale" onclick="openHistory(<?= $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['name'])) ?>', <?= $row['actual_due'] ?>, <?= $row['empty_tins'] ?>, 'sales')">
                                            <i class="fas fa-cart-arrow-down"></i> Sales
                                        </button>
                                        <button type="button" class="btn-sm btn-job" onclick="openHistory(<?= $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['name'])) ?>', <?= $row['actual_due'] ?>, <?= $row['empty_tins'] ?>, 'jobs')">
                                            <i class="fas fa-cogs"></i> Jobs
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">No customers found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </main>
    </div>
</div>

<div class="modal" id="historyModal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-tabs">
                <button class="tab-btn active" id="tab-ledger" onclick="switchTab('ledger')"><i class="fas fa-book-open"></i> Khata</button>
                <button class="tab-btn" id="tab-sales" onclick="switchTab('sales')"><i class="fas fa-shopping-cart"></i> Sales History</button>
                <button class="tab-btn" id="tab-jobs" onclick="switchTab('jobs')"><i class="fas fa-cogs"></i> Job Works</button>
            </div>
            <div>
                <button type="button" onclick="closeHistory()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
            </div>
        </div>

        <div class="modal-body-container">
            <div class="modal-left">
                <h3 id="modal-cust-name" style="margin-top:0; color:var(--primary); font-size:1.2rem; margin-bottom:15px;">Customer Name</h3>
                
                <div id="content-ledger" class="tab-content active">
                    <ul class="timeline" id="timelineList"><li style="text-align:center; padding:20px; color:#94a3b8;">Loading ledger...</li></ul>
                </div>
                
                <div id="content-sales" class="tab-content">
                    <div style="display:flex; justify-content:space-between; margin-bottom:15px; align-items:center;">
                        <h4 style="margin:0; color:#334155;">Past Sales</h4>
                        <a id="btn-new-sale" href="#" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Sale</a>
                    </div>
                    <div id="salesList" class="table-responsive"><div style="text-align:center; padding:20px; color:#94a3b8;">Loading sales...</div></div>
                </div>
                
                <div id="content-jobs" class="tab-content">
                    <div style="display:flex; justify-content:space-between; margin-bottom:15px; align-items:center;">
                        <h4 style="margin:0; color:#334155;">Past Job Works</h4>
                        <a id="btn-new-job" href="#" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Job</a>
                    </div>
                    <div id="jobsList" class="table-responsive"><div style="text-align:center; padding:20px; color:#94a3b8;">Loading jobs...</div></div>
                </div>
            </div>
            
            <div class="modal-right">
                <div style="background:#fff7ed; padding:15px; border-radius:8px; border:1px solid #ffedd5; margin-bottom:20px; text-align:center;">
                    <span style="font-size:0.8rem; color:#c2410c; font-weight:700;">CURRENT DUE</span>
                    <div id="k_due" style="font-size:2rem; font-weight:800; color:#9a3412;">₹0.00</div>
                </div>

                <h4 style="font-size:1rem; margin-bottom:15px; color:#0f172a;"><i class="fas fa-hand-holding-usd"></i> Receive Payment</h4>
                <form method="POST">
                    <input type="hidden" name="customer_id" id="k_id">
                    <div class="form-group">
                        <label class="form-label">Amount Received (₹)</label>
                        <input type="number" name="amount" step="0.01" id="k_pay_amount" class="form-input" placeholder="0.00" value="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Mode</label>
                        <select name="payment_mode" class="form-input">
                            <option>Cash</option>
                            <option>UPI / PhonePe</option>
                            <option>Bank Transfer</option>
                            <option>Discount / Waived</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Empty Tins in Mill 🛢️</label>
                        <input type="number" name="empty_tins" id="k_tins" class="form-input" placeholder="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Note / Remark</label>
                        <input type="text" name="note" class="form-input" placeholder="e.g. Paid by brother">
                    </div>
                    <button type="submit" name="receive_payment" class="btn-primary" style="width:100%; margin-top:10px;"><i class="fas fa-save"></i> Save Payment & Clear Bills</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    if(window.history.replaceState) {
        const url = new URL(window.location); url.searchParams.delete('msg'); window.history.replaceState(null, '', url);
    }

    document.getElementById('selectAll').addEventListener('change', function(e) {
        document.querySelectorAll('.cb-cust').forEach(cb => cb.checked = e.target.checked);
    });

    // --- MODAL & TABS LOGIC ---
    const modal = document.getElementById('historyModal');
    let currentCustId = 0;

    function openHistory(id, name, due, tins, defaultTab) {
        currentCustId = id;
        document.getElementById('k_id').value = id;
        document.getElementById('modal-cust-name').innerHTML = `<i class="fas fa-user-circle"></i> ${name}`;
        document.getElementById('k_due').innerText = '₹ ' + parseFloat(due).toFixed(2);
        document.getElementById('k_pay_amount').value = parseFloat(due).toFixed(2);
        document.getElementById('k_tins').value = tins;
        
        document.getElementById('btn-new-sale').href = 'pos.php?cust_id=' + id;
        document.getElementById('btn-new-job').href = 'admin_orders.php?view=services&cust_id=' + id;
        
        modal.classList.add('active');
        switchTab(defaultTab);
    }
    
    function closeHistory() { modal.classList.remove('active'); }
    modal.addEventListener('click', function(e) { if(e.target === this) closeHistory(); });

    function switchTab(tab) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

        document.getElementById('tab-' + tab).classList.add('active');
        document.getElementById('content-' + tab).classList.add('active');

        if (tab === 'ledger') fetchLedger(currentCustId);
        else if (tab === 'sales') fetchSales(currentCustId);
        else if (tab === 'jobs') fetchJobs(currentCustId);
    }

    // --- AJAX FETCHERS ---
    function fetchLedger(id) {
        const list = document.getElementById('timelineList');
        list.innerHTML = '<li style="text-align:center; padding:20px; color:#94a3b8;">Loading...</li>';
        
        fetch(`admin_customers.php?action=get_ledger&customer_id=${id}`)
        .then(res => res.json()).then(data => {
            if(data.length === 0) { list.innerHTML = '<li style="text-align:center; padding:20px; color:#94a3b8;">No transaction history found.</li>'; return; }
            
            let html = '';
            data.forEach(item => {
                let icon, bgClass, title, amtColor, sign;
                let d = new Date(item.date);
                let dateStr = d.toLocaleDateString('en-GB', {day:'2-digit', month:'short'}) + ', ' + d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
                
                // Set Badge by method instead of status for sales to be accurate
                let p_stat = item.status ? item.status.toLowerCase() : 'pending';

                if(item.type === 'sale') { 
                    icon = 'shopping-cart'; bgClass = 'bg-sale'; title = 'Sale #'+item.ref; 
                    if(['cash','upi','card','bank'].includes(p_stat)) { amtColor = '#059669'; sign = 'Paid (Sale)'; }
                    else { amtColor = '#dc2626'; sign = 'Due +'; }
                } 
                else if(item.type === 'service') { 
                    icon = 'cogs'; bgClass = 'bg-job'; title = 'Job Work #'+item.ref; 
                    if(p_stat === 'paid') { amtColor = '#059669'; sign = 'Paid (Job)'; }
                    else { amtColor = '#dc2626'; sign = 'Due +'; }
                } 
                else { 
                    icon = 'rupee-sign'; bgClass = 'bg-pay'; title = 'Payment Received ('+item.status+')'; amtColor = '#059669'; sign = 'Paid -'; 
                }

                html += `<li class="tl-item">
                    <div style="display:flex; align-items:center;">
                        <div class="tl-icon ${bgClass}"><i class="fas fa-${icon}"></i></div>
                        <div><strong style="font-size:0.85rem; color:#1e293b;">${title}</strong><br><small style="color:#64748b;">${dateStr}</small></div>
                    </div>
                    <div style="text-align:right;">
                        <small style="color:#94a3b8; font-size:0.7rem; display:block;">${sign}</small>
                        <strong style="color:${amtColor}; font-size:0.95rem;">₹${parseFloat(item.amount).toFixed(2)}</strong>
                    </div>
                </li>`;
            });
            list.innerHTML = html;
        });
    }

    function fetchSales(id) {
        const list = document.getElementById('salesList');
        list.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8;">Loading...</div>';
        
        fetch(`admin_customers.php?action=get_sales&customer_id=${id}`)
        .then(res => res.json()).then(data => {
            if(data.length === 0) { list.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8;">No past sales found.</div>'; return; }
            
            let html = '<table class="history-table"><thead><tr><th>Order No</th><th>Date</th><th>Amount</th><th>Method</th><th>Pay Status</th></tr></thead><tbody>';
            data.forEach(s => {
                let d = new Date(s.created_at).toLocaleDateString('en-GB');
                let p_meth = s.payment_method ? s.payment_method.toLowerCase() : 'due';
                let p_stat = s.payment_status ? s.payment_status.toLowerCase() : 'pending';
                
                // Display override if method is cash/upi it means it's paid
                if(['cash','upi','card','bank','online'].includes(p_meth)) { p_stat = 'paid'; }

                html += `<tr>
                    <td><strong>#${s.order_no}</strong></td>
                    <td>${d}</td>
                    <td>₹${parseFloat(s.total).toFixed(2)}</td>
                    <td><span class="badge st-${p_meth}">${s.payment_method || 'Due'}</span></td>
                    <td><span class="badge st-${p_stat}">${p_stat === 'paid' ? 'Paid' : (s.payment_status || 'Pending')}</span></td>
                </tr>`;
            });
            html += '</tbody></table>';
            list.innerHTML = html;
        });
    }

    function fetchJobs(id) {
        const list = document.getElementById('jobsList');
        list.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8;">Loading...</div>';
        
        fetch(`admin_customers.php?action=get_jobs&customer_id=${id}`)
        .then(res => res.json()).then(data => {
            if(data.length === 0) { list.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8;">No past jobs found.</div>'; return; }
            
            let html = '<table class="history-table"><thead><tr><th>Job ID</th><th>Date</th><th>Item</th><th>Weight</th><th>Total</th><th>Status</th></tr></thead><tbody>';
            data.forEach(s => {
                let d = new Date(s.service_date).toLocaleDateString('en-GB');
                let p_stat = s.payment_status ? s.payment_status.toLowerCase() : 'pending';
                html += `<tr>
                    <td><strong>#${s.id}</strong></td>
                    <td>${d}</td>
                    <td>${s.seed_type}</td>
                    <td>${s.weight_kg}kg</td>
                    <td>₹${parseFloat(s.total_amount).toFixed(2)}</td>
                    <td><span class="badge st-${p_stat}">${s.payment_status || 'Pending'}</span></td>
                </tr>`;
            });
            html += '</tbody></table>';
            list.innerHTML = html;
        });
    }
</script>

</body>
</html>