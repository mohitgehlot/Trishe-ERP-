<?php
// admin_reports.php - MASTER REPORTS WITH GRAPHS (CHART.JS)
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// 1. DATE FILTER LOGIC
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$start_safe = $conn->real_escape_string($start_date . " 00:00:00");
$end_safe = $conn->real_escape_string($end_date . " 23:59:59");

// ==============================================
// 1. SALES REPORT DATA
// ==============================================
$sales_stats = $conn->query("
    SELECT 
        COUNT(id) as total_bills,
        COALESCE(SUM(total), 0) as total_revenue,
        COALESCE(SUM(tax), 0) as total_tax,
        COALESCE(SUM(discount), 0) as total_discount
    FROM orders 
    WHERE created_at BETWEEN '$start_safe' AND '$end_safe'
")->fetch_assoc();

// (A) For Line Chart (Daily Sales)
$daily_dates = [];
$daily_totals = [];
$daily_q = $conn->query("
    SELECT DATE(created_at) as sale_date, SUM(total) as daily_total
    FROM orders
    WHERE created_at BETWEEN '$start_safe' AND '$end_safe'
    GROUP BY DATE(created_at)
    ORDER BY sale_date ASC
");
if ($daily_q) {
    while ($r = $daily_q->fetch_assoc()) {
        $daily_dates[] = date('d M', strtotime($r['sale_date']));
        $daily_totals[] = (float)$r['daily_total'];
    }
}

// (B) For Doughnut Chart (Payment Modes)
$mode_labels = [];
$mode_totals = [];
$mode_q = $conn->query("SELECT payment_method, SUM(total) as amt FROM orders WHERE created_at BETWEEN '$start_safe' AND '$end_safe' GROUP BY payment_method");
if ($mode_q) {
    while ($r = $mode_q->fetch_assoc()) {
        $m = $r['payment_method'] ? $r['payment_method'] : 'Cash';
        $mode_labels[] = $m;
        $mode_totals[] = (float)$r['amt'];
    }
}

// Recent Sales Table
$sales_list = $conn->query("
    SELECT o.id, o.order_no, o.created_at, o.total, o.payment_method, c.name as cust_name 
    FROM orders o LEFT JOIN customers c ON o.customer_id = c.id 
    WHERE o.created_at BETWEEN '$start_safe' AND '$end_safe' 
    ORDER BY o.created_at DESC
");

// ==============================================
// 2. JOB WORK REPORT DATA
// ==============================================
$job_stats = $conn->query("
    SELECT 
        COUNT(id) as total_jobs,
        COALESCE(SUM(weight_kg), 0) as total_weight,
        COALESCE(SUM(total_amount), 0) as total_revenue
    FROM service_orders 
    WHERE service_date BETWEEN '$start_safe' AND '$end_safe'
")->fetch_assoc();

// (C) For Bar Chart (Jobs by Seed)
$seed_labels = [];
$seed_weights = [];
$seed_q = $conn->query("SELECT seed_type, SUM(weight_kg) as wt, SUM(total_amount) as amt FROM service_orders WHERE service_date BETWEEN '$start_safe' AND '$end_safe' GROUP BY seed_type");
if ($seed_q) {
    while ($r = $seed_q->fetch_assoc()) {
        $seed_labels[] = $r['seed_type'];
        $seed_weights[] = (float)$r['wt'];
    }
}

$job_list = $conn->query("
    SELECT s.id, s.service_date, s.seed_type, s.weight_kg, s.total_amount, s.payment_status, c.name as cust_name 
    FROM service_orders s LEFT JOIN customers c ON s.user_id = c.id 
    WHERE s.service_date BETWEEN '$start_safe' AND '$end_safe' 
    ORDER BY s.service_date DESC
");

// ==============================================
// 3. MARKET DUES (UDHAARI) & INVENTORY DATA
// ==============================================
$dues_list = $conn->query("SELECT id, name, phone, total_due, empty_tins FROM customers WHERE total_due > 0 ORDER BY total_due DESC");
$total_market_due = $conn->query("SELECT SUM(total_due) as td FROM customers")->fetch_assoc()['td'] ?? 0;

$stock_list = $conn->query("
    SELECT p.name, p.min_stock, p.unit, p.base_price,
           (SELECT COALESCE(SUM(qty), 0) FROM inventory_products ip WHERE ip.product_id = p.id) as current_stock
    FROM products p
    WHERE p.is_active = 1
    ORDER BY current_stock ASC
");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reports Dashboard | Trishe ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: #4f46e5; --bg: #f1f5f9; --card: #ffffff; --text: #0f172a; --border: #e2e8f0;
            --success: #10b981; --warning: #f59e0b; --danger: #ef4444;
            --radius: 10px; --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); padding-left: 260px; padding-bottom: 60px; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

        /* Header */
        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: var(--card); padding: 15px 20px; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); flex-wrap: wrap; gap: 15px; }
        .page-title { font-size: 1.3rem; font-weight: 700; display: flex; align-items: center; gap: 10px; margin: 0; }
        .date-filter { display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 8px; border-radius: 8px; border: 1px solid var(--border); flex-wrap: wrap; }
        .date-filter input { padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; }
        .btn { padding: 9px 15px; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .btn-excel { background: #16a34a; }
        .btn-print { background: #475569; }

        /* Tabs */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; }
        .tab-btn { padding: 12px 20px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 0.95rem; color: #64748b; white-space: nowrap; box-shadow: var(--shadow); transition: 0.2s; }
        .tab-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Summary Cards */
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .summary-card { background: var(--card); padding: 20px; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); }
        .summary-title { font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px; }
        .summary-value { font-size: 1.6rem; font-weight: 800; color: var(--text); }
        .summary-value.green { color: var(--success); }
        .summary-value.red { color: var(--danger); }

        /* Chart Containers */
        .chart-row { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow); }
        .chart-title { font-size: 1rem; font-weight: 700; margin-bottom: 15px; color: #334155; border-bottom: 1px dashed var(--border); padding-bottom: 10px; }
        .canvas-container { position: relative; height: 300px; width: 100%; }

        /* Tables */
        .table-card { background: var(--card); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); overflow: hidden; margin-bottom: 20px; }
        .table-header { padding: 15px 20px; border-bottom: 1px solid var(--border); background: #f8fafc; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
        .table-responsive { overflow-x: auto; max-height: 400px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left; }
        th { padding: 12px 15px; font-weight: 600; color: #475569; border-bottom: 1px solid var(--border); background: #fff; position: sticky; top: 0; z-index: 1; }
        td { padding: 12px 15px; border-bottom: 1px dashed var(--border); color: #334155; }
        tr:hover { background: #f8fafc; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }

        /* Global Modal Styles */
        .global-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .global-modal.active { display: flex; animation: fadeInModal 0.2s; }
        .g-modal-content { background: #fff; width: 90%; max-width: 600px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); overflow: hidden; position: relative; }
        .g-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .g-modal-body { padding: 20px; max-height: 70vh; overflow-y: auto; }
        .g-close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; line-height: 1; }
        @keyframes fadeInModal { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

        /* Mobile Adjustments */
        @media (max-width: 1024px) { body { padding-left: 0; } .chart-row { grid-template-columns: 1fr; } }
        @media print { body { padding: 0; } .header-bar, .tabs, .chart-card, .btn-excel, .btn-print { display: none !important; } .tab-content { display: block !important; } }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="header-bar">
            <h1 class="page-title"><i class="fas fa-chart-line text-primary"></i> Analytics & Reports</h1>
            <form method="GET" class="date-filter">
                <label style="font-size:0.85rem; font-weight:600; color:#64748b;">Date Range:</label>
                <input type="date" name="start_date" value="<?= $start_date ?>" required>
                <span>to</span>
                <input type="date" name="end_date" value="<?= $end_date ?>" required>
                <button type="submit" class="btn"><i class="fas fa-filter"></i> Generate</button>
            </form>
        </div>

        <div class="tabs">
            <button class="tab-btn active" onclick="openTab('sales')"><i class="fas fa-shopping-cart"></i> Sales Report</button>
            <button class="tab-btn" onclick="openTab('jobs')"><i class="fas fa-cogs"></i> Job Work Report</button>
            <button class="tab-btn" onclick="openTab('dues')"><i class="fas fa-book"></i> Market Dues</button>
            <button class="tab-btn" onclick="openTab('stock')"><i class="fas fa-boxes"></i> Inventory Status</button>
        </div>

        <div id="tab-sales" class="tab-content active">
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-title">Total Sales Revenue</div>
                    <div class="summary-value green">₹ <?= number_format($sales_stats['total_revenue'], 2) ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Total Bills</div>
                    <div class="summary-value"><?= number_format($sales_stats['total_bills']) ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Discount Given</div>
                    <div class="summary-value red">₹ <?= number_format($sales_stats['total_discount'], 2) ?></div>
                </div>
            </div>

            <div class="chart-row">
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-chart-area"></i> Daily Sales Trend</div>
                    <div class="canvas-container">
                        <canvas id="salesLineChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-wallet"></i> Collection by Mode</div>
                    <div class="canvas-container">
                        <canvas id="paymentPieChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <span>Sales Transaction List</span>
                    <div>
                        <button class="btn btn-excel btn-sm" onclick="exportTableToCSV('salesTable', 'Sales_Report.csv')"><i class="fas fa-file-excel"></i> Export</button>
                        <button class="btn btn-print btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="salesTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Order No</th>
                                <th>Customer Name</th>
                                <th>Pay Mode</th>
                                <th style="text-align:right;">Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($sales_list && $sales_list->num_rows > 0): while ($row = $sales_list->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= date('d M Y, h:i A', strtotime($row['created_at'])) ?></td>
                                        <td>
                                            <a href="#" onclick="viewOrderDetails(<?= $row['id'] ?>); return false;" style="color:#3b82f6; font-weight:bold; text-decoration:none;">
                                                #<?= $row['order_no'] ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($row['cust_name'] ?? 'Walk-in Customer') ?></td>
                                        <td><?= htmlspecialchars($row['payment_method'] ?? 'N/A') ?></td>
                                        <td style="text-align:right; font-weight:600; color:var(--success);">₹<?= number_format($row['total'], 2) ?></td>
                                    </tr>
                                <?php endwhile;
                            else: ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding:20px;">No sales found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: rgba(0,0,0,0.03); font-weight:bold;">
                                <td colspan="4" style="text-align:right;">Grand Total:</td>
                                <td style="text-align:right; color:var(--success);">₹<?= number_format($sales_stats['total_revenue'], 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-jobs" class="tab-content">
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-title">Total Job Revenue</div>
                    <div class="summary-value green">₹ <?= number_format($job_stats['total_revenue'], 2) ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Total Weight Processed</div>
                    <div class="summary-value"><?= number_format($job_stats['total_weight'], 2) ?> Kg</div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Total Jobs Done</div>
                    <div class="summary-value"><?= number_format($job_stats['total_jobs']) ?></div>
                </div>
            </div>

            <div class="chart-row" style="grid-template-columns: 1fr;">
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-seedling"></i> Seed Processing Volume (Kg)</div>
                    <div class="canvas-container">
                        <canvas id="jobsBarChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <span>Job Work History</span>
                    <button class="btn btn-excel btn-sm" onclick="exportTableToCSV('jobsTable', 'JobWork_Report.csv')"><i class="fas fa-file-excel"></i> Export</button>
                </div>
                <div class="table-responsive">
                    <table id="jobsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Job ID</th>
                                <th>Customer</th>
                                <th>Item</th>
                                <th>Weight (Kg)</th>
                                <th style="text-align:right;">Labour (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($job_list && $job_list->num_rows > 0): while ($row = $job_list->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($row['service_date'])) ?></td>
                                        <td><strong>#<?= $row['id'] ?></strong></td>
                                        <td><?= htmlspecialchars($row['cust_name'] ?? 'Unknown') ?></td>
                                        <td><?= htmlspecialchars($row['seed_type']) ?></td>
                                        <td><?= $row['weight_kg'] ?></td>
                                        <td style="text-align:right; font-weight:600;">₹<?= number_format($row['total_amount'], 2) ?></td>
                                    </tr>
                                <?php endwhile;
                            else: ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding:20px;">No jobs found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-dues" class="tab-content">
            <div class="summary-grid">
                <div class="summary-card" style="border-left: 5px solid var(--danger);">
                    <div class="summary-title">Total Market Outstanding (Udhaari)</div>
                    <div class="summary-value red">₹ <?= number_format($total_market_due, 2) ?></div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <span>Customer Dues List</span>
                    <button class="btn btn-excel btn-sm" onclick="exportTableToCSV('duesTable', 'MarketDues.csv')"><i class="fas fa-file-excel"></i> Export CSV</button>
                </div>
                <div class="table-responsive">
                    <table id="duesTable">
                        <thead>
                            <tr>
                                <th>Customer Name</th>
                                <th>Phone Number</th>
                                <th>Empty Tins</th>
                                <th style="text-align:right;">Outstanding Due (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($dues_list && $dues_list->num_rows > 0): while ($row = $dues_list->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['phone']) ?></td>
                                        <td><?= $row['empty_tins'] ?> Tins</td>
                                        <td style="text-align:right; font-weight:700; color:var(--danger);">₹<?= number_format($row['total_due'], 2) ?></td>
                                    </tr>
                                <?php endwhile;
                            else: ?>
                                <tr>
                                    <td colspan="4" style="text-align:center; padding:20px; color:var(--success);">All clear! No market dues.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-stock" class="tab-content">
            <div class="table-card">
                <div class="table-header">
                    <span>Current Godown Stock</span>
                    <button class="btn btn-excel btn-sm" onclick="exportTableToCSV('stockTable', 'Inventory.csv')"><i class="fas fa-file-excel"></i> Export CSV</button>
                </div>
                <div class="table-responsive">
                    <table id="stockTable">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Price (MRP)</th>
                                <th>Current Stock</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($stock_list && $stock_list->num_rows > 0): while ($row = $stock_list->fetch_assoc()):
                                    $stk = floatval($row['current_stock']);
                                    $min = floatval($row['min_stock']);
                                    $status = "<span style='color:green; font-weight:bold;'>In Stock</span>";
                                    if ($stk <= 0) $status = "<span style='color:red; font-weight:bold;'>Out of Stock</span>";
                                    elseif ($stk <= $min) $status = "<span style='color:orange; font-weight:bold;'>Low Stock</span>";
                            ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                                        <td>₹<?= number_format($row['base_price'], 2) ?> / <?= $row['unit'] ?></td>
                                        <td><strong><?= $stk ?></strong> <?= $row['unit'] ?></td>
                                        <td><?= $status ?></td>
                                    </tr>
                            <?php endwhile;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <div id="globalOrderModal" class="global-modal">
        <div class="g-modal-content">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.1rem; color:#0f172a;"><i class="fas fa-receipt text-primary"></i> Order Details</h3>
                <button class="g-close-btn" onclick="closeGlobalOrder()">&times;</button>
            </div>
            <div class="g-modal-body" id="globalOrderBody">
                <div style="text-align:center; padding:30px; color:#94a3b8;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading details...
                </div>
            </div>
            <div style="padding:15px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; text-align:right;">
                <button class="btn" style="width:auto; background:#64748b;" onclick="closeGlobalOrder()">Close</button>
            </div>
        </div>
    </div>

    <script>
        function openTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        function exportTableToCSV(tableID, filename) {
            var csv = [];
            var rows = document.querySelectorAll("#" + tableID + " tr");
            for (var i = 0; i < rows.length; i++) {
                var row = [],
                    cols = rows[i].querySelectorAll("td, th");
                for (var j = 0; j < cols.length; j++) {
                    var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/₹/g, "").trim();
                    row.push('"' + data.replace(/"/g, '""') + '"');
                }
                csv.push(row.join(","));
            }
            var link = document.createElement("a");
            link.download = filename;
            link.href = window.URL.createObjectURL(new Blob([csv.join("\n")], {
                type: "text/csv"
            }));
            link.style.display = "none";
            document.body.appendChild(link);
            link.click();
        }

        // --- GLOBAL ORDER VIEWER JS ---
        function viewOrderDetails(orderId) {
            const modal = document.getElementById('globalOrderModal');
            const body = document.getElementById('globalOrderBody');

            modal.classList.add('active');
            body.innerHTML = '<div style="text-align:center; padding:30px; color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading details...</div>';

            fetch(`ajax_order_details.php?id=${orderId}`)
                .then(response => response.text())
                .then(html => {
                    body.innerHTML = html; 
                })
                .catch(err => {
                    body.innerHTML = '<div style="color:red; text-align:center; padding:20px;">Failed to load order details.</div>';
                });
        }

        function closeGlobalOrder() {
            document.getElementById('globalOrderModal').classList.remove('active');
        }

        window.onclick = function(event) {
            const modal = document.getElementById('globalOrderModal');
            if (event.target == modal) {
                closeGlobalOrder();
            }
        }

        // --- CHART.JS INITIALIZATION ---
        window.onload = function() {
            // 1. Line Chart (Daily Sales)
            const ctxLine = document.getElementById('salesLineChart').getContext('2d');
            new Chart(ctxLine, {
                type: 'line',
                data: {
                    labels: <?= json_encode($daily_dates) ?>,
                    datasets: [{
                        label: 'Daily Sales (₹)',
                        data: <?= json_encode($daily_totals) ?>,
                        borderColor: '#4f46e5',
                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#4f46e5'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } }
                }
            });

            // 2. Doughnut Chart (Payment Modes)
            const ctxPie = document.getElementById('paymentPieChart').getContext('2d');
            new Chart(ctxPie, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($mode_labels) ?>,
                    datasets: [{
                        data: <?= json_encode($mode_totals) ?>,
                        backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%'
                }
            });

            // 3. Bar Chart (Jobs by Seed)
            const ctxBar = document.getElementById('jobsBarChart').getContext('2d');
            new Chart(ctxBar, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($seed_labels) ?>,
                    datasets: [{
                        label: 'Weight Processed (Kg)',
                        data: <?= json_encode($seed_weights) ?>,
                        backgroundColor: '#f59e0b',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        };
    </script>
</body>
</html>