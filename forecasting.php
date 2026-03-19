<?php
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

// 🔥 FIXED: Sorted Cakes & Oils Alphabetically
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
    $sql = "SELECT id as recipe_id, packaging_id, item_type, qty_needed FROM product_recipes WHERE raw_material_id = $selected_product_id";
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
    <title>Production Recipe Builder</title>
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root { --primary: #2563eb; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; --success: #10b981; --danger: #ef4444; }
       .container {     margin-left: 30px;
  padding: 10px; }
        .header-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 25px; color: #0f172a; border-bottom: 2px solid var(--border); padding-bottom: 10px;}
        
        .grid-box { display: grid; grid-template-columns: 1.2fr 1fr; gap: 20px; align-items: start; }
        
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border); overflow: hidden; margin-bottom: 20px;}
        .card-header { background: #f1f5f9; padding: 15px 20px; font-weight: 700; border-bottom: 1px solid var(--border); color: #334155;}
        .card-body { padding: 20px; }
        
        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: #475569; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; outline: none; box-sizing: border-box;}
        input:focus, select:focus { border-color: var(--primary); }
        
        /* Select2 Custom Styling to match your theme */
        .select2-container .select2-selection--single {
            height: 42px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            padding: 5px 0px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px !important;
        }
        
        .btn { padding: 10px 16px; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; display:inline-block; text-align:center;}
        .btn-green { background: #10b981; width:100%; padding: 12px; font-size: 1.05rem; margin-top: 10px;}
        .btn-blue { background: #3b82f6; }
        .btn-sm { padding: 6px 10px; font-size: 0.8rem; }
        .btn-danger { background: #fee2e2; color: #b91c1c; }
        .btn-warning { background: #fef3c7; color: #b45309; }
        
        .type-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; }
        .badge-cake { background: #fef08a; color: #92400e; }
        .badge-oil { background: #fed7aa; color: #9a3412; }
        .badge-pack { background: #e0e7ff; color: #3730a3; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .data-table th { text-align: left; padding: 12px; border-bottom: 2px solid var(--border); background: #f8fafc; color: #475569;}
        .data-table td { padding: 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        
        .add-boxes { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px dashed #cbd5e1; margin-bottom: 20px;}

        /* Modal Styles */
        .modal { display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; }
        .modal-content { background:white; width:90%; max-width:400px; border-radius:10px; padding:20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);}

        @media(max-width: 1024px) { .container {margin-left: 0px;} .grid-box, .add-boxes { grid-template-columns: 1fr; } }
        @media(max-width: 762px) { .container {margin-left: 0px;} }
    </style>
</head>
<body>

    <?php include 'admin_header.php'; ?>
<div class="container">
    <div class="header-title"><i class="fas fa-boxes text-primary"></i> Product Recipe & Bill of Materials (BOM)</div>

    <div class="grid-box">
        
        <div>
            <div class="card">
                <div class="card-header">1. Select Master Product</div>
                <div class="card-body">
                    <form method="GET" id="productForm">
                        <div class="form-group" style="margin:0;">
                            <label>Product you want to make (e.g., Cattle Feed 50Kg, Mustard Oil 1L)</label>
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
            <form method="POST" id="unifiedAddForm" onsubmit="return validateAddForm()">
                <input type="hidden" name="product_id" value="<?= $selected_product_id ?>">
                <input type="hidden" name="add_to_recipe" value="1">
                
                <div class="add-boxes">
                    <div style="background: white; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                        <h4 style="margin: 0 0 15px 0; color: #92400e;"><i class="fas fa-seedling"></i> Add Raw Material</h4>
                        <div class="form-group">
                            <label>Search Cake or Raw Oil</label>
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
                        <div class="form-group">
                            <label>Quantity (Kg)</label>
                            <input type="number" step="0.001" name="rm_qty" id="rm_qty" placeholder="e.g. 50">
                        </div>
                    </div>

                    <div style="background: white; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                        <h4 style="margin: 0 0 15px 0; color: #3730a3;"><i class="fas fa-box"></i> Add Packing Material</h4>
                        <div class="form-group">
                            <label>Search Bag, Bottle, Tin, etc.</label>
                            <select name="packing_id" id="pack_val" class="searchable-select">
                                <option value="">-- None --</option>
                                <?php foreach($packing_items as $pm) echo "<option value='{$pm['id']}'>{$pm['item_name']}</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Quantity (Pcs)</label>
                            <input type="number" step="0.001" name="pack_qty" id="pack_qty" placeholder="e.g. 1">
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-green"><i class="fas fa-plus-circle"></i> Add to Recipe Formula</button>
            </form>

            <div class="card" style="margin-top: 25px;">
                <div class="card-header">Current Recipe Formula (For 1 Unit)</div>
                <div class="card-body" style="padding:0;">
                    <?php if(empty($forecast_data)): ?>
                        <div style="text-align: center; padding: 30px; color: #94a3b8;">No items added to recipe yet.</div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Material Name</th>
                                    <th>Quantity Needed</th>
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
                                        <td style="font-weight:600; color:#1e293b;"><?= htmlspecialchars($item['raw_name']) ?></td>
                                        <td>
                                            <span style="font-weight:700; color:#2563eb; background:#eff6ff; padding:4px 10px; border-radius:20px;">
                                                <?= $item['qty_needed'] ?> <?= $item['raw_unit'] ?? '' ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right; gap:5px; display:flex; justify-content:flex-end;">
                                            <button type="button" class="btn btn-sm btn-warning" onclick="openEditModal(<?= $item['recipe_id'] ?>, '<?= htmlspecialchars($item['raw_name']) ?>', <?= $item['qty_needed'] ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <a href="?delete_ing=<?= $item['recipe_id'] ?>&pid=<?= $selected_product_id ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove this item from recipe?');">
                                                <i class="fas fa-trash-alt"></i> Delete
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

        <div class="card">
            <div class="card-header">Stock Predictor & Requirements</div>
            <div class="card-body">
                <?php if($selected_product_id): ?>
                    <form method="GET" style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:20px; display:flex; gap:10px; align-items:flex-end; border:1px solid #e2e8f0;">
                        <input type="hidden" name="selected_product" value="<?= $selected_product_id ?>">
                        <div style="flex:1;">
                            <label style="color:#0f172a;">How many units do you want to produce?</label>
                            <input type="number" name="target_qty" value="<?= $target_qty ?>" style="font-size:1.2rem; font-weight:bold; color:var(--primary);" onchange="this.form.submit()">
                        </div>
                        <button type="submit" class="btn btn-blue" style="width:auto;">Predict</button>
                    </form>

                    <?php if(!empty($forecast_data)): ?>
                        <table class="data-table">
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
                                    <td style="font-weight:600;"><?= htmlspecialchars($row['raw_name']) ?></td>
                                    <td>
                                        <b style="color:#0f172a;"><?= number_format($row['total_needed'], 2) ?></b>
                                        <small><?= htmlspecialchars($row['raw_unit'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <?php if($row['shortage'] > 0): ?>
                                            <span style="color:var(--danger); font-weight:bold;"><?= number_format($row['current_stock'], 2) ?> (Need <?= number_format($row['shortage'], 2) ?>)</span>
                                        <?php else: ?>
                                            <span style="color:var(--success); font-weight:bold;"><?= number_format($row['current_stock'], 2) ?> (OK)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <?php if($has_shortage): ?>
                            <div style="margin-top:20px; padding:15px; background:#fef2f2; border-left:4px solid #ef4444; border-radius:4px; color:#991b1b;">
                                <strong>⚠️ Shortage Detected!</strong> You don't have enough materials in stock.
                            </div>
                        <?php else: ?>
                            <div style="margin-top:20px; padding:15px; background:#f0fdf4; border-left:4px solid #10b981; border-radius:4px; color:#166534;">
                                <strong>✅ Ready for Production!</strong> All materials are available.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="text-align:center; padding: 40px 20px; color: #94a3b8;">
                        Please select a product first to view its stock requirements.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:20px;">
                <h3 style="margin:0; color:#0f172a;">Edit Ingredient Quantity</h3>
                <span onclick="document.getElementById('editModal').style.display='none'" style="cursor:pointer; font-size:24px; color:#94a3b8;">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="update_qty" value="1">
                <input type="hidden" name="product_id" value="<?= $selected_product_id ?>">
                <input type="hidden" name="recipe_id" id="edit_recipe_id">
                
                <div class="form-group">
                    <label>Material Name</label>
                    <input type="text" id="edit_mat_name" class="form-control" readonly style="background:#f1f5f9; color:#64748b;">
                </div>
                <div class="form-group">
                    <label>New Quantity Needed (For 1 Unit)</label>
                    <input type="number" step="0.001" name="new_qty" id="edit_qty_val" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-blue" style="width:100%; margin-top:10px;"><i class="fas fa-save"></i> Save Changes</button>
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
            document.getElementById('editModal').style.display = 'flex';
        }
    </script>
</body>
</html>