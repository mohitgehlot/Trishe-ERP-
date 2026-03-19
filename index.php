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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | Trishe ERP</title>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    /* =========================================
       GLOBAL VARIABLES & RESET
       ========================================= */
    :root {
      --primary: #4f46e5;
      --primary-hover: #4338ca;
      --bg-body: #f1f5f9;
      --card-bg: #ffffff;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border: #e2e8f0;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #3b82f6;
      --radius: 12px;
      --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html {
      font-size: 16px;
    }

    /* Ensures base font is readable on mobile */

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg-body);
      color: var(--text-main);
      padding-left: 260px;
      /* Desktop Sidebar Width */
      padding-bottom: 80px;
      overflow-x: hidden;
      /* Stops horizontal scrolling */
    }

    .container {
      width: 100%;

      margin: 0 auto;
      padding: 5px;
    }

    /* =========================================
       HEADER
       ========================================= */
    .page-header {
      margin-bottom: 24px;
    }

    .page-title {
      font-size: 1.8rem;
      font-weight: 800;
      color: var(--text-main);
    }

    .page-subtitle {
      font-size: 1rem;
      color: var(--text-muted);
      margin-top: 5px;
    }

    /* =========================================
       STAT CARDS (TOP ROW)
       ========================================= */
    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 20px;
      margin-bottom: 24px;
    }

    .stat-card {
      background: var(--card-bg);
      padding: 24px;
      border-radius: var(--radius);
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border);
      border-top: 5px solid var(--primary);
      display: flex;
      flex-direction: column;
      justify-content: center;
      transition: transform 0.2s;
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
      font-size: 0.9rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      margin-bottom: 8px;
      letter-spacing: 0.5px;
    }

    .stat-value {
      font-size: 2.2rem;
      font-weight: 800;
      color: var(--text-main);
      line-height: 1.2;
    }

    .stat-subtext {
      font-size: 0.9rem;
      color: #94a3b8;
      margin-top: 8px;
    }

    /* =========================================
       MAIN LAYOUT (SHELL)
       ========================================= */
    .shell {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 24px;
      align-items: start;
    }

    .section-card {
      background: var(--card-bg);
      border-radius: var(--radius);
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border);
      padding: 24px;
      margin-bottom: 24px;
      width: 100%;
      overflow: hidden;
    }

    .section-title {
      font-size: 1.2rem;
      font-weight: 700;
      margin-bottom: 20px;
      color: var(--text-main);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* =========================================
       QUICK ACTIONS
       ========================================= */
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
      font-size: 1rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      transition: all 0.2s ease;
    }

    .action-btn i {
      font-size: 2rem;
      color: var(--primary);
    }

    /* =========================================
       TABLE (RECENT ACTIVITY)
       ========================================= */
    .table-responsive {
      width: 100%;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    th {
      padding: 15px;
      font-size: 0.85rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      border-bottom: 1px solid var(--border);
    }

    td {
      padding: 15px;
      border-bottom: 1px solid var(--border);
      font-size: 1rem;
      color: var(--text-main);
      vertical-align: middle;
    }

    tr:last-child td {
      border-bottom: none;
    }

    .badge {
      padding: 6px 10px;
      border-radius: 6px;
      font-size: 0.8rem;
      font-weight: 700;
      text-transform: uppercase;
    }

    .bg-success {
      background: #dcfce7;
      color: #15803d;
    }

    .bg-warning {
      background: #fef3c7;
      color: #b45309;
    }

    .bg-secondary {
      background: #f1f5f9;
      color: #475569;
    }

    /* =========================================
       RIGHT SIDEBAR (ALERTS & LINKS)
       ========================================= */
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
      font-size: 1rem;
      font-weight: 500;
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
      font-size: 1.1rem;
    }

    .chart-wrapper {
      height: 350px;
      width: 100%;
      position: relative;
    }

    /* =========================================
       MOBILE RESPONSIVE (BIG & TOUCH FRIENDLY)
       ========================================= */
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
        padding: 15px;
      }

      .page-header {
        text-align: center;
      }

      .page-title {
        font-size: 1.6rem;
      }

      /* Stack Stat Cards 1 per row for maximum readability */
      .dashboard-grid {
        grid-template-columns: 1fr 1fr;
        gap: 15px;
      }

      /* Quick Actions 2x2 Grid */
      .quick-actions {
        grid-template-columns: 1fr 1fr;
        gap: 15px;
      }

      .action-btn {
        padding: 25px 15px;
        font-size: 1.1rem;
      }

      .action-btn i {
        font-size: 2.5rem;
      }

      .section-card {
        padding: 15px;
      }

      /* -----------------------------------------
           TABLE TO CARDS CONVERSION (FIXES ZOOM ISSUE)
           ----------------------------------------- */
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

      /* Hide Headers */

      tr {
        margin-bottom: 15px;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px;
        background: #fff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
      }

      td {
        border: none;
        border-bottom: 1px dashed var(--border);
        padding: 12px 5px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        text-align: right;
        font-size: 1.05rem;
        /* Big text */
      }

      td:last-child {
        border-bottom: none;
      }

      /* Pseudo Labels */
      td::before {
        content: attr(data-label);
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        font-size: 0.85rem;
        text-align: left;
        padding-right: 15px;
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

        <div class="section-card">
          <h4 class="section-title"><i class="fas fa-bolt text-warning"></i> Quick Actions</h4>
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

        <div class="section-card">
          <h4 class="section-title"><i class="fas fa-chart-area text-info"></i> Production Trend</h4>
          <div class="chart-wrapper">
            <canvas id="productionChart"></canvas>
          </div>
        </div>

        <div class="section-card">
          <h4 class="section-title"><i class="fas fa-history text-muted"></i> Recent Processing</h4>
          <div class="table-responsive">
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
                    <td colspan="6" class="empty-state" style="border:none;">
                      <i class="fas fa-inbox"></i>
                      <p>No recent activities found.</p>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($dashboard['recent_activities'] as $activity): ?>
                    <?php
                    $status_color = '';
                    if ($activity['status'] == 'completed') $status_color = 'bg-success';
                    elseif ($activity['status'] == 'running') $status_color = 'bg-warning';
                    else $status_color = 'bg-secondary';
                    ?>
                    <tr>
                      <td data-label="Batch No"><strong><?php echo htmlspecialchars($activity['batch_no']); ?></strong></td>
                      <td data-label="Seed Type"><?php echo htmlspecialchars($activity['seed_name'] ?? 'Unknown'); ?></td>
                      <td data-label="Input"><?php echo htmlspecialchars($activity['seed_qty']); ?> kg</td>
                      <td data-label="Oil Out"><?php echo ($activity['oil_out'] > 0 ? $activity['oil_out'] : '0'); ?> Ltr</td>
                      <td data-label="Status"><span class="badge <?php echo $status_color; ?>"><?php echo htmlspecialchars($activity['status']); ?></span></td>
                      <td data-label="Time"><?php echo date('d M, h:i A', strtotime($activity['created_at'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <div class="right-side">

        <div class="section-card">
          <h4 class="section-title"><i class="fas fa-exclamation-triangle text-danger"></i> Low Stock Alerts</h4>
          <?php if (empty($dashboard['low_stock_items'])): ?>
            <div class="empty-state" style="padding:20px 10px;">
              <i class="fas fa-check-circle text-success" style="font-size:2.5rem;"></i>
              <p>Stocks are sufficient.</p>
            </div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($dashboard['low_stock_items'] as $item): ?>
                <?php
                $min = isset($item['min_stock_level']) && $item['min_stock_level'] > 0 ? $item['min_stock_level'] : 50;
                $percentage = ($item['current_stock'] / $min) * 100;
                ?>
                <div class="list-group-item">
                  <div>
                    <strong style="color:var(--text-main); display:block; font-size:1.1rem; margin-bottom:5px;"><?php echo htmlspecialchars($item['name']); ?></strong>
                    <span style="font-size:0.9rem; color:var(--danger); font-weight:600;">Left: <?php echo $item['current_stock']; ?> kg</span>
                  </div>
                  <span class="badge" style="background:#fee2e2; color:#dc2626; padding:8px 12px; font-size:0.9rem;">
                    <i class="fas fa-arrow-down"></i> <?php echo number_format($percentage, 0); ?>%
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="section-card">
          <h4 class="section-title"><i class="fas fa-link text-primary"></i> Helpful Links</h4>
          <div class="list-group">
            <a href="inventory.php" class="list-group-item">
              <span><i class="fas fa-boxes text-muted" style="width:30px;"></i> Inventory</span>
              <i class="fas fa-chevron-right text-muted"></i>
            </a>
            <a href="admin_orders.php" class="list-group-item">
              <span><i class="fas fa-shopping-cart text-muted" style="width:30px;"></i> Sales Orders</span>
              <i class="fas fa-chevron-right text-muted"></i>
            </a>
            <a href="reports.php" class="list-group-item">
              <span><i class="fas fa-chart-pie text-muted" style="width:30px;"></i> Reports</span>
              <i class="fas fa-chevron-right text-muted"></i>
            </a>
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