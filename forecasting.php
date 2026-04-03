<?php
// forecasting.php - INTEGRATED WITH MASTER CSS
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit;
}

// ==========================================
// 1. COMBINED ADD FORMULA (RAW MATERIAL + PACKING)
// ==========================================
if (isset($_POST['add_to_recipe'])) {
    $p_id = intval($_POST['product_id']); 
    
    // --- A. Process Raw Material (If Selected) ---
    if (!empty($_POST['raw_material_val']) && floatval($_POST['rm_qty']) > 0) {
        $rm_val = $_POST['raw_material_val']; 
        $rm_qty = floatval($_POST['rm_qty']);
        
        $parts = explode('_', $rm_val);
        $type = $parts[0]; // CAKE or OIL
        $s_id = intval($parts[1]); // Seed ID
        
        $check = $conn->query("SELECT id FROM product_recipes WHERE raw_material_id=$p_id AND packaging_id=$s_id AND item_type='$type'");
        if($check->num_rows == 0){
            $stmt = $conn->prepare("INSERT INTO product_recipes (raw_material_id, packaging_id, item_type, qty_needed) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iisd", $p_id, $s_id, $type, $rm_qty);
            $stmt->execute();
        }
    }

    // --- B. Process Packing Material (If Selected) ---
    if (!empty($_POST['packing_id']) && floatval($_POST['pack_qty']) > 0) {
        $pack_inv_id = intval($_POST['packing_id']); 
        $pack_qty = floatval($_POST['pack_qty']);
        
        $check = $conn->query("SELECT id FROM product_recipes WHERE raw_material_id=$p_id AND packaging_id=$pack_inv_id AND item_type='PACKING'");
        if($check->num_rows == 0){
            $stmt = $conn->prepare("INSERT INTO product_recipes (raw_material_id, packaging_id, item_type, qty_needed) VALUES (?, ?, 'PACKING', ?)");
            $stmt->bind_param("iid", $p_id, $pack_inv_id, $pack_qty);
            $stmt->execute();
        }
    }

    header("Location: forecasting.php?selected_product=$p_id");
    exit;
}

// ==========================================
// 2. UPDATE RECIPE QUANTITY (EDIT)
// ==========================================
if (isset($_POST['update_qty'])) {
    $recipe_id = intval($_POST['recipe_id']);
    $new_qty = floatval($_POST['new_qty']);
    $p_id = intval($_POST['product_id']);

    if ($new_qty > 0) {
        $stmt = $conn->prepare("UPDATE product_recipes SET qty_needed = ? WHERE id = ?");
        $stmt->bind_param("di", $new_qty, $recipe_id);
        $stmt->execute();
    }
    header("Location: forecasting.php?selected_product=$p_id");
    exit;
}

// ==========================================
// 3. DELETE INGREDIENT
// ==========================================
if (isset($_GET['delete_ing'])) {
    $id = intval($_GET['delete_ing']);
    $pid = intval($_GET['pid']); 
    $conn->query("DELETE FROM product_recipes WHERE id=$id");
    header("Location: forecasting.php?selected_product=$pid");
    exit;
}

// --- FETCH LISTS FOR UI (SORTED ALPHABETICALLY) ---
$finished_goods = $conn->query("SELECT id, name FROM products WHERE is_active=1 ORDER BY name ASC");

// Sorted Cakes & Oils Alphabetically
$active_cakes = [];
$cakes_q = $conn->query("SELECT DISTINCT rmi.seed_id, sm.name FROM raw_material_inventory rmi JOIN seeds_master sm ON rmi.seed_id = sm.id WHERE rmi.product_type = 'CAKE' ORDER BY sm.name ASC");
if($cakes_q) while($r = $cakes_q->fetch_assoc()) $active_cakes[] = $r;

$active_oils = [];
$oils_q = $conn->query("SELECT DISTINCT rmi.seed_id, sm.name FROM raw_material_inventory rmi JOIN seeds_master sm ON rmi.seed_id = sm.id WHERE rmi.product_type = 'OIL' ORDER BY sm.name ASC");
if($oils_q) while($r = $oils_q->fetch_assoc()) $active_oils[] = $r;

// Packing Materials sorted alphabetically
$packing_items = [];
$pack_q = $conn->query("SELECT id, item_name FROM inventory_packaging ORDER BY item_name ASC");
if($pack_q) while($r = $pack_q->fetch_assoc()) $packing_items[] = $r;


// --- FORECASTING & STOCK CHECK LOGIC ---
$forecast_data = [];
$selected_product_id = isset($_GET['selected_product']) ? intval($_GET['selected_product']) : 0;
$target_qty = isset($_GET['target_qty']) ? floatval($_GET['target_qty']) : 1; 

if($selected_product_id > 0) {
    $sql = "SELECT pr.id as recipe_id, pr.packaging_id, pr.item_type, pr.qty_needed,
            CASE 
                WHEN pr.item_type = 'PACKING' THEN (SELECT item_name FROM inventory_packaging WHERE id = pr.packaging_id)
                WHEN pr.item_type = 'OIL' THEN (SELECT CONCAT(name, ' Raw Oil') FROM seeds_master WHERE id = pr.packaging_id)
                WHEN pr.item_type = 'CAKE' THEN (SELECT CONCAT(name, ' Cake') FROM seeds_master WHERE id = pr.packaging_id)
            END as raw_name,
            CASE 
                WHEN pr.item_type = 'PACKING' THEN 'Pcs'
                ELSE 'Kg'
            END as raw_unit
            FROM product_recipes pr 
            WHERE pr.raw_material_id = $selected_product_id";
    $recipe_res = $conn->query($sql);
    
    if($recipe_res) {
        while($row = $recipe_res->fetch_assoc()) {
            $ing_id = intval($row['packaging_id']);
            $type = $row['item_type'];
            
            $current_stock = 0;
            $raw_name = "Unknown";
            $raw_unit = "Pcs";

            if ($type == 'OIL') {
                $nm_q = $conn->query("SELECT name FROM seeds_master WHERE id=$ing_id");
                $raw_name = ($nm_q && $nm_q->num_rows > 0) ? $nm_q->fetch_assoc()['name'] . " Raw Oil" : "Oil";
                $raw_unit = "Kg";
                
                $st_sql = "SELECT SUM(CASE WHEN transaction_type IN ('RAW_IN','ADJUSTMENT_IN') THEN quantity WHEN transaction_type IN ('RAW_OUT','ADJUSTMENT_OUT') THEN -quantity ELSE 0 END) as net FROM raw_material_inventory WHERE seed_id = $ing_id AND product_type = 'OIL'";
                $current_stock = floatval($conn->query($st_sql)->fetch_assoc()['net'] ?? 0);
            } 
            elseif ($type == 'CAKE') {
                $nm_q = $conn->query("SELECT name FROM seeds_master WHERE id=$ing_id");
                $raw_name = ($nm_q && $nm_q->num_rows > 0) ? $nm_q->fetch_assoc()['name'] . " Cake" : "Cake";
                $raw_unit = "Kg";
                
                $st_sql = "SELECT SUM(CASE WHEN transaction_type IN ('RAW_IN','ADJUSTMENT_IN') THEN quantity WHEN transaction_type IN ('RAW_OUT','ADJUSTMENT_OUT') THEN -quantity ELSE 0 END) as net FROM raw_material_inventory WHERE seed_id = $ing_id AND product_type = 'CAKE'";
                $current_stock = floatval($conn->query($st_sql)->fetch_assoc()['net'] ?? 0);
            } 
            elseif ($type == 'PACKING') {
                $nm_q = $conn->query("SELECT item_name, quantity as net FROM inventory_packaging WHERE id=$ing_id");
                if($nm_q && $nm_data = $nm_q->fetch_assoc()) {
                    $raw_name = $nm_data['item_name'];
                    $current_stock = floatval($nm_data['net']);
                }
                $raw_unit = "Pcs";
            }

            $total_needed = $row['qty_needed'] * $target_qty;
            $row['raw_name'] = $raw_name;
            $row['raw_unit'] = $raw_unit;
            $row['total_needed'] = $total_needed;
            $row['current_stock'] = $current_stock;
            $row['shortage'] = ($total_needed > $current_stock) ? ($total_needed - $current_stock) : 0;
            
            $forecast_data[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Production Recipe Builder | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="css/admin_style.css">
    
    <style>
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; overflow-x: hidden; }

        .page-header-box { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin: 0; }

        .grid-box { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
        
        .add-boxes { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px dashed var(--border); margin-bottom: 20px;}

        /* Select2 Custom Styling to match Master CSS */
        .select2-container .select2-selection--single {
            height: 40px !important;
            border: 1px solid var(--border) !important;
            border-radius: 6px !important;
            padding: 5px 0px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 38px !important;
        }

        .type-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .badge-cake { background: #fef08a; color: #92400e; border: 1px solid #fde047; }
        .badge-oil { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
        .badge-pack { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }

        /* Fix Table Horizontal Scroll */
        .table-wrap table { min-width: 100% !important; }

        @media(max-width: 1024px) { 
            .grid-box { grid-template-columns: 1fr; } 
        }
        @media(max-width: 768px) { 
            body { padding-left: 0; }
            .container { padding: 15px; }
            .page-header-box { text-align: center; }
            .add-boxes { grid-template-columns: 1fr; } 
        }
    </style>
</head>
<body>

    <?php include 'admin_header.php'; ?>

<div class="container">
    <div class="page-header-box">
        <h1 class="page-title"><i class="fas fa-boxes text-primary" style="margin-right:10px;"></i> Product Recipe & Bill of Materials (BOM)</h1>
    </div>

    <div class="grid-box">
        
        <div>
            <div class="card">
                <div class="card-header"><i class="fas fa-search text-info" style="margin-right:8px;"></i> 1. Select Master Product</div>
                <div style="padding:20px;">
                    <form method="GET" id="productForm">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Product you want to make (e.g., Cattle Feed 50Kg, Mustard Oil 1L)</label>
                            <select name="selected_product" class="searchable-select" onchange="document.getElementById('productForm').submit()">
                                <option value="">-- Search & Select Product --</option>
                                <?php 
                                $finished_goods->data_seek(0);
                                while($fg = $finished_goods->fetch_assoc()): 
                                    $sel = ($selected_product_id == $fg['id']) ? 'selected' : '';
                                ?>
                                    <option value="<?= $fg['id'] ?>" <?= $sel ?>><?= htmlspecialchars($fg['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <?php if($selected_product_id): ?>
            <div class="card" style="padding-bottom: 20px;">
                <div class="card-header"><i class="fas fa-plus-circle text-primary" style="margin-right:8px;"></i> 2. Build Recipe Formula</div>
                <div style="padding:20px 20px 0 20px;">
                    <form method="POST" id="unifiedAddForm" onsubmit="return validateAddForm()">
                        <input type="hidden" name="product_id" value="<?= $selected_product_id ?>">
                        <input type="hidden" name="add_to_recipe" value="1">
                        
                        <div class="add-boxes">
                            <div style="background: white; padding: 15px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                                <h4 style="margin: 0 0 15px 0; color: var(--warning);"><i class="fas fa-seedling"></i> Add Raw Material</h4>
                                <div class="form-group">
                                    <label class="form-label">Search Cake or Raw Oil</label>
                                    <select name="raw_material_val" id="rm_val" class="searchable-select">
                                        <option value="">-- None --</option>
                                        <optgroup label="🧱 CAKES (From Inventory)">
                                            <?php foreach($active_cakes as $sm) echo "<option value='CAKE_{$sm['seed_id']}'>{$sm['name']} Cake</option>"; ?>
                                        </optgroup>
                                        <optgroup label="🛢️ RAW OILS (From Tank)">
                                            <?php foreach($active_oils as $sm) echo "<option value='OIL_{$sm['seed_id']}'>{$sm['name']} Raw Oil</option>"; ?>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label class="form-label">Quantity (Kg)</label>
                                    <input type="number" step="0.001" name="rm_qty" id="rm_qty" class="form-input" placeholder="e.g. 50">
                                </div>
                            </div>

                            <div style="background: white; padding: 15px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                                <h4 style="margin: 0 0 15px 0; color: var(--primary);"><i class="fas fa-box"></i> Add Packing Material</h4>
                                <div class="form-group">
                                    <label class="form-label">Search Bag, Bottle, Tin, etc.</label>
                                    <select name="packing_id" id="pack_val" class="searchable-select">
                                        <option value="">-- None --</option>
                                        <?php foreach($packing_items as $pm) echo "<option value='{$pm['id']}'>{$pm['item_name']}</option>"; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label class="form-label">Quantity (Pcs)</label>
                                    <input type="number" step="0.001" name="pack_qty" id="pack_qty" class="form-input" placeholder="e.g. 1">
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; font-size:1.05rem; background:var(--success); border-color:var(--success);"><i class="fas fa-check-circle" style="margin-right:5px;"></i> Save to Recipe Formula</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="fas fa-list-alt text-muted" style="margin-right:8px;"></i> Current Recipe Formula (For 1 Unit)</div>
                <div class="table-wrap" style="border:none; border-radius:0; box-shadow:none;">
                    <?php if(empty($forecast_data)): ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-muted); border-bottom:1px solid var(--border);">No items added to recipe yet.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Material Name</th>
                                    <th>Qty Needed</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($forecast_data as $item): 
                                    $badge_class = 'badge-pack';
                                    if($item['item_type'] == 'CAKE') $badge_class = 'badge-cake';
                                    if($item['item_type'] == 'OIL') $badge_class = 'badge-oil';
                                ?>
                                    <tr>
                                        <td><span class="type-badge <?= $badge_class ?>"><?= $item['item_type'] ?></span></td>
                                        <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($item['raw_name']) ?></td>
                                        <td>
                                            <span style="font-weight:700; color:var(--primary); background:#eff6ff; padding:4px 10px; border-radius:20px;">
                                                <?= $item['qty_needed'] ?> <?= $item['raw_unit'] ?? '' ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right; white-space:nowrap;">
                                            <button type="button" class="btn-icon" style="color:var(--warning); margin-right:5px;" onclick="openEditModal(<?= $item['recipe_id'] ?>, '<?= htmlspecialchars($item['raw_name']) ?>', <?= $item['qty_needed'] ?>)" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?delete_ing=<?= $item['recipe_id'] ?>&pid=<?= $selected_product_id ?>" class="btn-icon delete" onclick="return confirm('Remove this item from recipe?');" title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="card">
                <div class="card-header"><i class="fas fa-chart-bar text-warning" style="margin-right:8px;"></i> Stock Predictor & Requirements</div>
                <div style="padding: 20px;">
                    <?php if($selected_product_id): ?>
                        <form method="GET" style="background:#f8fafc; padding:20px; border-radius:8px; margin-bottom:20px; display:flex; gap:15px; align-items:flex-end; border:1px solid var(--border); flex-wrap:wrap;">
                            <input type="hidden" name="selected_product" value="<?= $selected_product_id ?>">
                            <div style="flex:1; min-width:200px;">
                                <label class="form-label" style="color:var(--text-main);">How many units do you want to produce?</label>
                                <input type="number" name="target_qty" class="form-input" value="<?= $target_qty ?>" style="font-size:1.2rem; font-weight:700; color:var(--primary);" onchange="this.form.submit()">
                            </div>
                            <button type="submit" class="btn btn-primary" style="width:auto; padding:12px 20px;"><i class="fas fa-bolt"></i> Predict</button>
                        </form>

                        <?php if(!empty($forecast_data)): ?>
                            <div class="table-wrap" style="border:none; box-shadow:none;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Material Required</th>
                                            <th>Needed</th>
                                            <th>In Stock</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $has_shortage = false;
                                        foreach($forecast_data as $row): 
                                            if($row['shortage'] > 0) $has_shortage = true;
                                        ?>
                                        <tr>
                                            <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($row['raw_name']) ?></td>
                                            <td>
                                                <b style="color:var(--text-main); font-size:1.05rem;"><?= number_format($row['total_needed'], 2) ?></b>
                                                <small style="color:var(--text-muted);"><?= htmlspecialchars($row['raw_unit'] ?? '') ?></small>
                                            </td>
                                            <td>
                                                <?php if($row['shortage'] > 0): ?>
                                                    <span style="color:var(--danger); font-weight:700; font-size:1.05rem;"><?= number_format($row['current_stock'], 2) ?></span>
                                                    <small style="color:var(--danger); display:block;">(Need <?= number_format($row['shortage'], 2) ?>)</small>
                                                <?php else: ?>
                                                    <span style="color:var(--success); font-weight:700; font-size:1.05rem;"><?= number_format($row['current_stock'], 2) ?></span>
                                                    <span class="badge" style="background:#dcfce7; color:#166534; margin-left:5px;">OK</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if($has_shortage): ?>
                                <div class="alert" style="margin-top:20px; margin-bottom:0; background:#fef2f2; border-color:#fca5a5; color:#991b1b; display:flex; align-items:center; gap:10px;">
                                    <i class="fas fa-exclamation-circle fa-2x"></i>
                                    <div><strong>Shortage Detected!</strong> You don't have enough materials in stock to produce <?= $target_qty ?> units.</div>
                                </div>
                            <?php else: ?>
                                <div class="alert" style="margin-top:20px; margin-bottom:0; background:#f0fdf4; border-color:#bbf7d0; color:#166534; display:flex; align-items:center; gap:10px;">
                                    <i class="fas fa-check-circle fa-2x"></i>
                                    <div><strong>Ready for Production!</strong> All materials are available in stock.</div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="text-align:center; padding: 60px 20px; color: var(--text-muted); border:2px dashed var(--border); border-radius:8px;">
                            <i class="fas fa-arrow-left fa-3x" style="margin-bottom:15px; opacity:0.3;"></i><br>
                            <span style="font-weight:500;">Please select a product from the left panel first to view its stock requirements.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<div id="editModal" class="global-modal">
    <div class="g-modal-content" style="max-width:400px;">
        <div class="g-modal-header">
            <h3 style="margin:0; font-size:1.1rem; color:var(--text-main);"><i class="fas fa-edit text-warning" style="margin-right:8px;"></i> Edit Ingredient Quantity</h3>
            <span class="g-close-btn" onclick="document.getElementById('editModal').classList.remove('active')">&times;</span>
        </div>
        <div class="g-modal-body">
            <form method="POST">
                <input type="hidden" name="update_qty" value="1">
                <input type="hidden" name="product_id" value="<?= $selected_product_id ?>">
                <input type="hidden" name="recipe_id" id="edit_recipe_id">
                
                <div class="form-group" style="margin-bottom:15px;">
                    <label class="form-label">Material Name</label>
                    <input type="text" id="edit_mat_name" class="form-input" readonly style="background:#f1f5f9; color:#64748b; font-weight:600; cursor:not-allowed;">
                </div>
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label">New Quantity Needed (For 1 Unit)</label>
                    <input type="number" step="0.001" name="new_qty" id="edit_qty_val" class="form-input" required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width:100%; padding:12px;"><i class="fas fa-save" style="margin-right:5px;"></i> Save Changes</button>
            </form>
        </div>
    </div>
</div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Searchable Dropdowns
            $('.searchable-select').select2({
                width: '100%',
                placeholder: "-- Search & Select --"
            });
        });

        // Form Validation for Unified Add Form
        function validateAddForm() {
            let rmVal = document.getElementById('rm_val').value;
            let rmQty = parseFloat(document.getElementById('rm_qty').value);
            
            let packVal = document.getElementById('pack_val').value;
            let packQty = parseFloat(document.getElementById('pack_qty').value);

            let hasRM = (rmVal !== "" && rmQty > 0);
            let hasPack = (packVal !== "" && packQty > 0);

            if (!hasRM && !hasPack) {
                alert("Please select at least one material (Raw OR Packing) and enter its quantity!");
                return false;
            }
            return true;
        }

        // Open Edit Modal
        function openEditModal(recipeId, matName, currentQty) {
            document.getElementById('edit_recipe_id').value = recipeId;
            document.getElementById('edit_mat_name').value = matName;
            document.getElementById('edit_qty_val').value = currentQty;
            document.getElementById('editModal').classList.add('active');
        }
        
        // Close modal on click outside
        window.onclick = function(e) {
            const editModal = document.getElementById('editModal');
            if (e.target == editModal) {
                editModal.classList.remove('active');
            }
        }
    </script>
</body>
</html>