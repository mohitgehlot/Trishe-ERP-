<?php
// packaging.php - Fully Synced with Master CSS & Responsive
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

            $multiplier = intval($_POST['n_multiplier'] ?? 1);
            if ($multiplier < 1) $multiplier = 1;

            $s_name = $conn->query("SELECT name FROM seeds_master WHERE id=$seed_id")->fetch_assoc()['name'];

            if ($multiplier > 1) {
                $final_prod_name = "$s_name Oil - {$multiplier}x$size_val$size_unit Combo $container_type";
            } else {
                $final_prod_name = "$s_name Oil - $size_val$size_unit $container_type";
            }

            $base_weight_kg = ($size_unit == 'ml') ? ($size_val / 1000) * 0.910 : (($size_unit == 'L') ? $size_val * 0.910 : $size_val);
            $total_oil_weight_kg = $base_weight_kg * $multiplier;

            $db_unit = ($size_unit == 'Kg') ? 'KG' : 'Liter';
            $barcode = "TRISHE-" . time() . rand(10, 99);

            $conn->begin_transaction();

            $stmt = $conn->prepare("INSERT INTO products (name, weight, unit, seed_id, is_active, barcode, product_type) VALUES (?, ?, ?, ?, 1, ?, 'oil')");
            $stmt->bind_param("sdsis", $final_prod_name, $total_oil_weight_kg, $db_unit, $seed_id, $barcode);
            $stmt->execute();

            $new_product_id = $stmt->insert_id;

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

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* Requirement Table */
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

        /* Fix Table Horizontal Scroll */
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
            <h1 class="page-title"><i class="fas fa-box-open text-primary" style="margin-right:8px;"></i> Daily Production & Packaging</h1>
            <div>
                <button class="btn btn-outline" onclick="openGlobalCreateModal()">
                    <i class="fas fa-plus"></i> Create New Size
                </button>
            </div>
        </div>

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
                                        <?= htmlspecialchars($p['name']) ?> (<?= floatval($p['weight']) ?> Kg Oil)
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
            <div class="card-header"><i class="fas fa-list text-info" style="margin-right:8px;"></i> 2. Manage Packaging Sizes (Master List)</div>
            <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                <table>
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
                                <td colspan="5" style="text-align:center; color:var(--text-muted); padding:30px;">No packaging sizes created yet. Click 'Create New Size' to add one.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($saved_sizes as $size): ?>
                                <tr>
                                    <td><span class="badge bg-gray">#<?= $size['prod_id'] ?></span></td>
                                    <td style="font-weight:700; color:var(--text-main);"><?= htmlspecialchars($size['prod_name']) ?></td>
                                    <td style="font-weight:600; color:var(--primary);"><?= floatval($size['oil_weight']) ?> Kg</td>
                                    <td>
                                        <i class="fas fa-box" style="color:var(--text-muted); margin-right:5px;"></i>
                                        <span style="font-weight:800; color:var(--success);"><?= floatval($size['qty_needed']) ?>x</span> <?= htmlspecialchars($size['container_name']) ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <a href="?delete_size=<?= $size['prod_id'] ?>" class="btn-icon delete" onclick="return confirm('Are you sure you want to delete this packaging configuration?');" title="Delete">
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

    

    <script>
        // --- MODAL LOGIC (With Keyboard Shortcut) ---
        const createModal = document.getElementById('productModal');

        function openCreateModal() {
            createModal.classList.add('active');
        }

        function closeCreateModal() {
            createModal.classList.remove('active');
        }

        window.onclick = function(e) {
            if (e.target == createModal) closeCreateModal();
        }

        // Shortcut Key (Alt + C for Create Size)
        document.addEventListener('keydown', function(e) {
            if (e.altKey && (e.key === 'c' || e.key === 'C')) {
                e.preventDefault();
                openCreateModal();
            }
            if (e.key === "Escape") closeCreateModal();
        });

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
                            <td style="color:var(--warning); font-weight:700;"><i class="fas fa-tint"></i> Raw Loose Oil</td>
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
                                html += `<tr><td colspan="4" style="color:var(--warning); background:#fffbeb; padding:15px; border-radius:6px; text-align:center;"><b>⚠️ No Container Linked!</b> Delete this product below and recreate it, or link a container from the BOM page.</td></tr>`;
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
            if (!confirm("Confirm production? Raw Oil and Empty Containers will be deducted immediately.")) return;

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
                    alert("Network Error");
                    btn.disabled = false;
                });
        });

        // --- CREATE NEW PRODUCT / COMBO ---
        document.getElementById('newProdForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
            btn.disabled = true;

            fetch('packaging.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        alert("Error: " + res.error);
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                }).catch(err => {
                    alert("Network Error");
                    btn.disabled = false;
                });
        });
    </script>
</body>

</html>