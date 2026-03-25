<?php
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'];
if (!isset($admin_id)) {
  header('location:login.php');
  exit();
}

/* Fetch data for dashboard */
function getDashboardData($conn)
{
  $data = [];

  // Today's processing
  $today_query = mysqli_query($conn, "SELECT COUNT(*) as batches, COALESCE(SUM(seed_qty),0) as seeds FROM seed_processing WHERE DATE(created_at) = CURDATE()");
  $data['today'] = mysqli_fetch_assoc($today_query);

  // Running batches
  $running_query = mysqli_query($conn, "SELECT COUNT(*) as running FROM seed_processing WHERE status = 'running'");
  $data['running'] = mysqli_fetch_assoc($running_query)['running'];

  // Total oil produced
  $oil_query = mysqli_query($conn, "SELECT COALESCE(SUM(oil_out),0) as oil FROM seed_processing WHERE status = 'completed'");
  $data['oil'] = mysqli_fetch_assoc($oil_query)['oil'];

  // Total cake produced
  $cake_query = mysqli_query($conn, "SELECT COALESCE(SUM(cake_out),0) as cake FROM seed_processing WHERE status = 'completed'");
  $data['cake'] = mysqli_fetch_assoc($cake_query)['cake'];

  // Pending orders
  $pending_query = mysqli_query($conn, "SELECT COUNT(*) as pending FROM orders WHERE payment_status != 'paid'");
  $data['pending_orders'] = mysqli_fetch_assoc($pending_query)['pending'];

  // Total revenue
  $revenue_query = mysqli_query($conn, "SELECT COALESCE(SUM(total),0) as revenue FROM orders WHERE payment_status = 'paid'");
  $data['revenue'] = mysqli_fetch_assoc($revenue_query)['revenue'];

  // --- SMART CHECK FOR MIN STOCK LEVEL ---
  $check_col = mysqli_query($conn, "SHOW COLUMNS FROM seeds_master LIKE 'min_stock_level'");
  $min_stock_val = (mysqli_num_rows($check_col) > 0) ? "min_stock_level" : "50"; // Use 50 if column doesn't exist

  // Low stock alerts
  $low_stock_query = mysqli_query($conn, "SELECT COUNT(*) as alerts FROM seeds_master WHERE current_stock <= $min_stock_val");
  $data['low_stock'] = $low_stock_query ? mysqli_fetch_assoc($low_stock_query)['alerts'] : 0;

  // Recent activities
  $recent_query = mysqli_query($conn, "SELECT sp.*, sm.name as seed_name FROM seed_processing sp LEFT JOIN seeds_master sm ON sp.seed_id = sm.id ORDER BY sp.created_at DESC LIMIT 6");
  $data['recent_activities'] = [];
  if ($recent_query) {
    while ($row = mysqli_fetch_assoc($recent_query)) {
      $data['recent_activities'][] = $row;
    }
  }

  // Low stock items
  $low_items_query = mysqli_query($conn, "SELECT name, current_stock, $min_stock_val as min_stock_level FROM seeds_master WHERE current_stock <= $min_stock_val ORDER BY current_stock ASC LIMIT 5");
  $data['low_stock_items'] = [];
  if ($low_items_query) {
    while ($row = mysqli_fetch_assoc($low_items_query)) {
      $data['low_stock_items'][] = $row;
    }
  }

  return $data;
}

$dashboard = getDashboardData($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Dashboard | Trishe ERP</title>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="css/admin_style.css">

  <style>
    .container {
      width: 100%;
      max-width: 1400px;
      margin: 0 auto;
      padding: 20px;
      overflow-x: hidden;
    }

    .page-subtitle {
      font-size: 1rem;
      color: var(--text-muted);
      margin-top: 5px;
    }

    /* STAT CARDS (TOP ROW) */
    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 20px;
      margin-bottom: 24px;
    }

    .stat-card {
      background: var(--card-bg);
      padding: 20px;
      border-radius: var(--radius);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
      border: 1px solid var(--border);
      border-top: 4px solid var(--primary);
      display: flex;
      flex-direction: column;
      justify-content: center;
      transition: transform 0.2s;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
    }

    .stat-card.warning {
      border-top-color: var(--warning);
    }

    .stat-card.success {
      border-top-color: var(--success);
    }

    .stat-card.danger {
      border-top-color: var(--danger);
    }

    .stat-card.info {
      border-top-color: var(--info);
    }

    .stat-label {
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      margin-bottom: 8px;
      letter-spacing: 0.5px;
    }

    .stat-value {
      font-size: 2rem;
      font-weight: 800;
      color: var(--text-main);
      line-height: 1.2;
    }

    .stat-subtext {
      font-size: 0.85rem;
      color: #64748b;
      margin-top: 8px;
      font-weight: 500;
    }

    /* MAIN LAYOUT (SHELL) */
    .shell {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 24px;
      align-items: start;
    }

    /* 🌟 MAGICAL FIX FOR RESPONSIVE: `min-width: 0` stops table from breaking grid! */
    .main-inner,
    .right-side {
      min-width: 0;
      width: 100%;
      overflow: hidden;
    }

    /* QUICK ACTIONS */
    .quick-actions {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 15px;
    }

    .action-btn {
      background: #f8fafc;
      border: 1px solid var(--border);
      padding: 20px 10px;
      border-radius: 10px;
      text-align: center;
      text-decoration: none;
      color: var(--text-main);
      font-weight: 600;
      font-size: 0.95rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      transition: all 0.2s ease;
    }

    .action-btn:hover {
      background: #fff;
      border-color: var(--primary);
      color: var(--primary);
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .action-btn i {
      font-size: 1.8rem;
      color: var(--primary);
    }

    /* RIGHT SIDEBAR (ALERTS & LINKS) */
    .list-group {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .list-group-item {
      padding: 15px;
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      text-decoration: none;
      color: var(--text-main);
      font-size: 0.95rem;
      font-weight: 600;
      transition: 0.2s;
      word-break: break-word;
    }

    .list-group-item:hover {
      border-color: #cbd5e1;
      background: #fff;
    }

    .empty-state {
      text-align: center;
      padding: 40px 10px;
      color: var(--text-muted);
    }

    .empty-state i {
      font-size: 3rem;
      margin-bottom: 15px;
      opacity: 0.3;
    }

    .empty-state p {
      font-size: 1rem;
      font-weight: 500;
    }

    .chart-wrapper {
      height: 350px;
      width: 100%;
      position: relative;
    }

    /* ========================================================
       🌟 100% PERFECT MOBILE RESPONSIVE MEDIA QUERIES
       ======================================================== */

    @media (max-width: 1200px) {
      .dashboard-grid {
        grid-template-columns: repeat(3, 1fr);
      }
    }

    @media (max-width: 1024px) {
      body {
        padding-left: 0;
      }

      .shell {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 768px) {
      .container {
        padding: 12px;
      }

      .page-header {
        text-align: center;
        margin-bottom: 20px;
      }

      .page-title {
        font-size: 1.5rem;
        justify-content: center;
      }

      /* Stat Cards: Mobile par 2 lines mein */
      .dashboard-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
      }

      .stat-card {
        padding: 15px;
      }

      .stat-value {
        font-size: 1.5rem;
      }

      /* Quick Actions: Mobile par bade buttons 2 line mein */
      .quick-actions {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
      }

      .action-btn {
        padding: 15px;
        font-size: 0.85rem;
      }

      .action-btn i {
        font-size: 1.5rem;
      }

      /* Charts aur Cards ki padding mobile par kam rahegi */
      .card-header {
        font-size: 0.95rem;
        padding: 12px 15px;
      }

      .card>div {
        padding: 15px !important;
      }
    }

    @media (max-width: 480px) {

      /* Choti screen par stat cards ek ke niche ek */
      .dashboard-grid {
        grid-template-columns: 1fr;
      }

      .quick-actions {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>

  <?php include 'admin_header.php'; ?>

  <div class="container">
    <?php
    // Aaj ki Live Sale aur Profit fetch kar rahe hain
    $td_sales = floatval($dashboard['today_profit_data']['today_sales'] ?? 0);
    $td_profit = floatval($dashboard['today_profit_data']['today_profit'] ?? 0);
    $margin_pct = ($td_sales > 0) ? ($td_profit / $td_sales) * 100 : 0;
    ?>

    <div class="page-header">
      <h1 class="page-title"><i class="fas fa-chart-line text-primary"></i> Dashboard Overview</h1>
      <div class="page-subtitle">Welcome back, here is what's happening today.</div>
    </div>

    <div class="dashboard-grid">
      <div class="stat-card" style="border-top-color: #3b82f6;">
        <div class="stat-label">Today's Sale</div>
        <div class="stat-value">₹<?= number_format($td_sales, 0) ?></div>
        <div class="stat-subtext">Live Counter</div>
      </div>

      <div class="stat-card success" style="background:#f0fdf4; border-color:#bbf7d0;">
        <div class="stat-label" style="color:#166534;">Net Profit</div>
        <div class="stat-value" style="color:#15803d;">₹<?= number_format($td_profit, 0) ?></div>
        <div class="stat-subtext" style="color:#059669; font-weight:bold;">
          <i class="fas <?= $margin_pct >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i> <?= number_format($margin_pct, 1) ?>% Margin
        </div>
      </div>

      <div class="stat-card info">
        <div class="stat-label">Today's Batches</div>
        <div class="stat-value"><?php echo $dashboard['today']['batches']; ?></div>
        <div class="stat-subtext"><?php echo number_format($dashboard['today']['seeds'], 1); ?> kg processed</div>
      </div>

      <div class="stat-card warning">
        <div class="stat-label">Running Batches</div>
        <div class="stat-value"><?php echo $dashboard['running']; ?></div>
        <div class="stat-subtext">Currently in progress</div>
      </div>

      <div class="stat-card" style="border-top-color: #8b5cf6;">
        <div class="stat-label">Total Oil Prod.</div>
        <div class="stat-value"><?php echo number_format($dashboard['oil'], 0); ?> <span style="font-size:1rem;">Ltr</span></div>
        <div class="stat-subtext">Lifetime production</div>
      </div>
    </div>

    <div class="shell">
      <div class="main-inner">

        <div class="card">
          <div class="card-header"><i class="fas fa-bolt text-warning"></i> Quick Actions</div>
          <div style="padding: 20px;">
            <div class="quick-actions">
              <a href="process_raw_material.php" class="action-btn">
                <i class="fas fa-play-circle"></i>
                Start Process
              </a>
              <a href="admin_orders.php?view=services" class="action-btn">
                <i class="fas fa-tools"></i>
                Job Work
              </a>
              <a href="pos.php" class="action-btn">
                <i class="fas fa-cart-plus"></i>
                New Sale
              </a>
              <a href="admin_customers.php" class="action-btn">
                <i class="fas fa-address-book"></i>
                Customers
              </a>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><i class="fas fa-chart-area text-info"></i> Production Trend</div>
          <div style="padding: 20px;">
            <div class="chart-wrapper">
              <canvas id="productionChart"></canvas>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><i class="fas fa-history text-muted"></i> Recent Processing</div>
          <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
            <table>
              <thead>
                <tr>
                  <th>Batch No</th>
                  <th>Seed Type</th>
                  <th>Input</th>
                  <th>Output (Oil)</th>
                  <th>Status</th>
                  <th>Time</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($dashboard['recent_activities'])): ?>
                  <tr>
                    <td colspan="6" class="empty-state">
                      <i class="fas fa-inbox"></i>
                      <p>No recent activities found.</p>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($dashboard['recent_activities'] as $activity): ?>
                    <?php
                    // Mapping old status logic to Master CSS Classes
                    $status_class = '';
                    if ($activity['status'] == 'completed') $status_class = 'st-completed';
                    elseif ($activity['status'] == 'running') $status_class = 'st-processing';
                    else $status_class = 'st-pending';
                    ?>
                    <tr>
                      <td style="font-weight:700; color:var(--primary); white-space:nowrap;">#<?php echo htmlspecialchars($activity['batch_no']); ?></td>
                      <td style="white-space:nowrap;"><strong><?php echo htmlspecialchars($activity['seed_name'] ?? 'Unknown'); ?></strong></td>
                      <td style="white-space:nowrap;"><?php echo htmlspecialchars($activity['seed_qty']); ?> kg</td>
                      <td style="font-weight:600; white-space:nowrap;"><?php echo ($activity['oil_out'] > 0 ? $activity['oil_out'] : '0'); ?> Ltr</td>
                      <td style="white-space:nowrap;"><span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($activity['status']); ?></span></td>
                      <td style="color:var(--text-muted); font-size:0.85rem; white-space:nowrap;"><?php echo date('d M, h:i A', strtotime($activity['created_at'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <div class="right-side">

        <div class="card">
          <div class="card-header"><i class="fas fa-exclamation-triangle text-danger"></i> Low Stock Alerts</div>
          <div style="padding: 20px;">
            <?php if (empty($dashboard['low_stock_items'])): ?>
              <div class="empty-state">
                <i class="fas fa-check-circle text-success" style="font-size:2.5rem; opacity:1;"></i>
                <p style="margin-top:10px;">Stocks are sufficient.</p>
              </div>
            <?php else: ?>
              <div class="list-group">
                <?php foreach ($dashboard['low_stock_items'] as $item): ?>
                  <?php
                  $min = isset($item['min_stock_level']) && $item['min_stock_level'] > 0 ? $item['min_stock_level'] : 50;
                  $percentage = ($item['current_stock'] / $min) * 100;
                  ?>
                  <div class="list-group-item">
                    <div style="flex:1; min-width:0;">
                      <strong style="color:var(--text-main); display:block; font-size:1rem; margin-bottom:5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($item['name']); ?></strong>
                      <span style="font-size:0.85rem; color:var(--danger); font-weight:600;">Left: <?php echo $item['current_stock']; ?> kg</span>
                    </div>
                    <span class="badge" style="background:#fee2e2; color:#dc2626; padding:6px 10px; font-size:0.8rem; border:1px solid #fca5a5; white-space:nowrap;">
                      <i class="fas fa-arrow-down"></i> <?php echo number_format($percentage, 0); ?>%
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><i class="fas fa-link text-primary"></i> Helpful Links</div>
          <div style="padding: 20px;">
            <div class="list-group">
              <a href="inventory.php" class="list-group-item">
                <span><i class="fas fa-boxes text-primary" style="width:25px;"></i> Inventory</span>
                <i class="fas fa-chevron-right text-muted"></i>
              </a>
              <a href="admin_orders.php" class="list-group-item">
                <span><i class="fas fa-shopping-cart text-success" style="width:25px;"></i> Sales Orders</span>
                <i class="fas fa-chevron-right text-muted"></i>
              </a>
              <a href="reports.php" class="list-group-item">
                <span><i class="fas fa-chart-pie text-warning" style="width:25px;"></i> Reports</span>
                <i class="fas fa-chevron-right text-muted"></i>
              </a>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    const productionCtx = document.getElementById('productionChart').getContext('2d');
    new Chart(productionCtx, {
      type: 'line',
      data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
        datasets: [{
          label: 'Oil Production (Ltr)',
          data: [120, 190, 150, 200, 180, 220, 240], // Dummy Data
          borderColor: '#4f46e5',
          backgroundColor: 'rgba(79, 70, 229, 0.1)',
          borderWidth: 3,
          tension: 0.4,
          fill: true,
          pointBackgroundColor: '#fff',
          pointBorderColor: '#4f46e5',
          pointRadius: 4
        }, {
          label: 'Cake Production (Kg)',
          data: [80, 120, 100, 140, 110, 130, 150], // Dummy Data
          borderColor: '#10b981',
          backgroundColor: 'rgba(16, 185, 129, 0.1)',
          borderWidth: 3,
          tension: 0.4,
          fill: true,
          pointBackgroundColor: '#fff',
          pointBorderColor: '#10b981',
          pointRadius: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: {
          legend: {
            position: 'top',
            labels: {
              font: {
                family: 'Inter',
                size: 13
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            grid: {
              color: '#e2e8f0',
              drawBorder: false
            }
          },
          x: {
            grid: {
              display: false
            }
          }
        }
      }
    });
  </script>

</body>

</html>