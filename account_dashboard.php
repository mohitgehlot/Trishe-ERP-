<?php
include 'config.php';
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit;
}

// ==========================================
// 1. DUMMY / BASIC DATA FETCHING (Replace with actual tables)
// ==========================================
// A. Customers & Vendors Count
$total_clients = $conn->query("SELECT COUNT(id) as count FROM customers")->fetch_assoc()['count'] ?? 0;
// Note: Agar vendors ki table nahi hai, toh banani padegi
$total_vendors = 15; // Example Placeholder

// B. Payments (Monthly / Lifetime)
$customer_payments = $conn->query("SELECT COALESCE(SUM(total), 0) as total FROM orders WHERE payment_status = 'Paid'")->fetch_assoc()['total'] ?? 0;
$vendor_payments = 15858; // Example Placeholder (Purchase orders se aayega)

// C. Advanced Metrics (Logic Setup)
$marketing_expense = 5000; // Example: Fetch from factory_expenses WHERE category='Marketing'
$new_customers_this_month = 20; // Example
$cac = ($new_customers_this_month > 0) ? ($marketing_expense / $new_customers_this_month) : 0;
$crr = 45.5; // Example 45.5% repeat customers

function formatCurr($amt)
{
    return '₹' . number_format($amt, 2);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Account Dashboard | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <link rel="stylesheet" href="/css/admin_styles.css">

    <style>
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 25px;
        }

        /* 1. TOP KPI CARDS (Dashboard Specific) */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .kpi-card {
            padding: 20px;
            border-radius: var(--radius);
            border: 1px solid;
            display: flex;
            flex-direction: column;
            position: relative;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .kpi-card.clients {
            background: #fff7ed;
            border-color: #ffedd5;
            color: #c2410c;
        }

        .kpi-card.vendors {
            background: #f0fdf4;
            border-color: #dcfce7;
            color: #047857;
        }

        .kpi-card.cust-pay {
            background: #ecfdf5;
            border-color: #d1fae5;
            color: #059669;
        }

        .kpi-card.vend-pay {
            background: #fff1f2;
            border-color: #ffe4e6;
            color: #e11d48;
        }

        .kpi-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .kpi-val {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .kpi-sub {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .kpi-icon {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 1.5rem;
            opacity: 0.7;
        }

        /* 2. CHARTS GRID */
        .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .chart-grid.three-col {
            grid-template-columns: repeat(3, 1fr);
        }

        /* 3. RECENT LISTS (For Future Use) */
        .list-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .list-item {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px dashed var(--border);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .item-desc {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 5px;
        }

        /* --- MOBILE RESPONSIVENESS --- */
        @media (max-width: 1024px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }

            .chart-grid.three-col {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            .container {
                padding: 15px;
            }

            .kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>
    <div class="container">
        <div class="page-header" style="background:transparent; border:none; box-shadow:none; padding:0; margin-bottom: 20px;">
            <h1 class="page-title"><i class="fas fa-file-invoice-dollar text-primary"></i> Account Dashboard</h1>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card clients">
                <div class="kpi-title">Total Clients</div>
                <div class="kpi-val"><?= $total_clients ?></div>
                <div class="kpi-sub">Active clients</div>
                <i class="fas fa-user-check kpi-icon"></i>
            </div>
            <div class="kpi-card vendors">
                <div class="kpi-title">Total Vendors</div>
                <div class="kpi-val"><?= $total_vendors ?></div>
                <div class="kpi-sub">Active raw material suppliers</div>
                <i class="fas fa-truck kpi-icon"></i>
            </div>
            <div class="kpi-card cust-pay">
                <div class="kpi-title">Total Customer Payment</div>
                <div class="kpi-val"><?= formatCurr($customer_payments) ?></div>
                <div class="kpi-sub">Received payments</div>
                <i class="fas fa-arrow-circle-down kpi-icon"></i>
            </div>
            <div class="kpi-card vend-pay">
                <div class="kpi-title">Total Vendor Payment</div>
                <div class="kpi-val"><?= formatCurr($vendor_payments) ?></div>
                <div class="kpi-sub">Paid to vendors/farmers</div>
                <i class="fas fa-arrow-circle-up kpi-icon"></i>
            </div>
        </div>

        <div class="chart-grid">
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header">Monthly Customer Payments (Inflow)</div>
                <div style="padding: 20px;">
                    <div id="custChart" style="height: 250px;"></div>
                </div>
            </div>
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header">Monthly Vendor Payments (Outflow)</div>
                <div style="padding: 20px;">
                    <div id="vendChart" style="height: 250px;"></div>
                </div>
            </div>
        </div>

        <div class="chart-grid three-col">
            <div class="card" style="border-left: 4px solid #3b82f6; padding: 20px; margin-bottom: 0;">
                <div style="font-size: 0.95rem; color: #64748b; font-weight: 600; margin-bottom: 5px;">Customer Acquisition Cost (CAC)</div>
                <div style="font-size: 1.8rem; font-weight: 700; color: #0f172a;"><?= formatCurr($cac) ?></div>
                <div style="font-size: 0.8rem; color: #64748b; margin-top: 5px;">Marketing Spend ÷ New Customers</div>
            </div>
            <div class="card" style="border-left: 4px solid #10b981; padding: 20px; margin-bottom: 0;">
                <div style="font-size: 0.95rem; color: #64748b; font-weight: 600; margin-bottom: 5px;">Customer Retention Rate (CRR)</div>
                <div style="font-size: 1.8rem; font-weight: 700; color: #0f172a;"><?= $crr ?>%</div>
                <div style="font-size: 0.8rem; color: #64748b; margin-top: 5px;">Customers returning for repeat purchase</div>
            </div>
            <div class="card" style="border-left: 4px solid #8b5cf6; padding: 20px; margin-bottom: 0;">
                <div style="font-size: 0.95rem; color: #64748b; font-weight: 600; margin-bottom: 5px;">Avg. Customer Lifetime Value</div>
                <div style="font-size: 1.8rem; font-weight: 700; color: #0f172a;">₹4,500</div>
                <div style="font-size: 0.8rem; color: #64748b; margin-top: 5px;">Estimated revenue per customer</div>
            </div>
        </div>

    </div>

    <script>
        // Dummy data for charts - Replace with actual PHP arrays later
        var optionsCust = {
            series: [{
                name: 'Customer Payments',
                data: [15000, 22000, 18000, 32000, 28000, 38000]
            }],
            chart: {
                type: 'line',
                height: 250,
                toolbar: {
                    show: false
                },
                fontFamily: 'Inter, sans-serif'
            },
            stroke: {
                curve: 'smooth',
                width: 3
            },
            colors: ['#059669'],
            xaxis: {
                categories: ['Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar']
            }
        };
        new ApexCharts(document.querySelector("#custChart"), optionsCust).render();

        var optionsVend = {
            series: [{
                name: 'Vendor Payments',
                data: [8000, 12000, 9000, 18000, 22000, 24000]
            }],
            chart: {
                type: 'line',
                height: 250,
                toolbar: {
                    show: false
                },
                fontFamily: 'Inter, sans-serif'
            },
            stroke: {
                curve: 'smooth',
                width: 3
            },
            colors: ['#e11d48'],
            xaxis: {
                categories: ['Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar']
            }
        };
        new ApexCharts(document.querySelector("#vendChart"), optionsVend).render();
    </script>
</body>

</html>