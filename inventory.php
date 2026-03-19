<?php
// inventory.php - Complete Inventory Management Center
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

// ================= DATA FETCHING =================
// HANDLE AJAX REQUESTS
// ================= NEW LOGIC START =================

// 0. FETCH PACKAGING VENDORS (For Dropdown)
$vendors = [];
$sql_v = "SELECT id, name FROM sellers WHERE category IN ('Packaging', 'Both') ORDER BY name";
$res_v = $conn->query($sql_v);
if ($res_v) {
    while ($r = $res_v->fetch_assoc()) {
        $vendors[] = $r;
    }
}

// F. ADD PACKAGING ITEM (ADVANCED: With Avg Price & Vendor Link)
if (isset($_POST['action']) && $_POST['action'] == 'add_packaging') {
    ob_clean();
    header('Content-Type: application/json');

    $name = trim($_POST['p_name']);
    $cat = $_POST['p_category'];
    $new_qty = floatval($_POST['p_qty']); // New Quantity
    $alert = intval($_POST['p_alert']);
    $unit = $_POST['p_unit'];

    // New Fields
    $rate_per_unit = floatval($_POST['p_rate']); // Rate per pc
    $vendor_id = intval($_POST['p_vendor']);
    $pay_mode = $_POST['p_payment_mode'];

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Item Name is required']);
        exit;
    }

    try {
        $conn->begin_transaction();

        // 1. STOCK & AVERAGE PRICE CALCULATION
        $check = $conn->query("SELECT id, quantity, avg_price FROM inventory_packaging WHERE item_name = '$name'");

        if ($check->num_rows > 0) {
            // Update Existing Item
            $row = $check->fetch_assoc();
            $old_qty = floatval($row['quantity']);
            $old_avg = floatval($row['avg_price']);

            // Weighted Average Formula
            $total_old_val = $old_qty * $old_avg;
            $total_new_val = $new_qty * $rate_per_unit;
            $final_qty = $old_qty + $new_qty;

            $new_avg_price = ($final_qty > 0) ? ($total_old_val + $total_new_val) / $final_qty : $rate_per_unit;

            $stmt = $conn->prepare("UPDATE inventory_packaging SET quantity = ?, avg_price = ?, last_price = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("dddi", $final_qty, $new_avg_price, $rate_per_unit, $row['id']);
            $stmt->execute();
        } else {
            // Create New Item
            $stmt = $conn->prepare("INSERT INTO inventory_packaging (item_name, category, quantity, unit, alert_level, avg_price, last_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdsidd", $name, $cat, $new_qty, $unit, $alert, $rate_per_unit, $rate_per_unit);
            $stmt->execute();
        }

        // 2. EXPENSE ENTRY
        $total_cost = $new_qty * $rate_per_unit;

        if ($total_cost > 0) {
            $date = date('Y-m-d');
            $category = 'Packaging Purchase';

            // Get Vendor Name for Description
            $v_name = "Unknown";
            $v_res = $conn->query("SELECT name FROM sellers WHERE id = $vendor_id");
            if ($v_res && $v_res->num_rows > 0) {
                $v_name = $v_res->fetch_assoc()['name'];
            }

            $desc = "Purchase of $new_qty $unit $name @ ₹$rate_per_unit from $v_name";
            $admin_id = $_SESSION['admin_id'];

            // Insert into factory_expenses
            $stmtExp = $conn->prepare("INSERT INTO factory_expenses 
                (date, category, vendor_id, amount, description, payment_mode, status, authorized_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'Paid', ?, NOW())");

            $stmtExp->bind_param("ssidssi", $date, $category, $vendor_id, $total_cost, $desc, $pay_mode, $admin_id);

            if (!$stmtExp->execute()) {
                throw new Exception("Expense Error: " . $stmtExp->error);
            }
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Stock & Expense Added Successfully!']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// ================= NEW LOGIC END =================
// F. ADD PACKAGING ITEM & EXPENSE ENTRY (UPDATED)
if (isset($_POST['action']) && $_POST['action'] == 'add_packaging') {
    ob_clean();
    header('Content-Type: application/json');

    $name = trim($_POST['p_name']);
    $cat = $_POST['p_category'];
    $qty = floatval($_POST['p_qty']);
    $alert = intval($_POST['p_alert']);
    $unit = $_POST['p_unit'];

    // New Fields for Expense
    $cost = floatval($_POST['p_cost']); // Total Amount
    $pay_mode = $_POST['p_payment_mode']; // Cash/Online

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Item Name is required']);
        exit;
    }

    try {
        $conn->begin_transaction(); // Transaction start taaki dono tables me data ek sath jaye

        // 1. UPDATE INVENTORY (Stock Badhana)
        $check = $conn->query("SELECT id, quantity FROM inventory_packaging WHERE item_name = '$name'");

        if ($check->num_rows > 0) {
            // Agar item pehle se hai -> Update Quantity
            $row = $check->fetch_assoc();
            $new_qty = $row['quantity'] + $qty;
            $conn->query("UPDATE inventory_packaging SET quantity = $new_qty, updated_at = NOW() WHERE id = {$row['id']}");
        } else {
            // Agar naya item hai -> Insert New
            $stmt = $conn->prepare("INSERT INTO inventory_packaging (item_name, category, quantity, unit, alert_level) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdsi", $name, $cat, $qty, $unit, $alert);
            $stmt->execute();
        }

        // 2. ADD EXPENSE ENTRY (Agar Cost daali hai to)
        if ($cost > 0) {
            $date = date('Y-m-d'); // Aaj ki date
            $category = 'Packaging Purchase'; // Fixed Category
            $desc = "Purchase of $qty $unit - $name"; // Description auto-generate
            $status = 'Paid'; // Maan ke chal rahe hain payment ho gayi
            $admin_id = $_SESSION['admin_id']; // Authorized By

            // Aapki table structure ke hisaab se Query
            $stmtExp = $conn->prepare("INSERT INTO factory_expenses 
                (date, category, amount, description, payment_mode, status, authorized_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

            // Bind: s=string, s=string, d=double, s=string, s=string, s=string, i=int
            $stmtExp->bind_param("ssdsssi", $date, $category, $cost, $desc, $pay_mode, $status, $admin_id);

            if (!$stmtExp->execute()) {
                throw new Exception("Expense Insert Failed: " . $stmtExp->error);
            }
        }

        $conn->commit(); // Sab sahi hai to save karo
        echo json_encode(['success' => true, 'message' => 'Stock & Expense Added Successfully!']);
    } catch (Exception $e) {
        $conn->rollback(); // Error aaya to wapas piche jao
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
if (isset($_POST['action']) && $_POST['action'] == 'get_seed_ledger') {

    ob_clean();
    header('Content-Type: application/json');

    $seed_id = (int)$_POST['seed_id'];


    $sql = "SELECT transaction_date, transaction_type, batch_no, quantity, notes 
            FROM inventory 
            WHERE seed_id = ? 
            ORDER BY transaction_date ASC";

    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("SQL Error: " . $conn->error);

        $stmt->bind_param("i", $seed_id);
        $stmt->execute();
        $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $row['date'] = date('d M Y, h:i A', strtotime($row['transaction_date']));
            $data[] = $row;
        }

        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        // Error ko JSON format me bhejein
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// HANDLE AJAX: LOOSE STOCK LEDGER
if (isset($_POST['action']) && $_POST['action'] == 'get_loose_ledger') {
    ob_clean();
    header('Content-Type: application/json');

    $seed_id = (int)$_POST['seed_id'];
    $prod_type = $_POST['product_type']; // 'OIL' or 'CAKE'

    // Fetch history from raw_material_inventory
    $sql = "SELECT transaction_date, transaction_type, batch_no, quantity, notes, source_type 
            FROM raw_material_inventory 
            WHERE seed_id = ? AND product_type = ? 
            ORDER BY transaction_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $seed_id, $prod_type);
    $stmt->execute();
    $res = $stmt->get_result();

    $data = [];
    while ($row = $res->fetch_assoc()) {
        $row['date'] = date('d M Y, h:i A', strtotime($row['transaction_date']));
        $data[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}
// 1. RAW MATERIAL (SEEDS)
$seeds_stock = [];
$sql_seeds = "SELECT sm.id,sm.name, sm.category, sm.current_stock FROM seeds_master sm ORDER BY sm.name";
$res_seeds = $conn->query($sql_seeds);
if ($res_seeds) {
    while ($row = $res_seeds->fetch_assoc()) {
        $seeds_stock[] = $row;
    }
}


// 2. LOOSE STOCK (Oil & Cake from raw_material_inventory)
$loose_stock = [];
$sql_loose = "
    SELECT 
        sm.name as seed_name,
        rmi.seed_id,
        rmi.product_type,
        SUM(CASE 
            WHEN rmi.transaction_type IN ('RAW_IN', 'ADJUSTMENT_IN') THEN rmi.quantity 
            WHEN rmi.transaction_type IN ('RAW_OUT', 'ADJUSTMENT_OUT') THEN -rmi.quantity 
            ELSE 0 
        END) as current_qty
    FROM raw_material_inventory rmi
    JOIN seeds_master sm ON rmi.seed_id = sm.id
    GROUP BY rmi.seed_id, rmi.product_type
    HAVING current_qty > 0
    ORDER BY sm.name, rmi.product_type";

$res_loose = $conn->query($sql_loose);
if ($res_loose) {
    while ($row = $res_loose->fetch_assoc()) {
        // Formatting name for display (e.g., "Mustard - OIL")
        $row['display_name'] = $row['seed_name'] . ' - ' . $row['product_type'];
        $row['storage_location'] = ($row['product_type'] == 'OIL') ? 'Oil Tank' : 'Cake Heap/Silo';
        $loose_stock[] = $row;
    }
}

// 3. PACKING MATERIAL - Example Logic
$packing_stock = [];
try {
    $sql_pack = "SELECT item_name, category, quantity, unit, alert_level FROM inventory_packaging ORDER BY item_name";
    $res_pack = $conn->query($sql_pack);
    if ($res_pack) {
        while ($row = $res_pack->fetch_assoc()) {
            $packing_stock[] = $row;
        }
    }
} catch (Exception $e) {
}

// 4. FINISHED GOODS - Example Logic
$finished_stock = [];
try {
    $sql_prod = "SELECT p.name as product_name, ip.batch_no, ip.qty, ip.unit, ip.mfg_date 
                 FROM inventory_products ip 
                 JOIN products p ON ip.product_id = p.id 
                 ORDER BY ip.mfg_date DESC";
    $res_prod = $conn->query($sql_prod);
    if ($res_prod) {
        while ($row = $res_prod->fetch_assoc()) {
            $finished_stock[] = $row;
        }
    }
} catch (Exception $e) {
}

// 5. RECENT GRN LIST (For Bottom Section)
$grn_list = [];
$sql_grn = "SELECT ig.*, s.name as seller_name FROM inventory_grn ig LEFT JOIN sellers s ON ig.seller_id = s.id ORDER BY ig.created_at DESC LIMIT 10";
$res_grn = $conn->query($sql_grn);
if ($res_grn) {
    while ($row = $res_grn->fetch_assoc()) {
        $grn_list[] = $row;
    }
}

function formatCurrency($amount)
{
    return '₹' . number_format($amount, 2);
}
function formatDate($date)
{
    return date('d M Y, h:i A', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Inventory Management | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* --- MODERN CSS VARIABLES (Same as GRN Page) --- */
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg-body: #f3f4f6;
            --bg-card: #ffffff;
            --text-main: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --radius: 8px;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding-bottom: 60px;
        }

        /* --- LAYOUT --- */
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;

        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge.GRN_IN {
            background: #dcfce7;
            color: #166534;
        }

        .badge.GRN_OUT {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge.PRODUCTION_OUT {
            background: #fef3c7;
            color: #92400e;
        }

        @media (max-width: 992px) {
            .container {
                margin-left: 0;
                padding: 15px;
            }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            color: var(--primary);
        }

        /* --- TABS NAVIGATION --- */
        .tabs-container {
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 2px;
            /* For focus ring visibility */
        }

        .tab-btn {
            padding: 10px 20px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-muted);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.95rem;
            white-space: nowrap;
        }

        .tab-btn:hover {
            color: var(--primary);
            background: #eef2ff;
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        /* --- TAB CONTENT AREAS --- */
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* --- CARDS & TABLES --- */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: #f9fafb;
            font-weight: 600;
            font-size: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th {
            background: #f9fafb;
            text-align: left;
            padding: 12px 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            font-size: 0.95rem;
            color: var(--text-main);
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: #f9fafb;
        }

        /* --- BUTTONS --- */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            gap: 8px;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: white;
            border: 1px solid var(--border);
            color: var(--text-main);
        }

        .btn-outline:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .action-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .action-link:hover {
            text-decoration: underline;
        }

        /* --- BADGES --- */
        .stock-badge {
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .stock-ok {
            background: #d1fae5;
            color: #065f46;
        }

        .stock-low {
            background: #fee2e2;
            color: #991b1b;
        }

        .stock-wip {
            background: #fef3c7;
            color: #92400e;
        }

        /* --- MODAL --- */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            width: 90%;
            max-width: 800px;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 20px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .close-modal {
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }

        /* GRN Detail Styles inside Modal */
        .grn-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }

        .grn-label {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .grn-val {
            font-weight: 600;
            color: var(--text-main);
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="page-header">
            <div class="page-title">
                <i class="fas fa-boxes-stacked"></i> Inventory Overview
            </div>
            <div>
                <a href="grn.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New GRN (Inward)
                </a>
                <a href="production_entry.php" class="btn btn-outline" style="margin-left:10px;">
                    <i class="fas fa-industry"></i> Production Entry
                </a>
            </div>
        </div>

        <div class="tabs-container">
            <button class="tab-btn active" onclick="openTab('tab-seeds')">
                <i class="fas fa-seedling"></i> Raw Material (Seeds)
            </button>
            <button class="tab-btn" onclick="openTab('tab-loose')">
                <i class="fas fa-oil-can"></i> Loose Stock (Oil/Cake)
            </button>
            <button class="tab-btn" onclick="openTab('tab-packing')">
                <i class="fas fa-box-open"></i> Packaging Material
            </button>
            <button class="tab-btn" onclick="openTab('tab-finished')">
                <i class="fas fa-check-circle"></i> Finished Goods
            </button>
        </div>

        <div id="tab-seeds" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <span>Current Seed Stock</span>
                    <span class="stock-badge stock-ok"><?= count($seeds_stock) ?> Items</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Seed Name</th>
                                <th>Category</th>
                                <th>Current Stock (Kg)</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($seeds_stock)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding:20px; color:#999;">No seeds found. Add stock via GRN.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($seeds_stock as $seed): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($seed['name']) ?></strong></td>
                                        <td><?= htmlspecialchars($seed['category']) ?></td>
                                        <td style="font-weight:bold;"><?= number_format($seed['current_stock'], 3) ?></td>
                                        <td>
                                            <?php if ($seed['current_stock'] < 100): ?>
                                                <span class="stock-badge stock-low">Low</span>
                                            <?php else: ?>
                                                <span class="stock-badge stock-ok">In Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><button class="btn btn-outline btn-sm" onclick="viewLedger(<?= $seed['id'] ?>, '<?= htmlspecialchars($seed['name']) ?>')">
                                                <i class="fas fa-book"></i> Ledger
                                            </button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-loose" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <span>Work In Progress (Oil & Cake)</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Type</th>
                                <th>Storage Location</th>
                                <th>Quantity (Kg)</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($loose_stock)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding:20px; color:#999;">
                                        No loose stock found. Process seeds to generate stock.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($loose_stock as $item): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($item['seed_name']) ?></strong></td>
                                        <td>
                                            <span class="badge" style="background:<?= $item['product_type'] == 'OIL' ? '#fff7ed' : '#ecfccb' ?>; color:<?= $item['product_type'] == 'OIL' ? '#c2410c' : '#3f6212' ?>;">
                                                <?= $item['product_type'] ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($item['storage_location']) ?></td>
                                        <td style="font-weight:bold;"><?= number_format($item['current_qty'], 2) ?></td>
                                        <td>
                                            <button class="btn btn-outline btn-sm"
                                                onclick="viewLooseLedger(<?= $item['seed_id'] ?>, '<?= $item['product_type'] ?>', '<?= htmlspecialchars($item['display_name']) ?>')">
                                                <i class="fas fa-book"></i> Ledger
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-packing" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <span>Packaging Consumables</span>
                    <button class="btn btn-primary btn-sm" style="width:auto;" onclick="openPackModal()">
                        <i class="fas fa-plus"></i> Add Stock / New Item
                    </button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Current Qty</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($packing_stock)): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center; padding:20px; color:#999;">No packaging material defined.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($packing_stock as $item): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                                        </td>
                                        <td><span class="badge" style="background:#f3f4f6;"><?= $item['category'] ?? 'General' ?></span></td>
                                        <td style="font-weight:bold;"><?= number_format($item['quantity']) ?> <?= $item['unit'] ?></td>
                                        <td>
                                            <?php if ($item['quantity'] <= $item['alert_level']): ?>
                                                <span class="stock-badge stock-low">Low Stock</span>
                                            <?php else: ?>
                                                <span class="stock-badge stock-ok">OK</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-finished" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <span>Ready for Sale (Packed)</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Batch No</th>
                                <th>Quantity</th>
                                <th>Mfg Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($finished_stock)): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center; padding:20px; color:#999;">No finished goods in stock.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($finished_stock as $prod): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($prod['product_name']) ?></td>
                                        <td><?= htmlspecialchars($prod['batch_no']) ?></td>
                                        <td><?= number_format($prod['qty']) ?> <?= $prod['unit'] ?></td>
                                        <td><?= date('d M Y', strtotime($prod['mfg_date'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <h3 style="margin-top:40px; margin-bottom:15px; font-size:1.2rem; color:var(--text-main);">
            <i class="fas fa-history" style="color:var(--text-muted);"></i> Recent GRN History
        </h3>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>GRN No</th>
                            <th>Seller</th>
                            <th>Vehicle No</th>
                            <th>Total Weight</th>
                            <th>Total Value</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grn_list)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:20px;">No records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($grn_list as $grn): ?>
                                <tr>
                                    <td style="color:var(--primary); font-weight:600;"><?= $grn['grn_no'] ?></td>
                                    <td><?= htmlspecialchars($grn['seller_name']) ?></td>
                                    <td><?= htmlspecialchars($grn['vehicle_no']) ?></td>
                                    <td><?= number_format($grn['total_weight_kg'], 2) ?> kg</td>
                                    <td style="color:var(--success); font-weight:600;"><?= formatCurrency($grn['total_value']) ?></td>
                                    <td><?= formatDate($grn['created_at']) ?></td>
                                    <td>
                                        <button class="btn btn-outline" style="padding:5px 10px; font-size:0.8rem;" onclick="viewGRN(<?= $grn['id'] ?>)">
                                            View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    <div id="grnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin:0;">GRN Details</h3>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="grnModalBody">
                <div style="text-align:center; padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>
    </div>
    <div id="ledgerModal" class="modal">
        <div class="modal-content" style="max-width:900px;">
            <div class="modal-header">
                <div>
                    <h3 style="margin:0;">Item Ledger</h3>
                    <small id="ledgerItemName" style="color:var(--primary); font-weight:600;">Loading...</small>
                </div>
                <span class="close-modal" onclick="closeLedgerModal()">&times;</span>
            </div>
            <div class="modal-body" id="ledgerBody">
            </div>
        </div>
    </div>
    <div id="packModal" class="modal">
        <div class="modal-content" style="max-width:600px;">
            <div class="modal-header">
                <h3 style="margin:0;">Add Packaging Stock</h3>
                <span class="close-modal" onclick="closePackModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="packForm">
                    <input type="hidden" name="action" value="add_packaging">

                    <div class="form-group" style="margin-bottom:15px;">
                        <label style="font-weight:600; display:block; margin-bottom:5px;">Item Name</label>
                        <input type="text" name="p_name" class="form-control" placeholder="e.g. 15 Kg Tin" required
                            style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div>
                            <label style="font-weight:600; display:block; margin-bottom:5px;">Category</label>
                            <select name="p_category" class="form-control" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                                <option value="Tin">Tin (Peepa)</option>
                                <option value="Bottle">Bottle</option>
                                <option value="Cap">Cap</option>
                                <option value="Label">Label</option>
                                <option value="Carton">Carton</option>
                                <option value="Bag">Bag</option>
                                <option value="Other">Jar</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600; display:block; margin-bottom:5px;">Supplier (Vendor)</label>
                            <select name="p_vendor" class="form-control" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                                <option value="">-- Select Supplier --</option>
                                <?php
                                // Only show vendors with category 'Packaging' or 'Both'
                                if (!empty($vendors)) {
                                    foreach ($vendors as $v) {
                                        echo "<option value='{$v['id']}'>{$v['name']}</option>";
                                    }
                                } else {
                                    echo "<option value='' disabled>No Packaging Vendors Found</option>";
                                }
                                ?>
                            </select>
                            <small style="display:block; margin-top:5px; font-size:0.8rem;">
                                <a href="#" style="color:var(--primary);">+ Add New Vendor</a>
                            </small>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; margin-bottom:15px;">
                        <div>
                            <label style="font-weight:600; display:block; margin-bottom:5px;">Quantity (+)</label>
                            <input type="number" name="p_qty" class="form-control" placeholder="0" required oninput="calcTotal()"
                                style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                        </div>
                        <div>
                            <label style="font-weight:600; display:block; margin-bottom:5px;">Rate / Pc (₹)</label>
                            <input type="number" name="p_rate" class="form-control" placeholder="0.00" step="0.01" required oninput="calcTotal()"
                                style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                        </div>
                        <div>
                            <label style="font-weight:600; display:block; margin-bottom:5px;">Unit</label>
                            <select name="p_unit" class="form-control" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                                <option value="Pcs">Pcs</option>
                                <option value="Kg">Kg</option>
                                <option value="Roll">Roll</option>
                            </select>
                        </div>
                    </div>

                    <div class="live-stats" style="background:#f0fdf4; padding:10px; border:1px solid #bbf7d0; margin-bottom:15px; border-radius:6px; text-align:center;">
                        <span style="color:#166534; font-weight:bold;">Total Cost: ₹<span id="totalCostDisplay">0.00</span></span>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label style="font-weight:600; display:block; margin-bottom:5px;">Payment Mode</label>
                        <select name="p_payment_mode" class="form-control" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                            <option value="Credit">Credit (Udhaar)</option>
                            <option value="Cash">Cash</option>
                            <option value="Online">Online / UPI</option>
                        </select>
                    </div>

                    <input type="hidden" name="p_alert" value="50">

                    <button type="submit" class="btn btn-primary" style="width:100%; padding:12px;">Save Stock & Expense</button>
                </form>
            </div>
        </div>
    </div>



    <script>
        function calcTotal() {
            const qty = parseFloat(document.querySelector('input[name="p_qty"]').value) || 0;
            const rate = parseFloat(document.querySelector('input[name="p_rate"]').value) || 0;
            document.getElementById('totalCostDisplay').innerText = (qty * rate).toFixed(2);
        }
        // PACKAGING MODAL LOGIC
        const packModal = document.getElementById('packModal');

        function openPackModal() {
            packModal.style.display = 'block';
        }

        function closePackModal() {
            packModal.style.display = 'none';
        }

        // Close on click outside
        window.onclick = function(e) {
            if (e.target == packModal) closePackModal();
            // Existing modal logic
            if (e.target == document.getElementById('grnModal')) closeModal();
            if (e.target == document.getElementById('ledgerModal')) closeLedgerModal();
            if (e.target == document.getElementById('seedModal')) closeSeedModal();
        }

        // AJAX SUBMIT
        document.getElementById('packForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!confirm("Confirm adding this packaging stock?")) return;

            const btn = this.querySelector('button');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            const fd = new FormData(this);

            fetch('inventory.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert(res.message);
                        window.location.reload();
                    } else {
                        alert("Error: " + res.error);
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    alert("System Error");
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        });
        // --- LOOSE STOCK LEDGER LOGIC ---
        function viewLooseLedger(seedId, prodType, displayName) {
            // Reusing the existing ledgerModal
            const ledgerModal = document.getElementById('ledgerModal');
            document.getElementById('ledgerItemName').innerText = displayName;
            ledgerModal.style.display = 'block';
            document.getElementById('ledgerBody').innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';

            const fd = new FormData();
            fd.append('action', 'get_loose_ledger');
            fd.append('seed_id', seedId);
            fd.append('product_type', prodType);

            fetch('inventory.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.data.length > 0) {
                        let rows = '';
                        let balance = 0;

                        res.data.forEach(row => {
                            let qty = parseFloat(row.quantity);
                            let type = row.transaction_type; // RAW_IN, RAW_OUT
                            let color = 'black';

                            // Logic for Balance Calculation based on raw_material_inventory ENUMs
                            if (type === 'RAW_IN' || type === 'ADJUSTMENT_IN') {
                                balance += qty;
                                color = 'green';
                            } else {
                                balance -= qty;
                                color = 'red';
                                qty = -qty; // Visual negative
                            }

                            rows += `
                                <tr>
                                    <td>${row.date}</td>
                                    <td><span class="badge" style="font-size:0.7rem; background:#f3f4f6;">${type}</span></td>
                                    <td>${row.batch_no || '-'}</td>
                                    <td><small>${row.notes || '-'}</small></td>
                                    <td style="color:${color}; font-weight:bold;">${qty.toFixed(2)}</td>
                                    <td style="background:#f9fafb; font-weight:bold;">${balance.toFixed(2)}</td>
                                </tr>
                            `;
                        });

                        const html = `
                            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                                <thead>
                                    <tr style="background:#f3f4f6; text-align:left;">
                                        <th style="padding:10px;">Date</th>
                                        <th>Type</th>
                                        <th>Batch</th>
                                        <th>Notes</th>
                                        <th>Qty</th>
                                        <th>Bal</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        `;
                        document.getElementById('ledgerBody').innerHTML = html;
                    } else {
                        document.getElementById('ledgerBody').innerHTML = '<p style="color:#666; text-align:center; padding:20px;">No transaction history found for this item.</p>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    document.getElementById('ledgerBody').innerHTML = '<p style="color:red; text-align:center;">System Error</p>';
                });
        }
        // --- LEDGER LOGIC ---
        const ledgerModal = document.getElementById('ledgerModal');

        function viewLedger(seedId, seedName) {
            document.getElementById('ledgerItemName').innerText = seedName;
            ledgerModal.style.display = 'block';
            document.getElementById('ledgerBody').innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';

            const fd = new FormData();
            fd.append('action', 'get_seed_ledger');
            fd.append('seed_id', seedId);

            // Call same file or a handler file
            fetch('inventory.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        let rows = '';
                        let balance = 0;

                        res.data.forEach(row => {
                            let qty = parseFloat(row.quantity);
                            let type = row.transaction_type;
                            let color = 'black';

                            // Logic for In/Out and Balance
                            if (type === 'GRN_IN' || type === 'PRODUCTION_IN' || type === 'ADJUSTMENT_IN') {
                                balance += qty;
                                color = 'green';
                            } else {
                                balance -= qty;
                                color = 'red';
                                qty = -qty; // Show negative for OUT
                            }

                            rows += `
                    <tr>
                        <td>${row.date}</td>
                        <td><span class="badge ${type}">${type}</span></td>
                        <td>${row.batch_no || '-'}</td>
                        <td>${row.notes || '-'}</td>
                        <td style="color:${color}; font-weight:bold;">${qty.toFixed(2)}</td>
                        <td style="background:#f9fafb; font-weight:bold;">${balance.toFixed(2)}</td>
                    </tr>
                `;
                        });

                        const html = `
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f3f4f6; text-align:left;">
                            <th style="padding:10px;">Date</th>
                            <th>Type</th>
                            <th>Batch/Ref</th>
                            <th>Notes</th>
                            <th>Qty</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            `;
                        document.getElementById('ledgerBody').innerHTML = html;
                    } else {
                        document.getElementById('ledgerBody').innerHTML = '<p style="color:red; text-align:center;">No transactions found.</p>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    document.getElementById('ledgerBody').innerHTML = '<p style="color:red; text-align:center;">System Error</p>';
                });
        }

        function closeLedgerModal() {
            ledgerModal.style.display = 'none';
        }
        // --- TAB SWITCHER ---
        function openTab(tabId) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));

            // Show selected
            document.getElementById(tabId).classList.add('active');
            // Highlight button (find button that called this, but simpler to loop logic if passed 'this')
            // Here we just find button with onclick matching ID
            const btns = document.getElementsByClassName('tab-btn');
            for (let btn of btns) {
                if (btn.getAttribute('onclick').includes(tabId)) {
                    btn.classList.add('active');
                }
            }
        }

        // --- GRN MODAL LOGIC ---
        const modal = document.getElementById('grnModal');
        const modalBody = document.getElementById('grnModalBody');

        function viewGRN(id) {
            modal.style.display = 'block';
            modalBody.innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';

            // AJAX Call
            const fd = new FormData();
            fd.append('action', 'get_grn_details');
            fd.append('grn_id', id);

            fetch('grn_handler.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const g = res.grn;
                        let itemsHtml = '';
                        res.items.forEach(item => {
                            itemsHtml += `
                                <tr>
                                    <td>${item.seed_name} (${item.category})</td>
                                    <td>₹${parseFloat(item.price_per_qtl).toFixed(2)}</td>
                                    <td>${parseFloat(item.weight_kg).toFixed(3)} kg</td>
                                    <td style="text-align:right;">₹${parseFloat(item.line_value).toFixed(2)}</td>
                                </tr>
                            `;
                        });

                        modalBody.innerHTML = `
                            <div class="grn-info-grid">
                                <div><span class="grn-label">GRN NO:</span> <div class="grn-val" style="color:var(--primary);">${g.grn_no}</div></div>
                                <div><span class="grn-label">Date:</span> <div class="grn-val">${new Date(g.created_at).toLocaleString()}</div></div>
                                <div><span class="grn-label">Seller:</span> <div class="grn-val">${g.seller_name}</div></div>
                                <div><span class="grn-label">Vehicle:</span> <div class="grn-val">${g.vehicle_no}</div></div>
                            </div>
                            <div style="margin-top:20px; border:1px solid var(--border); border-radius:8px; overflow:hidden;">
                                <table>
                                    <thead style="background:#f9fafb;">
                                        <tr><th>Item</th><th>Rate</th><th>Weight</th><th style="text-align:right;">Total</th></tr>
                                    </thead>
                                    <tbody>${itemsHtml}</tbody>
                                    <tfoot style="background:#f9fafb; font-weight:bold;">
                                        <tr>
                                            <td colspan="2" style="text-align:right;">Total:</td>
                                            <td>${parseFloat(g.total_weight_kg).toFixed(3)} kg</td>
                                            <td style="text-align:right;">₹${parseFloat(g.total_value).toFixed(2)}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div style="text-align:right; margin-top:20px;">
<button class="btn btn-outline" onclick="window.open('print_engine.php?doc=grn_receipt&id=${id}', 'ThermalPrint', 'width=400,height=600')">
    <i class="fas fa-print"></i> Print Receipt
</button>                                   <button class="btn btn-primary" onclick="closeModal()">Close</button>
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = `<p style="color:red; text-align:center;">Error: ${res.error}</p>`;
                    }
                })
                .catch(err => {
                    modalBody.innerHTML = `<p style="color:red; text-align:center;">Network Error</p>`;
                });
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        // Close on outside click
        window.onclick = function(e) {
            if (e.target == modal) closeModal();
        }

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === "Escape") closeModal();
        });
    </script>
</body>

</html>