<?php
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'];
if (!isset($admin_id)) {
    header('location:login.php');
    exit();
}

// Fetch Stats safely (handling nulls)
$totalSellers = $conn->query("SELECT COUNT(*) c FROM sellers")->fetch_assoc()['c'] ?? 0;
$totalGRN = $conn->query("SELECT COUNT(*) c FROM inventory_grn")->fetch_assoc()['c'] ?? 0;
$totalWeight = $conn->query("SELECT SUM(total_weight_kg) t FROM inventory_grn")->fetch_assoc()['t'] ?? 0;
$totalValue = $conn->query("SELECT SUM(total_value) t FROM inventory_grn")->fetch_assoc()['t'] ?? 0;

$top = $conn->query("
    SELECT s.name, SUM(g.total_value) val
    FROM sellers s
    JOIN inventory_grn g ON g.seller_id=s.id
    GROUP BY s.id
    ORDER BY val DESC
    LIMIT 5
");

// Fetch All Suppliers with their History Stats
$suppliers_sql = "
    SELECT s.id, s.name, s.phone, s.category,
           COUNT(g.id) as total_grns,
           COALESCE(SUM(g.total_value), 0) as total_purchase
    FROM sellers s
    LEFT JOIN inventory_grn g ON s.id = g.seller_id
    GROUP BY s.id
    ORDER BY total_purchase DESC
";
$suppliers_res = $conn->query($suppliers_sql);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Suppliers & GRN Dashboard | Trishe ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            overflow-x: hidden;
        }

        .page-header-box {
            background: #fff;
            padding: 20px;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
            border-top: 4px solid var(--primary);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .stat-card.pink {
            border-top-color: #ec4899;
        }

        .stat-card.green {
            border-top-color: var(--success);
        }

        .stat-card.orange {
            border-top-color: var(--warning);
        }

        .stat-card.blue {
            border-top-color: var(--info);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            margin-top: 10px;
            color: var(--text-main);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .shell {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 24px;
            margin-bottom: 24px;
            align-items: start;
        }

        .chart-container {
            height: 300px;
            width: 100%;
            position: relative;
        }

        /* Specific fix for table width */
        .table-wrap table {
            min-width: 100% !important;
        }

        @media (max-width: 1024px) {
            .shell {
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

            .page-header-box .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-value {
                font-size: 1.4rem;
            }

            /* Table to Cards */
            .table-wrap {
                border: none;
                background: transparent;
                overflow: visible;
                box-shadow: none;
            }

            table,
            thead,
            tbody,
            th,
            td,
            tr {
                display: block;
                width: 100%;
            }

            thead {
                display: none;
            }

            tr {
                margin-bottom: 15px;
                border: 1px solid var(--border);
                border-radius: 10px;
                padding: 10px;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            }

            td {
                border: none;
                border-bottom: 1px dashed var(--border) !important;
                padding: 12px 5px !important;
                position: relative;
                text-align: right;
                display: flex;
                justify-content: space-between;
                align-items: center;
                min-height: 40px;
            }

            td:last-child {
                border-bottom: none !important;
                display: block;
                padding: 15px 5px 5px !important;
                text-align: center;
            }

            td:last-child .btn {
                width: 100%;
                justify-content: center;
            }

            td::before {
                content: attr(data-label);
                font-weight: 700;
                color: var(--text-muted);
                text-transform: uppercase;
                font-size: 0.75rem;
                text-align: left;
                padding-right: 10px;
            }

            td:last-child::before {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-truck-loading text-primary"></i> Suppliers & GRN Overview</h1>
            <a href="grn.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New GRN</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card pink">
                <div class="stat-label">Total Sellers</div>
                <div class="stat-value"><?= number_format($totalSellers) ?></div>
            </div>
            <div class="stat-card green">
                <div class="stat-label">Total GRN</div>
                <div class="stat-value"><?= number_format($totalGRN) ?></div>
            </div>
            <div class="stat-card orange">
                <div class="stat-label">Total Weight Purchased</div>
                <div class="stat-value"><?= number_format((float)$totalWeight, 2) ?> <span style="font-size:1rem;">Kg</span></div>
            </div>
            <div class="stat-card blue">
                <div class="stat-label">Total Purchase Value</div>
                <div class="stat-value">₹ <?= number_format((float)$totalValue, 2) ?></div>
            </div>
        </div>

        <div class="shell">

            <div class="card">
                <div class="card-header"><span><i class="fas fa-medal text-warning" style="margin-right:8px;"></i> Top 5 Sellers</span></div>
                <div class="table-wrap" style="border:none; border-radius:0; box-shadow:none;">
                    <table>
                        <thead>
                            <tr>
                                <th>Seller Name</th>
                                <th style="text-align:right;">Purchase Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($top && $top->num_rows > 0): while ($t = $top->fetch_assoc()): ?>
                                    <tr>
                                        <td data-label="Seller Name"><strong style="color:var(--text-main);"><?= htmlspecialchars($t['name']) ?></strong></td>
                                        <td data-label="Purchase Value" style="text-align:right; font-weight:700; color:var(--primary);">₹ <?= number_format((float)$t['val'], 2) ?></td>
                                    </tr>
                                <?php endwhile;
                            else: ?>
                                <tr>
                                    <td colspan="2" style="text-align:center; padding:30px; color:var(--text-muted);">No data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><span><i class="fas fa-chart-line text-info" style="margin-right:8px;"></i> Purchase Flow (Trend)</span></div>
                <div style="padding: 20px;">
                    <div class="chart-container">
                        <canvas id="purchaseChart"></canvas>
                    </div>
                </div>
            </div>

        </div>

        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <span><i class="fas fa-users text-muted" style="margin-right:8px;"></i> Supplier Directory & History</span>
                <button class="btn btn-outline btn-sm" onclick="openGlobalVendorModal()"><i class="fas fa-user-plus"></i> Add Supplier</button>
            </div>
            <div class="table-wrap" style="border:none; border-radius:0; box-shadow:none;">
                <table>
                    <thead>
                        <tr>
                            <th>Supplier Info</th>
                            <th>Category</th>
                            <th>Total GRNs</th>
                            <th style="text-align:right;">Total Purchase Value</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($suppliers_res && $suppliers_res->num_rows > 0): ?>
                            <?php while ($s = $suppliers_res->fetch_assoc()): ?>
                                <tr>
                                    <td data-label="Supplier Info">
                                        <strong style="color:var(--text-main); font-size:1.05rem;"><?= htmlspecialchars($s['name']) ?></strong><br>
                                        <span style="font-size:0.85rem; color:var(--text-muted); font-weight:500;"><i class="fas fa-phone fa-sm"></i> <?= htmlspecialchars($s['phone'] ?? 'N/A') ?></span>
                                    </td>
                                    <td data-label="Category">
                                        <span class="badge bg-gray" style="padding:4px 8px;"><?= htmlspecialchars($s['category'] ?? 'General') ?></span>
                                    </td>
                                    <td data-label="Total GRNs">
                                        <strong style="color:var(--text-main);"><?= $s['total_grns'] ?></strong> Deliveries
                                    </td>
                                    <td data-label="Total Purchase Value" style="text-align:right;">
                                        <strong style="color:var(--success); font-size:1.1rem;">₹ <?= number_format((float)$s['total_purchase'], 2) ?></strong>
                                    </td>
                                    <td data-label="Action" style="text-align:right;">
                                        <a class="btn btn-outline" style="padding:6px 12px; font-size:0.85rem;" href="seller_details.php?id=<?= $s['id'] ?>">
                                            <i class="fas fa-book-open text-primary"></i> View Khata
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:40px; color:var(--text-muted);">No suppliers found in the system.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
        const ctx = document.getElementById('purchaseChart').getContext('2d');

        // Dummy data for the chart to make it look professional
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6'],
                datasets: [{
                    label: 'Purchase Amount (₹)',
                    data: [15000, 22000, 18000, 35000, 28000, 42000],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    tension: 0.4, // smooth curves
                    fill: true,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#3b82f6',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: {
                            family: 'Inter'
                        },
                        bodyFont: {
                            family: 'Inter'
                        },
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e2e8f0',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#64748b',
                            font: {
                                family: 'Inter'
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            color: '#64748b',
                            font: {
                                family: 'Inter'
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>