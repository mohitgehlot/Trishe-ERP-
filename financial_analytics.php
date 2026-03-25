<?php
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit;
}

// Helper function
function formatCurrency($amount)
{
    return '₹' . number_format((float)$amount, 2);
}

// =======================================================
// 1. FETCH FINANCIAL DATA (LIFETIME OR FILTERED BY MONTH)
// =======================================================

// Default is Current Year, but you can add month filters later
$year = date('Y');

// A. REVENUE & COGS (From Sales)
// Sales (Revenue) aur laagat (COGS) nikalna
$sales_sql = "
    SELECT 
        COALESCE(SUM(oi.line_total), 0) as total_revenue,
        COALESCE(SUM(oi.qty * p.cost_price), 0) as total_cogs
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    WHERE o.status != 'Cancelled' AND YEAR(o.created_at) = '$year'
";
$sales_data = $conn->query($sales_sql)->fetch_assoc();
$total_revenue = $sales_data['total_revenue'];
$total_cogs = $sales_data['total_cogs'];

// B. GROSS PROFIT
$gross_profit = $total_revenue - $total_cogs;
$gross_margin = ($total_revenue > 0) ? ($gross_profit / $total_revenue) * 100 : 0;

// C. OPERATING EXPENSES (Opex) - Factory ka kharcha
$exp_sql = "
    SELECT 
        category,
        COALESCE(SUM(amount), 0) as total_amount
    FROM factory_expenses 
    WHERE entry_type = 'Expense' AND YEAR(date) = '$year'
    GROUP BY category
    ORDER BY total_amount DESC
";
$exp_res = $conn->query($exp_sql);
$expenses_breakdown = [];
$total_opex = 0;

if ($exp_res) {
    while ($row = $exp_res->fetch_assoc()) {
        $expenses_breakdown[] = $row;
        $total_opex += $row['total_amount'];
    }
}

// D. NET PROFIT (EBITDA Baseline)
$net_profit = $gross_profit - $total_opex;
$net_margin = ($total_revenue > 0) ? ($net_profit / $total_revenue) * 100 : 0;

// E. FUNDS ADDED (Capital Inflow)
$funds_sql = "SELECT COALESCE(SUM(amount), 0) as total_funds FROM factory_expenses WHERE entry_type = 'Income' AND YEAR(date) = '$year'";
$total_funds = $conn->query($funds_sql)->fetch_assoc()['total_funds'];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Financial Analytics | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 25px;
            overflow-x: hidden;
        }

        .page-header-box {
            background: #fff;
            padding: 20px 25px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Financial Metric Cards */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        /* Top Colored Borders */
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }

        .metric-card.revenue::before {
            background: #3b82f6;
        }

        .metric-card.cogs::before {
            background: var(--warning);
        }

        .metric-card.gross::before {
            background: #8b5cf6;
        }

        .metric-card.opex::before {
            background: var(--danger);
        }

        .metric-card.net::before {
            background: var(--success);
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
        }

        /* Split Grid for Tables & Charts */
        .split-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
            align-items: start;
        }

        /* P&L Table Styling */
        .pl-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pl-table td {
            padding: 15px 10px;
            border-bottom: 1px dashed var(--border);
            font-size: 1rem;
            color: var(--text-main);
        }

        .pl-table td.val {
            text-align: right;
            font-weight: 700;
            font-family: monospace;
            font-size: 1.15rem;
        }

        .pl-table tr.total-row td {
            border-top: 2px solid var(--border);
            border-bottom: none;
            font-weight: 800;
            font-size: 1.15rem;
            padding-top: 15px;
        }

        .indent {
            padding-left: 30px !important;
            color: var(--text-muted) !important;
            font-size: 0.95rem !important;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .split-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
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

            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .metric-card {
                padding: 15px;
            }

            .metric-value {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }

            .pl-table td {
                font-size: 0.9rem;
                padding: 12px 5px;
            }

            .pl-table td.val {
                font-size: 1rem;
            }

            .indent {
                padding-left: 15px !important;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-chart-pie text-primary"></i> Business Analytics (P&L)</h1>
            <div style="background: #e0e7ff; color: #3730a3; padding: 8px 20px; border-radius: 20px; font-weight: 800; border: 1px solid #c7d2fe; letter-spacing: 0.5px;">
                FY <?= $year ?>
            </div>
        </div>

        <div class="metrics-grid">
            <div class="metric-card revenue">
                <div class="metric-title">Total Revenue (Sales)</div>
                <div class="metric-value"><?= formatCurrency($total_revenue) ?></div>
                <div class="metric-sub" style="color: #3b82f6;">Income from Operations</div>
            </div>

            <div class="metric-card cogs">
                <div class="metric-title">Cost of Goods (COGS)</div>
                <div class="metric-value"><?= formatCurrency($total_cogs) ?></div>
                <div class="metric-sub" style="color: var(--warning);">Seed + Packaging Cost</div>
            </div>

            <div class="metric-card gross">
                <div class="metric-title">Gross Profit</div>
                <div class="metric-value"><?= formatCurrency($gross_profit) ?></div>
                <div class="metric-sub" style="color: #8b5cf6;"><?= number_format($gross_margin, 1) ?>% Gross Margin</div>
            </div>

            <div class="metric-card opex">
                <div class="metric-title">Operating Expenses</div>
                <div class="metric-value"><?= formatCurrency($total_opex) ?></div>
                <div class="metric-sub" style="color: var(--danger);">Factory & Office Overheads</div>
            </div>

            <div class="metric-card net" style="background: #f0fdf4; border-color: #bbf7d0;">
                <div class="metric-title" style="color: #166534;">Net Profit (EBITDA)</div>
                <div class="metric-value" style="color: #15803d;"><?= formatCurrency($net_profit) ?></div>
                <div class="metric-sub badge <?= $net_profit >= 0 ? 'bg-success' : 'bg-danger' ?>" style="display:inline-block; margin-top:10px; color:white; padding:4px 10px;">
                    <?= number_format($net_margin, 1) ?>% Net Margin
                </div>
            </div>
        </div>

        <div class="split-grid">

            <div class="card">
                <div class="card-header" style="font-size: 1.1rem; font-weight: 800; border-bottom: 2px solid var(--border); padding-bottom: 15px; margin-bottom: 15px; color: var(--text-main);">
                    Profit & Loss Statement (Summary)
                </div>
                <div style="padding: 10px;">
                    <table class="pl-table">
                        <tr>
                            <td style="font-weight: 800; color: #3b82f6;">Total Revenue (A)</td>
                            <td class="val" style="color: #3b82f6;"><?= formatCurrency($total_revenue) ?></td>
                        </tr>

                        <tr>
                            <td colspan="2" style="padding-top:25px; font-weight:800; color:var(--text-main);">Less: Direct Costs</td>
                        </tr>
                        <tr>
                            <td class="indent">Cost of Materials (Seeds, Packing)</td>
                            <td class="val" style="color: var(--danger);">- <?= formatCurrency($total_cogs) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td style="color: #8b5cf6;">Gross Profit (B)</td>
                            <td class="val" style="color: #8b5cf6;"><?= formatCurrency($gross_profit) ?></td>
                        </tr>

                        <tr>
                            <td colspan="2" style="padding-top:25px; font-weight:800; color:var(--text-main);">Less: Operating Expenses (Opex)</td>
                        </tr>
                        <?php if (empty($expenses_breakdown)): ?>
                            <tr>
                                <td class="indent" colspan="2" style="text-align:center; padding:20px;">No expenses recorded yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expenses_breakdown as $exp): ?>
                                <tr>
                                    <td class="indent"><?= htmlspecialchars($exp['category']) ?></td>
                                    <td class="val" style="color: var(--danger);">- <?= formatCurrency($exp['total_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <tr>
                            <td style="font-weight: 800; padding-top: 15px; color:var(--text-main);">Total Opex (C)</td>
                            <td class="val" style="color: var(--danger); padding-top: 15px;">- <?= formatCurrency($total_opex) ?></td>
                        </tr>

                        <tr class="total-row" style="background: #f8fafc;">
                            <td style="color: #15803d; font-size: 1.2rem; padding: 20px 10px;">NET PROFIT (B - C)</td>
                            <td class="val" style="color: #15803d; font-size: 1.4rem; padding: 20px 10px;"><?= formatCurrency($net_profit) ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div>
                <div class="card" style="margin-bottom: 24px;">
                    <div class="card-header" style="font-size: 1rem; font-weight: 700; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 15px;">Expense Breakdown</div>
                    <div id="expenseChart" style="min-height: 250px; padding: 10px;"></div>
                </div>

                <div class="card" style="background: #fffbeb; border: 1px solid #fde68a;">
                    <div class="card-header" style="border-bottom: 1px solid #fcd34d; color: #b45309; padding-bottom: 15px; margin-bottom: 15px; font-weight:700;">
                        <i class="fas fa-piggy-bank"></i> Capital & Funds
                    </div>
                    <div style="text-align: center; padding: 20px 0;">
                        <p style="color: #b45309; font-weight: 700; margin-bottom: 8px; text-transform:uppercase; font-size:0.85rem; letter-spacing:0.5px;">Total Owner Funds / Capital Added</p>
                        <h2 style="color: #92400e; font-size: 2.2rem; font-weight:800; margin: 0;"><?= formatCurrency($total_funds) ?></h2>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        // Prepare Data for Expense Donut Chart
        const expLabels = <?= json_encode(array_column($expenses_breakdown, 'category')) ?>;
        const expData = <?= json_encode(array_column($expenses_breakdown, 'total_amount')) ?>;

        if (expData.length > 0) {
            var options = {
                series: expData.map(Number),
                labels: expLabels,
                chart: {
                    type: 'donut',
                    height: 300,
                    fontFamily: 'Inter, sans-serif'
                },
                colors: ['#ef4444', '#f59e0b', '#3b82f6', '#8b5cf6', '#10b981', '#64748b'],
                plotOptions: {
                    pie: {
                        donut: {
                            size: '65%'
                        }
                    }
                },
                dataLabels: {
                    enabled: false
                },
                legend: {
                    position: 'bottom',
                    fontSize: '13px',
                    fontFamily: 'Inter, sans-serif',
                    fontWeight: 500,
                    markers: {
                        radius: 12
                    }
                },
                tooltip: {
                    y: {
                        formatter: function(val) {
                            return "₹" + val.toLocaleString("en-IN");
                        }
                    }
                }
            };
            var chart = new ApexCharts(document.querySelector("#expenseChart"), options);
            chart.render();
        } else {
            document.querySelector("#expenseChart").innerHTML = "<div style='text-align:center; color:var(--text-muted); padding:50px 20px; font-weight:500;'><i class='fas fa-chart-pie fa-2x' style='opacity:0.3; margin-bottom:10px;'></i><br>No expense data to display chart.</div>";
        }
    </script>
</body>

</html>