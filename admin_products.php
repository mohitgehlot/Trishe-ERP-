<?php
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

// --- Helper: Format Currency ---
function formatCurrency($amount)
{
    return '₹' . number_format($amount, 2);
}

// --- 1. HANDLE SAVE / UPDATE ---
if (isset($_POST['save_product'])) {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $cat = !empty($_POST['category']) ? (int)$_POST['category'] : NULL;
    $seed_id = !empty($_POST['seed_id']) ? (int)$_POST['seed_id'] : NULL;
    $p_type = $_POST['product_type'] ?? 'oil';
    $hsn = trim($_POST['hsn_code'] ?? '');
    $cost = (float)($_POST['cost_price'] ?? 0);
    $base = (float)($_POST['base_price'] ?? 0);
    $tax = isset($_POST['tax_rate']) ? (float)$_POST['tax_rate'] : 0;
    $weight = (float)($_POST['weight'] ?? 0);
    $unit = $_POST['unit'] ?? 'Pcs';
    $min_s = (int)($_POST['min_stock'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');
    $status = isset($_POST['is_active']) ? 1 : 0;
    $edit_id = (int)($_POST['product_id'] ?? 0);

    if ($edit_id > 0) {
        if (empty($barcode)) {
            $old_data = $conn->query("SELECT barcode FROM products WHERE id=$edit_id")->fetch_assoc();
            $barcode = $old_data['barcode'];
        }
        $sql = "UPDATE products SET name=?, category_id=?, seed_id=?, product_type=?, hsn_code=?, cost_price=?, base_price=?, tax_rate=?, weight=?, unit=?, min_stock=?, barcode=?, is_active=?, description=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('siissddddsisisi', $name, $cat, $seed_id, $p_type, $hsn, $cost, $base, $tax, $weight, $unit, $min_s, $barcode, $status, $desc, $edit_id);
    } else {
        if (empty($barcode)) {
            $barcode = 'TRISHE-' . time() . rand(10, 99);
        }
        $sql = "INSERT INTO products (name, category_id, seed_id, product_type, hsn_code, cost_price, base_price, tax_rate, weight, unit, min_stock, barcode, is_active, description) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('siissddddsisis', $name, $cat, $seed_id, $p_type, $hsn, $cost, $base, $tax, $weight, $unit, $min_s, $barcode, $status, $desc);
    }

    try {
        if ($stmt->execute()) {
            header('Location: admin_products.php?msg=saved');
            exit;
        }
    } catch (Exception $e) {
        echo "<script>alert('Error! " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}

// --- 2. DELETE ---
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $conn->query("DELETE FROM products WHERE id=$del_id");
    header('Location: admin_products.php?msg=deleted');
    exit;
}

// --- 3. FETCH DATA FOR DROPDOWNS ---
$cats = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$seeds_list = $conn->query("SELECT id, name FROM seeds_master ORDER BY name ASC");

// --- 4. 🌟 FETCH LOOSE OIL & CAKE INVENTORY (Like packaging.php) 🌟 ---
$loose_stocks = [];
$raw_stock_sql = "
    SELECT s.name as seed_name, 
    SUM(CASE WHEN r.product_type = 'OIL' AND r.transaction_type IN ('RAW_IN','ADJUSTMENT_IN') THEN r.quantity 
             WHEN r.product_type = 'OIL' AND r.transaction_type IN ('RAW_OUT','ADJUSTMENT_OUT') THEN -r.quantity ELSE 0 END) as oil_qty,
    SUM(CASE WHEN r.product_type = 'CAKE' AND r.transaction_type IN ('RAW_IN','ADJUSTMENT_IN') THEN r.quantity 
             WHEN r.product_type = 'CAKE' AND r.transaction_type IN ('RAW_OUT','ADJUSTMENT_OUT') THEN -r.quantity ELSE 0 END) as cake_qty
    FROM seeds_master s
    LEFT JOIN raw_material_inventory r ON s.id = r.seed_id
    GROUP BY s.id
";
$raw_stock_res = $conn->query($raw_stock_sql);
if ($raw_stock_res) {
    while ($row = $raw_stock_res->fetch_assoc()) {
        if ($row['oil_qty'] > 0 || $row['cake_qty'] > 0) { // Sirf wahi dikhayega jiska stock ho
            $loose_stocks[] = $row;
        }
    }
}

// --- 5. FETCH PRODUCTS LIST & STATS ---
$search = $_GET['search'] ?? '';
$where_sql = $search ? "WHERE p.name LIKE '%$search%' OR p.barcode LIKE '%$search%' OR p.description LIKE '%$search%'" : "";

$sql_products = "
    SELECT p.*, c.name as cat_name, s.name as seed_name,
    COALESCE((SELECT SUM(qty) FROM inventory_products WHERE product_id = p.id), 0) as current_stock
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN seeds_master s ON p.seed_id = s.id 
    $where_sql 
    GROUP BY p.id 
    ORDER BY FIELD(LOWER(p.product_type), 'oil', 'seed', 'cake', 'raw_oil'), p.name ASC";

$products_res = $conn->query($sql_products);

$stats = ['total' => 0, 'value' => 0, 'low' => 0, 'active' => 0];
$p_data = [];
if ($products_res) {
 // --- 5. FETCH PRODUCTS LIST & STATS ---
$search = $_GET['search'] ?? '';
$where_sql = $search ? "WHERE p.name LIKE '%$search%' OR p.barcode LIKE '%$search%' OR p.description LIKE '%$search%'" : "";

$sql_products = "
    SELECT p.*, c.name as cat_name, s.name as seed_name
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN seeds_master s ON p.seed_id = s.id 
    $where_sql 
    ORDER BY FIELD(LOWER(p.product_type), 'oil', 'seed', 'cake', 'raw_oil'), p.name ASC";

$products_res = $conn->query($sql_products);

// 🌟 NAYA: 'active' ki jagah 'seed_value' add kiya 🌟
$stats = ['total' => 0, 'value' => 0, 'low' => 0, 'seed_value' => 0]; 
$p_data = [];

if ($products_res) {
    while ($row = $products_res->fetch_assoc()) {
        $stats['total']++;
        $p_type = strtolower($row['product_type']);
        $s_id = $row['seed_id'];
        $current_stock = 0;

        // SMART STOCK CALCULATION
        if ($p_type == 'seed') {
            $st_res = $conn->query("SELECT current_stock FROM seeds_master WHERE id = '$s_id'");
            $current_stock = ($st_res && $st_res->num_rows > 0) ? $st_res->fetch_assoc()['current_stock'] : 0;
        } elseif ($p_type == 'cake' || $p_type == 'raw_oil') {
            $db_prod_type = ($p_type == 'raw_oil') ? 'OIL' : 'CAKE';
            $st_res = $conn->query("SELECT SUM(CASE WHEN transaction_type IN ('RAW_IN','ADJUSTMENT_IN') THEN quantity WHEN transaction_type IN ('RAW_OUT','ADJUSTMENT_OUT') THEN -quantity ELSE 0 END) as qty FROM raw_material_inventory WHERE seed_id = '$s_id' AND UPPER(product_type) = '$db_prod_type'");
            $current_stock = ($st_res && $st_res->num_rows > 0) ? $st_res->fetch_assoc()['qty'] : 0;
        } else {
            $st_sql = "SELECT SUM(CASE 
                          WHEN transaction_type IN ('PRODUCTION', 'SALE_RETURN', 'ADJUSTMENT_IN') THEN qty 
                          WHEN transaction_type IN ('SALE', 'DAMAGE', 'ADJUSTMENT_OUT') THEN -qty 
                          ELSE 0 
                       END) as final_qty 
                       FROM inventory_products WHERE product_id = '{$row['id']}'";
            $st_res = $conn->query($st_sql);
            $current_stock = ($st_res && $st_res->num_rows > 0) ? $st_res->fetch_assoc()['final_qty'] : 0;
        }

        $current_stock = max(0, $current_stock ?? 0);
        $row['real_stock'] = $current_stock; 

        // CALCULATE VALUES
        $item_value = ($current_stock * $row['cost_price']);

        // 🌟 NAYA: Seed ki value alag, aur baaki sabki (Tel, Khal, Packed) alag 🌟
        if ($p_type == 'seed') {
            $stats['seed_value'] += $item_value; // Sirf Seed card mein judega
        } else {
            $stats['value'] += $item_value; // Baaki sab Total Inventory mein judega
        }

        if ($current_stock < $row['min_stock']) $stats['low']++;
        
        $p_data[] = $row;
    }
}
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Product Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            overflow-x: hidden;
        }

        /* HEADER */
        .page-header-box {
            background: #fff;
            padding: 15px 20px;
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

        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #fff;
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .stat-icon {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-card.blue .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .stat-card.green .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .stat-card.red .stat-icon {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .stat-card.orange .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            margin-top: 10px;
            color: var(--text-main);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* FILTER BAR & SEARCH */
        .filter-bar-wrap {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 15px;
            background: #f8fafc;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-input-wrapper {
            flex: 1;
            position: relative;
            min-width: 250px;
        }

        .search-input-wrapper i {
            position: absolute;
            left: 15px;
            top: 12px;
            color: #94a3b8;
        }

        .search-input {
            width: 100%;
            padding: 10px 12px 10px 40px;
            border: 1px solid var(--border);
            border-radius: 6px;
            outline: none;
            background: #fff;
            transition: border-color 0.2s;
            font-size: 0.9rem;
        }

        .search-input:focus {
            border-color: var(--primary);
        }

        .sort-dropdown {
            max-width: 200px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            outline: none;
            background: #fff;
            cursor: pointer;
            font-size: 0.9rem;
        }

        /* CUSTOM PRODUCT ROW STYLING (Used inside Table) */
        .product-row {
            transition: background 0.2s;
            position: relative;
        }

        .product-row:hover {
            background: #f8fafc;
        }

        .row-actions {
            display: flex;
            gap: 8px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease-in-out;
            transform: translateX(-10px);
        }

        .product-row:hover .row-actions {
            opacity: 1;
            visibility: visible;
            transform: translateX(0);
        }

        .btn-action-small {
            padding: 6px 10px;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text-main);
            text-decoration: none;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-action-small:hover {
            background: #f1f5f9;
        }

        .btn-action-small.text-danger {
            color: #ef4444;
        }

        .btn-action-small.text-danger:hover {
            background: #fee2e2;
            border-color: #fca5a5;
        }

        /* SPECIFIC BADGES FOR PRODUCTS */
        .badge-type {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            background: #f1f5f9;
            color: #475569;
            margin-left: 5px;
            vertical-align: middle;
        }

        .badge-status.active {
            background: #dcfce7;
            color: #166534;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .badge-status.inactive {
            background: #fee2e2;
            color: #991b1b;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        /* LOOSE OIL SCROLL AREA */
        .loose-grid {
            display: flex;
            gap: 15px;
            overflow-x: auto;
            padding-bottom: 10px;
            margin-bottom: 25px;
        }

        .loose-grid::-webkit-scrollbar {
            height: 6px;
        }

        .loose-grid::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .loose-grid::-webkit-scrollbar-track {
            background: transparent;
        }

        .loose-card {
            min-width: 250px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        /* FORM GRID FOR MODAL */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        /* MOBILE RESPONSIVE (Table to Cards) */
        @media (max-width: 1024px) {
            body {
                padding-left: 0;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 12px;
            }

            .page-header-box {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-header-box button {
                width: 100%;
                justify-content: center;
            }

            .filter-bar-wrap {
                flex-direction: column;
                align-items: stretch;
            }

            .sort-dropdown {
                max-width: 100%;
            }

            /* Table to Card view conversion for mobile */
            .table-wrap {
                border: none;
                background: transparent;
                box-shadow: none;
                max-height: none;
                overflow: visible;
            }

            table,
            thead,
            tbody,
            tr,
            td {
                display: block;
                width: 100%;
            }

            thead {
                display: none;
            }

            tr.product-row {
                margin-bottom: 15px;
                border: 1px solid var(--border);
                border-radius: 8px;
                padding: 15px;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            }

            td {
                padding: 10px 0 !important;
                border-bottom: 1px dashed #e2e8f0 !important;
                display: flex;
                justify-content: space-between;
                align-items: center;
                text-align: right;
            }

            td:last-child {
                border-bottom: none !important;
            }

            /* Re-arranging specific cells for Mobile */
            td:nth-child(1) {
                display: block;
                text-align: left;
                padding-top: 0 !important;
                border-bottom: 1px solid #e5e7eb !important;
                padding-bottom: 12px !important;
            }

            td:nth-child(2)::before {
                content: "Pricing :";
                color: #64748b;
                font-weight: 700;
                font-size: 0.8rem;
                text-transform: uppercase;
            }

            td:nth-child(3) {
                align-items: flex-start;
            }

            td:nth-child(3)::before {
                content: "Stock Status :";
                color: #64748b;
                font-weight: 700;
                font-size: 0.8rem;
                text-transform: uppercase;
                margin-top: 2px;
            }

            .row-actions {
                position: static;
                opacity: 1;
                visibility: visible;
                transform: none;
                margin-top: 15px;
                display: flex;
                gap: 10px;
                width: 100%;
                justify-content: flex-end;
            }

            .btn-action-small {
                flex: 1;
                justify-content: center;
                padding: 10px;
                font-size: 0.9rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            /* Modal forms stack on mobile */
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <?php if (isset($_GET['msg'])): ?>
            <?php
            $msg_text = "Action Completed Successfully!";
            if ($_GET['msg'] == 'saved') $msg_text = "Product Saved Successfully!";
            if ($_GET['msg'] == 'deleted') $msg_text = "Product Deleted Successfully!";
            ?>
            <div class="alert"><i class="fas fa-check-circle"></i> <?= $msg_text ?></div>
        <?php endif; ?>

        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-boxes text-primary" style="margin-right:10px;"></i> Product Inventory</h1>
            <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add Product</button>
        </div>

       <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                <div class="stat-value"><?= number_format($stats['total']) ?></div>
                <div class="stat-label">Total Products</div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
                <div class="stat-value"><?= formatCurrency($stats['value']) ?></div>
                <div class="stat-label">Total Inventory Value</div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-value"><?= number_format($stats['low']) ?></div>
                <div class="stat-label">Low Stock Alerts</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon"><i class="fas fa-seedling"></i></div>
                <div class="stat-value" style="color: #d97706;"><?= formatCurrency($stats['seed_value']) ?></div>
                <div class="stat-label">Seed Inventory Value</div>
            </div>
        </div>

        <?php if (!empty($loose_stocks)): ?>
            <div style="margin-bottom: 25px;">
                <h2 style="font-size: 0.95rem; font-weight: 700; color: #64748b; text-transform:uppercase; margin-bottom: 12px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-tint text-primary"></i> Live Loose Oil & Khali Stock
                </h2>
                <div class="loose-grid">
                    <?php foreach ($loose_stocks as $ls): ?>
                        <div class="loose-card">
                            <h3 style="margin: 0 0 12px 0; font-size: 1rem; color: var(--text-main); font-weight: 700;">
                                <?= htmlspecialchars($ls['seed_name']) ?>
                            </h3>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.85rem; align-items:center;">
                                <span style="color: var(--text-muted); font-weight:600;">Loose Oil:</span>
                                <span class="badge st-completed"><?= number_format($ls['oil_qty'], 2) ?> Kg</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; align-items:center;">
                                <span style="color: var(--text-muted); font-weight:600;">Khali (Cake):</span>
                                <span class="badge st-readytoship"><?= number_format($ls['cake_qty'], 2) ?> Kg</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="filter-bar-wrap">
                <div class="search-input-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" id="liveSearch" class="search-input" placeholder="Type to search products, SKU or category...">
                </div>
                <select id="sortDropdown" class="sort-dropdown">
                    <option value="default">Sort By: Default</option>
                    <option value="name_asc">Name: A to Z</option>
                    <option value="price_low">Price: Low to High</option>
                    <option value="price_high">Price: High to Low</option>
                    <option value="stock_low">Stock: Low to High</option>
                </select>
            </div>

            <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                <table>
                    <thead>
                        <tr>
                            <th style="width:50%;">Product Info</th>
                            <th style="width:25%;">Pricing</th>
                            <th style="width:25%;">Stock Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($p_data as $row):
                            $current_stock = $row['real_stock']; // Picked from top calculation
                            $p_type = strtolower($row['product_type']);
                            
                            // Smart Display Unit
                            if ($p_type == 'seed' || $p_type == 'cake' || $p_type == 'raw_oil') {
                                $stock_display = number_format($current_stock, 2) . " Kg";
                            } else {
                                $stock_display = number_format($current_stock, 0) . " Pcs"; 
                            }
                        ?>
                            <tr class="product-row"
                                data-name="<?= strtolower(htmlspecialchars($row['name'])) ?>"
                                data-price="<?= $row['base_price'] ?>"
                                data-stock="<?= $current_stock ?>">
                                <td>
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                        <div>
                                            <div style="font-weight:700; font-size:1rem; color:var(--text-main);">
                                                <?= htmlspecialchars($row['name']) ?>
                                                <span class="badge-type"><?= $p_type ?></span>
                                            </div>
                                            <div style="font-size:0.8rem; color:var(--text-muted); margin-top:6px; font-weight:500;">
                                                <?= !empty($row['description']) ? 'SKU: ' . htmlspecialchars($row['description']) : 'No description' ?>
                                            </div>
                                        </div>

                                        <div class="row-actions">
                                            <button onclick='editProduct(<?= json_encode($row) ?>)' class="btn-action-small" title="Edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <a href="?delete=<?= $row['id'] ?>" class="btn-action-small text-danger" onclick="return confirm('Are you sure you want to delete this product?')" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:700; color:var(--text-main); font-size:1rem;">
                                        <?= formatCurrency($row['base_price']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:800; font-size:1.1rem; color:<?= ($current_stock < $row['min_stock']) ? 'var(--danger)' : 'var(--primary)' ?>;">
                                        <?= $stock_display ?>
                                    </div>
                                    <div style="margin-top:6px;">
                                        <span class="badge-status <?= $row['is_active'] ? 'active' : 'inactive' ?>">
                                            <?= $row['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="global-modal" id="productModal">
        <div class="g-modal-content">
            <div class="g-modal-header">
                <h3 id="modalTitle" style="margin:0; font-size:1.2rem; color:var(--text-main);"><i class="fas fa-box text-primary" style="margin-right:8px;"></i> Add New Product</h3>
                <button type="button" class="g-close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="g-modal-body">
                    <input type="hidden" name="product_id" id="p_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="name" id="p_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Product Type *</label>
                            <select name="product_type" id="p_type" class="form-input" required>
                                <option value="oil">Oil (Packaged)</option>
                                <option value="seed">Seed (Raw Material)</option>
                                <option value="cake">Cake (Khal)</option>
                                <option value="raw_oil">Raw Oil</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:15px;">
                        <label class="form-label">Description / SKU (Used for Combo Linking)</label>
                        <textarea name="description" id="p_desc" class="form-input" rows="2" placeholder="e.g. UqF2zxVI"></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Link Raw Material (Seed)</label>
                            <select name="seed_id" id="p_seed" class="form-input">
                                <option value="">-- No Link --</option>
                                <?php $seeds_list->data_seek(0);
                                while ($s = $seeds_list->fetch_assoc()) echo "<option value='{$s['id']}'>{$s['name']}</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">HSN Code</label>
                            <input type="text" name="hsn_code" id="p_hsn" class="form-input">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Cost Price (₹)</label>
                            <input type="number" step="0.01" name="cost_price" id="p_cost" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Selling Price (₹) *</label>
                            <input type="number" step="0.01" name="base_price" id="p_base" class="form-input" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Tax Rate (%)</label>
                            <select name="tax_rate" id="p_tax" class="form-input">
                                <option value="0">0%</option>
                                <option value="5">5%</option>
                                <option value="12">12%</option>
                                <option value="18">18%</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Min Stock Alert</label>
                            <input type="number" name="min_stock" id="p_min" class="form-input" value="10">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Net Weight/Volume</label>
                            <input type="number" step="0.01" name="weight" id="p_weight" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unit</label>
                            <select name="unit" id="p_unit" class="form-input">
                                <option value="KG">Kg</option>
                                <option value="Liter">Liter (L)</option>
                                <option value="ml">ml</option>
                                <option value="PCS">Pcs</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 10px;">
                        <label style="display:flex; align-items:center; gap:10px; font-size:0.9rem; font-weight:600; cursor:pointer; color:var(--text-main);">
                            <input type="checkbox" name="is_active" id="p_active" value="1" checked style="width:18px; height:18px; cursor:pointer;"> Active & Show in POS
                        </label>
                    </div>
                </div>
                <div style="padding:15px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="save_product" class="btn btn-primary"><i class="fas fa-save"></i> Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // MODAL LOGIC (Using Master CSS Global Modal structure)
        const modal = document.getElementById('productModal');

        function openModal() {
            modal.classList.add('active');
            document.getElementById('p_id').value = '';
            document.forms[0].reset(); // Form index might be 0 here
        }

        function closeModal() {
            modal.classList.remove('active');
        }

        function editProduct(data) {
            modal.classList.add('active');
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-primary" style="margin-right:8px;"></i> Edit Product';
            document.getElementById('p_id').value = data.id;
            document.getElementById('p_name').value = data.name;
            document.getElementById('p_desc').value = data.description || '';
            document.getElementById('p_type').value = data.product_type || 'oil';
            document.getElementById('p_seed').value = data.seed_id || '';
            document.getElementById('p_cost').value = data.cost_price;
            document.getElementById('p_base').value = data.base_price;
            document.getElementById('p_min').value = data.min_stock;
            document.getElementById('p_weight').value = data.weight || '';
            document.getElementById('p_hsn').value = data.hsn_code || '';
            document.getElementById('p_tax').value = data.tax_rate || '0';
            document.getElementById('p_unit').value = data.unit || 'KG';
            document.getElementById('p_active').checked = (data.is_active == 1);
        }

        // LIVE SEARCH & SORTING LOGIC
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('liveSearch');
            const sortDropdown = document.getElementById('sortDropdown');
            const tbody = document.querySelector('tbody');

            // 1. LIVE SEARCH FUNCTION
            searchInput.addEventListener('input', function() {
                let filter = this.value.toLowerCase();
                let rows = document.querySelectorAll('.product-row');

                rows.forEach(row => {
                    let text = row.innerText.toLowerCase();
                    if (text.includes(filter)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });

            // 2. SORTING FUNCTION
            sortDropdown.addEventListener('change', function() {
                let rows = Array.from(document.querySelectorAll('.product-row'));
                let sortBy = this.value;

                rows.sort((a, b) => {
                    if (sortBy === 'name_asc') {
                        return a.dataset.name.localeCompare(b.dataset.name);
                    } else if (sortBy === 'price_low') {
                        return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
                    } else if (sortBy === 'price_high') {
                        return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
                    } else if (sortBy === 'stock_low') {
                        return parseFloat(a.dataset.stock) - parseFloat(b.dataset.stock);
                    } else {
                        return 0; // Default
                    }
                });

                // Re-append sorted rows to tbody
                rows.forEach(row => tbody.appendChild(row));
            });
        });
    </script>
</body>

</html>