<?php
// admin_orders.php - PRO VERSION (Live Search, No Decimal Return, Edit Delivered)
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    header('location:login.php');
    exit;
}

// ==========================================
// --- ACTIONS ---
// ==========================================

// 1. ADD JOB WORK
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

// 2. EDIT JOB WORK (Works for Active & Delivered)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_service_order'])) {
    $sid = intval($_POST['edit_job_id']);
    $weight = floatval($_POST['e_weight_kg']);
    $rate = floatval($_POST['e_rate_per_kg']);
    $total = $weight * $rate;
    $note = $_POST['e_notes'];

    $stmt = $conn->prepare("UPDATE service_orders SET weight_kg=?, rate_per_kg=?, total_amount=?, notes=? WHERE id=?");
    $stmt->bind_param("dddsi", $weight, $rate, $total, $note, $sid);
    $stmt->execute();
    header("Location: admin_orders.php?view=services&msg=JobUpdated");
    exit;
}

// 3. UPDATE SERVICE STATUS (STAGE 1 & STAGE 2)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service_status'])) {
    $sid = intval($_POST['service_id']);
    $status = $_POST['status'];
    $payment_status = $_POST['payment_status'] ?? 'Pending';

    if (isset($_POST['oil_returned']) && isset($_POST['cake_returned'])) {
        $oil = floatval($_POST['oil_returned']);
        $cake = floatval($_POST['cake_returned']);
        $conn->query("UPDATE service_orders SET status='$status', oil_returned='$oil', cake_returned='$cake' WHERE id=$sid");
    } else {
        $cake_settlement = $_POST['cake_settlement'] ?? 'customer';

        $conn->begin_transaction();
        try {
            if ($cake_settlement === 'factory') {
                $payment_status = 'Waived (Cake Kept)';
                $note = "Cake Kept - Job #$sid";
                $check_stock = $conn->query("SELECT id FROM raw_material_inventory WHERE notes = '$note'");

                if ($check_stock->num_rows == 0) {
                    $jobData = $conn->query("SELECT seed_type, cake_returned FROM service_orders WHERE id=$sid")->fetch_assoc();
                    $s_name = $jobData['seed_type'];
                    $cake_qty = floatval($jobData['cake_returned']);

                    if ($cake_qty > 0) {
                        $seed_res = $conn->query("SELECT id FROM seeds_master WHERE name = '$s_name'");
                        if ($seed_res && $seed_res->num_rows > 0) {
                            $seed_id = $seed_res->fetch_assoc()['id'];
                            $conn->query("INSERT INTO raw_material_inventory (seed_id, product_type, transaction_type, quantity, unit, source_type, notes, transaction_date) VALUES ($seed_id, 'CAKE', 'RAW_IN', $cake_qty, 'KG', 'PRODUCTION', '$note', NOW())");
                        }
                    }
                }
            }

            $stmt = $conn->prepare("UPDATE service_orders SET status=?, payment_status=? WHERE id=?");
            $stmt->bind_param("ssi", $status, $payment_status, $sid);
            $stmt->execute();

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            die("Error: " . $e->getMessage());
        }
    }
    header("Location: admin_orders.php?view=services&msg=Updated");
    exit;
}

// 4. UPDATE SALES ORDER STATUS (Active Orders)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sales_order'])) {
    $oid = intval($_POST['order_id']);
    $status = $_POST['order_status'];
    $pay_status = $_POST['payment_status'];

    $check_cancel = $conn->query("SELECT status FROM orders WHERE id=$oid")->fetch_assoc();
    if ($check_cancel['status'] !== 'Cancelled') {
        $extra = ($pay_status == 'Paid') ? ", paid_amount = total, due_amount = 0" : "";
        $conn->query("UPDATE orders SET status='$status', payment_status='$pay_status' $extra WHERE id=$oid");
        header("Location: admin_orders.php?view=orders&msg=Updated");
    } else {
        header("Location: admin_orders.php?view=orders&error=Cannot update a Cancelled Order");
    }
    exit;
}

// 5. BASIC EDIT FOR DELIVERED SALES ORDERS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_sales_order_basic'])) {
    $oid = intval($_POST['eso_id']);
    $status = $_POST['eso_status'];
    $pstatus = $_POST['eso_pstatus'];

    $conn->query("UPDATE orders SET status='$status', payment_status='$pstatus' WHERE id=$oid");
    header("Location: admin_orders.php?view=orders&msg=Order Updated");
    exit;
}

// 6. DELETE JOB WORK (Service Order)
if (isset($_GET['delete_service'])) {
    $id = intval($_GET['delete_service']);
    $conn->query("DELETE FROM service_orders WHERE id=$id");
    $conn->query("DELETE FROM raw_material_inventory WHERE notes = 'Cake Kept - Job #$id'");
    header("Location: admin_orders.php?view=services&msg=Deleted");
    exit;
}

// ==========================================
// 🌟 7. CANCEL SALES ORDER (SMART STOCK RETURN) 🌟
// ==========================================
if (isset($_GET['cancel_order'])) {
    $id = intval($_GET['cancel_order']);
    
    $conn->begin_transaction();
    try {
        $order_check = $conn->query("SELECT status FROM orders WHERE id=$id")->fetch_assoc();
        if ($order_check['status'] === 'Cancelled') throw new Exception("Order is already cancelled.");

        $items_res = $conn->query("
            SELECT oi.product_id, oi.qty, p.product_type, p.seed_id, p.weight 
            FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id=$id
        ");

        if ($items_res) {
            while ($item = $items_res->fetch_assoc()) {
                $p_id = intval($item['product_id']);
                $qty = floatval($item['qty']); 
                $p_type = strtolower($item['product_type']);
                $s_id = intval($item['seed_id']);
                $weight = floatval($item['weight'] ?? 1); 
                if ($weight <= 0) $weight = 1;

                if ($p_type == 'seed') {
                    $return_weight = $qty * $weight;
                    $conn->query("UPDATE seeds_master SET current_stock = current_stock + $return_weight WHERE id = $s_id");
                } elseif ($p_type == 'raw_oil') {
                    $return_weight = $qty * $weight;
                    $note = "Stock Returned - Order Cancelled #$id";
                    if ($s_id > 0) {
                        $stmtInv = $conn->prepare("INSERT INTO raw_material_inventory (seed_id, product_type, transaction_type, quantity, unit, source_type, notes, created_at) VALUES (?, 'OIL', 'RAW_IN', ?, 'KG', 'ADJUSTMENT', ?, NOW())");
                        $stmtInv->bind_param("ids", $s_id, $return_weight, $note);
                        $stmtInv->execute();
                    }
                } else {
                    // 🌟 FIXED: Added mfg_date (CURDATE) and strict Error Checking 🌟
                    $stmtPack = $conn->prepare("INSERT INTO inventory_products (product_id, batch_no, qty, unit, transaction_type, mfg_date, created_at) VALUES (?, 'RETURN', ?, 'Pcs', 'RETURN', CURDATE(), NOW())");
                    if(!$stmtPack) throw new Exception("DB Error: " . $conn->error);
                    $stmtPack->bind_param("id", $p_id, $qty);
                    if(!$stmtPack->execute()) throw new Exception("Stock Update Failed: " . $stmtPack->error);
                }
            }
        }
        $conn->query("UPDATE orders SET status='Cancelled', payment_status='Refunded/Cancelled' WHERE id=$id");
        $conn->commit();
        header("Location: admin_orders.php?view=orders&msg=Order Cancelled & Stock Returned");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: admin_orders.php?view=orders&error=" . urlencode($e->getMessage()));
        exit;
    }
}

// ==========================================
// 🌟 8. PROCESS SINGLE ITEM RETURN 🌟
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_return'])) {
    $order_id = intval($_POST['return_order_id']);
    $item_id = intval($_POST['return_item_id']); 
    $return_qty = intval($_POST['return_qty']); 
    $return_reason = $conn->real_escape_string($_POST['return_reason'] ?? 'Customer Return');

    if ($return_qty > 0) {
        $conn->begin_transaction();
        try {
            $check_item = $conn->query("SELECT qty FROM order_items WHERE order_id = $order_id AND product_id = $item_id")->fetch_assoc();
            if (!$check_item) throw new Exception("This item does not belong to this order!");
            
            $actual_bought_qty = intval($check_item['qty']);
            if ($return_qty > $actual_bought_qty) throw new Exception("Invalid Return! Customer only bought $actual_bought_qty items.");

            $p_info = $conn->query("SELECT product_type, seed_id, weight, base_price FROM products WHERE id = $item_id")->fetch_assoc();
            
            if ($p_info) {
                $p_type = strtolower($p_info['product_type']);
                $s_id = intval($p_info['seed_id']);
                $weight = floatval($p_info['weight'] ?? 1); 
                if ($weight <= 0) $weight = 1;
                $refund_amount = $return_qty * floatval($p_info['base_price']);

                if ($p_type == 'seed') {
                    $return_weight = $return_qty * $weight;
                    $conn->query("UPDATE seeds_master SET current_stock = current_stock + $return_weight WHERE id = $s_id");
                } elseif ($p_type == 'raw_oil') {
                    $return_weight = $return_qty * $weight;
                    $note = "Partial Return - Order #$order_id";
                    if ($s_id > 0) {
                        $stmtInv = $conn->prepare("INSERT INTO raw_material_inventory (seed_id, product_type, transaction_type, quantity, unit, source_type, notes, created_at) VALUES (?, 'OIL', 'RAW_IN', ?, 'KG', 'ADJUSTMENT', ?, NOW())");
                        $stmtInv->bind_param("ids", $s_id, $return_weight, $note);
                        $stmtInv->execute();
                    }
                } else {
                    // 🌟 FIXED: Added mfg_date (CURDATE) and strict Error Checking 🌟
                    $stmtPack = $conn->prepare("INSERT INTO inventory_products (product_id, batch_no, qty, unit, transaction_type, mfg_date, created_at) VALUES (?, 'RETURN', ?, 'Pcs', 'RETURN', CURDATE(), NOW())");
                    if(!$stmtPack) throw new Exception("DB Error: " . $conn->error);
                    $stmtPack->bind_param("id", $item_id, $return_qty);
                    if(!$stmtPack->execute()) throw new Exception("Stock Update Failed: " . $stmtPack->error);
                }

                $conn->query("UPDATE orders SET total = GREATEST(0, total - $refund_amount) WHERE id = $order_id");
                $conn->query("UPDATE order_items SET qty = GREATEST(0, qty - $return_qty), line_total = GREATEST(0, line_total - $refund_amount) WHERE order_id = $order_id AND product_id = $item_id");

                $conn->commit();
                header("Location: admin_orders.php?view=orders&msg=Item Returned & Stock Updated");
                exit;
            }
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: admin_orders.php?view=orders&error=" . urlencode($e->getMessage()));
            exit;
        }
    }
}

// --- DATA FETCHING FOR UI ---
$view = $_GET['view'] ?? 'orders';
$today_date = date('Y-m-d');

$q1 = $conn->query("SELECT COUNT(id) as cnt FROM orders WHERE DATE(created_at) = '$today_date' AND status != 'Cancelled'");
$today_orders = $q1->fetch_assoc()['cnt'] ?? 0;

$q2 = $conn->query("SELECT SUM(total) as amt FROM orders WHERE DATE(created_at) = '$today_date' AND status != 'Cancelled'");
$today_sale = $q2->fetch_assoc()['amt'] ?? 0;

$q3 = $conn->query("SELECT COUNT(id) as cnt FROM orders WHERE status = 'Pending'");
$pending_packing = $q3->fetch_assoc()['cnt'] ?? 0;

$q4 = $conn->query("SELECT SUM(total - paid_amount) as amt FROM orders WHERE payment_status != 'Paid' AND status != 'Cancelled'");
$unpaid_amount = $q4->fetch_assoc()['amt'] ?? 0;

$seeds_list = [];
$last_rates = [];
$s_query = $conn->query("SELECT name FROM seeds_master ORDER BY name");
while ($row = $s_query->fetch_assoc()) $seeds_list[] = $row['name'];
$r_query = $conn->query("SELECT seed_type, rate_per_kg FROM service_orders");
while ($row = $r_query->fetch_assoc()) $last_rates[$row['seed_type']] = $row['rate_per_kg'];

$date_filter = $_GET['date'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';

$processing_list = [];
$completed_list = [];
$history_list = [];

if ($view === 'services') {
    $res_proc = $conn->query("SELECT s.*, c.name as customer_name, c.phone FROM service_orders s LEFT JOIN customers c ON s.user_id = c.id WHERE s.status IN ('Pending', 'Processing') ORDER BY s.service_date DESC");
    if ($res_proc) while ($r = $res_proc->fetch_assoc()) $processing_list[] = $r;

    $res_comp = $conn->query("SELECT s.*, c.name as customer_name, c.phone FROM service_orders s LEFT JOIN customers c ON s.user_id = c.id WHERE s.status = 'Completed' ORDER BY s.service_date DESC");
    if ($res_comp) while ($r = $res_comp->fetch_assoc()) $completed_list[] = $r;

    $where = "1=1";
    if ($date_filter) $where .= " AND DATE(s.service_date) = '$date_filter'";
    if ($status_filter) $where .= " AND s.status = '$status_filter'";

    $res_hist = $conn->query("SELECT s.*, c.name as customer_name, c.phone FROM service_orders s LEFT JOIN customers c ON s.user_id = c.id WHERE $where ORDER BY s.service_date DESC LIMIT 100");
    if ($res_hist) while ($r = $res_hist->fetch_assoc()) $history_list[] = $r;
} else {
    $res_act = $conn->query("SELECT o.*, c.name as customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.status IN ('pending', 'ReadyToShip', 'Shipped') ORDER BY o.created_at DESC");
    if ($res_act) while ($r = $res_act->fetch_assoc()) $active_list[] = $r;

    $where = "1=1";
    if ($date_filter) $where .= " AND DATE(o.created_at) = '$date_filter'";
    if ($status_filter) $where .= " AND o.status = '$status_filter'";

    $res_hist = $conn->query("SELECT o.*, c.name as customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE $where ORDER BY o.created_at DESC LIMIT 100");
    if ($res_hist) while ($r = $res_hist->fetch_assoc()) $history_list[] = $r;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Operations | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
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
            background: #f1f5f9;
            padding: 4px;
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .tab-btn {
            text-decoration: none;
            color: #64748b;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: 0.2s;
            border: none;
            background: transparent;
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn-upload {
            background: #4f46e5;
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
            box-shadow: 0 2px 4px rgba(79, 70, 229, 0.2);
        }

        .btn-upload:hover {
            background: #4338ca;
            transform: translateY(-1px);
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 24px;
            align-items: start;
        }

        main {
            min-width: 0;
            width: 100%;
        }

        .jobs-grid {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            gap: 15px;
            margin-bottom: 25px;
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
            border-radius: 10px;
        }

        .job-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            font-size: 0.85rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            min-width: 330px;
            max-width: 330px;
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
        }

        .job-card.processing {
            border-top: 4px solid var(--warning);
        }

        .job-card.ready {
            border-top: 4px solid var(--success);
        }

        .job-card.cancelled {
            border-top: 4px solid var(--danger);
            opacity: 0.8;
        }

        .job-card.cancelled .job-header {
            background: #fef2f2;
        }

        .job-header {
            padding: 12px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .job-body {
            padding: 15px;
            flex: 1;
        }

        .job-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            color: #475569;
        }

        .job-row strong {
            color: #0f172a;
            font-weight: 600;
        }

        .job-footer {
            padding: 12px;
            background: #f8fafc;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border);
            flex-wrap: wrap;
            align-items: end;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
        }

        .filter-item {
            flex: 1;
            min-width: 150px;
        }

        .suggestions {
            position: absolute;
            background: white;
            width: 100%;
            border: 1px solid #cbd5e1;
            border-top: none;
            border-radius: 0 0 6px 6px;
            max-height: 150px;
            overflow-y: auto;
            z-index: 50;
            display: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .s-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
        }

        .s-item:hover {
            background-color: #f8fafc;
            color: var(--primary);
        }

        .section-title {
            font-size: 1rem;
            margin-bottom: 12px;
            color: #1e293b;
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @media (max-width: 1024px) {
            .grid-layout {
                grid-template-columns: 1fr;
                display: flex;
                flex-direction: column;
            }

            aside {
                order: 1;
                width: 100%;
            }

            main {
                order: 2;
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header-actions,
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
            }

            .filter-item {
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert" style="padding:10px 15px; background:#dcfce7; color:#166534; border-radius:6px; margin-bottom:15px; border:1px solid #bbf7d0; font-weight:600;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['msg']) ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert" style="padding:10px 15px; background:#fef2f2; color:#991b1b; border-radius:6px; margin-bottom:15px; border:1px solid #fca5a5; font-weight:600;">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_GET['error']) ?>
            </div>
        <?php endif; ?>

        <div class="page-header card" style="padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 25px;">
            <h1 class="page-title"><i class="fas fa-tasks"></i> Operations</h1>

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
                        <div class="card-header"><span>Create New Job</span> <i class="fas fa-plus text-primary"></i></div>
                        <div style="padding: 20px;">
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
                                <div style="display:flex; gap:12px;">
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
                                    <label class="form-label">Total Amount</label>
                                    <input type="text" id="t_disp" class="form-input" readonly style="background:#f8fafc; font-weight:700; color:var(--primary); font-size:1.1rem;">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Note / Remark</label>
                                    <input type="text" name="notes" class="form-input" placeholder="Optional">
                                </div>
                                <button type="submit" name="add_service_order" class="btn btn-primary" style="width:100%; margin-top:10px;"><i class="fas fa-plus-circle" style="margin-right:8px;"></i> Add Job & Print Slip</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header"><span><i class="fas fa-chart-pie text-primary" style="margin-right:8px;"></i> Live Sales Dashboard</span></div>
                        <div style="padding: 20px;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px 10px; border-radius: 8px; text-align: center;">
                                    <div style="color: #64748b; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px;">Today's Orders</div>
                                    <div style="font-size: 1.5rem; font-weight: 800; color: #0f172a;"><?= $today_orders ?></div>
                                </div>
                                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px 10px; border-radius: 8px; text-align: center;">
                                    <div style="color: #166534; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px;">Today's Sale</div>
                                    <div style="font-size: 1.3rem; font-weight: 800; color: #15803d;">₹<?= number_format($today_sale) ?></div>
                                </div>
                                <div style="background: #fff7ed; border: 1px solid #ffedd5; padding: 15px 10px; border-radius: 8px; text-align: center;">
                                    <div style="color: #c2410c; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px;">Pending Pack</div>
                                    <div style="font-size: 1.5rem; font-weight: 800; color: #ea580c;"><?= $pending_packing ?></div>
                                </div>
                                <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 15px 10px; border-radius: 8px; text-align: center;">
                                    <div style="color: #b91c1c; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px;">Total Unpaid</div>
                                    <div style="font-size: 1.3rem; font-weight: 800; color: #dc2626;">₹<?= number_format($unpaid_amount) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </aside>

            <main>
                <?php if ($view === 'services'): ?>
                    <div class="section-title"><i class="fas fa-cogs text-warning"></i> Stage 1: Machine Processing</div>
                    <div class="jobs-grid">
                        <?php if (!empty($processing_list)): foreach ($processing_list as $row): ?>
                                <div class="job-card processing">
                                    <div class="job-header">
                                        <strong style="font-size:1rem;">#<?= $row['id'] ?></strong>
                                        <span class="badge st-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span>
                                    </div>
                                    <div class="job-body">
                                        <div class="job-row"><span>Customer:</span> <strong><?= htmlspecialchars($row['customer_name']) ?></strong></div>
                                        <div class="job-row"><span>Seed / Item:</span> <?= htmlspecialchars($row['seed_type']) ?></div>
                                        <div class="job-row"><span>Inward Weight:</span> <?= $row['weight_kg'] ?> Kg</div>
                                        <div class="job-row" style="color:var(--primary); font-weight:700; font-size:1rem; margin-top:12px; border-top:1px dashed #e2e8f0; padding-top:12px;">
                                            <span>Bill Amount:</span> ₹<?= number_format($row['total_amount'], 2) ?>
                                        </div>
                                    </div>
                                    <form method="POST" class="job-footer">
                                        <input type="hidden" name="service_id" value="<?= $row['id'] ?>">
                                        <div style="background:#fffbeb; padding:10px; border-radius:6px; border:1px solid #fde68a;">
                                            <div style="font-size:0.75rem; font-weight:700; color:#b45309; margin-bottom:5px;">ENTER OUTPUT TO COMPLETE</div>
                                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                                <div><input type="number" name="oil_returned" step="0.01" class="form-input" style="padding:6px; font-size:0.85rem;" placeholder="Oil (Kg)" required></div>
                                                <div><input type="number" name="cake_returned" step="0.01" class="form-input" style="padding:6px; font-size:0.85rem;" placeholder="Cake (Kg)" required></div>
                                            </div>
                                        </div>
                                        <input type="hidden" name="status" value="Completed">
                                        <div style="display:flex; gap:8px; width:100%;">
                                            <button type="submit" name="update_service_status" class="btn btn-warning" style="flex:1; padding:8px;"><i class="fas fa-check-circle"></i> Mark Completed</button>
                                            <button type="button" onclick='editJobWork(<?= json_encode($row) ?>)' class="btn btn-outline" style="padding:8px 12px; color:var(--text-main);" title="Edit Job"><i class="fas fa-edit"></i></button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach;
                        else: ?>
                            <div style="flex:1; padding:20px; text-align:center; font-size:0.95rem; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:8px; background:white;">No active jobs on machine.</div>
                        <?php endif; ?>
                    </div>

                    <div class="section-title" style="margin-top:20px;"><i class="fas fa-box-open text-success"></i> Stage 2: Ready For Delivery</div>
                    <div class="jobs-grid">
                        <?php if (!empty($completed_list)): foreach ($completed_list as $row): ?>
                                <div class="job-card ready">
                                    <div class="job-header">
                                        <strong style="font-size:1rem;">#<?= $row['id'] ?></strong>
                                        <span class="badge st-completed">Ready</span>
                                    </div>
                                    <div class="job-body">
                                        <div class="job-row"><span>Customer:</span> <strong><?= htmlspecialchars($row['customer_name']) ?></strong></div>
                                        <div class="job-row"><span>Item Processed:</span> <?= htmlspecialchars($row['seed_type']) ?></div>
                                        <div class="job-row" style="background:#f1f5f9; padding:8px; border-radius:6px;">
                                            <span style="font-size:0.8rem;">OUTPUT:</span>
                                            <strong>Oil: <?= $row['oil_returned'] ?>Kg | Cake: <?= $row['cake_returned'] ?>Kg</strong>
                                        </div>
                                        <div class="job-row" style="color:var(--primary); font-weight:700; font-size:1rem; margin-top:12px; border-top:1px dashed #e2e8f0; padding-top:12px;">
                                            <span>Bill Amount:</span> ₹<?= number_format($row['total_amount'], 2) ?>
                                        </div>
                                    </div>
                                    <form method="POST" class="job-footer">
                                        <input type="hidden" name="service_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="status" value="Delivered">
                                        <div style="padding:10px; border-radius:6px; border:1px solid #e2e8f0; background:#fff;">
                                            <div style="margin-bottom:10px;">
                                                <label style="font-size:0.7rem; font-weight:700; color:var(--text-muted);">CAKE SETTLEMENT</label>
                                                <select name="cake_settlement" class="form-input" style="padding:6px; font-size:0.85rem; border-color:#f59e0b; background:#fffbeb;" onchange="autoWaivePayment(this)">
                                                    <option value="customer">Customer Took Cake</option>
                                                    <option value="factory">Factory Kept Cake</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label style="font-size:0.7rem; font-weight:700; color:var(--text-muted);">PAYMENT STATUS</label>
                                                <select name="payment_status" class="form-input" style="padding: 6px; font-size:0.85rem; font-weight:600;">
                                                    <option value="Pending" <?= ($row['payment_status'] ?? 'Pending') == 'Pending' ? 'selected' : '' ?>>Unpaid (Payment Due)</option>
                                                    <option value="Paid" <?= ($row['payment_status'] ?? 'Pending') == 'Paid' ? 'selected' : '' ?>>Paid (Cash Received)</option>
                                                    <option value="Waived (Cake Kept)" style="display:none;">Fee Waived (Hidden)</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div style="display:flex; gap:8px; width:100%;">
                                            <button type="submit" name="update_service_status" class="btn btn-success" style="flex:1; padding:8px; background:var(--success); border-color:var(--success); color:white;"><i class="fas fa-truck"></i> Deliver Order</button>
                                            <a href="#" onclick="openPrintEngine('job_sticker', <?= $row['id'] ?>)" class="btn btn-outline" style="padding:8px 12px;" title="Print Slip"><i class="fas fa-print text-primary"></i></a>
                                            <button type="button" onclick='editJobWork(<?= json_encode($row) ?>)' class="btn btn-outline" style="padding:8px 12px; color:var(--text-main);" title="Edit Job"><i class="fas fa-edit"></i></button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach;
                        else: ?>
                            <div style="flex:1; padding:20px; text-align:center; font-size:0.95rem; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:8px; background:white;">No jobs ready for delivery right now.</div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <div class="section-title"><i class="fas fa-box"></i> Active Sales Orders</div>
                    <div class="jobs-grid">
                        <?php if (!empty($active_list)): foreach ($active_list as $row): ?>
                                <div class="job-card" style="border-top-color:#3b82f6;">
                                    <div class="job-header">
                                        <strong style="color:#3b82f6; font-size:1rem;">#<?= $row['order_no'] ?></strong>
                                        <span class="badge st-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span>
                                    </div>
                                    <div class="job-body">
                                        <div class="job-row"><span>Customer:</span> <strong><?= $row['customer_name'] ?></strong></div>
                                        <div class="job-row"><span>Payment:</span> <span class="badge st-<?= strtolower($row['payment_status']) ?>"><?= $row['payment_status'] ?></span></div>
                                        <div class="job-row" style="color:#3b82f6; font-weight:700; font-size:1rem; margin-top:12px; border-top:1px dashed #e2e8f0; padding-top:12px;">
                                            <span>Total Value:</span> ₹<?= number_format($row['total'], 2) ?>
                                        </div>
                                    </div>
                                    <form method="POST" class="job-footer">
                                        <input type="hidden" name="order_id" value="<?= $row['id'] ?>">
                                        <div style="display:flex; width:100%; gap:8px;">
                                            <select name="order_status" class="form-input" style="flex:2; padding: 8px;">
                                                <option <?= $row['status'] == 'pending' ? 'selected' : '' ?> value="pending">Pending</option>
                                                <option <?= $row['status'] == 'ReadyToShip' ? 'selected' : '' ?> value="ReadyToShip">Ready</option>
                                                <option <?= $row['status'] == 'Shipped' ? 'selected' : '' ?> value="Shipped">Shipped</option>
                                                <option <?= $row['status'] == 'Delivered' ? 'selected' : '' ?> value="Delivered">Delivered</option>
                                            </select>
                                            <select name="payment_status" class="form-input" style="flex:1.5; padding: 8px;">
                                                <option <?= $row['payment_status'] == 'Pending' ? 'selected' : '' ?> value="Pending">Unpaid</option>
                                                <option <?= $row['payment_status'] == 'Paid' ? 'selected' : '' ?> value="Paid">Paid</option>
                                            </select>
                                            <button type="submit" name="update_sales_order" class="btn btn-primary" style="background:#3b82f6; padding:8px 12px; flex-shrink:0;"><i class="fas fa-check"></i></button>
                                        </div>
                                        <div style="display:flex; justify-content:space-between; margin-top:8px;">
                                            <a href="#" onclick="viewOrderDetails(<?= $row['id'] ?>); return false;" class="btn btn-outline" style="padding:6px 10px; font-size:0.8rem;"><i class="fas fa-eye"></i> View</a>
                                            <a href="#" onclick='editSalesOrder(<?= json_encode($row) ?>); return false;' class="btn btn-outline" style="padding:6px 10px; font-size:0.8rem; color:var(--warning); border-color:var(--warning);"><i class="fas fa-edit"></i> Edit</a>
                                            <a href="?view=orders&cancel_order=<?= $row['id'] ?>" onclick="return confirm('Cancel this Order and return all stock?')" class="btn btn-outline" style="padding:6px 10px; font-size:0.8rem; color:var(--danger); border-color:var(--danger);"><i class="fas fa-ban"></i> Cancel</a>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach;
                        else: ?>
                            <div style="flex:1; padding:30px; text-align:center; font-size:0.95rem; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:8px; background:white;">No active sales orders.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="section-title" style="margin-top:20px;"><i class="fas fa-history text-muted"></i> History & Filters</div>

                <form method="GET" class="filter-bar" onsubmit="return false;">
                    <input type="hidden" name="view" value="<?= $view ?>">
                    <div class="filter-item">
                        <label class="form-label">Live Search (Name, Order, Phone)</label>
                        <input type="text" id="liveSearchInput" class="form-input" placeholder="Type to search live..." autocomplete="off">
                    </div>
                    <div class="filter-item">
                        <label class="form-label">Filter by Date</label>
                        <input type="date" name="date" class="form-input" value="<?= htmlspecialchars($date_filter) ?>" onchange="this.form.submit()">
                    </div>
                    <div class="filter-item">
                        <label class="form-label">Order Status</label>
                        <select name="status_filter" class="form-input" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?>>Completed/Shipped</option>
                            <option value="Delivered" <?= $status_filter == 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                            <?php if ($view == 'orders'): ?>
                                <option value="Cancelled" <?= $status_filter == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="filter-item" style="flex:0; min-width:auto;">
                        <a href="admin_orders.php?view=<?= $view ?>" class="btn btn-outline" style="padding:10px 20px; text-decoration:none;">Reset</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <?php if ($view === 'services'): ?>
                                <tr>
                                    <th>Job ID</th>
                                    <th>Date</th>
                                    <th>Customer Details</th>
                                    <th>Item Processed</th>
                                    <th>Inward</th>
                                    <th>Outward (Oil/Cake)</th>
                                    <th>Pay Status</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Action</th>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <th>Order No</th>
                                    <th>Date</th>
                                    <th>Customer Name</th>
                                    <th>Total Amount</th>
                                    <th>Pay Status</th>
                                    <th>Order Status</th>
                                    <th style="text-align:right;">Action</th>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php if (!empty($history_list)): foreach ($history_list as $row): ?>
                                    <?php if ($view === 'services'): ?>
                                        <tr>
                                            <td style="font-weight:700; color:#0f172a;">#<?= $row['id'] ?></td>
                                            <td><?= date('d M, Y', strtotime($row['service_date'])) ?></td>
                                            <td><strong style="color:#334155;"><?= htmlspecialchars($row['customer_name']) ?></strong><br><small style="color:#64748b"><?= $row['phone'] ?></small></td>
                                            <td><?= htmlspecialchars($row['seed_type']) ?></td>
                                            <td style="font-weight:600;"><?= $row['weight_kg'] ?> Kg</td>
                                            <td><?= $row['oil_returned'] ?> / <?= $row['cake_returned'] ?></td>
                                            <td><span class="badge st-<?= strtolower($row['payment_status'] ?? 'pending') ?>"><?= $row['payment_status'] ?? 'Pending' ?></span></td>
                                            <td><span class="badge st-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                                            <td style="text-align:right; white-space:nowrap;">
                                                <a href="#" onclick="openPrintEngine('job_sticker', <?= $row['id'] ?>)" class="btn-icon print" title="Print Slip"><i class="fas fa-print"></i></a>
                                                <a href="#" onclick='editJobWork(<?= json_encode($row) ?>); return false;' class="btn-icon" style="color:var(--warning); margin-right:5px;" title="Edit Job"><i class="fas fa-edit"></i></a>
                                                <a href="?view=services&delete_service=<?= $row['id'] ?>" onclick="return confirm('Are you sure you want to delete this Job?')" class="btn-icon delete" title="Delete"><i class="fas fa-trash"></i></a>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr <?= $row['status'] == 'Cancelled' ? 'style="background:#fef2f2; opacity:0.8;"' : '' ?>>
                                            <td>
                                                <a href="#" onclick="viewOrderDetails(<?= $row['id'] ?>); return false;" style="color:#3b82f6; font-weight:700; text-decoration:none;">
                                                    #<?= $row['order_no'] ?>
                                                </a>
                                            </td>
                                            <td><?= date('d M, Y', strtotime($row['created_at'])) ?></td>
                                            <td><strong style="color:#334155;"><?= $row['customer_name'] ?></strong></td>
                                            <td style="font-weight:700; color:#0f172a;">
                                                ₹<?= number_format($row['total'], 2) ?>
                                                <?php if ($row['status'] == 'Cancelled') echo '<br><small style="color:var(--danger)">Refunded</small>'; ?>
                                            </td>
                                            <td><span class="badge st-<?= strtolower(str_replace(' ', '', $row['payment_status'])) ?>"><?= $row['payment_status'] ?></span></td>
                                            <td><span class="badge st-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                                            <td style="text-align:right; white-space:nowrap;">
                                                <?php if ($row['status'] !== 'Cancelled'): ?>
                                                    <a href="#" onclick='editSalesOrder(<?= json_encode($row) ?>); return false;' class="btn-icon" style="color:var(--warning); margin-right:8px;" title="Edit Details"><i class="fas fa-edit"></i></a>
                                                    <a href="#" onclick="openReturnModal(<?= $row['id'] ?>); return false;" class="btn-icon" style="color:var(--primary); margin-right:8px;" title="Return Item"><i class="fas fa-undo"></i></a>
                                                    <a href="?view=orders&cancel_order=<?= $row['id'] ?>" onclick="return confirm('Cancel this full Order and return stock?')" class="btn-icon delete" title="Cancel Order"><i class="fas fa-ban"></i></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="9" style="text-align:center; padding:40px; color:#94a3b8; font-size:0.95rem;">No history records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>

    <div id="editJobModal" class="global-modal">
        <div class="g-modal-content" style="max-width:400px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.1rem;"><i class="fas fa-edit text-warning" style="margin-right:8px;"></i> Edit Job Details</h3>
                <button class="g-close-btn" onclick="closeEditJob()">&times;</button>
            </div>
            <div class="g-modal-body">
                <form method="POST">
                    <input type="hidden" name="edit_job_id" id="ej_id">
                    <input type="hidden" name="edit_service_order" value="1">

                    <div class="form-group" style="margin-bottom:15px;">
                        <label class="form-label">Customer Name</label>
                        <input type="text" id="ej_cust" class="form-input" readonly style="background:#f1f5f9; color:#64748b;">
                    </div>
                    <div class="form-group" style="margin-bottom:15px;">
                        <label class="form-label">Seed Processed</label>
                        <input type="text" id="ej_seed" class="form-input" readonly style="background:#f1f5f9; color:#64748b;">
                    </div>
                    <div style="display:flex; gap:10px; margin-bottom:15px;">
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">Weight (Kg)</label>
                            <input type="number" name="e_weight_kg" id="ej_wt" step="0.01" class="form-input" required>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">Rate (₹)</label>
                            <input type="number" name="e_rate_per_kg" id="ej_rt" step="0.01" class="form-input" required>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label">Notes</label>
                        <input type="text" name="e_notes" id="ej_note" class="form-input">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; padding:10px;"><i class="fas fa-save"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <div id="editSalesOrderModal" class="global-modal">
        <div class="g-modal-content" style="max-width:400px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.1rem;"><i class="fas fa-edit text-warning" style="margin-right:8px;"></i> Edit Sales Order</h3>
                <button type="button" class="g-close-btn" onclick="closeEditSalesOrder()">&times;</button>
            </div>
            <div class="g-modal-body">
                <form method="POST">
                    <input type="hidden" name="eso_id" id="eso_id">
                    <input type="hidden" name="edit_sales_order_basic" value="1">

                    <div class="form-group" style="margin-bottom:15px;">
                        <label class="form-label">Order Status</label>
                        <select name="eso_status" id="eso_status" class="form-input">
                            <option value="pending">Pending</option>
                            <option value="ReadyToShip">ReadyToShip</option>
                            <option value="Shipped">Shipped</option>
                            <option value="Delivered">Delivered</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label">Payment Status</label>
                        <select name="eso_pstatus" id="eso_pstatus" class="form-input">
                            <option value="Pending">Unpaid (Pending)</option>
                            <option value="Paid">Paid</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; padding:10px;"><i class="fas fa-save"></i> Update Order</button>
                </form>
            </div>
        </div>
    </div>

    <div id="returnItemModal" class="global-modal">
        <div class="g-modal-content" style="max-width:400px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.1rem;"><i class="fas fa-undo text-warning" style="margin-right:8px;"></i> Return Specific Item</h3>
                <button type="button" class="g-close-btn" onclick="closeReturnModal()">&times;</button>
            </div>
            <div class="g-modal-body">
                <form method="POST">
                    <input type="hidden" name="process_return" value="1">
                    <input type="hidden" name="return_order_id" id="ret_order_id">

                    <div class="form-group" style="margin-bottom:15px;">
                        <label class="form-label">Select Item to Return</label>
                        <select name="return_item_id" id="ret_item_select" class="form-input" required>
                            <option value="">Loading items...</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:15px;">
                        <label class="form-label">Quantity Returning (Pcs/Bags)</label>
                        <input type="number" name="return_qty" id="ret_qty" step="1" min="1" class="form-input" placeholder="How many?" required onkeypress="return event.charCode >= 48 && event.charCode <= 57">
                        <small style="color:var(--text-muted); display:block; margin-top:5px;">This quantity will be added back to your stock.</small>
                    </div>
                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label">Reason (Optional)</label>
                        <input type="text" name="return_reason" class="form-input" placeholder="e.g. Damaged packing">
                    </div>
                    <button type="submit" class="btn btn-warning" style="width:100%; padding:10px;"><i class="fas fa-check"></i> Process Return</button>
                </form>
            </div>
        </div>
    </div>

    <div id="globalOrderModal" class="global-modal">
        <div class="g-modal-content">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.1rem; color:#0f172a;"><i class="fas fa-receipt text-primary" style="margin-right:8px;"></i> Order Details</h3>
                <button type="button" class="g-close-btn" onclick="closeGlobalOrder()">&times;</button>
            </div>
            <div class="g-modal-body" id="globalOrderBody">
                <div style="text-align:center; padding:30px; color:#94a3b8;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i><br><br>Loading order details...
                </div>
            </div>
            <div style="padding:15px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; text-align:right;">
                <button type="button" class="btn btn-outline" style="width:auto; padding:8px 20px;" onclick="closeGlobalOrder()">Close Window</button>
            </div>
        </div>
    </div>

    <script>
        // --- 🌟 LIVE SEARCH JS 🌟 ---
        document.getElementById('liveSearchInput').addEventListener('input', function(e) {
            let term = e.target.value.toLowerCase();

            // Filter Job Cards
            document.querySelectorAll('.job-card').forEach(card => {
                let text = card.innerText.toLowerCase();
                card.style.display = text.includes(term) ? '' : 'none';
            });

            // Filter History Table Rows
            document.querySelectorAll('.table-wrap tbody tr').forEach(row => {
                let text = row.innerText.toLowerCase();
                if (row.innerText.includes("No history records") || text.includes(term)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // --- SMART CAKE SETTLEMENT LOGIC ---
        function autoWaivePayment(selectElement) {
            const form = selectElement.closest('form');
            const payStatusSelect = form.querySelector('[name="payment_status"]');

            if (selectElement.value === 'factory') {
                payStatusSelect.value = 'Waived (Cake Kept)';
                payStatusSelect.style.backgroundColor = '#dcfce7';
                payStatusSelect.style.color = '#166534';
            } else {
                payStatusSelect.value = 'Paid';
                payStatusSelect.style.backgroundColor = '';
                payStatusSelect.style.color = '';
            }
        }

        // --- Print Engine Opener ---
        function openPrintEngine(docType, refId) {
            window.open(`print_engine.php?doc=${docType}&id=${refId}`, 'PrintWindow', 'width=400,height=600');
        }

        <?php if (isset($_GET['print_id']) && !empty($_GET['print_id'])): ?>
            openPrintEngine('job_sticker', <?= intval($_GET['print_id']) ?>);
            if (window.history.replaceState) {
                const url = new URL(window.location);
                url.searchParams.delete('print_id');
                url.searchParams.delete('msg');
                window.history.replaceState(null, '', url);
            }
        <?php endif; ?>

        // --- Rate Auto Fill ---
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

        // --- Customer Live Search ---
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

        // --- EDIT JOB WORK JS ---
        function editJobWork(data) {
            document.getElementById('ej_id').value = data.id;
            document.getElementById('ej_cust').value = data.customer_name;
            document.getElementById('ej_seed').value = data.seed_type;
            document.getElementById('ej_wt').value = data.weight_kg;
            document.getElementById('ej_rt').value = data.rate_per_kg;
            document.getElementById('ej_note').value = data.notes || '';
            document.getElementById('editJobModal').classList.add('active');
        }

        function closeEditJob() {
            document.getElementById('editJobModal').classList.remove('active');
        }

        // --- EDIT SALES ORDER JS ---
        function editSalesOrder(data) {
            document.getElementById('eso_id').value = data.id;
            document.getElementById('eso_status').value = data.status;
            document.getElementById('eso_pstatus').value = data.payment_status;
            document.getElementById('editSalesOrderModal').classList.add('active');
        }

        function closeEditSalesOrder() {
            document.getElementById('editSalesOrderModal').classList.remove('active');
        }

        // --- GLOBAL ORDER VIEWER JS ---
        function viewOrderDetails(orderId) {
            const modal = document.getElementById('globalOrderModal');
            const body = document.getElementById('globalOrderBody');

            modal.classList.add('active');
            body.innerHTML = '<div style="text-align:center; padding:40px; color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x"></i><br><br>Loading order details...</div>';

            fetch(`ajax_order_details.php?id=${orderId}`)
                .then(response => response.text())
                .then(html => {
                    body.innerHTML = html;
                })
                .catch(err => {
                    body.innerHTML = '<div style="color:red; text-align:center; padding:20px;">Failed to load order details. Please try again.</div>';
                });
        }

        function closeGlobalOrder() {
            document.getElementById('globalOrderModal').classList.remove('active');
        }

        // --- RETURN ITEM LOGIC ---
        function openReturnModal(orderId) {
            const modal = document.getElementById('returnItemModal');
            document.getElementById('ret_order_id').value = orderId;
            const select = document.getElementById('ret_item_select');

            select.innerHTML = '<option>Loading...</option>';
            modal.classList.add('active');

            fetch(`ajax_order_details.php?id=${orderId}&get_json=1`)
                .then(r => r.json())
                .then(items => {
                    if (items.length === 0) {
                        select.innerHTML = '<option value="">No items found</option>';
                    } else {
                        let html = '';
                        items.forEach(i => {
                            html += `<option value="${i.product_id}">${i.name} (Bought: ${i.qty})</option>`;
                        });
                        select.innerHTML = html;
                    }
                }).catch(err => {
                    select.innerHTML = '<option value="">Failed to load items. View details first.</option>';
                });
        }

        function closeReturnModal() {
            document.getElementById('returnItemModal').classList.remove('active');
        }


        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('globalOrderModal')) closeGlobalOrder();
            if (event.target == document.getElementById('editJobModal')) closeEditJob();
            if (event.target == document.getElementById('editSalesOrderModal')) closeEditSalesOrder();
            if (event.target == document.getElementById('returnItemModal')) closeReturnModal();
        }
    </script>
</body>

</html>