<?php
// admin_orders.php - MOBILE RESPONSIVE + DYNAMIC PRINT + JOBWORK PAYMENT STATUS + ONLINE ORDERS BUTTON
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    header('location:login.php');
    exit;
}

// --- ACTIONS ---

// 1. ADD JOB
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service_order'])) {
    $cust_id = intval($_POST['customer_id']);
    $cust_name = trim($_POST['customer_name']);
    $cust_phone = trim($_POST['customer_phone']);
    $seed = $_POST['seed_type'];
    $weight = floatval($_POST['weight_kg']);
    $rate = floatval($_POST['rate_per_kg']);
    $total = $weight * $rate;
    $note = $_POST['notes'];

    $conn->begin_transaction();
    try {
        if ($cust_id == 0) {
            $check = $conn->query("SELECT id FROM customers WHERE phone = '$cust_phone' LIMIT 1");
            if ($check->num_rows > 0) {
                $cust_id = $check->fetch_assoc()['id'];
            } else {
                $stmtC = $conn->prepare("INSERT INTO customers (name, phone, created_at) VALUES (?, ?, NOW())");
                $stmtC->bind_param("ss", $cust_name, $cust_phone);
                $stmtC->execute();
                $cust_id = $stmtC->insert_id;
            }
        }

        if ($cust_id && $weight > 0) {
            $stmt = $conn->prepare("INSERT INTO service_orders (user_id, seed_type, weight_kg, rate_per_kg, total_amount, notes, status, payment_status, service_date) VALUES (?, ?, ?, ?, ?, ?, 'Pending', 'Pending', NOW())");
            $stmt->bind_param("isddds", $cust_id, $seed, $weight, $rate, $total, $note);
            $stmt->execute();

            $new_job_id = $stmt->insert_id;
            $conn->commit();

            header("Location: admin_orders.php?view=services&msg=JobAdded&print_id=" . $new_job_id);
            exit;
        }
    } catch (Exception $e) {
        $conn->rollback();
    }
}

// 2. UPDATE SERVICE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service_status'])) {
    $sid = intval($_POST['service_id']);
    $status = $_POST['status'];
    $payment_status = $_POST['payment_status'];
    $oil = floatval($_POST['oil_returned'] ?? 0);
    $cake = floatval($_POST['cake_returned'] ?? 0);

    $conn->query("UPDATE service_orders SET status='$status', payment_status='$payment_status', oil_returned='$oil', cake_returned='$cake' WHERE id=$sid");
    header("Location: admin_orders.php?view=services&msg=Updated");
    exit;
}

// 3. UPDATE SALES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sales_order'])) {
    $oid = intval($_POST['order_id']);
    $status = $_POST['order_status'];
    $pay_status = $_POST['payment_status'];
    $extra = ($pay_status == 'Paid') ? ", paid_amount = total, due_amount = 0" : "";
    $conn->query("UPDATE orders SET status='$status', payment_status='$pay_status' $extra WHERE id=$oid");
    header("Location: admin_orders.php?view=orders&msg=Updated");
    exit;
}

// 4. DELETE
if (isset($_GET['delete_service'])) {
    $id = intval($_GET['delete_service']);
    $conn->query("DELETE FROM service_orders WHERE id=$id");
    header("Location: admin_orders.php?view=services&msg=Deleted");
    exit;
}
if (isset($_GET['delete_order'])) {
    $id = intval($_GET['delete_order']);
    $conn->query("DELETE FROM order_items WHERE order_id=$id");
    $conn->query("DELETE FROM orders WHERE id=$id");
    header("Location: admin_orders.php?view=orders&msg=Deleted");
    exit;
}

// --- DATA FETCHING ---
$view = $_GET['view'] ?? 'orders';
// --- LIVE DASHBOARD STATS (SALES) ---
$today_date = date('Y-m-d');

// 1. Aaj ke Orders
$q1 = $conn->query("SELECT COUNT(id) as cnt FROM orders WHERE DATE(created_at) = '$today_date'");
$today_orders = $q1->fetch_assoc()['cnt'] ?? 0;

// 2. Aaj ki Sale
$q2 = $conn->query("SELECT SUM(total) as amt FROM orders WHERE DATE(created_at) = '$today_date'");
$today_sale = $q2->fetch_assoc()['amt'] ?? 0;

// 3. Pending Packing
$q3 = $conn->query("SELECT COUNT(id) as cnt FROM orders WHERE status = 'Pending'");
$pending_packing = $q3->fetch_assoc()['cnt'] ?? 0;

// 4. Unpaid Amount
$q4 = $conn->query("SELECT SUM(total - paid_amount) as amt FROM orders WHERE payment_status != 'Paid'");
$unpaid_amount = $q4->fetch_assoc()['amt'] ?? 0;
$seeds_list = [];
$last_rates = [];
$s_query = $conn->query("SELECT name FROM seeds_master ORDER BY name");
while ($row = $s_query->fetch_assoc()) $seeds_list[] = $row['name'];
$r_query = $conn->query("SELECT seed_type, rate_per_kg FROM service_orders");
while ($row = $r_query->fetch_assoc()) $last_rates[$row['seed_type']] = $row['rate_per_kg'];

$search = $_GET['search'] ?? '';
$date_filter = $_GET['date'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';

$active_list = [];
$history_list = [];

if ($view === 'services') {
    $sql_act = "SELECT s.*, c.name as customer_name, c.phone FROM service_orders s LEFT JOIN customers c ON s.user_id = c.id WHERE s.status IN ('Pending', 'Processing') ORDER BY s.service_date DESC";
    $res_act = $conn->query($sql_act);
    if ($res_act) while ($r = $res_act->fetch_assoc()) $active_list[] = $r;

    $where = "1=1";
    if ($search) $where .= " AND (c.name LIKE '%$search%' OR c.phone LIKE '%$search%')";
    if ($date_filter) $where .= " AND DATE(s.service_date) = '$date_filter'";
    if ($status_filter) $where .= " AND s.status = '$status_filter'";

    $sql_hist = "SELECT s.*, c.name as customer_name, c.phone FROM service_orders s LEFT JOIN customers c ON s.user_id = c.id WHERE $where ORDER BY s.service_date DESC LIMIT 100";
    $res_hist = $conn->query($sql_hist);
    if ($res_hist) while ($r = $res_hist->fetch_assoc()) $history_list[] = $r;
} else {
    $sql_act = "SELECT o.*, c.name as customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.status IN ('pending', 'ReadyToShip', 'Shipped') ORDER BY o.created_at DESC";
    $res_act = $conn->query($sql_act);
    if ($res_act) while ($r = $res_act->fetch_assoc()) $active_list[] = $r;

    $where = "1=1";
    if ($search) $where .= " AND (c.name LIKE '%$search%' OR c.phone LIKE '%$search%')";
    if ($date_filter) $where .= " AND DATE(o.created_at) = '$date_filter'";
    if ($status_filter) $where .= " AND o.status = '$status_filter'";

    $sql_hist = "SELECT o.*, c.name as customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE $where ORDER BY o.created_at DESC LIMIT 100";
    $res_hist = $conn->query($sql_hist);
    if ($res_hist) while ($r = $res_hist->fetch_assoc()) $history_list[] = $r;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manager | Trishe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* === SCREEN STYLES === */
        :root {
            --primary: #059669;
            --bg: #f8fafc;
            --card: #fff;
            --text: #334155;
            --border: #e2e8f0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            padding-left: 260px;
            margin: 0;
            padding-bottom: 80px;
            overflow-x: hidden;
        }

        .container {
            margin: 0 auto;
            padding: 15px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: #fff;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
            flex-wrap: wrap;
            /* Added for mobile responsiveness */
            gap: 15px;
        }

        .page-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
        }

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .tab-group {
            display: flex;
            gap: 5px;
        }

        .tab-btn {
            text-decoration: none;
            color: #64748b;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: 0.2s;
            background: #f1f5f9;
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
        }

        main {
            min-width: 0;
            width: 100%;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .card-head {
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--primary);
            font-size: 0.9rem;
            border-bottom: 1px dashed var(--border);
            padding-bottom: 5px;
            display: flex;
            justify-content: space-between;
        }

        .form-group {
            margin-bottom: 10px;
        }

        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 3px;
            color: #64748b;
            text-transform: uppercase;
        }

        .form-input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.9rem;
            -webkit-appearance: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px;
            width: 100%;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .btn-upload {
            background: #4f46e5;
            color: #fff;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
        }

        .btn-upload:hover {
            background: #4338ca;
        }

        .jobs-grid {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            gap: 15px;
            margin-bottom: 30px;
            padding-bottom: 10px;
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        .jobs-grid::-webkit-scrollbar {
            height: 6px;
        }

        .jobs-grid::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .jobs-grid::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .job-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            border-left: 4px solid var(--primary);
            font-size: 0.85rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            min-width: 320px;
            max-width: 320px;
            flex: 0 0 auto;
        }

        .job-header {
            padding: 10px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .job-body {
            padding: 10px;
        }

        .job-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            color: #475569;
        }

        .job-row strong {
            color: #1e293b;
        }

        .job-footer {
            padding: 10px;
            background: #fff;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border);
            flex-wrap: wrap;
            align-items: end;
        }

        .filter-item {
            flex: 1;
            min-width: 150px;
        }

        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border-radius: 8px;
            border: 1px solid var(--border);
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            min-width: 750px;
            text-align: left;
        }

        th {
            background: #f8fafc;
            padding: 12px;
            font-weight: 600;
            color: #64748b;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            color: #334155;
            vertical-align: middle;
        }

        tr:hover {
            background: #f8fafc;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .st-pending {
            background: #fff7ed;
            color: #c2410c;
        }

        .st-processing,
        .st-shipped {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .st-completed,
        .st-paid,
        .st-delivered {
            background: #f0fdf4;
            color: #15803d;
        }

        .st-cancelled,
        .st-due {
            background: #fef2f2;
            color: #b91c1c;
        }

        .suggestions {
            position: absolute;
            background: white;
            width: 100%;
            border: 1px solid #ddd;
            max-height: 150px;
            overflow-y: auto;
            z-index: 50;
            display: none;
        }

        .s-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }

        .alert {
            padding: 10px;
            background: #dcfce7;
            color: #15803d;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 0.85rem;
        }

        .global-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }

        .global-modal.active {
            display: flex;
            animation: fadeIn 0.2s;
        }

        .g-modal-content {
            background: #fff;
            width: 90%;
            max-width: 800px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            position: relative;
        }

        .g-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .g-modal-body {
            padding: 20px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .g-close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
            line-height: 1;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @media (max-width: 1024px) {
            body {
                padding-left: 0;
            }

            .grid-layout {
                grid-template-columns: 1fr;
                display: flex;
                flex-direction: column;
            }

            aside {
                order: 1;
            }

            main {
                order: 2;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
            }

            .tab-group {
                width: 100%;
            }

            .tab-btn,
            .btn-upload {
                flex: 1;
                text-align: center;
                justify-content: center;
            }

            .filter-bar {
                flex-direction: column;
                gap: 15px;
            }

            .filter-item {
                width: 100%;
            }

            .filter-bar button,
            .filter-bar a {
                width: 100%;
                text-align: center;
            }

            .job-footer select,
            .job-footer button,
            .job-footer a {
                width: 100%;
                height: 40px;
            }

            .table-wrap {
                margin-bottom: 80px;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert">✅ Action Completed Successfully!</div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title">Operations</h1>

            <div class="header-actions">
                <div class="tab-group">
                    <a href="?view=orders" class="tab-btn <?= $view == 'orders' ? 'active' : '' ?>">Sales Orders</a>
                    <a href="?view=services" class="tab-btn <?= $view == 'services' ? 'active' : '' ?>">Job Work</a>
                </div>
                <a href="online_orders.php" class="btn-upload">
                    <i class="fas fa-cloud-upload-alt"></i> Upload Online Orders
                </a>
            </div>
        </div>

        <div class="grid-layout">
            <aside>
                <?php if ($view === 'services'): ?>
                    <div class="card">
                        <div class="card-head"><span>Create New Job</span> <i class="fas fa-plus"></i></div>
                        <form method="POST">
                            <div class="form-group" style="position:relative;">
                                <label class="form-label">Customer Name</label>
                                <input type="text" name="customer_name" id="c_search" class="form-input" placeholder="Enter Name..." autocomplete="off" required>
                                <input type="hidden" name="customer_id" id="c_id" value="0">
                                <div id="c_list" class="suggestions"></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="customer_phone" id="c_phone" class="form-input" placeholder="Enter Phone..." required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Seed</label>
                                <select name="seed_type" id="seed_select" class="form-input" onchange="autoFillRate()">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($seeds_list as $seed): ?>
                                        <option value="<?= htmlspecialchars($seed) ?>"><?= htmlspecialchars($seed) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="display:flex; gap:10px;">
                                <div class="form-group" style="flex:1;">
                                    <label class="form-label">Weight (Kg)</label>
                                    <input type="number" name="weight_kg" id="w_input" step="0.01" class="form-input" oninput="calcTotal()" required>
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label class="form-label">Rate (₹)</label>
                                    <input type="number" name="rate_per_kg" id="r_input" step="0.01" class="form-input" oninput="calcTotal()" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Total</label>
                                <input type="text" id="t_disp" class="form-input" readonly style="background:#f1f5f9; font-weight:bold;">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Note / Remark</label>
                                <input type="text" name="notes" class="form-input" placeholder="Optional">
                            </div>
                            <button type="submit" name="add_service_order" class="btn-primary">Add Job & Print Slip</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="card" style="padding: 20px;">
                        <div class="card-head" style="font-size: 1rem; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;">
                            <span><i class="fas fa-chart-pie text-primary"></i> Live Sales Dashboard</span>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 15px;">

                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px 10px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                <div style="color: #64748b; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px; letter-spacing: 0.5px;">Today's Orders</div>
                                <div style="font-size: 1.5rem; font-weight: 800; color: #0f172a;"><?= $today_orders ?></div>
                            </div>

                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px 10px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                <div style="color: #166534; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px; letter-spacing: 0.5px;">Today's Sale</div>
                                <div style="font-size: 1.3rem; font-weight: 800; color: #15803d;">₹<?= number_format($today_sale) ?></div>
                            </div>

                            <div style="background: #fff7ed; border: 1px solid #ffedd5; padding: 15px 10px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                <div style="color: #c2410c; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px; letter-spacing: 0.5px;">Pending Pack</div>
                                <div style="font-size: 1.5rem; font-weight: 800; color: #ea580c;"><?= $pending_packing ?></div>
                            </div>

                            <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 15px 10px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                <div style="color: #b91c1c; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px; letter-spacing: 0.5px;">Total Unpaid</div>
                                <div style="font-size: 1.3rem; font-weight: 800; color: #dc2626;">₹<?= number_format($unpaid_amount) ?></div>
                            </div>

                        </div>

                        <div style="margin-top: 20px;">
                            <a href="?view=orders&status_filter=Pending" class="btn-primary" style="display: flex; justify-content: center; align-items: center; gap: 8px; text-decoration: none; background: #3b82f6;">
                                <i class="fas fa-box-open"></i> View Pending Orders
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </aside>

            <main>
                <h3 style="font-size:0.9rem; margin-bottom:10px; color:#64748b; text-transform:uppercase; font-weight:700;">Running Jobs</h3>
                <div class="jobs-grid">
                    <?php if (!empty($active_list)): foreach ($active_list as $row): ?>

                            <?php if ($view === 'services'): ?>
                                <div class="job-card">
                                    <div class="job-header">
                                        <strong>#<?= $row['id'] ?></strong>
                                        <span class="badge st-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span>
                                    </div>
                                    <div class="job-body">
                                        <div class="job-row"><span>Cust:</span> <strong><?= htmlspecialchars($row['customer_name']) ?></strong></div>
                                        <div class="job-row"><span>Item:</span> <?= htmlspecialchars($row['seed_type']) ?></div>
                                        <div class="job-row"><span>In:</span> <?= $row['weight_kg'] ?> Kg</div>

                                        <div class="job-row">
                                            <span>Pay:</span>
                                            <span class="badge st-<?= strtolower($row['payment_status'] ?? 'pending') ?>">
                                                <?= htmlspecialchars($row['payment_status'] ?? 'Pending') ?>
                                            </span>
                                        </div>

                                        <div class="job-row" style="color:var(--primary); font-weight:bold;"><span>Bill:</span> ₹<?= $row['total_amount'] ?></div>
                                    </div>
                                    <form method="POST" class="job-footer">
                                        <input type="hidden" name="service_id" value="<?= $row['id'] ?>">

                                        <select name="status" class="form-input" style="flex:1; padding:8px; min-width: 100px; margin:0;">
                                            <option <?= $row['status'] == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                            <option <?= $row['status'] == 'Processing' ? 'selected' : '' ?>>Processing</option>
                                            <option <?= $row['status'] == 'Completed' ? 'selected' : '' ?>>Completed</option>
                                            <option <?= $row['status'] == 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                                        </select>

                                        <select name="payment_status" class="form-input" style="flex:1; padding:8px; min-width: 90px; margin:0;">
                                            <option value="Pending" <?= ($row['payment_status'] ?? 'Pending') == 'Pending' ? 'selected' : '' ?>>Unpaid</option>
                                            <option value="Paid" <?= ($row['payment_status'] ?? 'Pending') == 'Paid' ? 'selected' : '' ?>>Paid</option>
                                        </select>

                                        <?php if ($row['status'] != 'Pending'): ?>
                                            <input type="number" name="oil_returned" step="0.01" placeholder="Oil(kg)" class="form-input" style="flex:1; min-width:60px; padding:8px; margin:0;" value="<?= $row['oil_returned'] ?>">
                                            <input type="number" name="cake_returned" step="0.01" placeholder="Cake(kg)" class="form-input" style="flex:1; min-width:60px; padding:8px; margin:0;" value="<?= $row['cake_returned'] ?>">
                                        <?php endif; ?>

                                        <div style="display:flex; gap:5px; width:100%;">
                                            <button type="submit" name="update_service_status" class="btn-primary" style="flex:1; padding:8px 12px;" title="Update DB"><i class="fas fa-save"></i> Update</button>
                                            <a href="#" onclick="openPrintEngine('job_sticker', <?= $row['id'] ?>)" class="btn-primary" style="width:auto; padding:8px 12px; background:#64748b; text-decoration:none;" title="Print Slip"><i class="fas fa-print"></i></a>
                                        </div>
                                    </form>
                                </div>

                            <?php else: ?>
                                <div class="job-card" style="border-left-color:#3b82f6;">
                                    <div class="job-header">
                                        <strong style="color:#3b82f6;">#<?= $row['order_no'] ?></strong>
                                        <span class="badge st-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span>
                                    </div>
                                    <div class="job-body">
                                        <div class="job-row"><span>Cust:</span> <strong><?= $row['customer_name'] ?></strong></div>
                                        <div class="job-row"><span>Pay:</span> <span class="badge st-<?= strtolower($row['payment_status']) ?>"><?= $row['payment_status'] ?></span></div>
                                        <div class="job-row" style="color:#3b82f6; font-weight:bold;"><span>Total:</span> ₹<?= number_format($row['total'], 2) ?></div>
                                    </div>
                                    <form method="POST" class="job-footer">
                                        <input type="hidden" name="order_id" value="<?= $row['id'] ?>">
                                        <select name="order_status" class="form-input" style="flex:1; margin:0;">
                                            <option <?= $row['status'] == 'pending' ? 'selected' : '' ?> value="pending">Pending</option>
                                            <option <?= $row['status'] == 'ReadyToShip' ? 'selected' : '' ?> value="ReadyToShip">Ready</option>
                                            <option <?= $row['status'] == 'Shipped' ? 'selected' : '' ?> value="Shipped">Shipped</option>
                                            <option <?= $row['status'] == 'Delivered' ? 'selected' : '' ?> value="Delivered">Delivered</option>
                                        </select>
                                        <select name="payment_status" class="form-input" style="flex:1; margin:0;">
                                            <option <?= $row['payment_status'] == 'Pending' ? 'selected' : '' ?> value="Pending">Unpaid</option>
                                            <option <?= $row['payment_status'] == 'Paid' ? 'selected' : '' ?> value="Paid">Paid</option>
                                        </select>
                                        <button type="submit" name="update_sales_order" class="btn-primary" style="width:auto; background:#3b82f6; padding:8px 12px;"><i class="fas fa-check"></i></button>
                                    </form>
                                </div>
                            <?php endif; ?>

                        <?php endforeach;
                    else: ?>
                        <div style="grid-column:1/-1; padding:15px; text-align:center; font-size:0.85rem; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:6px;">No Active Jobs</div>
                    <?php endif; ?>
                </div>

                <h3 style="font-size:0.9rem; margin-bottom:10px; color:#64748b; text-transform:uppercase; font-weight:700;">History & Filters</h3>

                <form method="GET" class="filter-bar">
                    <input type="hidden" name="view" value="<?= $view ?>">
                    <div class="filter-item">
                        <label class="form-label">Name / Phone</label>
                        <input type="text" name="search" class="form-input" value="<?= htmlspecialchars($search) ?>" placeholder="Search...">
                    </div>
                    <div class="filter-item">
                        <label class="form-label">Date</label>
                        <input type="date" name="date" class="form-input" value="<?= htmlspecialchars($date_filter) ?>">
                    </div>
                    <div class="filter-item">
                        <label class="form-label">Status</label>
                        <select name="status_filter" class="form-input">
                            <option value="">All</option>
                            <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Processing" <?= $status_filter == 'Processing' ? 'selected' : '' ?>>Processing</option>
                            <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="Delivered" <?= $status_filter == 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                        </select>
                    </div>
                    <div class="filter-item" style="flex:0; min-width:auto;">
                        <button type="submit" class="btn-primary" style="padding:10px 20px;">Filter</button>
                    </div>
                    <div class="filter-item" style="flex:0; min-width:auto;">
                        <a href="admin_orders.php?view=<?= $view ?>" style="display:block; padding:10px; color:#64748b; text-decoration:none; text-align:center;">Reset</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <?php if ($view === 'services'): ?>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Item</th>
                                    <th>In</th>
                                    <th>Out</th>
                                    <th>Pay Status</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Act</th>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <th>Order No</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Pay Status</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Act</th>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php if (!empty($history_list)): foreach ($history_list as $row): ?>

                                    <?php if ($view === 'services'): ?>
                                        <tr>
                                            <td>#<?= $row['id'] ?></td>
                                            <td><?= date('d M', strtotime($row['service_date'])) ?></td>
                                            <td><?= htmlspecialchars($row['customer_name']) ?><br><small style="color:#94a3b8"><?= $row['phone'] ?></small></td>
                                            <td><?= htmlspecialchars($row['seed_type']) ?></td>
                                            <td><?= $row['weight_kg'] ?> Kg</td>
                                            <td><?= $row['oil_returned'] ?> / <?= $row['cake_returned'] ?></td>

                                            <td><span class="badge st-<?= strtolower($row['payment_status'] ?? 'pending') ?>"><?= $row['payment_status'] ?? 'Pending' ?></span></td>

                                            <td><span class="badge st-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                                            <td style="text-align:right;">
                                                <a href="#" onclick="openPrintEngine('job_sticker', <?= $row['id'] ?>)" class="btn-icon" style="color:#4f46e5; margin-right:5px; text-decoration:none;" title="Print Slip"><i class="fas fa-print"></i></a>
                                                <a href="?view=services&delete_service=<?= $row['id'] ?>" onclick="return confirm('Delete?')" class="btn-icon" style="color:#ef4444;"><i class="fas fa-trash"></i></a>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td>
                                                <a href="#" onclick="viewOrderDetails(<?= $row['id'] ?>); return false;" style="color:#3b82f6; font-weight:bold; text-decoration:none;">
                                                    #<?= $row['order_no'] ?>
                                                </a>
                                            </td>
                                            <td><?= date('d M', strtotime($row['created_at'])) ?></td>
                                            <td><?= $row['customer_name'] ?></td>
                                            <td>₹<?= number_format($row['total'], 2) ?></td>
                                            <td><span class="badge st-<?= strtolower($row['payment_status']) ?>"><?= $row['payment_status'] ?></span></td>
                                            <td><span class="badge st-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                                            <td style="text-align:right;"><a href="?view=orders&delete_order=<?= $row['id'] ?>" onclick="return confirm('Delete?')" class="btn-icon" style="color:#ef4444;"><i class="fas fa-trash"></i></a></td>
                                        </tr>
                                    <?php endif; ?>

                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="9" style="text-align:center; padding:20px;">No records found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </main>
        </div>
    </div>
    <div id="globalOrderModal" class="global-modal">
        <div class="g-modal-content">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.1rem; color:#0f172a;"><i class="fas fa-receipt text-primary"></i> Order Details</h3>
                <button class="g-close-btn" onclick="closeGlobalOrder()">&times;</button>
            </div>
            <div class="g-modal-body" id="globalOrderBody">
                <div style="text-align:center; padding:30px; color:#94a3b8;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading details...
                </div>
            </div>
            <div style="padding:15px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; text-align:right;">
                <button class="btn-primary" style="width:auto; padding:8px 15px; background:#64748b;" onclick="closeGlobalOrder()">Close</button>
            </div>
        </div>
    </div>
    <script>
        // --- 1. Dynamic Print Engine Opener ---
        function openPrintEngine(docType, refId) {
            window.open(`print_engine.php?doc=${docType}&id=${refId}`, 'PrintWindow', 'width=400,height=600');
        }

        // --- 2. Auto Print Trigger for New Jobs ---
        <?php if (isset($_GET['print_id']) && !empty($_GET['print_id'])): ?>
            openPrintEngine('job_sticker', <?= intval($_GET['print_id']) ?>);
            if (window.history.replaceState) {
                const url = new URL(window.location);
                url.searchParams.delete('print_id');
                url.searchParams.delete('msg');
                window.history.replaceState(null, '', url);
            }
        <?php endif; ?>

        // --- 3. Rate Auto Fill ---
        const lastRates = <?php echo json_encode($last_rates); ?>;

        function autoFillRate() {
            const seed = document.getElementById('seed_select').value;
            if (seed && lastRates[seed]) {
                document.getElementById('r_input').value = lastRates[seed];
                calcTotal();
            }
        }

        function calcTotal() {
            let w = parseFloat(document.getElementById('w_input').value) || 0;
            let r = parseFloat(document.getElementById('r_input').value) || 0;
            document.getElementById('t_disp').value = "₹ " + (w * r).toFixed(2);
        }

        // --- 4. Customer Live Search ---
        const sInput = document.getElementById('c_search');
        const sList = document.getElementById('c_list');
        const idInput = document.getElementById('c_id');
        const phInput = document.getElementById('c_phone');

        if (sInput) {
            sInput.addEventListener('input', function() {
                idInput.value = "0";
                if (this.value.length < 2) {
                    sList.style.display = 'none';
                    return;
                }

                const fd = new FormData();
                fd.append('action', 'search_customer');
                fd.append('term', this.value);
                fetch('sales_entry.php', {
                    method: 'POST',
                    body: fd
                }).then(r => r.json()).then(data => {
                    let html = '';
                    data.forEach(c => {
                        html += `<div class="s-item" onclick="selCust('${c.id}', '${c.value}', '${c.phone}')">${c.value} (${c.phone})</div>`;
                    });
                    sList.innerHTML = html;
                    sList.style.display = 'block';
                });
            });
        }

        function selCust(id, name, phone) {
            idInput.value = id;
            sInput.value = name;
            phInput.value = phone;
            sList.style.display = 'none';
        }
        // --- GLOBAL ORDER VIEWER JS ---
        function viewOrderDetails(orderId) {
            const modal = document.getElementById('globalOrderModal');
            const body = document.getElementById('globalOrderBody');

            // Show modal with loading state
            modal.classList.add('active');
            body.innerHTML = '<div style="text-align:center; padding:30px; color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading details...</div>';

            // Fetch data from our new PHP file
            fetch(`ajax_order_details.php?id=${orderId}`)
                .then(response => response.text())
                .then(html => {
                    body.innerHTML = html; // Inject the design
                })
                .catch(err => {
                    body.innerHTML = '<div style="color:red; text-align:center; padding:20px;">Failed to load order details.</div>';
                });
        }

        function closeGlobalOrder() {
            document.getElementById('globalOrderModal').classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('globalOrderModal');
            if (event.target == modal) {
                closeGlobalOrder();
            }
        }
    </script>

</body>

</html>