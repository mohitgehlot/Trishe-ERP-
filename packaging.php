<?php
// packaging.php - Fully Synced with Table, Delete Option & COMBO Logic
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
    // Recipe se packing link hatao
    $conn->query("DELETE FROM product_recipes WHERE raw_material_id = $del_id AND item_type = 'PACKING'");
    // Product ko inactive karo (taaki purani reports kharab na ho)
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
            $p_res = $conn->query("SELECT weight, seed_id FROM products WHERE id = $prod_id");
            $prod = $p_res->fetch_assoc();

            $required_oil = floatval($prod['weight'] ?? 0) * $pack_qty;
            $seed_id = intval($prod['seed_id'] ?? 0);

            $avail_oil = 0;
            if ($seed_id > 0) {
                $oil_sql = "SELECT SUM(CASE WHEN transaction_type IN ('RAW_IN','ADJUSTMENT_IN') THEN quantity WHEN transaction_type IN ('RAW_OUT','ADJUSTMENT_OUT') THEN -quantity ELSE 0 END) as net_oil FROM raw_material_inventory WHERE seed_id = $seed_id AND product_type = 'OIL'";
                $avail_oil = floatval($conn->query($oil_sql)->fetch_assoc()['net_oil'] ?? 0);
            }

            $packaging_status = [];
            $can_produce = ($seed_id > 0 && $avail_oil >= $required_oil);

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

            echo json_encode([
                'success' => true,
                'oil' => [
                    'needed' => $required_oil,
                    'stock' => $avail_oil,
                    'status' => ($avail_oil >= $required_oil)
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

    // --- ACTION: CREATE NEW PRODUCT CONFIG ---
    if ($_POST['action'] == 'create_new_product') {
        try {
            $seed_id = intval($_POST['n_seed']);
            $pack_mat_id = intval($_POST['n_packing_material']);
            $size_val = floatval($_POST['n_size']);
            $size_unit = $_POST['n_unit'];
            $container_type = $_POST['n_type'];

            // NAYA LOGIC: Multiplier capture karna
            $multiplier = intval($_POST['n_multiplier'] ?? 1);
            if ($multiplier < 1) $multiplier = 1;

            $s_name = $conn->query("SELECT name FROM seeds_master WHERE id=$seed_id")->fetch_assoc()['name'];

            // Naam change karna agar Combo hai
            if ($multiplier > 1) {
                $final_prod_name = "$s_name Oil - {$multiplier}x$size_val$size_unit Combo $container_type";
            } else {
                $final_prod_name = "$s_name Oil - $size_val$size_unit $container_type";
            }

            // Weight calculation - Multiplier ke hisaab se total weight
            $base_weight_kg = ($size_unit == 'ml') ? ($size_val / 1000) * 0.910 : (($size_unit == 'L') ? $size_val * 0.910 : $size_val);
            $total_oil_weight_kg = $base_weight_kg * $multiplier;

            $db_unit = ($size_unit == 'Kg') ? 'KG' : 'Liter';
            $barcode = "TRISHE-" . time() . rand(10, 99);

            $conn->begin_transaction();

            $stmt = $conn->prepare("INSERT INTO products (name, weight, unit, seed_id, is_active, barcode, product_type) VALUES (?, ?, ?, ?, 1, ?, 'oil')");
            $stmt->bind_param("sdsis", $final_prod_name, $total_oil_weight_kg, $db_unit, $seed_id, $barcode);
            $stmt->execute();

            $new_product_id = $stmt->insert_id;

            // Recipe mein qty_needed me multiplier pass karna
            $stmtRec = $conn->prepare("INSERT INTO product_recipes (raw_material_id, packaging_id, item_type, qty_needed) VALUES (?, ?, 'PACKING', ?)");
            $stmtRec->bind_param("iii", $new_product_id, $pack_mat_id, $multiplier);
            $stmtRec->execute();

            $conn->commit();
            echo json_encode(['success' => true, 'message' => "New Product Created!"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ==========================================
// B. SAVE PRODUCTION
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

        $prod = $conn->query("SELECT weight, seed_id FROM products WHERE id = $prod_id")->fetch_assoc();
        $oil_to_deduct = floatval($prod['weight']) * $qty;
        $seed_id = intval($prod['seed_id']);

        // DEDUCT LOOSE OIL
        $stmtOil = $conn->prepare("INSERT INTO raw_material_inventory (seed_id, product_type, batch_no, quantity, unit, transaction_type, source_type, notes, transaction_date, created_by) VALUES (?, 'OIL', ?, ?, 'KG', 'RAW_OUT', 'PRODUCTION', 'Used for Batch $batch_no', NOW(), ?)");
        $stmtOil->bind_param("isdi", $seed_id, $batch_no, $oil_to_deduct, $admin_id);
        $stmtOil->execute();

        // DEDUCT PACKAGING MATERIAL
        $res_rec = $conn->query("SELECT packaging_id, qty_needed FROM product_recipes WHERE raw_material_id = $prod_id AND item_type = 'PACKING'");
        while ($row = $res_rec->fetch_assoc()) {
            $deduct_qty = floatval($row['qty_needed']) * $qty;
            $conn->query("UPDATE inventory_packaging SET quantity = quantity - $deduct_qty WHERE id = {$row['packaging_id']}");
        }

        // ADD FINISHED GOODS
        $stmtFG = $conn->prepare("INSERT INTO inventory_products (product_id, batch_no, qty, unit, mfg_date, created_at, transaction_type) VALUES (?, ?, ?, 'Pcs', ?, NOW(), 'PRODUCTION_OUTPUT')");
        $stmtFG->bind_param("isis", $prod_id, $batch_no, $qty, $mfg_date);
        $stmtFG->execute();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => "Production Saved! Stock updated."]);
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
$res = $conn->query("SELECT p.id, p.name, p.weight, p.seed_id FROM products p WHERE p.is_active = 1 AND p.product_type = 'oil' ORDER BY p.name");
if ($res) while ($r = $res->fetch_assoc()) $products[] = $r;

$seeds_list = $conn->query("SELECT id, name FROM seeds_master ORDER BY name");
$pack_list = $conn->query("SELECT id, item_name as name FROM inventory_packaging ORDER BY item_name");

// Fetch Configured Sizes for the Table (Now includes qty_needed)
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --bg-body: #f8fafc;
            --text-main: #1e293b;
            --border: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding-bottom: 60px;
            padding-left: 260px;
        }

        .container {
            margin: 30px auto;
            padding: 20px;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            padding: 25px;
            margin-bottom: 25px;
        }

        .card-header-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.85rem;
            color: #475569;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-sizing: border-box;
            outline: none;
            transition: 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
        }

        /* Requirement Table */
        .req-box {
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            display: none;
        }

        .req-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 0.9rem;
        }

        .req-table th {
            text-align: left;
            color: #475569;
            padding: 8px;
            border-bottom: 1px solid #cbd5e1;
        }

        .req-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .data-table th {
            background: #f8fafc;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid var(--border);
            color: #475569;
            font-weight: 600;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px dashed var(--border);
            vertical-align: middle;
        }

        .status-ok {
            color: var(--success);
            font-weight: 600;
        }

        .status-fail {
            color: var(--danger);
            font-weight: 600;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            width: 100%;
            margin-top: 20px;
            padding: 12px;
        }

        .btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        .btn-outline {
            background: white;
            color: var(--primary);
            border: 1px solid var(--primary);
            width: auto;
            margin-top: 0;
        }

        .btn-danger {
            background: #fee2e2;
            color: #b91c1c;
            padding: 6px 12px;
            font-size: 0.8rem;
            border-radius: 4px;
        }

        .btn-danger:hover {
            background: #fca5a5;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            width: 90%;
            max-width: 600px;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        @media(max-width: 768px) {
            body {
                padding-left: 0;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
            <div style="background:#dcfce7; color:#166534; padding:12px; border-radius:6px; margin-bottom:20px; font-weight:600; border:1px solid #bbf7d0;">
                <i class="fas fa-check-circle"></i> Packaging Size Deleted Successfully!
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-box-open text-primary"></i> Daily Production & Packaging</h1>
            <div>
                <button class="btn btn-outline" onclick="document.getElementById('productModal').style.display='flex'">
                    <i class="fas fa-plus"></i> Create New Size
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-header-title">1. Pack Final Goods</div>
            <form id="prodForm">
                <input type="hidden" name="save_production" value="1">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Select Product (To Pack)</label>
                        <select name="product_id" id="product_id" class="form-control" required onchange="checkStock()">
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> (<?= floatval($p['weight']) ?> Kg Oil)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity to Pack (Packs/Combos)</label>
                        <input type="number" name="qty" id="qty" class="form-control" placeholder="e.g. 100" required oninput="checkStock()">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Batch Number</label>
                        <input type="text" name="batch_no" class="form-control" value="BT-<?= date('mdHi') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Mfg Date</label>
                        <input type="date" name="mfg_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div id="reqBox" class="req-box">
                    <h4 style="margin:0 0 10px 0; color:#334155;"><i class="fas fa-search"></i> Material Consumption Preview</h4>
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

                <button type="submit" id="saveBtn" class="btn btn-primary" disabled>
                    Save Production (Deduct Stock)
                </button>
            </form>
        </div>

        <div class="card">
            <div class="card-header-title">2. Manage Packaging Sizes (Master List)</div>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product Name</th>
                            <th>Total Oil Content (Kg)</th>
                            <th>Linked Container(s)</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($saved_sizes)): ?>
                            <tr>
                                <td colspan="5" style="text-align:center; color:#94a3b8; padding:20px;">No packaging sizes created yet. Click 'Create New Size' to add one.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($saved_sizes as $size): ?>
                                <tr>
                                    <td><span style="background:#f1f5f9; padding:4px 8px; border-radius:4px; font-size:0.8rem; color:#64748b;">#<?= $size['prod_id'] ?></span></td>
                                    <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($size['prod_name']) ?></td>
                                    <td><?= floatval($size['oil_weight']) ?> Kg</td>
                                    <td>
                                        <i class="fas fa-box" style="color:var(--primary); margin-right:5px;"></i>
                                        <span style="font-weight:bold; color:#059669;"><?= floatval($size['qty_needed']) ?>x</span> <?= htmlspecialchars($size['container_name']) ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <a href="?delete_size=<?= $size['prod_id'] ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this packaging configuration?');">
                                            <i class="fas fa-trash-alt"></i> Delete
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

    <div id="productModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:15px; margin-bottom:20px;">
                <h3 style="margin:0; color:#0f172a;">Define New Packing Size (or Combo)</h3>
                <span onclick="document.getElementById('productModal').style.display='none'" style="cursor:pointer; font-size:24px; color:#94a3b8;">&times;</span>
            </div>

            <form id="newProdForm">
                <input type="hidden" name="action" value="create_new_product">

                <div class="form-group" style="margin-bottom:15px;">
                    <label>1. Oil Type (Seed)</label>
                    <select name="n_seed" class="form-control" required>
                        <option value="">-- Select Oil --</option>
                        <?php
                        $seeds_list->data_seek(0);
                        while ($s = $seeds_list->fetch_assoc()) echo "<option value='{$s['id']}'>{$s['name']} Oil</option>";
                        ?>
                    </select>
                </div>

                <div style="background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:15px;">
                    <label style="color:#0f172a; margin-bottom:10px;">2. Single Item Specification</label>
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                        <div class="form-group" style="margin:0;">
                            <label>Base Size</label>
                            <input type="number" name="n_size" class="form-control" placeholder="1" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Unit</label>
                            <select name="n_unit" class="form-control">
                                <option value="L">Litre</option>
                                <option value="ml">ml</option>
                                <option value="Kg">Kg</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Container Type</label>
                            <select name="n_type" class="form-control">
                                <option value="Bottle">Bottle</option>
                                <option value="Jar">Jar</option>
                                <option value="Pouch">Pouch</option>
                                <option value="Tin">Tin</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label>3. Select Empty Container Material</label>
                    <select name="n_packing_material" class="form-control" required>
                        <option value="">-- Select Container --</option>
                        <?php
                        $pack_list->data_seek(0);
                        while ($pm = $pack_list->fetch_assoc()) echo "<option value='{$pm['id']}'>{$pm['name']}</option>";
                        ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:20px; border-left:4px solid var(--primary); padding-left:10px;">
                    <label style="color:var(--primary);">4. Is this a Combo Pack?</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="number" name="n_multiplier" class="form-control" value="1" min="1" required style="width:100px;">
                        <span style="color:#64748b; font-size:0.85rem;">Items per pack (Change to 2 for "1+1 Combo")</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top:10px; width:100%;">Create & Save Product</button>
            </form>
        </div>
    </div>

    <script>
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
            reqBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px;"><i class="fas fa-spinner fa-spin"></i> Checking Stock...</td></tr>';
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
                            <td><strong>Raw Loose Oil</strong></td>
                            <td>${res.oil.needed.toFixed(3)} Kg</td>
                            <td>${parseFloat(res.oil.stock).toFixed(3)} Kg</td>
                            <td class="${res.oil.status ? 'status-ok' : 'status-fail'}">${res.oil.status ? '✅ OK' : '❌ SHORT'}</td>
                        </tr>`;

                        if (res.packaging && res.packaging.length > 0) {
                            res.packaging.forEach(item => {
                                html += `
                                <tr>
                                    <td><i class="fas fa-box" style="color:#94a3b8;"></i> ${item.name}</td>
                                    <td>${item.needed} ${item.unit}</td>
                                    <td>${parseFloat(item.stock).toFixed(0)}</td>
                                    <td class="${item.status ? 'status-ok' : 'status-fail'}">${item.status ? '✅ OK' : '❌ SHORT'}</td>
                                </tr>`;
                            });
                        } else {
                            if (res.no_recipe) {
                                html += `<tr><td colspan="4" style="color:#d97706; background:#fef3c7; padding:15px; border-radius:6px;"><b>⚠️ No Container Linked!</b> Delete this product below and recreate it, or link a container from the BOM page.</td></tr>`;
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
                        reqBody.innerHTML = `<tr><td colspan="4" style="color:red;">Error: ${res.error}</td></tr>`;
                    }
                });
        }

        document.getElementById('prodForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!confirm("Confirm production? Raw Oil and Empty Containers will be deducted immediately.")) return;

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
                    }
                });
        });

        document.getElementById('newProdForm').addEventListener('submit', function(e) {
            e.preventDefault();
            fetch('packaging.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert("Product created successfully!");
                        location.reload();
                    } else {
                        alert("Error: " + res.error);
                    }
                });
        });
    </script>
</body>

</html>