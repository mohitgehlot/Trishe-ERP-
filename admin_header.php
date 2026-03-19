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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

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

            <li>
                <a href="index.php" class="nav-link <?= isChildActive('index.php') ?>">
                    <div class="nav-content">
                        <i class="fas fa-th-large icon"></i>
                        <span>Dashboard</span>
                    </div>
                </a>
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
            $rep_pages = ['expenses.php', 'admin_reports.php','financial_analytics.php'];
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
            $rep_pages = ['admin_users.php', 'daily_prices.php','print_builder.php','profile.php'];
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

    <div class="main-content-wrapper">

        <script>
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