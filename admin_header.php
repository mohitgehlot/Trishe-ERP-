<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);

// Function to check if a single page is active
function isChildActive($page)
{
    global $current_page;
    return $current_page === $page ? 'active-child' : '';
}

// Function to check if a Parent Menu should be open
function isParentOpen($pages_array)
{
    global $current_page;
    return in_array($current_page, $pages_array) ? 'open' : '';
}
$global_seeds = [];
$res_s = $conn->query("SELECT id, name, current_stock as available_quantity FROM seeds_master WHERE current_stock > 0 ORDER BY name");
if ($res_s) while ($row = $res_s->fetch_assoc()) $global_seeds[] = $row;

$global_machines = [];
$res_m = $conn->query("SELECT id, name, model FROM machines WHERE is_active = 1");
if ($res_m) while ($row = $res_m->fetch_assoc()) $global_machines[] = $row;
if (empty($global_machines)) $global_machines[] = ['id' => 1, 'name' => 'Expeller 1', 'model' => 'Default'];
$global_pack_list = [];
$res_pack_global = $conn->query("SELECT id, item_name as name FROM inventory_packaging ORDER BY item_name");
if ($res_pack_global) while ($row = $res_pack_global->fetch_assoc()) $global_pack_list[] = $row;
// Fetch Vendors for the Expense Form
$global_vendor_list = [];
$res_ven_global = $conn->query("SELECT id, name FROM sellers ORDER BY name ASC");
if ($res_ven_global) while ($row = $res_ven_global->fetch_assoc()) $global_vendor_list[] = $row;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="images/favicon-32x32.png">
    <style>
        /* ========================
           1. MODERN VARIABLES
           ======================== */
        :root {
            /* Image Design Colors */
            --sidebar-bg: #f4f4f5;
            /* Light Gray Background */
            --active-card-bg: #ffffff;
            /* White Active Item */
            --text-main: #18181b;
            /* Nearly Black */
            --text-muted: #71717a;
            /* Gray Text */
            --line-color: #d4d4d8;
            /* Tree Connector Lines */
            --accent-blue: #3b82f6;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);

            /* Badges */
            --badge-orange: #f97316;
            --badge-green: #10b981;
        }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
            display: flex;
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        /* ========================
           2. SIDEBAR STYLES
           ======================== */
        .sidebar {
            width: 260px;
            background-color: var(--sidebar-bg);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            border-right: 1px solid #e4e4e7;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        /* Logo Area */
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
            padding: 0 8px;
        }

        .brand-icon {
            width: 32px;
            height: 32px;
            background: var(--text-main);
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }

        .brand-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.5px;
        }

        /* Menu Items */
        .menu-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 20px 0 10px 12px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: 0.2s;
            position: relative;
        }

        .nav-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-link i.icon {
            width: 18px;
            text-align: center;
            font-size: 16px;
        }

        /* Hover State */
        .nav-link:hover {
            color: var(--text-main);
            background-color: rgba(0, 0, 0, 0.03);
        }

        /* Active Single Link (Dashboard) */
        .nav-link.active-child {
            background-color: var(--active-card-bg);
            color: var(--text-main);
            font-weight: 600;
            box-shadow: var(--shadow-sm);
        }

        /* Dropdown Arrow */
        .arrow {
            font-size: 10px;
            transition: transform 0.3s;
        }

        .nav-link.open .arrow {
            transform: rotate(180deg);
            color: var(--text-main);
        }

        .nav-link.open {
            color: var(--text-main);
        }

        /* ========================
           3. TREE VIEW SUBMENU
           ======================== */
        .submenu {
            display: none;
            flex-direction: column;
            padding-left: 21px;
            /* Align with parent icon center */
            margin-top: 5px;
            position: relative;
        }

        .submenu.show {
            display: flex;
        }

        /* Main Vertical Line */
        .submenu::before {
            content: '';
            position: absolute;
            left: 21px;
            top: 0;
            bottom: 18px;
            /* Stop before last item */
            width: 2px;
            background-color: var(--line-color);
        }

        .sub-item {
            position: relative;
            padding: 8px 12px 8px 16px;
            margin-left: 12px;
            /* Push right from line */
            font-size: 13px;
            color: var(--text-muted);
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: 0.2s;
        }

        /* Horizontal Connector Line (Curved) */
        .sub-item::before {
            content: '';
            position: absolute;
            left: -13px;
            /* Touch vertical line */
            top: -10px;
            /* Start from top */
            height: 28px;
            /* Go down to center of text */
            width: 12px;
            border-bottom: 2px solid var(--line-color);
            border-left: 2px solid var(--line-color);
            border-bottom-left-radius: 10px;
            /* Curve effect */
        }

        /* Fix first item line connection */
        .submenu .sub-item:first-child::before {
            height: 30px;
            top: -12px;
        }

        /* Active Sub Item (White Card Look) */
        .sub-item.active-child {
            background-color: var(--active-card-bg);
            color: var(--text-main);
            font-weight: 600;
            box-shadow: var(--shadow-sm);
            z-index: 2;
            /* Sit on top of lines */
        }

        .sub-item:hover {
            color: var(--text-main);
        }

        /* Badges */
        .badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            color: white;
        }

        .bg-orange {
            background: var(--badge-orange);
        }

        .bg-green {
            background: var(--badge-green);
        }

        /* ========================
           4. MOBILE RESPONSIVE
           ======================== */
        .mobile-header {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: white;
            z-index: 900;
            padding: 0 20px;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 950;
            display: none;
            backdrop-filter: blur(2px);
        }

        .overlay.show {
            display: block;
        }

        /* Main Content Pusher */
        .main-content-wrapper {
            margin-left: 260px;

            width: calc(100% - 260px);
            min-height: 100vh;
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .mobile-header {
                display: flex;
            }

            .main-content-wrapper {
                margin-left: 0;
                width: 100%;
                padding-top: 80px;
                padding-left: 0px;
            }
        }
    </style>
</head>

<body>

    <div class="mobile-header">
        <div class="brand" style="margin:0">
            <div class="brand-icon">TA</div>
            <span class="brand-name" style="margin-left:10px;">Trishe Agro</span>
        </div>
        <i class="fas fa-bars" style="font-size:20px; cursor:pointer;" onclick="toggleSidebar()"></i>
    </div>

    <div class="overlay" onclick="toggleSidebar()"></div>

    <aside class="sidebar" id="sidebar">

        <div class="brand">
            <div class="brand-icon">TA</div>
            <div class="brand-name">Trishe Agro</div>
        </div>

        <ul class="nav-list">

            <?php
            $inv_pages = ['index.php', 'sales_dashboard.php', 'account_dashboard.php'];
            $inv_open = isParentOpen($inv_pages);
            ?>
            <li>
                <div class="nav-link <?= $inv_open ?>" onclick="toggleMenu(this)">
                    <div class="nav-content">
                        <i class="fas fa-box icon"></i>
                        <span>Dashboard</span>
                    </div>
                    <i class="fas fa-chevron-down arrow"></i>
                </div>
                <ul class="submenu <?= $inv_open ? 'show' : '' ?>">
                    <li>
                        <a href="index.php" class="sub-item <?= isChildActive('index.php') ?>">
                            <span>Overview</span>
                        </a>
                    </li>
                    <li>
                        <a href="sales_dashboard.php" class="sub-item <?= isChildActive('sales_dashboard.php') ?>">
                            <span>Sales Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="account_dashboard.php" class="sub-item <?= isChildActive('account_dashboard.php') ?>">
                            <span>Account Dashboard</span>
                        </a>
                    </li>

                </ul>
            </li>

            <?php
            $inv_pages = ['inventory.php', 'admin_products.php', 'process_raw_material.php', 'packaging.php'];
            $inv_open = isParentOpen($inv_pages);
            ?>
            <li>
                <div class="nav-link <?= $inv_open ?>" onclick="toggleMenu(this)">
                    <div class="nav-content">
                        <i class="fas fa-box icon"></i>
                        <span>Inventory</span>
                    </div>
                    <i class="fas fa-chevron-down arrow"></i>
                </div>
                <ul class="submenu <?= $inv_open ? 'show' : '' ?>">
                    <li>
                        <a href="admin_products.php" class="sub-item <?= isChildActive('admin_products.php') ?>">
                            <span>Products List</span>
                        </a>
                    </li>
                    <li>
                        <a href="inventory.php" class="sub-item <?= isChildActive('inventory.php') ?>">
                            <span>Stock / GRN</span>
                            <span class="badge bg-orange">3</span> </a>
                    </li>
                    <li>
                        <a href="process_raw_material.php" class="sub-item <?= isChildActive('process_raw_material.php') ?>">
                            <span>Production</span>
                        </a>
                    </li>
                    <li>
                        <a href="packaging.php" class="sub-item <?= isChildActive('packaging.php') ?>">
                            <span>Packaging</span>
                            <span class="badge bg-green">8</span>
                        </a>
                    </li>
                </ul>
            </li>

            <?php
            $sale_pages = ['admin_customers.php', 'admin_orders.php', 'pos.php', 'costing.php', 'forecasting.php'];
            $sale_open = isParentOpen($sale_pages);
            ?>
            <li>
                <div class="nav-link <?= $sale_open ?>" onclick="toggleMenu(this)">
                    <div class="nav-content">
                        <i class="fas fa-users icon"></i>
                        <span>Sales & CRM</span>
                    </div>
                    <i class="fas fa-chevron-down arrow"></i>
                </div>
                <ul class="submenu <?= $sale_open ? 'show' : '' ?>">
                    <li>
                        <a href="admin_customers.php" class="sub-item <?= isChildActive('admin_customers.php') ?>">
                            <span>Customers</span>
                        </a>
                    </li>
                    <li>
                        <a href="admin_orders.php" class="sub-item <?= isChildActive('admin_orders.php') ?>">
                            <span>Orders</span>
                        </a>
                    </li>
                    <li>
                        <a href="pos.php" class="sub-item <?= isChildActive('pos.php') ?>">
                            <span>POS Billing</span>
                        </a>
                    </li>
                    <li>
                        <a href="costing.php" class="sub-item <?= isChildActive('costing.php') ?>">
                            <span>Costing</span>
                        </a>
                    </li>
                    <li>
                        <a href="forecasting.php" class="sub-item <?= isChildActive('forecasting.php') ?>">
                            <span>Product Recipes</span>
                        </a>
                    </li>
                </ul>
            </li>

            <?php
            $rep_pages = ['expenses.php', 'admin_reports.php', 'financial_analytics.php'];
            $rep_open = isParentOpen($rep_pages);
            ?>
            <li>
                <div class="nav-link <?= $rep_open ?>" onclick="toggleMenu(this)">
                    <div class="nav-content">
                        <i class="fas fa-chart-pie icon"></i>
                        <span>Finance</span>
                    </div>
                    <i class="fas fa-chevron-down arrow"></i>
                </div>
                <ul class="submenu <?= $rep_open ? 'show' : '' ?>">
                    <li>
                        <a href="expenses.php" class="sub-item <?= isChildActive('expenses.php') ?>">
                            <span>Expenses</span>
                        </a>
                    </li>
                    <li>
                        <a href="admin_reports.php" class="sub-item <?= isChildActive('admin_reports.php') ?>">
                            <span>Admin Reports</span>
                        </a>
                    </li>
                    <li>
                        <a href="financial_analytics.php" class="sub-item <?= isChildActive('financial_analytics.php') ?>">
                            <span>Financial Analytics</span>
                        </a>
                    </li>

                </ul>
            </li>

            <li>
                <a href="suppliers.php" class="nav-link <?= isChildActive('suppliers.php') ?>">
                    <div class="nav-content">
                        <i class="fas fa-truck icon"></i>
                        <span>Suppliers</span>
                    </div>
                </a>
            </li>
            <?php
            $rep_pages = ['admin_users.php', 'daily_prices.php', 'print_builder.php', 'profile.php'];
            $rep_open = isParentOpen($rep_pages);
            ?>
            <li>
                <div class="nav-link <?= $rep_open ?>" onclick="toggleMenu(this)">
                    <div class="nav-content">
                        <i class="fas fa-cogs icon"></i>
                        <span>Settings</span>
                    </div>
                    <i class="fas fa-chevron-down arrow"></i>
                </div>
                <ul class="submenu <?= $rep_open ? 'show' : '' ?>">
                    <li>
                        <a href="admin_users.php" class="sub-item <?= isChildActive('admin_users.php') ?>">
                            <span>Users</span>
                        </a>
                    </li>

                    <li>
                        <a href="daily_prices.php" class="sub-item <?= isChildActive('daily_prices.php') ?>">
                            <span>Daily Prices</span>
                        </a>
                    </li>
                    <li>
                        <a href="print_builder.php" class="sub-item <?= isChildActive('print_builder.php') ?>">
                            <span>Print Builder</span>
                        </a>
                    </li>
                    <li>
                        <a href="profile.php" class="sub-item <?= isChildActive('profile.php') ?>">
                            <span>Profile</span>
                        </a>
                    </li>
                </ul>
            </li>
            <li style="margin-top: 20px; border-top:1px solid #eee; padding-top:10px;">
                <a href="logout.php" class="nav-link" style="color: #ef4444;">
                    <div class="nav-content">
                        <i class="fas fa-sign-out-alt icon"></i>
                        <span>Logout</span>
                    </div>
                </a>
            </li>

        </ul>
    </aside>
    <div id="globalCreateModal" class="global-modal">
        <div class="g-modal-content">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.2rem; color:var(--text-main);">
                    <i class="fas fa-puzzle-piece text-primary" style="margin-right:8px;"></i> Define New Packing Size
                </h3>
                <span class="g-close-btn" onclick="closeGlobalCreateModal()">&times;</span>
            </div>
            <div class="g-modal-body">
                <form id="globalCreateProdForm">
                    <input type="hidden" name="action" value="create_new_product">

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label">1. Oil Type (Seed)</label>
                        <select name="n_seed" id="gc_seed" class="form-input" required>
                            <option value="">-- Select Oil --</option>
                            <?php foreach ($global_seeds as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= $s['name'] ?> Oil</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="background:#f8fafc; padding:20px; border-radius:8px; border:1px solid var(--border); margin-bottom:20px;">
                        <label style="color:var(--text-main); font-weight:700; margin-bottom:15px; display:block;">2. Single Item Specification</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                            <div class="form-group" style="margin:0;">
                                <label class="form-label">Base Size</label>
                                <input type="number" name="n_size" class="form-input" placeholder="1" required>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label">Unit</label>
                                <select name="n_unit" class="form-input">
                                    <option value="L">Litre</option>
                                    <option value="ml">ml</option>
                                    <option value="Kg">Kg</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label">Container Type</label>
                                <select name="n_type" class="form-input">
                                    <option value="Bottle">Bottle</option>
                                    <option value="Jar">Jar</option>
                                    <option value="Pouch">Pouch</option>
                                    <option value="Tin">Tin</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label">3. Select Empty Container Material</label>
                        <select name="n_packing_material" class="form-input" required>
                            <option value="">-- Select Container --</option>
                            <?php foreach ($global_pack_list as $pm): ?>
                                <option value="<?= $pm['id'] ?>"><?= $pm['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:20px; border-left:4px solid var(--warning); padding-left:15px; background:#fffbeb; padding:15px; border-radius:0 8px 8px 0;">
                        <label class="form-label" style="color:var(--warning);">4. Is this a Combo Pack?</label>
                        <div style="display:flex; align-items:center; gap:15px;">
                            <input type="number" name="n_multiplier" class="form-input" value="1" min="1" required style="width:120px;">
                            <span style="color:var(--text-muted); font-size:0.9rem; font-weight:600;">Items per pack (Change to 2 for "1+1 Combo")</span>
                        </div>
                    </div>

                    <button type="submit" id="gc_submitBtn" class="btn btn-primary" style="width:100%; padding:12px; font-size:1.1rem;">
                        <i class="fas fa-save"></i> Create & Save Product
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div id="globalStartModal" class="global-modal">
        <div class="g-modal-content" style="max-width:450px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.2rem; color:var(--text-main);">
                    <i class="fas fa-play-circle text-warning" style="margin-right:8px;"></i> Start New Batch
                </h3>
                <span class="g-close-btn" onclick="closeGlobalStartModal()">&times;</span>
            </div>
            <div class="g-modal-body">
                <form id="globalStartForm">
                    <input type="hidden" name="action" value="start_process">
                    <input type="hidden" name="start_process" value="1">

                    <div class="form-group" style="margin-bottom:15px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase;">Raw Material (Seed)</label>
                        <select id="global_seed_select" name="seed_id" style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; outline:none; background:#fff;" required>
                            <option value="">-- Choose Seed --</option>
                            <?php foreach ($global_seeds as $s): ?>
                                <option value="<?= $s['id'] ?>" data-stock="<?= $s['available_quantity'] ?>">
                                    <?= $s['name'] ?> (Stock: <?= number_format($s['available_quantity'], 2) ?> kg)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:15px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase;">Linked GRN (Optional)</label>
                        <select id="global_grn_select" name="linked_grn_no" style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; outline:none; background:#fff;">
                            <option value="">-- Auto / Any --</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:15px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase;">Select Machine</label>
                        <select name="machine_id" style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; outline:none; background:#fff;" required>
                            <?php foreach ($global_machines as $m): ?>
                                <option value="<?= $m['id'] ?>"><?= $m['name'] ?> (<?= $m['model'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase;">Input Quantity (Kg)</label>
                        <input type="number" id="global_input_qty" name="seed_qty" step="0.01" placeholder="Weight in Kg" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; outline:none;">
                        <small id="global_stock_error" style="color:var(--danger); display:none; font-weight:600; margin-top:8px;">⚠️ Insufficient Stock!</small>
                    </div>

                    <button type="submit" id="globalStartBtn" style="width:100%; margin-top:20px; padding:12px; background:var(--warning); color:#fff; border:none; border-radius:6px; font-weight:700; cursor:pointer; font-size:1rem; transition:0.2s;">
                        <i class="fas fa-power-off"></i> Start Machine
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div id="globalExpenseModal" class="global-modal">
        <div class="g-modal-content" style="max-width: 700px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.2rem; color:var(--text-main);">
                    <i class="fas fa-file-invoice-dollar text-primary" style="margin-right:8px;"></i> Add New Expense
                </h3>
                <span class="g-close-btn" onclick="closeGlobalExpenseModal()">&times;</span>
            </div>
            <div class="g-modal-body">
                <form id="globalExpenseForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_global_expense">

                    <h4 style="font-size:12px; text-transform:uppercase; color:var(--primary); margin-bottom:10px;">Basic Info</h4>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Expense Date</label>
                            <input type="date" name="date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-input">
                                <optgroup label="Production">
                                    <option>Raw Material</option>
                                    <option>Packing Material</option>
                                    <option>Labor</option>
                                    <option>Fuel (Diesel/Wood)</option>
                                </optgroup>
                                <optgroup label="Factory Overheads">
                                    <option>Electricity Bill</option>
                                    <option>Factory Rent</option>
                                    <option>Water Bill</option>
                                    <option>Maintenance & Repair</option>
                                </optgroup>
                                <optgroup label="Others">
                                    <option>Transport</option>
                                    <option>Salary (Staff)</option>
                                    <option>Tea/Snacks (Office)</option>
                                    <option>Other</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Vendor (Seller)</label>
                            <select name="vendor_id" class="form-input" required>
                                <option value="">Select Vendor</option>
                                <?php foreach ($global_vendor_list as $v): ?>
                                    <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Authorized By</label>
                            <input type="text" name="authorized_by" class="form-input" placeholder="Manager Name">
                        </div>
                    </div>

                    <h4 style="font-size:12px; text-transform:uppercase; color:var(--primary); margin:20px 0 10px;">Payment Details</h4>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Amount (₹)</label>
                            <input type="number" step="0.01" name="amount" id="g_exp_amount" class="form-input" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Invoice / Bill No</label>
                            <input type="text" name="invoice_no" class="form-input" placeholder="INV-001">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-input">
                                <option value="Pending">Pending</option>
                                <option value="Paid">Paid</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Due Date (If Pending)</label>
                            <input type="date" name="due_date" class="form-input">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Payment Mode</label>
                            <select name="payment_mode" class="form-input">
                                <option>Cash</option>
                                <option>UPI</option>
                                <option>Bank Transfer</option>
                                <option>Cheque</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Bill File (Img/PDF)</label>
                            <input type="file" name="bill_file" class="form-input" style="padding:7px;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label">Description / Note</label>
                        <input type="text" name="description" class="form-input">
                    </div>

                    <button type="submit" id="gc_exp_submit" class="btn btn-primary" style="width:100%; padding:12px; font-size:1.1rem;">
                        <i class="fas fa-save"></i> Save Expense
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="globalVendorModal" class="global-modal">
        <div class="g-modal-content" style="max-width: 400px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.2rem; color:var(--text-main);">
                    <i class="fas fa-user-plus text-primary" style="margin-right:8px;"></i> Add New Vendor
                </h3>
                <span class="g-close-btn" onclick="closeGlobalVendorModal()">&times;</span>
            </div>
            <div class="g-modal-body">
                <form id="globalVendorForm">
                    <input type="hidden" name="action" value="save_global_vendor">
                    <div class="form-group" style="margin-bottom:15px;">
                        <label class="form-label">Vendor Name</label>
                        <input type="text" name="new_vendor_name" id="g_vendor_name" class="form-input" required placeholder="e.g. Ramesh Traders">
                    </div>
                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label">Phone No (Optional)</label>
                        <input type="text" name="new_vendor_phone" class="form-input" placeholder="9876543210">
                    </div>
                    <button type="submit" id="gc_ven_submit" class="btn btn-primary" style="width:100%; padding:12px; font-size:1.1rem;">
                        <i class="fas fa-save"></i> Save Vendor
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="main-content-wrapper">

        <script>
            // --- EXPENSE & VENDOR SHORTCUT KEYS (Alt + E & Alt + V) ---
            document.addEventListener('keydown', function(e) {
                if (e.altKey && (e.key === 'e' || e.key === 'E')) {
                    e.preventDefault();
                    openGlobalExpenseModal();
                }
                if (e.altKey && (e.key === 'v' || e.key === 'V')) {
                    e.preventDefault();
                    openGlobalVendorModal();
                }
                if (e.key === "Escape") {
                    closeGlobalExpenseModal();
                    closeGlobalVendorModal();
                }
            });

            function openGlobalExpenseModal() {
                document.getElementById('globalExpenseModal').classList.add('active');
                document.getElementById('g_exp_amount').focus();
            }

            function closeGlobalExpenseModal() {
                document.getElementById('globalExpenseModal').classList.remove('active');
            }

            function openGlobalVendorModal() {
                document.getElementById('globalVendorModal').classList.add('active');
                document.getElementById('g_vendor_name').focus();
            }

            function closeGlobalVendorModal() {
                document.getElementById('globalVendorModal').classList.remove('active');
            }

            // Close on outside click
            window.addEventListener('click', function(e) {
                if (e.target == document.getElementById('globalExpenseModal')) closeGlobalExpenseModal();
                if (e.target == document.getElementById('globalVendorModal')) closeGlobalVendorModal();
            });

            // --- AJAX SUBMITS ---
            document.getElementById('globalExpenseForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = document.getElementById('gc_exp_submit');
                const ogText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                btn.disabled = true;

                fetch('expenses.php', {
                        method: 'POST',
                        body: new FormData(this)
                    })
                    .then(r => r.json()).then(res => {
                        if (res.success) {
                            alert("Expense Saved!");
                            window.location.href = 'expenses.php';
                        } else {
                            alert("Error: " + res.error);
                            btn.innerHTML = ogText;
                            btn.disabled = false;
                        }
                    }).catch(err => {
                        alert("Network Error");
                        btn.innerHTML = ogText;
                        btn.disabled = false;
                    });
            });

            document.getElementById('globalVendorForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = document.getElementById('gc_ven_submit');
                const ogText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                btn.disabled = true;

                fetch('expenses.php', {
                        method: 'POST',
                        body: new FormData(this)
                    })
                    .then(r => r.json()).then(res => {
                        if (res.success) {
                            alert("Vendor Saved!");
                            window.location.href = 'expenses.php';
                        } else {
                            alert("Error: " + res.error);
                            btn.innerHTML = ogText;
                            btn.disabled = false;
                        }
                    }).catch(err => {
                        alert("Network Error");
                        btn.innerHTML = ogText;
                        btn.disabled = false;
                    });
            });
            document.addEventListener('keydown', function(e) {
                // Alt + C dabane par "Create Size" modal khulega
                if (e.altKey && (e.key === 'c' || e.key === 'C')) {
                    e.preventDefault();
                    openGlobalCreateModal();
                }

                // Escape dabane par dono global modals band ho jayenge
                if (e.key === "Escape") {
                    closeGlobalCreateModal();
                    if (typeof closeGlobalStartModal === 'function') closeGlobalStartModal();
                }
            });

            function openGlobalCreateModal() {
                document.getElementById('globalCreateModal').classList.add('active');
                document.getElementById('gc_seed').focus(); // Focus on first field
            }

            function closeGlobalCreateModal() {
                document.getElementById('globalCreateModal').classList.remove('active');
            }

            // Modal ke bahar click karne par band karein
            window.addEventListener('click', function(e) {
                if (e.target == document.getElementById('globalCreateModal')) {
                    closeGlobalCreateModal();
                }
            });

            // --- FORM SUBMIT LOGIC FOR CREATE SIZE ---
            document.getElementById('globalCreateProdForm').addEventListener('submit', function(e) {
                e.preventDefault();

                const btn = document.getElementById('gc_submitBtn');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
                btn.disabled = true;

                // Data packaging.php ko bheja jayega (Kyunki wahan iska backend code hai)
                fetch('packaging.php', {
                        method: 'POST',
                        body: new FormData(this)
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            alert("New Packaging Size Created Successfully!");
                            // Success hone par user ko directly packaging.php par le jayenge
                            window.location.href = 'packaging.php';
                        } else {
                            alert("Error: " + res.error);
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        }
                    }).catch(err => {
                        alert("Network Error: Could not connect to server.");
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    });
            });
            // --- GLOBAL SHORTCUT KEY (Alt + B) ---
            document.addEventListener('keydown', function(e) {
                // Alt + B dabane par modal khulega
                if (e.altKey && (e.key === 'b' || e.key === 'B')) {
                    e.preventDefault();
                    openGlobalStartModal();
                }
                // Escape dabane par band hoga
                if (e.key === "Escape") {
                    closeGlobalStartModal();
                }
            });

            function openGlobalStartModal() {
                document.getElementById('globalStartModal').classList.add('active');
                document.getElementById('global_input_qty').focus(); // Khulte hi typing shuru
            }

            function closeGlobalStartModal() {
                document.getElementById('globalStartModal').classList.remove('active');
            }

            // Modal ke bahar click karne par band karein
            window.addEventListener('click', function(e) {
                if (e.target == document.getElementById('globalStartModal')) {
                    closeGlobalStartModal();
                }
            });

            // --- DYNAMIC STOCK & GRN LOGIC ---
            document.getElementById('global_input_qty').addEventListener('input', function() {
                const select = document.getElementById('global_seed_select');
                if (select.selectedIndex <= 0) return;

                const stk = parseFloat(select.selectedOptions[0].getAttribute('data-stock')) || 0;
                const val = parseFloat(this.value) || 0;

                if (val > stk) {
                    document.getElementById('global_stock_error').style.display = 'block';
                    document.getElementById('globalStartBtn').disabled = true;
                } else {
                    document.getElementById('global_stock_error').style.display = 'none';
                    document.getElementById('globalStartBtn').disabled = false;
                }
            });

            document.getElementById('global_seed_select').addEventListener('change', function() {
                const sid = this.value;
                const qtyInput = document.getElementById('global_input_qty');
                qtyInput.value = '';
                qtyInput.dispatchEvent(new Event('input'));

                const gSel = document.getElementById('global_grn_select');
                if (!sid) {
                    gSel.innerHTML = '<option value="">-- Auto / Any --</option>';
                    return;
                }

                gSel.innerHTML = '<option>Loading batches...</option>';
                const fd = new FormData();
                fd.append('action', 'get_grn_batches');
                fd.append('seed_id', sid);

                // Request directly to process_raw_material.php
                fetch('process_raw_material.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json()).then(d => {
                        let h = '<option value="">-- Auto / Any --</option>';
                        d.forEach(i => h += `<option value="${i.batch_no}">${i.display_text}</option>`);
                        gSel.innerHTML = h;
                    }).catch(err => {
                        gSel.innerHTML = '<option value="">-- Error loading / Auto --</option>';
                    });
            });

            // --- FORM SUBMIT LOGIC ---
            document.getElementById('globalStartForm').addEventListener('submit', function(e) {
                e.preventDefault();
                if (!confirm("Start machine processing?")) return;

                const btn = document.getElementById('globalStartBtn');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting...';
                btn.disabled = true;

                fetch('process_raw_material.php', {
                        method: 'POST',
                        body: new FormData(this)
                    })
                    .then(r => r.json()).then(res => {
                        if (res.success) {
                            alert("Batch Started Successfully!");
                            window.location.href = 'process_raw_material.php'; // Start hone par us page pe bhej dega
                        } else {
                            alert("Error: " + res.error);
                            btn.innerHTML = '<i class="fas fa-power-off"></i> Start Machine';
                            btn.disabled = false;
                        }
                    }).catch(err => {
                        alert("Network Error!");
                        btn.disabled = false;
                    });
            });

            function toggleMenu(element) {
                // Toggle 'open' class for arrow rotation
                element.classList.toggle('open');

                // Toggle submenu visibility
                const submenu = element.nextElementSibling;
                if (submenu.classList.contains('show')) {
                    submenu.classList.remove('show');
                } else {
                    submenu.classList.add('show');
                }
            }

            function toggleSidebar() {
                document.getElementById('sidebar').classList.toggle('show');
                document.querySelector('.overlay').classList.toggle('show');
            }
        </script>
</body>

</html>