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

// F. ADD PACKAGING ITEM
if (isset($_POST['action']) && $_POST['action'] == 'add_packaging') {
    ob_clean();
    header('Content-Type: application/json');

    $name = trim($_POST['p_name']);
    $cat = $_POST['p_category'];
    $new_qty = floatval($_POST['p_qty']);
    $alert = intval($_POST['p_alert']);
    $unit = $_POST['p_unit'];
    $rate_per_unit = floatval($_POST['p_rate']);
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
            $row = $check->fetch_assoc();
            $old_qty = floatval($row['quantity']);
            $old_avg = floatval($row['avg_price']);

            $total_old_val = $old_qty * $old_avg;
            $total_new_val = $new_qty * $rate_per_unit;
            $final_qty = $old_qty + $new_qty;

            $new_avg_price = ($final_qty > 0) ? ($total_old_val + $total_new_val) / $final_qty : $rate_per_unit;

            $stmt = $conn->prepare("UPDATE inventory_packaging SET quantity = ?, avg_price = ?, last_price = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("dddi", $final_qty, $new_avg_price, $rate_per_unit, $row['id']);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO inventory_packaging (item_name, category, quantity, unit, alert_level, avg_price, last_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdsidd", $name, $cat, $new_qty, $unit, $alert, $rate_per_unit, $rate_per_unit);
            $stmt->execute();
        }

        // 2. EXPENSE ENTRY
        $total_cost = $new_qty * $rate_per_unit;

        if ($total_cost > 0) {
            $date = date('Y-m-d');
            $category = 'Packaging Purchase';
            $v_name = "Unknown";
            $v_res = $conn->query("SELECT name FROM sellers WHERE id = $vendor_id");
            if ($v_res && $v_res->num_rows > 0) {
                $v_name = $v_res->fetch_assoc()['name'];
            }

            $desc = "Purchase of $new_qty $unit $name @ ₹$rate_per_unit from $v_name";
            $admin_id = $_SESSION['admin_id'];

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
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// HANDLE AJAX: LOOSE STOCK LEDGER
if (isset($_POST['action']) && $_POST['action'] == 'get_loose_ledger') {
    ob_clean();
    header('Content-Type: application/json');
    $seed_id = (int)$_POST['seed_id'];
    $prod_type = $_POST['product_type'];

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

// 🌟 NEW AJAX: FINISHED GOODS LEDGER 🌟
if (isset($_POST['action']) && $_POST['action'] == 'get_finished_ledger') {
    ob_clean();
    header('Content-Type: application/json');

    $prod_id = (int)$_POST['product_id'];

    $sql = "SELECT created_at as transaction_date, transaction_type, batch_no, qty 
            FROM inventory_products 
            WHERE product_id = ? 
            ORDER BY created_at ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $prod_id);
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

// 2. LOOSE STOCK (Oil & Cake)
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
        $row['display_name'] = $row['seed_name'] . ' - ' . $row['product_type'];
        $row['storage_location'] = ($row['product_type'] == 'OIL') ? 'Oil Tank' : 'Cake Heap/Silo';
        $loose_stock[] = $row;
    }
}

// 3. PACKING MATERIAL
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

// 🌟 4. FINISHED GOODS (TOTAL AVAILABLE STOCK) 🌟
$finished_stock = [];
try {
    $sql_prod = "SELECT 
                    p.id as product_id,
                    p.name as product_name,
                    p.barcode,
                    ip.unit,
                    SUM(CASE 
                        WHEN ip.transaction_type IN ('PRODUCTION', 'SALE_RETURN', 'ADJUSTMENT_IN') THEN ip.qty
                        WHEN ip.transaction_type IN ('SALE', 'DAMAGE', 'ADJUSTMENT_OUT') THEN -ip.qty
                        ELSE 0 
                    END) as current_qty
                 FROM inventory_products ip 
                 JOIN products p ON ip.product_id = p.id 
                 GROUP BY p.id, p.name, p.barcode, ip.unit
                 HAVING current_qty > 0
                 ORDER BY p.name ASC";

    $res_prod = $conn->query($sql_prod);
    if ($res_prod) {
        while ($row = $res_prod->fetch_assoc()) {
            $finished_stock[] = $row;
        }
    }
} catch (Exception $e) {
}

// 5. RECENT GRN LIST
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
    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }

        @media (max-width: 992px) {
            .container {
                padding: 15px;
            }
        }

        /* --- TABS NAVIGATION --- */
        .tabs-container {
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 2px;
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

        /* --- GRN SPECIFIC STYLES --- */
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

        .table-wrap table {
            min-width: 100% !important;
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="page-header" style="margin-bottom: 25px;">
            <div class="page-title">
                <i class="fas fa-boxes-stacked text-primary" style="margin-right:8px;"></i> Inventory Overview
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="grn.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New GRN</a>
                <a href="production_entry.php" class="btn btn-outline"><i class="fas fa-industry"></i> Production Entry</a>
            </div>
        </div>

        <div class="tabs-container">
            <button class="tab-btn active" onclick="openTab('tab-seeds')"><i class="fas fa-seedling"></i> Raw Material (Seeds)</button>
            <button class="tab-btn" onclick="openTab('tab-loose')"><i class="fas fa-oil-can"></i> Loose Stock (Oil/Cake)</button>
            <button class="tab-btn" onclick="openTab('tab-packing')"><i class="fas fa-box-open"></i> Packaging Material</button>
            <button class="tab-btn" onclick="openTab('tab-finished')"><i class="fas fa-check-circle"></i> Finished Goods</button>
        </div>

        <div id="tab-seeds" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <span>Current Seed Stock</span>
                    <span class="stock-badge stock-ok"><?= count($seeds_stock) ?> Items</span>
                </div>
                <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                    <table>
                        <thead>
                            <tr>
                                <th>Seed Name</th>
                                <th>Category</th>
                                <th>Current Stock (Kg)</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($seeds_stock)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding:30px; color:#999;">No seeds found. Add stock via GRN.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($seeds_stock as $seed): ?>
                                    <tr>
                                        <td style="color:var(--text-main); font-weight:600;"><?= htmlspecialchars($seed['name']) ?></td>
                                        <td><?= htmlspecialchars($seed['category']) ?></td>
                                        <td style="font-weight:700; color:var(--text-main);"><?= number_format($seed['current_stock'], 3) ?></td>
                                        <td>
                                            <?php if ($seed['current_stock'] < 100): ?>
                                                <span class="badge stock-low">Low</span>
                                            <?php else: ?>
                                                <span class="badge stock-ok">In Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <button class="btn btn-outline" style="padding:6px 12px; font-size:0.8rem;" onclick="viewLedger(<?= $seed['id'] ?>, '<?= htmlspecialchars($seed['name']) ?>')">
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

        <div id="tab-loose" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <span>Work In Progress (Oil & Cake)</span>
                </div>
                <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                    <table>
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Type</th>
                                <th>Storage Location</th>
                                <th>Quantity (Kg)</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($loose_stock)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding:30px; color:#999;">No loose stock found. Process seeds to generate stock.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($loose_stock as $item): ?>
                                    <tr>
                                        <td style="color:var(--text-main); font-weight:600;"><?= htmlspecialchars($item['seed_name']) ?></td>
                                        <td>
                                            <span class="badge" style="background:<?= $item['product_type'] == 'OIL' ? '#fff7ed' : '#ecfccb' ?>; color:<?= $item['product_type'] == 'OIL' ? '#c2410c' : '#3f6212' ?>;">
                                                <?= $item['product_type'] ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($item['storage_location']) ?></td>
                                        <td style="font-weight:700; color:var(--text-main);"><?= number_format($item['current_qty'], 2) ?></td>
                                        <td style="text-align:right;">
                                            <button class="btn btn-outline" style="padding:6px 12px; font-size:0.8rem;"
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
                    <button class="btn btn-primary" style="padding:6px 15px; font-size:0.85rem;" onclick="openPackModal()">
                        <i class="fas fa-plus"></i> Add Stock / New
                    </button>
                </div>
                <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
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
                                    <td colspan="4" style="text-align:center; padding:30px; color:#999;">No packaging material defined.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($packing_stock as $item): ?>
                                    <tr>
                                        <td style="color:var(--text-main); font-weight:600;"><?= htmlspecialchars($item['item_name']) ?></td>
                                        <td><span class="badge bg-gray"><?= $item['category'] ?? 'General' ?></span></td>
                                        <td style="font-weight:700; color:var(--text-main);"><?= number_format($item['quantity']) ?> <?= $item['unit'] ?></td>
                                        <td>
                                            <?php if ($item['quantity'] <= $item['alert_level']): ?>
                                                <span class="badge stock-low">Low Stock</span>
                                            <?php else: ?>
                                                <span class="badge stock-ok">OK</span>
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
                    <span>Ready for Sale (Packed Stock)</span>
                </div>
                <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                    <table>
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Barcode / ID</th>
                                <th>Current Available Qty</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($finished_stock)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding:30px; color:#999;">No finished goods in stock.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($finished_stock as $prod): ?>
                                    <tr>
                                        <td style="color:var(--text-main); font-weight:700;"><?= htmlspecialchars($prod['product_name']) ?></td>
                                        <td style="color:var(--text-muted);"><i class="fas fa-barcode"></i> <?= htmlspecialchars($prod['barcode'] ?? 'N/A') ?></td>
                                        <td style="font-weight:800; color:var(--primary); font-size:1.1rem;"><?= number_format($prod['current_qty']) ?> <?= $prod['unit'] ?></td>
                                        <td>
                                            <?php if ($prod['current_qty'] < 10): ?>
                                                <span class="badge stock-low">Low Stock</span>
                                            <?php else: ?>
                                                <span class="badge stock-ok">Available</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <button class="btn btn-outline" style="padding:6px 12px; font-size:0.8rem;" onclick="viewFinishedLedger(<?= $prod['product_id'] ?>, '<?= htmlspecialchars($prod['product_name'], ENT_QUOTES) ?>')">
                                                <i class="fas fa-list"></i> History
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

        <h3 style="margin-top:40px; margin-bottom:15px; font-size:1.2rem; color:var(--text-main);">
            <i class="fas fa-history text-muted" style="margin-right:8px;"></i> Recent GRN History
        </h3>

        <div class="card">
            <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                <table>
                    <thead>
                        <tr>
                            <th>GRN No</th>
                            <th>Seller</th>
                            <th>Vehicle No</th>
                            <th>Total Weight</th>
                            <th>Total Value</th>
                            <th>Date</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grn_list)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:30px; color:#999;">No records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($grn_list as $grn): ?>
                                <tr>
                                    <td style="color:var(--primary); font-weight:700;"><?= $grn['grn_no'] ?></td>
                                    <td><?= htmlspecialchars($grn['seller_name']) ?></td>
                                    <td><?= htmlspecialchars($grn['vehicle_no']) ?></td>
                                    <td><?= number_format($grn['total_weight_kg'], 2) ?> kg</td>
                                    <td style="color:var(--success); font-weight:700;"><?= formatCurrency($grn['total_value']) ?></td>
                                    <td><?= formatDate($grn['created_at']) ?></td>
                                    <td style="text-align:right;">
                                        <button class="btn btn-outline" style="padding:6px 12px; font-size:0.8rem;" onclick="viewGRN(<?= $grn['id'] ?>)">View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <div id="grnModal" class="global-modal">
        <div class="g-modal-content">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.1rem;"><i class="fas fa-file-invoice text-primary"></i> GRN Details</h3>
                <button class="g-close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="g-modal-body" id="grnModalBody">
                <div style="text-align:center; padding:20px;"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>
            </div>
        </div>
    </div>

    <div id="ledgerModal" class="global-modal">
        <div class="g-modal-content" style="max-width:900px;">
            <div class="g-modal-header">
                <div>
                    <h3 style="margin:0; font-size:1.1rem;"><i class="fas fa-book text-primary"></i> Item Ledger</h3>
                    <small id="ledgerItemName" style="color:var(--text-muted); font-weight:600;">Loading...</small>
                </div>
                <button class="g-close-btn" onclick="closeLedgerModal()">&times;</button>
            </div>
            <div class="g-modal-body" id="ledgerBody"></div>
        </div>
    </div>

    <div id="packModal" class="global-modal">
        <div class="g-modal-content" style="max-width:600px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.1rem;"><i class="fas fa-box text-primary"></i> Add Packaging Stock</h3>
                <button class="g-close-btn" onclick="closePackModal()">&times;</button>
            </div>
            <div class="g-modal-body">
                <form id="packForm">
                    <input type="hidden" name="action" value="add_packaging">
                    <div class="form-group" style="margin-bottom:15px;">
                        <label class="form-label">Item Name</label>
                        <input type="text" name="p_name" class="form-input" placeholder="e.g. 15 Kg Tin" required>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="p_category" class="form-input">
                                <option value="Tin">Tin (Peepa)</option>
                                <option value="Bottle">Bottle</option>
                                <option value="Cap">Cap</option>
                                <option value="Label">Label</option>
                                <option value="Carton">Carton</option>
                                <option value="Bag">Bag</option>
                                <option value="Jar">Jar</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Supplier (Vendor)</label>
                            <select name="p_vendor" class="form-input" required>
                                <option value="">-- Select Supplier --</option>
                                <?php
                                if (!empty($vendors)) {
                                    foreach ($vendors as $v) {
                                        echo "<option value='{$v['id']}'>{$v['name']}</option>";
                                    }
                                } else {
                                    echo "<option value='' disabled>No Packaging Vendors Found</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; margin-bottom:15px;">
                        <div class="form-group">
                            <label class="form-label">Quantity (+)</label>
                            <input type="number" name="p_qty" class="form-input" placeholder="0" required oninput="calcTotal()">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rate / Pc (₹)</label>
                            <input type="number" name="p_rate" class="form-input" placeholder="0.00" step="0.01" required oninput="calcTotal()">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unit</label>
                            <select name="p_unit" class="form-input">
                                <option value="Pcs">Pcs</option>
                                <option value="Kg">Kg</option>
                                <option value="Roll">Roll</option>
                            </select>
                        </div>
                    </div>

                    <div style="background:#f0fdf4; padding:12px; border:1px solid #bbf7d0; margin-bottom:15px; border-radius:6px; text-align:center;">
                        <span style="color:#166534; font-weight:700; font-size:1.1rem;">Total Cost: ₹<span id="totalCostDisplay">0.00</span></span>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label">Payment Mode</label>
                        <select name="p_payment_mode" class="form-input">
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
            packModal.classList.add('active');
        }

        function closePackModal() {
            packModal.classList.remove('active');
        }

        window.onclick = function(e) {
            if (e.target == packModal) closePackModal();
            if (e.target == document.getElementById('grnModal')) closeModal();
            if (e.target == document.getElementById('ledgerModal')) closeLedgerModal();
        }

        document.getElementById('packForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!confirm("Confirm adding this packaging stock?")) return;

            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            fetch('inventory.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json()).then(res => {
                    if (res.success) {
                        alert(res.message);
                        window.location.reload();
                    } else {
                        alert("Error: " + res.error);
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                }).catch(err => {
                    alert("System Error");
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        });

        // --- LOOSE STOCK LEDGER ---
        const ledgerModal = document.getElementById('ledgerModal');

        function viewLooseLedger(seedId, prodType, displayName) {
            document.getElementById('ledgerItemName').innerText = displayName;
            ledgerModal.classList.add('active');
            document.getElementById('ledgerBody').innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';

            const fd = new FormData();
            fd.append('action', 'get_loose_ledger');
            fd.append('seed_id', seedId);
            fd.append('product_type', prodType);

            fetch('inventory.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json()).then(res => {
                    if (res.success && res.data.length > 0) {
                        let rows = '';
                        let balance = 0;
                        res.data.forEach(row => {
                            let qty = parseFloat(row.quantity);
                            let type = row.transaction_type;
                            let color = 'black';

                            if (type === 'RAW_IN' || type === 'ADJUSTMENT_IN') {
                                balance += qty;
                                color = 'green';
                            } else {
                                balance -= qty;
                                color = 'red';
                                qty = -qty;
                            }

                            rows += `<tr>
                                    <td>${row.date}</td>
                                    <td><span class="badge bg-gray" style="font-size:0.7rem;">${type}</span></td>
                                    <td>${row.batch_no || '-'}</td>
                                    <td><small>${row.notes || '-'}</small></td>
                                    <td style="color:${color}; font-weight:bold;">${qty.toFixed(2)}</td>
                                    <td style="background:#f9fafb; font-weight:bold;">${balance.toFixed(2)}</td>
                                </tr>`;
                        });
                        document.getElementById('ledgerBody').innerHTML = `
                        <div class="table-wrap" style="border:none; box-shadow:none;">
                            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                                <thead><tr><th>Date</th><th>Type</th><th>Batch</th><th>Notes</th><th>Qty</th><th>Bal</th></tr></thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>`;
                    } else {
                        document.getElementById('ledgerBody').innerHTML = '<p style="color:#64748b; text-align:center; padding:20px;">No transaction history found.</p>';
                    }
                }).catch(err => {
                    document.getElementById('ledgerBody').innerHTML = '<p style="color:red; text-align:center;">System Error</p>';
                });
        }

        // --- SEED LEDGER ---
        function viewLedger(seedId, seedName) {
            document.getElementById('ledgerItemName').innerText = seedName;
            ledgerModal.classList.add('active');
            document.getElementById('ledgerBody').innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';

            const fd = new FormData();
            fd.append('action', 'get_seed_ledger');
            fd.append('seed_id', seedId);

            fetch('inventory.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json()).then(res => {
                    if (res.success) {
                        let rows = '';
                        let balance = 0;
                        res.data.forEach(row => {
                            let qty = parseFloat(row.quantity);
                            let type = row.transaction_type;
                            let color = 'black';
                            let badgeClass = 'bg-gray';

                            if (type === 'GRN_IN' || type === 'PRODUCTION_IN' || type === 'ADJUSTMENT_IN') {
                                balance += qty;
                                color = 'green';
                                badgeClass = 'stock-ok';
                            } else {
                                balance -= qty;
                                color = 'red';
                                qty = -qty;
                                badgeClass = 'stock-low';
                            }

                            rows += `<tr>
                                    <td>${row.date}</td>
                                    <td><span class="badge ${badgeClass}">${type}</span></td>
                                    <td>${row.batch_no || '-'}</td>
                                    <td>${row.notes || '-'}</td>
                                    <td style="color:${color}; font-weight:bold;">${qty.toFixed(2)}</td>
                                    <td style="background:#f9fafb; font-weight:bold;">${balance.toFixed(2)}</td>
                                </tr>`;
                        });
                        document.getElementById('ledgerBody').innerHTML = `
                        <div class="table-wrap" style="border:none; box-shadow:none;">
                            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                                <thead><tr><th>Date</th><th>Type</th><th>Batch/Ref</th><th>Notes</th><th>Qty</th><th>Balance</th></tr></thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>`;
                    } else {
                        document.getElementById('ledgerBody').innerHTML = '<p style="color:red; text-align:center;">No transactions found.</p>';
                    }
                }).catch(err => {
                    document.getElementById('ledgerBody').innerHTML = '<p style="color:red; text-align:center;">System Error</p>';
                });
        }

        // 🌟 NEW: FINISHED GOODS LEDGER 🌟
        function viewFinishedLedger(prodId, prodName) {
            document.getElementById('ledgerItemName').innerText = prodName + " (Packed Stock)";
            ledgerModal.classList.add('active');
            document.getElementById('ledgerBody').innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';

            const fd = new FormData();
            fd.append('action', 'get_finished_ledger');
            fd.append('product_id', prodId);

            fetch('inventory.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json()).then(res => {
                    if (res.success && res.data.length > 0) {
                        let rows = '';
                        let balance = 0;
                        res.data.forEach(row => {
                            let qty = parseFloat(row.qty);
                            let type = row.transaction_type;
                            let color = 'black';
                            let badgeClass = 'bg-gray';

                            if (type === 'PRODUCTION' || type === 'SALE_RETURN' || type === 'ADJUSTMENT_IN') {
                                balance += qty;
                                color = 'green';
                                badgeClass = 'stock-ok';
                            } else {
                                balance -= qty;
                                color = 'red';
                                qty = -qty;
                                badgeClass = 'stock-low';
                            }

                            rows += `<tr>
                                    <td>${row.date}</td>
                                    <td><span class="badge ${badgeClass}" style="font-size:0.7rem;">${type}</span></td>
                                    <td>${row.batch_no || '-'}</td>
                                    <td style="color:${color}; font-weight:bold;">${qty.toFixed(2)}</td>
                                    <td style="background:#f9fafb; font-weight:bold;">${balance.toFixed(2)}</td>
                                </tr>`;
                        });
                        document.getElementById('ledgerBody').innerHTML = `
                        <div class="table-wrap" style="border:none; box-shadow:none;">
                            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                                <thead><tr><th>Date</th><th>Type</th><th>Batch No. / Ref</th><th>Quantity</th><th>Current Balance</th></tr></thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>`;
                    } else {
                        document.getElementById('ledgerBody').innerHTML = '<p style="color:#64748b; text-align:center; padding:20px;">No transaction history found for this product.</p>';
                    }
                }).catch(err => {
                    document.getElementById('ledgerBody').innerHTML = '<p style="color:red; text-align:center;">System Error</p>';
                });
        }

        function closeLedgerModal() {
            ledgerModal.classList.remove('active');
        }

        // --- TAB SWITCHER ---
        function openTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');

            const btns = document.getElementsByClassName('tab-btn');
            for (let btn of btns) {
                if (btn.getAttribute('onclick').includes(tabId)) btn.classList.add('active');
            }
        }

        // --- GRN MODAL LOGIC ---
        const modalGRN = document.getElementById('grnModal');
        const modalBodyGRN = document.getElementById('grnModalBody');

        function viewGRN(id) {
            modalGRN.classList.add('active');
            modalBodyGRN.innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';

            const fd = new FormData();
            fd.append('action', 'get_grn_details');
            fd.append('grn_id', id);

            fetch('grn_handler.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json()).then(res => {
                    if (res.success) {
                        const g = res.grn;
                        let itemsHtml = '';
                        res.items.forEach(item => {
                            itemsHtml += `<tr>
                                        <td>${item.seed_name} (${item.category})</td>
                                        <td>₹${parseFloat(item.price_per_qtl).toFixed(2)}</td>
                                        <td style="font-weight:600;">${parseFloat(item.weight_kg).toFixed(3)} kg</td>
                                        <td style="text-align:right; font-weight:600; color:var(--text-main);">₹${parseFloat(item.line_value).toFixed(2)}</td>
                                      </tr>`;
                        });
                        modalBodyGRN.innerHTML = `
                        <div class="grn-info-grid">
                            <div><span class="grn-label">GRN NO:</span> <div class="grn-val" style="color:var(--primary); font-size:1.1rem;">${g.grn_no}</div></div>
                            <div><span class="grn-label">Date:</span> <div class="grn-val">${new Date(g.created_at).toLocaleString()}</div></div>
                            <div><span class="grn-label">Seller:</span> <div class="grn-val">${g.seller_name}</div></div>
                            <div><span class="grn-label">Vehicle:</span> <div class="grn-val">${g.vehicle_no}</div></div>
                        </div>
                        <div class="table-wrap" style="margin-top:20px;">
                            <table>
                                <thead><tr><th>Item</th><th>Rate (Qtl)</th><th>Weight</th><th style="text-align:right;">Total</th></tr></thead>
                                <tbody>${itemsHtml}</tbody>
                                <tfoot>
                                    <tr style="background:#f8fafc;">
                                        <td colspan="2" style="text-align:right; font-weight:700;">Total:</td>
                                        <td style="font-weight:700;">${parseFloat(g.total_weight_kg).toFixed(3)} kg</td>
                                        <td style="text-align:right; font-weight:800; font-size:1.1rem; color:var(--success);">₹${parseFloat(g.total_value).toFixed(2)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div style="text-align:right; margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                            <button class="btn btn-outline" onclick="window.location.href='print_engine.php?doc=grn_receipt&id=${id}'"><i class="fas fa-print text-primary"></i> Print</button>
                            <button class="btn btn-primary" onclick="closeModal()">Close</button>
                        </div>`;
                    } else {
                        modalBodyGRN.innerHTML = `<p style="color:red; text-align:center;">Error: ${res.error}</p>`;
                    }
                }).catch(err => {
                    modalBodyGRN.innerHTML = `<p style="color:red; text-align:center;">Network Error</p>`;
                });
        }

        function closeModal() {
            modalGRN.classList.remove('active');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === "Escape") {
                closeModal();
                closeLedgerModal();
                closePackModal();
            }
        });
    </script>
</body>

</html>