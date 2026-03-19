<?php
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit;
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
    while ($row = $products_res->fetch_assoc()) {
        $stats['total']++;
        $stock = max(0, $row['current_stock']);
        $stats['value'] += ($stock * $row['cost_price']);
        if ($stock < $row['min_stock']) $stats['low']++;
        if ($row['is_active']) $stats['active']++;
        $p_data[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Product Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --secondary: #3b82f6;
            --bg-body: #f3f4f6;
            --surface: #ffffff;
            --text-main: #111827;
            --text-light: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --radius: 12px;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            padding: 15px;
            /* Mobile ke hisaab se thodi kam padding */
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            margin: 0;
            padding-left: 260px;
            color: var(--text-main);
            padding-bottom: 80px;
        }

        .container {
            padding: 15px;
            /* Mobile ke hisaab se thodi kam padding */
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(156px, 2fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--surface);
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
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
            opacity: 0.2;
        }

        .stat-card.blue .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            opacity: 1;
        }

        .stat-card.green .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            opacity: 1;
        }

        .stat-card.red .stat-icon {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            opacity: 1;
        }

        .stat-card.orange .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            opacity: 1;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-top: 10px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }

        .content-card {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .filter-bar {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 10px;
            background: #f9fafb;
            align-items: center;
        }

        .search-input {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            outline: none;
            max-width: 400px;
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
            text-align: left;
            padding: 14px 20px;
            background: #f9fafb;
            color: var(--text-light);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
        }

        th:nth-child(1) {
            width: 50%;
        }

        th:nth-child(2) {
            width: 25%;
        }

        th:nth-child(3) {
            width: 25%;
        }

        td {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            vertical-align: middle;
        }

        .product-row {
            transition: background 0.2s;
            position: relative;
        }

        .product-row:hover {
            background: #f9fafb;
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

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }

        .modal-box {
            background: white;
            width: 95%;
            max-width: 750px;
            border-radius: 12px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            animation: slideIn 0.3s;
        }

        .modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            background: #f9fafb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 6px;
            color: #4b5563;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            outline: none;
            background: white;
            box-sizing: border-box;
        }

        .btn {
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-outline {
            background: white;
            border: 1px solid var(--border);
            color: var(--text-main);
        }

        .btn-action {
            padding: 6px 10px;
            font-size: 0.8rem;
            border-radius: 6px;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            background: #e5e7eb;
            color: #374151;
        }

        .badge.active {
            background: #dcfce7;
            color: #166534;
        }

        .badge.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Loose Oil Scrollbar Styling */
        .scroll-loose::-webkit-scrollbar {
            height: 6px;
        }

        .scroll-loose::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .scroll-loose::-webkit-scrollbar-track {
            background: transparent;
        }

        @media(max-width: 1024px) {
            body {
                padding-left: 0;
            }
        }

        @media (max-width: 768px) {

            /* Fix Header & Buttons */
            .page-header {
                flex-direction: column;
                align-items: stretch !important;
                gap: 15px;
            }

            .page-header button {
                width: 100%;
                justify-content: center;
            }

            /* Fix Stats Grid (Mobile par 2 dabbe ek line me) */
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-value {
                font-size: 20px;
            }

            .stat-icon {
                width: 35px;
                height: 35px;
                font-size: 1.2rem;
                top: 15px;
                right: 15px;
            }

            /* Fix Filter Bar */
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-input {
                max-width: 100%;
                width: 100%;
            }

            /* Baki apka purana Table to Card view wala code same rahega... */
            .table-responsive {
                border: none;
                background: transparent;
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
                margin-bottom: 16px;
                border: 1px solid var(--border);
                border-radius: 12px;
                padding: 16px;
                background: #fff;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04);
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

            td:nth-child(1) {
                display: block;
                text-align: left;
                padding-top: 0 !important;
                border-bottom: 1px solid #e5e7eb !important;
                padding-bottom: 12px !important;
            }

            td:nth-child(2)::before {
                content: "Pricing :";
                color: #6b7280;
                font-weight: 600;
                font-size: 13px;
            }

            td:nth-child(3) {
                align-items: flex-start;
            }

            td:nth-child(3)::before {
                content: "Stock Status :";
                color: #6b7280;
                font-weight: 600;
                font-size: 13px;
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

            .btn-action {
                flex: 1;
                justify-content: center;
                padding: 10px;
                font-size: 14px;
            }

            .table-responsive {
                border: none;
                background: transparent;
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
                margin-bottom: 16px;
                border: 1px solid var(--border);
                border-radius: 12px;
                padding: 16px;
                background: #fff;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04);
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

            td:nth-child(1) {
                display: block;
                text-align: left;
                padding-top: 0 !important;
                border-bottom: 1px solid #e5e7eb !important;
                padding-bottom: 12px !important;
            }

            td:nth-child(2)::before {
                content: "Pricing :";
                color: #6b7280;
                font-weight: 600;
                font-size: 13px;
            }

            td:nth-child(3) {
                align-items: flex-start;
            }

            td:nth-child(3)::before {
                content: "Stock Status :";
                color: #6b7280;
                font-weight: 600;
                font-size: 13px;
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

            .btn-action {
                flex: 1;
                justify-content: center;
                padding: 10px;
                font-size: 14px;
            }

            .search-input {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
            <h1 style="font-size:24px; font-weight:700; margin:0;">Product Inventory</h1>
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
                <div class="stat-label">Inventory Value</div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-value"><?= number_format($stats['low']) ?></div>
                <div class="stat-label">Low Stock Alerts</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value"><?= number_format($stats['active']) ?></div>
                <div class="stat-label">Active Items</div>
            </div>
        </div>

        <?php if (!empty($loose_stocks)): ?>
            <div style="margin-bottom: 24px;">
                <h2 style="font-size: 16px; font-weight: 700; color: #374151; margin-bottom: 12px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-tint" style="color: var(--primary);"></i> Live Loose Oil & Khali Stock
                </h2>
                <div class="scroll-loose" style="display: flex; gap: 16px; overflow-x: auto; padding-bottom: 10px;">
                    <?php foreach ($loose_stocks as $ls): ?>
                        <div style="min-width: 260px; background: white; border: 1px solid var(--border); border-radius: 10px; padding: 16px; box-shadow: var(--shadow);">
                            <h3 style="margin: 0 0 12px 0; font-size: 15px; color: var(--text-main); font-weight: 600;">
                                <?= htmlspecialchars($ls['seed_name']) ?>
                            </h3>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; align-items:center;">
                                <span style="color: var(--text-light);">Loose Oil:</span>
                                <span style="font-weight: 700; color: #059669; background:#dcfce7; padding:4px 8px; border-radius:4px;"><?= number_format($ls['oil_qty'], 2) ?> Kg</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 13px; align-items:center;">
                                <span style="color: var(--text-light);">Khali (Cake):</span>
                                <span style="font-weight: 700; color: #d97706; background:#fef3c7; padding:4px 8px; border-radius:4px;"><?= number_format($ls['cake_qty'], 2) ?> Kg</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="content-card">
            <form method="GET" class="filter-bar">
                <div class="filter-bar" style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <div style="flex: 1; position: relative;">
                        <i class="fas fa-search" style="position: absolute; left: 15px; top: 12px; color: #9ca3af;"></i>
                        <input type="text" id="liveSearch" class="search-input" placeholder="Type to search products, SKU or category..." style="width: 100%; padding-left: 40px; max-width: 100%;">
                    </div>
                    <select id="sortDropdown" class="search-input" style="max-width: 200px; cursor: pointer;">
                        <option value="default">Sort By: Default</option>
                        <option value="name_asc">Name: A to Z</option>
                        <option value="price_low">Price: Low to High</option>
                        <option value="price_high">Price: High to Low</option>
                        <option value="stock_low">Stock: Low to High</option>
                    </select>
                </div>
            </form>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Product Info</th>
                            <th>Pricing</th>
                            <th>Stock Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($p_data as $row):
                            $current_stock = 0;
                            $p_type = strtolower($row['product_type']);
                            $s_id = $row['seed_id'];

                            // FIX: Table logic updated to match packaging.php just in case someone makes a product manually
                            if ($p_type == 'seed') {
                                $st_res = $conn->query("SELECT current_stock FROM seeds_master WHERE id = '$s_id'");
                                $current_stock = ($st_res && $st_res->num_rows > 0) ? $st_res->fetch_assoc()['current_stock'] : 0;
                            } elseif ($p_type == 'cake' || $p_type == 'raw_oil') {
                                $db_prod_type = ($p_type == 'raw_oil') ? 'OIL' : 'CAKE';
                                $st_res = $conn->query("SELECT SUM(CASE WHEN transaction_type IN ('RAW_IN','ADJUSTMENT_IN') THEN quantity WHEN transaction_type IN ('RAW_OUT','ADJUSTMENT_OUT') THEN -quantity ELSE 0 END) as qty FROM raw_material_inventory WHERE seed_id = '$s_id' AND UPPER(product_type) = '$db_prod_type'");
                                $current_stock = ($st_res && $st_res->num_rows > 0) ? $st_res->fetch_assoc()['qty'] : 0;
                            } else {
                                $st_res = $conn->query("SELECT SUM(qty) as qty FROM inventory_products WHERE product_id = '{$row['id']}'");
                                $current_stock = ($st_res && $st_res->num_rows > 0) ? $st_res->fetch_assoc()['qty'] : 0;
                            }
                            $current_stock = $current_stock ?? 0;
                        ?>
                            <tr class="product-row"
                                data-name="<?= strtolower(htmlspecialchars($row['name'])) ?>"
                                data-price="<?= $row['base_price'] ?>"
                                data-stock="<?= $current_stock ?>">
                                <td>
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                        <div>
                                            <div style="font-weight:600; font-size:15px;">
                                                <?= htmlspecialchars($row['name']) ?>
                                                <span class="badge" style="margin-left:5px;"><?= $p_type ?></span>
                                            </div>
                                            <div style="font-size:12px; color:#6b7280; margin-top:4px;">
                                                <?= !empty($row['description']) ? 'SKU/Desc: ' . htmlspecialchars($row['description']) : 'No description' ?>
                                            </div>
                                        </div>

                                        <div class="row-actions">
                                            <button onclick='editProduct(<?= json_encode($row) ?>)' class="btn btn-outline btn-action" title="Edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <a href="?delete=<?= $row['id'] ?>" class="btn btn-outline btn-action" style="color:var(--danger);" onclick="return confirm('Are you sure you want to delete?')" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td style="font-weight:500; color:var(--text-main);">
                                    <?= formatCurrency($row['base_price']) ?>
                                </td>
                                <td>
                                    <div style="font-weight:700; font-size:15px; color:<?= ($current_stock < $row['min_stock']) ? 'var(--danger)' : 'var(--primary)' ?>;">
                                        <?= number_format($current_stock, 2) ?> <?= htmlspecialchars($row['unit']) ?>
                                    </div>
                                    <div style="font-size:11px; margin-top:2px;">
                                        <span class="badge <?= $row['is_active'] ? 'active' : 'inactive' ?>">
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

    <div class="modal-overlay" id="productModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="modalTitle" style="margin:0;">Add New Product</h3>
                <span onclick="closeModal()" style="cursor:pointer; font-size:24px;">&times;</span>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="product_id" id="p_id">
                    <div class="form-grid">
                        <div class="form-group"><label>Product Name *</label><input type="text" name="name" id="p_name" class="form-control" required></div>
                        <div class="form-group">
                            <label>Product Type *</label>
                            <select name="product_type" id="p_type" class="form-control" required>
                                <option value="oil">Oil (Packaged)</option>
                                <option value="seed">Seed (Raw Material)</option>
                                <option value="cake">Cake (Khal)</option>
                                <option value="raw_oil">Raw Oil</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label>Description / SKU (Used for Combo Linking)</label>
                        <textarea name="description" id="p_desc" class="form-control" rows="2" placeholder="e.g. UqF2zxVI"></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Link Raw Material (Seed)</label>
                            <select name="seed_id" id="p_seed" class="form-control">
                                <option value="">-- No Link --</option>
                                <?php $seeds_list->data_seek(0);
                                while ($s = $seeds_list->fetch_assoc()) echo "<option value='{$s['id']}'>{$s['name']}</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>HSN Code</label><input type="text" name="hsn_code" id="p_hsn" class="form-control"></div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Cost Price (₹)</label><input type="number" step="0.01" name="cost_price" id="p_cost" class="form-control"></div>
                        <div class="form-group"><label>Selling Price (₹) *</label><input type="number" step="0.01" name="base_price" id="p_base" class="form-control" required></div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Tax Rate (%)</label><select name="tax_rate" id="p_tax" class="form-control">
                                <option value="0">0%</option>
                                <option value="5">5%</option>
                                <option value="12">12%</option>
                                <option value="18">18%</option>
                            </select></div>
                        <div class="form-group"><label>Min Stock Alert</label><input type="number" name="min_stock" id="p_min" class="form-control" value="10"></div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Net Weight/Volume</label><input type="number" step="0.01" name="weight" id="p_weight" class="form-control"></div>
                        <div class="form-group"><label>Unit</label>
                        <select name="unit" id="p_unit" class="form-control">
                                <option value="KG">Kg</option>
                                <option value="Liter">Liter (L)</option>
                                <option value="ml">ml</option>
                                <option value="PCS">Pcs</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; font-size:14px; cursor:pointer;">
                            <input type="checkbox" name="is_active" id="p_active" value="1" checked style="width:16px; height:16px;"> Active & Show in POS
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="save_product" class="btn btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('productModal');

        function openModal() {
            modal.style.display = 'flex';
            document.getElementById('p_id').value = '';
            document.forms[1].reset();
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        function editProduct(data) {
            openModal();
            document.getElementById('modalTitle').innerText = 'Edit Product';
            document.getElementById('p_id').value = data.id;
            document.getElementById('p_name').value = data.name;
            document.getElementById('p_desc').value = data.description || '';
            document.getElementById('p_type').value = data.product_type || 'oil';
            document.getElementById('p_seed').value = data.seed_id || '';
            document.getElementById('p_cost').value = data.cost_price;
            document.getElementById('p_base').value = data.base_price;
            document.getElementById('p_min').value = data.min_stock;

            // 🌟 FIX: Ye 4 lines miss ho gayi thi, inhe add kar diya gaya hai 🌟
            document.getElementById('p_weight').value = data.weight || '';
            document.getElementById('p_hsn').value = data.hsn_code || '';
            document.getElementById('p_tax').value = data.tax_rate || '0';
            document.getElementById('p_unit').value = data.unit || 'KG';

            document.getElementById('p_active').checked = (data.is_active == 1);
        }
        // ==========================================
        // 🌟 LIVE SEARCH & SORTING LOGIC 🌟
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('liveSearch');
            const sortDropdown = document.getElementById('sortDropdown');
            const tbody = document.querySelector('tbody');

            // 1. LIVE SEARCH FUNCTION
            searchInput.addEventListener('input', function() {
                let filter = this.value.toLowerCase();
                let rows = document.querySelectorAll('.product-row');

                rows.forEach(row => {
                    // Row ke andar ka saara text (Name, SKU, Price sab) check karega
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