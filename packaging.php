<?php
// packaging.php - STRICT ROLLBACK & MASTER CSS SYNCED (FIXED FOR CAKE PACKING)
ob_start();
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

// ==========================================
// 1. DELETE CONFIGURATION (Soft Delete)
// ==========================================
if (isset($_GET['delete_size'])) {
    $del_id = intval($_GET['delete_size']);
    $conn->query("DELETE FROM product_recipes WHERE raw_material_id = $del_id AND item_type = 'PACKING'");
    $conn->query("UPDATE products SET is_active = 0 WHERE id = $del_id");
    header("Location: packaging.php?msg=deleted");
    exit;
}

// ==========================================
// AJAX ACTIONS
// ==========================================
if (isset($_POST['action'])) {
    error_reporting(0);
    header('Content-Type: application/json');

    // --- ACTION: CHECK REQUIREMENTS ---
    if ($_POST['action'] == 'check_requirements') {
        $prod_id = intval($_POST['product_id']);
        $pack_qty = intval($_POST['qty']);

        try {
            $p_res = $conn->query("SELECT weight, seed_id, product_type FROM products WHERE id = $prod_id");
            $prod = $p_res->fetch_assoc();

            $required_rm = floatval($prod['weight'] ?? 0) * $pack_qty;
            $seed_id = intval($prod['seed_id'] ?? 0);

            // 🌟 FIXED: Determine if it's Oil or Cake based on Product Type 🌟
            $p_type = strtoupper($prod['product_type'] ?? 'OIL');

            $avail_rm = 0;
            if ($seed_id > 0) {
                $rm_sql = "SELECT SUM(CASE WHEN transaction_type IN ('RAW_IN','ADJUSTMENT_IN') THEN quantity WHEN transaction_type IN ('RAW_OUT','ADJUSTMENT_OUT') THEN -quantity ELSE 0 END) as net_rm FROM raw_material_inventory WHERE seed_id = $seed_id AND product_type = '$p_type'";
                $avail_rm = floatval($conn->query($rm_sql)->fetch_assoc()['net_rm'] ?? 0);
            }

            $packaging_status = [];
            $can_produce = ($seed_id > 0 && $avail_rm >= $required_rm);

            $sql_rec = "SELECT packaging_id, qty_needed FROM product_recipes WHERE raw_material_id = $prod_id AND item_type = 'PACKING'";
            $res_rec = $conn->query($sql_rec);

            if ($res_rec && $res_rec->num_rows > 0) {
                while ($row = $res_rec->fetch_assoc()) {
                    $pack_id = intval($row['packaging_id']);
                    $needed = floatval($row['qty_needed']) * $pack_qty;

                    $pm_q = $conn->query("SELECT item_name, quantity FROM inventory_packaging WHERE id = $pack_id");
                    if ($pm_data = $pm_q->fetch_assoc()) {
                        $stock = floatval($pm_data['quantity']);
                        $name = $pm_data['item_name'];
                    } else {
                        $stock = 0;
                        $name = "Unknown Packing";
                    }

                    $has_stock = ($stock >= $needed);
                    if (!$has_stock) $can_produce = false;

                    $packaging_status[] = [
                        'name' => $name,
                        'needed' => $needed,
                        'stock' => $stock,
                        'unit' => 'Pcs',
                        'status' => $has_stock
                    ];
                }
            } else {
                $can_produce = false;
            }

            $material_name = ($p_type == 'CAKE') ? "Raw Loose Khali" : "Raw Loose Oil";

            echo json_encode([
                'success' => true,
                'oil' => [
                    'name' => $material_name,
                    'needed' => $required_rm,
                    'stock' => $avail_rm,
                    'status' => ($avail_rm >= $required_rm)
                ],
                'packaging' => $packaging_status,
                'can_produce' => $can_produce,
                'no_recipe' => ($res_rec->num_rows == 0)
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ==========================================
// B. SAVE PRODUCTION (Strict Rollback Logic)
// ==========================================
if (isset($_POST['save_production'])) {
    ob_clean();
    header('Content-Type: application/json');

    $prod_id = intval($_POST['product_id']);
    $batch_no = mysqli_real_escape_string($conn, $_POST['batch_no']);
    $qty = intval($_POST['qty']);
    $mfg_date = mysqli_real_escape_string($conn, $_POST['mfg_date']);
    $admin_id = $_SESSION['admin_id'];

    if ($qty <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid Quantity']);
        exit;
    }

    try {
        $conn->begin_transaction();

        $prod = $conn->query("SELECT weight, seed_id, product_type FROM products WHERE id = $prod_id")->fetch_assoc();
        if (!$prod) {
            throw new Exception("Product details not found.");
        }

        $rm_to_deduct = floatval($prod['weight']) * $qty;
        $seed_id = intval($prod['seed_id']);
        $p_type = strtoupper($prod['product_type'] ?? 'OIL'); // Can be OIL or CAKE

        // 🌟 STEP 1: DEDUCT LOOSE MATERIAL 🌟
        $stmtRM = $conn->prepare("INSERT INTO raw_material_inventory (seed_id, product_type, batch_no, quantity, unit, transaction_type, source_type, notes, created_at, created_by) VALUES (?, ?, ?, ?, 'KG', 'RAW_OUT', 'PRODUCTION', 'Used for Batch $batch_no', NOW(), ?)");
        if (!$stmtRM) throw new Exception("Database prepare error (RM): " . $conn->error);

        $stmtRM->bind_param("issdi", $seed_id, $p_type, $batch_no, $rm_to_deduct, $admin_id);
        if (!$stmtRM->execute()) {
            throw new Exception("Failed to deduct Loose Material: " . $stmtRM->error);
        }

        // 🌟 STEP 2: DEDUCT PACKAGING MATERIAL
        $res_rec = $conn->query("SELECT packaging_id, qty_needed FROM product_recipes WHERE raw_material_id = $prod_id AND item_type = 'PACKING'");
        if (!$res_rec) throw new Exception("Database error while fetching recipe.");

        while ($row = $res_rec->fetch_assoc()) {
            $deduct_qty = floatval($row['qty_needed']) * $qty;
            $pack_id = $row['packaging_id'];
            $update_pack = $conn->query("UPDATE inventory_packaging SET quantity = quantity - $deduct_qty WHERE id = $pack_id");
            if (!$update_pack) {
                throw new Exception("Failed to deduct empty container stock.");
            }
        }

        // 🌟 STEP 3: ADD FINISHED GOODS
        $stmtFG = $conn->prepare("INSERT INTO inventory_products (product_id, batch_no, qty, unit, mfg_date, created_at, transaction_type) VALUES (?, ?, ?, 'Pcs', ?, NOW(), 'PRODUCTION')");
        if (!$stmtFG) throw new Exception("Database prepare error (Finished Goods).");

        $stmtFG->bind_param("isis", $prod_id, $batch_no, $qty, $mfg_date);
        if (!$stmtFG->execute()) {
            throw new Exception("Failed to save Packed items in stock: " . $stmtFG->error);
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => "Production successfully saved & stock accurately updated!"]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ----------------------------------------------------
// FETCH DATA FOR HTML
// ----------------------------------------------------
$products = [];
// 🌟 FIXED: SHOW BOTH OIL & CAKE PRODUCTS IN DROPDOWN 🌟
$res = $conn->query("SELECT p.id, p.name, p.weight, p.seed_id, p.product_type FROM products p WHERE p.is_active = 1 AND p.product_type IN ('oil', 'cake') ORDER BY p.name");
if ($res) while ($r = $res->fetch_assoc()) $products[] = $r;

$saved_sizes = [];
$sql_sizes = "
    SELECT 
        p.id as prod_id, 
        p.name as prod_name, 
        p.weight as oil_weight, 
        ip.item_name as container_name,
        pr.qty_needed 
    FROM products p 
    JOIN product_recipes pr ON p.id = pr.raw_material_id 
    JOIN inventory_packaging ip ON pr.packaging_id = ip.id 
    WHERE p.is_active = 1 AND pr.item_type = 'PACKING' 
    ORDER BY p.id DESC
";
$res_sizes = $conn->query($sql_sizes);
if ($res_sizes) while ($row = $res_sizes->fetch_assoc()) $saved_sizes[] = $row;

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Production & Packaging | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            overflow-x: hidden;
        }

        .page-header-box {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
            align-items: start;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .req-box {
            background: #f8fafc;
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            display: none;
        }

        .req-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 0.95rem;
        }

        .req-table th {
            text-align: left;
            color: var(--text-muted);
            padding: 10px;
            border-bottom: 1px solid var(--border);
        }

        .req-table td {
            padding: 12px 10px;
            border-bottom: 1px solid var(--border);
        }

        .status-ok {
            color: var(--success);
            font-weight: 700;
        }

        .status-fail {
            color: var(--danger);
            font-weight: 700;
        }

        .table-wrap table {
            min-width: 100% !important;
        }

        @media(max-width: 768px) {
            body {
                padding-left: 0;
            }

            .container {
                padding: 15px;
            }

            .page-header-box {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .page-header-box button {
                width: 100%;
                justify-content: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .grid-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
            <div class="alert">
                <i class="fas fa-check-circle"></i> Packaging Size Deleted Successfully!
            </div>
        <?php endif; ?>

        <div class="page-header-box">
            <h1 class="page-title" style="margin:0; font-size:1.5rem; font-weight:700;"><i class="fas fa-box-open text-primary" style="margin-right:8px;"></i> Daily Production & Packaging</h1>
        </div>

        <div class="grid-layout">
            <div class="card">
                <div class="card-header"><i class="fas fa-cubes text-warning" style="margin-right:8px;"></i> 1. Pack Final Goods</div>
                <div style="padding: 20px;">
                    <form id="prodForm">
                        <input type="hidden" name="save_production" value="1">

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Select Product (To Pack)</label>
                                <select name="product_id" id="product_id" class="form-input" required onchange="checkStock()">
                                    <option value="">-- Choose Product --</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?= $p['id'] ?>">
                                            <?= htmlspecialchars($p['name']) ?> (<?= floatval($p['weight']) ?> Kg <?= strtoupper($p['product_type'] == 'cake' ? 'Cake' : 'Oil') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Quantity to Pack (Packs/Combos)</label>
                                <input type="number" name="qty" id="qty" class="form-input" placeholder="e.g. 100" required oninput="checkStock()">
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Batch Number</label>
                                <input type="text" name="batch_no" class="form-input" value="BT-<?= date('mdHi') ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Mfg Date</label>
                                <input type="date" name="mfg_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <div id="reqBox" class="req-box">
                            <h4 style="margin:0 0 10px 0; color:var(--text-main); font-size:1.1rem;"><i class="fas fa-search text-primary" style="margin-right:8px;"></i> Material Consumption Preview</h4>
                            <table class="req-table">
                                <thead>
                                    <tr>
                                        <th>Item Type</th>
                                        <th>Required</th>
                                        <th>Available Stock</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="reqBody">
                                </tbody>
                            </table>
                        </div>

                        <button type="submit" id="saveBtn" class="btn btn-primary" style="width:100%; margin-top:20px; padding:12px;" disabled>
                            Save Production (Deduct Stock)
                        </button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="fas fa-list text-info" style="margin-right:8px;"></i> 2. Current Active Sizes (From Recipes)</div>
                <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                    <table>
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Total Content (Kg)</th>
                                <th>Linked Container(s)</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($saved_sizes)): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center; color:var(--text-muted); padding:30px;">No packaging sizes found in recipes. Please create them via Product Recipe (BOM).</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($saved_sizes as $size): ?>
                                    <tr>
                                        <td style="font-weight:700; color:var(--text-main);"><?= htmlspecialchars($size['prod_name']) ?></td>
                                        <td style="font-weight:600; color:var(--primary);"><?= floatval($size['oil_weight']) ?> Kg</td>
                                        <td>
                                            <i class="fas fa-box" style="color:var(--text-muted); margin-right:5px;"></i>
                                            <span style="font-weight:800; color:var(--success);"><?= floatval($size['qty_needed']) ?>x</span> <?= htmlspecialchars($size['container_name']) ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <a href="?delete_size=<?= $size['prod_id'] ?>" class="btn-icon delete" style="color:var(--danger);" onclick="return confirm('Are you sure you want to delete this packaging configuration?');" title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script>
        // --- STOCK CHECKING ---
        function checkStock() {
            const prodId = document.getElementById('product_id').value;
            const qty = document.getElementById('qty').value;
            const reqBox = document.getElementById('reqBox');
            const reqBody = document.getElementById('reqBody');
            const saveBtn = document.getElementById('saveBtn');

            if (!prodId || !qty || qty <= 0) {
                reqBox.style.display = 'none';
                saveBtn.disabled = true;
                return;
            }

            reqBox.style.display = 'block';
            reqBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px;"><i class="fas fa-spinner fa-spin text-primary fa-2x"></i><br>Checking Stock...</td></tr>';
            saveBtn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'check_requirements');
            fd.append('product_id', prodId);
            fd.append('qty', qty);

            fetch('packaging.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        let html = `
                        <tr style="background:#fff7ed;">
                            <td style="color:var(--warning); font-weight:700;"><i class="fas fa-tint"></i> ${res.oil.name}</td>
                            <td style="font-weight:600;">${res.oil.needed.toFixed(3)} Kg</td>
                            <td style="font-weight:600;">${parseFloat(res.oil.stock).toFixed(3)} Kg</td>
                            <td class="${res.oil.status ? 'status-ok' : 'status-fail'}">${res.oil.status ? '✅ OK' : '❌ SHORT'}</td>
                        </tr>`;

                        if (res.packaging && res.packaging.length > 0) {
                            res.packaging.forEach(item => {
                                html += `
                                <tr>
                                    <td style="color:var(--text-main); font-weight:600;"><i class="fas fa-box" style="color:#94a3b8; margin-right:8px;"></i> ${item.name}</td>
                                    <td style="font-weight:600;">${item.needed} ${item.unit}</td>
                                    <td style="font-weight:600;">${parseFloat(item.stock).toFixed(0)}</td>
                                    <td class="${item.status ? 'status-ok' : 'status-fail'}">${item.status ? '✅ OK' : '❌ SHORT'}</td>
                                </tr>`;
                            });
                        } else {
                            if (res.no_recipe) {
                                html += `<tr><td colspan="4" style="color:var(--warning); background:#fffbeb; padding:15px; border-radius:6px; text-align:center;"><b>⚠️ No Container Linked!</b> Please link a container from the BOM page.</td></tr>`;
                            }
                        }

                        reqBody.innerHTML = html;

                        if (res.can_produce && !res.no_recipe) {
                            saveBtn.disabled = false;
                            saveBtn.innerHTML = "<i class='fas fa-check-circle'></i> Confirm & Save Production";
                        } else {
                            saveBtn.disabled = true;
                            saveBtn.innerHTML = "<i class='fas fa-exclamation-triangle'></i> Insufficient Material";
                        }
                    } else {
                        reqBody.innerHTML = `<tr><td colspan="4" style="color:var(--danger); text-align:center; padding:20px;">Error: ${res.error}</td></tr>`;
                    }
                });
        }

        // --- SUBMIT PRODUCTION ---
        document.getElementById('prodForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!confirm("Confirm production? Raw Material and Empty Containers will be deducted immediately.")) return;

            const btn = document.getElementById('saveBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            btn.disabled = true;

            fetch('packaging.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert(res.message);
                        window.location.reload();
                    } else {
                        alert("Error: " + res.error);
                        btn.innerHTML = "<i class='fas fa-check-circle'></i> Confirm & Save Production";
                        btn.disabled = false;
                    }
                }).catch(err => {
                    alert("Network Error: Could not save production.");
                    btn.innerHTML = "<i class='fas fa-check-circle'></i> Confirm & Save Production";
                    btn.disabled = false;
                });
        });
    </script>
</body>

</html>