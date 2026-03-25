<?php
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

$today = date('Y-m-d');

// ==========================================
// 1. TODAY'S POS & SALES STATS
// ==========================================
$stat_sql = "
    SELECT 
        COUNT(id) as total_bills,
        COALESCE(SUM(total), 0) as today_sales,
        COALESCE(SUM(CASE WHEN payment_method = 'Cash' THEN total ELSE 0 END), 0) as cash_sales,
        COALESCE(SUM(CASE WHEN payment_method IN ('UPI', 'Online', 'Card', 'upi') THEN total ELSE 0 END), 0) as online_sales
    FROM orders 
    WHERE DATE(created_at) = '$today' AND status != 'Cancelled'
";
$stat_res = $conn->query($stat_sql)->fetch_assoc();

$total_bills = intval($stat_res['total_bills']);
$today_sales = floatval($stat_res['today_sales']);
$cash_sales = floatval($stat_res['cash_sales']);
$online_sales = floatval($stat_res['online_sales']);
$avg_bill = ($total_bills > 0) ? ($today_sales / $total_bills) : 0;

// ==========================================
// 2. LAST 7 DAYS TREND (For Area Chart)
// ==========================================
$chart_sql = "
    SELECT DATE(created_at) as sale_date, COALESCE(SUM(total), 0) as daily_total 
    FROM orders 
    WHERE created_at >= DATE(NOW()) - INTERVAL 7 DAY AND status != 'Cancelled'
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at) ASC
";
$chart_res = $conn->query($chart_sql);
$dates = [];
$sales = [];
if ($chart_res) {
    while ($row = $chart_res->fetch_assoc()) {
        $dates[] = date('d M', strtotime($row['sale_date']));
        $sales[] = floatval($row['daily_total']);
    }
}

// ==========================================
// 3. LOW STOCK / OUT OF STOCK ALERTS
// ==========================================
$alerts = [];
// A. Check Raw Material (Seeds) < 50 Kg
$seed_alert = $conn->query("SELECT name, current_stock FROM seeds_master WHERE current_stock <= 50");
if ($seed_alert) {
    while ($s = $seed_alert->fetch_assoc()) {
        $alerts[] = ['type' => 'Raw Material', 'item' => $s['name'], 'stock' => $s['current_stock'] . ' Kg', 'critical' => ($s['current_stock'] <= 10)];
    }
}
// B. Check Packaging Material < 100 Pcs
$pack_alert = $conn->query("SELECT item_name, quantity FROM inventory_packaging WHERE quantity <= 100");
if ($pack_alert) {
    while ($p = $pack_alert->fetch_assoc()) {
        $alerts[] = ['type' => 'Packaging', 'item' => $p['item_name'], 'stock' => $p['quantity'] . ' Pcs', 'critical' => ($p['quantity'] <= 20)];
    }
}

// ==========================================
// 4. TOP SELLING PRODUCTS (Last 30 Days)
// ==========================================
$top_items_sql = "
    SELECT 
        p.name as product_name, 
        COALESCE(SUM(oi.qty), 0) as total_qty,
        COALESCE(SUM(oi.line_total), 0) as total_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    WHERE o.status != 'Cancelled' AND o.created_at >= DATE(NOW()) - INTERVAL 30 DAY
    GROUP BY p.id
    ORDER BY total_qty DESC
    LIMIT 6
";
$top_items = [];
$res_top = $conn->query($top_items_sql);
if ($res_top) {
    while ($r = $res_top->fetch_assoc()) {
        $top_items[] = $r;
    }
}

// ==========================================
// 5. RECENT TRANSACTIONS (Mixed Online/POS)
// ==========================================
$recent_sql = "
    SELECT 
        o.id, o.order_no, o.total, o.payment_method, o.created_at, o.notes, 
        c.name as customer_name 
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    ORDER BY o.id DESC LIMIT 15
";
$recent_orders = [];
$res_rec = $conn->query($recent_sql);
if ($res_rec) {
    while ($r = $res_rec->fetch_assoc()) {
        $recent_orders[] = $r;
    }
}

function formatCurr($amt)
{
    return '₹' . number_format($amt, 2);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Sales & POS Analytics | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 25px;
        }

        @media (max-width: 1024px) {
            .container {
                padding: 15px;
            }
        }

        /* KPI Cards */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .metric-card {
            background: var(--bg-card);
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
        }

        .metric-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.2;
        }

        .metric-sub {
            font-size: 0.85rem;
            margin-top: 8px;
            font-weight: 600;
            color: var(--text-muted);
        }

        /* Alert Strip */
        .alert-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 25px;
        }

        .alert-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            border-left: 4px solid;
            background: white;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .alert-critical {
            border-left-color: var(--danger);
        }

        .alert-warning {
            border-left-color: var(--warning);
        }

        .alert-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 800;
            color: white;
        }

        /* 50-50 Split Layout */
        .split-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        @media (max-width: 900px) {
            .split-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Custom Scrollable Lists */
        .scroll-list {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .scroll-list::-webkit-scrollbar {
            width: 6px;
        }

        .scroll-list::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 8px;
        }

        .scroll-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 8px;
        }

        /* List Items Styling */
        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px dashed var(--border);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .item-title {
            font-weight: 700;
            color: var(--text-main);
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .item-sub {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .item-value {
            font-weight: 800;
            color: var(--success);
            font-size: 1.05rem;
            text-align: right;
        }

        .badge-platform {
            background: #fdf4ff;
            color: #a21caf;
            border: 1px solid #f5d0fe;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-left: 8px;
        }

        .badge-pm {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
        }

        .badge-pm.upi {
            background: #e0f2fe;
            color: #0284c7;
        }

        .badge-pm.cash {
            background: #fef3c7;
            color: #b45309;
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="page-header" style="background:transparent; border:none; box-shadow:none; padding:0; margin-bottom: 25px;">
            <h1 class="page-title"><i class="fas fa-chart-line text-primary"></i> Sales Overview</h1>
            <a href="pos.php" class="btn btn-primary"><i class="fas fa-plus"></i> New POS Sale</a>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-title"><i class="fas fa-wallet text-primary"></i> Total Revenue Today</div>
                <div class="metric-value"><?= formatCurr($today_sales) ?></div>
                <div class="metric-sub"><?= $total_bills ?> Invoices Generated</div>
            </div>

            <div class="metric-card">
                <div class="metric-title"><i class="fas fa-chart-pie text-info"></i> Average Order Value</div>
                <div class="metric-value"><?= formatCurr($avg_bill) ?></div>
                <div class="metric-sub">Per Customer Spend</div>
            </div>

            <div class="metric-card">
                <div class="metric-title"><i class="fas fa-qrcode text-success"></i> Digital Payments</div>
                <div class="metric-value" style="color: var(--success);"><?= formatCurr($online_sales) ?></div>
                <div class="metric-sub">UPI / Card / Online</div>
            </div>

            <div class="metric-card">
                <div class="metric-title"><i class="fas fa-money-bill-wave text-warning"></i> Cash in Drawer</div>
                <div class="metric-value" style="color: var(--warning);"><?= formatCurr($cash_sales) ?></div>
                <div class="metric-sub">Physical Cash Collected</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div><i class="fas fa-chart-area text-primary"></i> Daily Sales Trend (Last 7 Days)</div>
            </div>
            <div style="padding: 20px;">
                <div id="mainChart" style="min-height: 320px;"></div>
            </div>
        </div>

        <?php if (!empty($alerts)): ?>
            <h3 style="font-size: 1.1rem; color: var(--text-main); margin-bottom: 15px;"><i class="fas fa-exclamation-triangle text-danger"></i> Low Stock Alerts (Action Required)</h3>
            <div class="alert-container">
                <?php foreach ($alerts as $al): ?>
                    <div class="alert-strip <?= $al['critical'] ? 'alert-critical' : 'alert-warning' ?>">
                        <div>
                            <strong style="color: var(--text-main);"><?= htmlspecialchars($al['item']) ?></strong>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500; margin-left: 10px;">[<?= $al['type'] ?>]</span>
                        </div>
                        <div class="alert-badge" style="background: <?= $al['critical'] ? 'var(--danger)' : 'var(--warning)' ?>;">
                            <?= $al['stock'] ?> Left
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="split-layout">

            <div class="card" style="margin-bottom: 0;">
                <div class="card-header">
                    <div><i class="fas fa-box-open text-success"></i> Top Selling Products</div>
                </div>
                <div style="padding: 20px;">
                    <div class="scroll-list">
                        <?php if (empty($top_items)): ?>
                            <div style="text-align:center; color:var(--text-muted); padding:30px;">No sales data available.</div>
                        <?php else: ?>
                            <?php foreach ($top_items as $item): ?>
                                <div class="list-item">
                                    <div>
                                        <div class="item-title"><?= htmlspecialchars($item['product_name']) ?></div>
                                        <div class="item-sub"><?= $item['total_qty'] ?> Units Sold</div>
                                    </div>
                                    <div class="item-value">
                                        <?= formatCurr($item['total_revenue']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-bottom: 0;">
                <div class="card-header">
                    <div><i class="fas fa-receipt text-primary"></i> Recent Transactions</div>
                </div>
                <div style="padding: 20px;">
                    <div class="scroll-list">
                        <?php if (empty($recent_orders)): ?>
                            <div style="text-align:center; color:var(--text-muted); padding:30px;">No transactions yet.</div>
                        <?php else: ?>
                            <?php foreach ($recent_orders as $order): ?>
                                <div class="list-item">
                                    <div>
                                        <div class="item-title">
                                            <?= $order['order_no'] ?>
                                            <?php if (!empty(trim($order['notes']))): ?>
                                                <span class="badge-platform"><i class="fas fa-shopping-bag"></i> <?= htmlspecialchars($order['notes']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="item-sub">
                                            <?= htmlspecialchars($order['customer_name'] ?: 'Walk-in') ?> •
                                            <?= date('d M, h:i A', strtotime($order['created_at'])) ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div class="item-value" style="color: var(--text-main);"><?= formatCurr($order['total']) ?></div>
                                        <?php $pm_class = (strtolower($order['payment_method']) == 'cash') ? 'cash' : 'upi'; ?>
                                        <span class="badge-pm <?= $pm_class ?>"><?= $order['payment_method'] ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <script>
        // RENDER AREA CHART
        const dates = <?= json_encode($dates) ?>;
        const sales = <?= json_encode($sales) ?>;

        if (sales.length > 0) {
            var options = {
                series: [{
                    name: 'Sales (₹)',
                    data: sales
                }],
                chart: {
                    type: 'area',
                    height: 320,
                    toolbar: {
                        show: false
                    },
                    fontFamily: 'Inter, sans-serif'
                },
                colors: ['#4f46e5'],
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.35,
                        opacityTo: 0.0,
                        stops: [0, 90, 100]
                    }
                },
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    curve: 'smooth',
                    width: 3
                },
                xaxis: {
                    categories: dates,
                    tooltip: {
                        enabled: false
                    },
                    axisBorder: {
                        show: false
                    },
                    axisTicks: {
                        show: false
                    }
                },
                yaxis: {
                    labels: {
                        formatter: (value) => {
                            return "₹" + value.toLocaleString("en-IN");
                        }
                    }
                },
                grid: {
                    borderColor: '#e2e8f0',
                    strokeDashArray: 4,
                    yaxis: {
                        lines: {
                            show: true
                        }
                    }
                },
                tooltip: {
                    theme: 'light',
                    y: {
                        formatter: function(val) {
                            return "₹" + val.toLocaleString("en-IN");
                        }
                    }
                }
            };

            var chart = new ApexCharts(document.querySelector("#mainChart"), options);
            chart.render();
        } else {
            document.querySelector("#mainChart").innerHTML = "<div style='text-align:center; padding:80px 20px; color:#94a3b8;'>Not enough data to display sales trend.</div>";
        }
    </script>
</body>

</html>