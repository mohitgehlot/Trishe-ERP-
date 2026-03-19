<?php
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    header('location:login.php');
    exit;
}

// --- DELETE ORDER LOGIC ---
if (isset($_GET['delete'])) {
    $delete_id = mysqli_real_escape_string($conn, $_GET['delete']);
    mysqli_query($conn, "DELETE FROM `online_orders` WHERE id = '$delete_id'");
    header('location:manage_online_orders.php?msg=deleted');
    exit;
}

// --- SEARCH & FILTER LOGIC ---
$search = $_GET['search'] ?? '';
$courier_filter = $_GET['courier'] ?? '';

$where_clause = "WHERE 1=1";
if ($search) {
    $search = mysqli_real_escape_string($conn, $search);
    $where_clause .= " AND (order_id LIKE '%$search%' OR tracking_no LIKE '%$search%' OR invoice_no LIKE '%$search%')";
}
if ($courier_filter) {
    $courier_filter = mysqli_real_escape_string($conn, $courier_filter);
    $where_clause .= " AND courier_company = '$courier_filter'";
}

// --- FETCH STATS ---
$total_orders = mysqli_query($conn, "SELECT COUNT(*) as c FROM `online_orders`")->fetch_assoc()['c'];
$delhivery_orders = mysqli_query($conn, "SELECT COUNT(*) as c FROM `online_orders` WHERE courier_company LIKE '%Delhivery%'")->fetch_assoc()['c'];
$other_couriers = $total_orders - $delhivery_orders;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Online Orders | Trishe ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --border: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --radius: 12px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            padding-left: 260px;
            padding-bottom: 60px;
        }

        .container {
            margin: 0 auto;
            padding: 20px;
        }

        /* Header & Alerts */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: var(--card);
            padding: 15px 20px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .page-title {
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--card);
            padding: 20px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
        }

        .stat-val {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text);
        }

        .stat-lbl {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            margin-top: 5px;
        }

        /* Toolbar */
        .toolbar {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            background: #fff;
            padding: 15px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            gap: 10px;
            flex: 1;
            min-width: 300px;
        }

        .form-input {
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.9rem;
            outline: none;
            flex: 1;
        }

        .form-input:focus {
            border-color: var(--primary);
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: 0.2s;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .btn-danger:hover {
            background: #fca5a5;
        }

        /* Table Styles */
        .table-card {
            background: var(--card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            text-align: left;
        }

        th {
            background: #f8fafc;
            padding: 15px;
            font-weight: 600;
            color: #64748b;
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            color: #334155;
            vertical-align: middle;
        }

        tr:hover {
            background: #f8fafc;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }

        .badge-courier {
            background: #e0e7ff;
            color: #4338ca;
        }

        @media (max-width: 1024px) {
            body {
                padding-left: 0;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
            <div class="alert alert-success"><i class="fas fa-trash-alt"></i> Order deleted successfully!</div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-box-open text-primary"></i> Online Orders Management</h1>
            <a href="bulk_upload.php" class="btn btn-primary"><i class="fas fa-upload"></i> Upload New Labels</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-val"><?= $total_orders ?></div>
                <div class="stat-lbl">Total Uploaded Orders</div>
            </div>
            <div class="stat-card" style="border-left-color: #f59e0b;">
                <div class="stat-val" style="color: #d97706;"><?= $delhivery_orders ?></div>
                <div class="stat-lbl">Delhivery Shipments</div>
            </div>
            <div class="stat-card" style="border-left-color: #10b981;">
                <div class="stat-val" style="color: #059669;"><?= $other_couriers ?></div>
                <div class="stat-lbl">Other Couriers</div>
            </div>
        </div>

        <div class="toolbar">
            <form method="GET" class="filter-group">
                <input type="text" name="search" class="form-input" placeholder="Search by Order ID, Tracking No, Invoice..." value="<?= htmlspecialchars($search) ?>">

                <select name="courier" class="form-input" style="flex: 0.5;">
                    <option value="">All Couriers</option>
                    <option value="Delhivery" <?= $courier_filter == 'Delhivery' ? 'selected' : '' ?>>Delhivery</option>
                    <option value="BlueDart" <?= $courier_filter == 'BlueDart' ? 'selected' : '' ?>>BlueDart</option>
                    <option value="Ecom Express" <?= $courier_filter == 'Ecom Express' ? 'selected' : '' ?>>Ecom Express</option>
                    <option value="Shadowfax" <?= $courier_filter == 'Shadowfax' ? 'selected' : '' ?>>Shadowfax</option>
                </select>

                <button type="submit" class="btn btn-primary" style="width:auto;"><i class="fas fa-search"></i> Search</button>
                <?php if ($search || $courier_filter): ?>
                    <a href="manage_online_orders.php" class="btn btn-danger" style="width:auto;" title="Clear Filters"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Order Details</th>
                        <th>Invoice No.</th>
                        <th>Tracking Details</th>
                        <th>Order Date</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $select_orders = mysqli_query($conn, "SELECT * FROM `online_orders` $where_clause ORDER BY id DESC") or die('Query failed');

                    if (mysqli_num_rows($select_orders) > 0) {
                        while ($row = mysqli_fetch_assoc($select_orders)) {
                    ?>
                            <tr>
                                <td>
                                    <strong style="color:#0f172a; font-size:1rem;">#<?= htmlspecialchars($row['order_id']) ?></strong><br>
                                    <small style="color:#94a3b8;">Record ID: <?= $row['id'] ?></small>
                                </td>
                                <td>
                                    <span style="font-weight: 500;"><?= htmlspecialchars($row['invoice_no']) ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-courier" style="margin-bottom: 5px;"><?= htmlspecialchars($row['courier_company']) ?></span><br>
                                    <strong style="color: #334155; font-size:0.95rem;"><?= htmlspecialchars($row['tracking_no']) ?></strong>
                                </td>
                                <td>
                                    <i class="far fa-calendar-alt" style="color:#94a3b8; margin-right:5px;"></i>
                                    <?= date('d M Y', strtotime($row['order_date'])) ?>
                                </td>
                                <td style="text-align:right;">
                                    <a href="manage_online_orders.php?delete=<?= $row['id'] ?>" onclick="return confirm('Are you sure you want to delete this order record?');" class="btn btn-danger" title="Delete Order">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                    <?php
                        }
                    } else {
                        echo '<tr><td colspan="5" style="text-align:center; padding:40px; color:#94a3b8; font-size:1.1rem;"><i class="fas fa-inbox" style="font-size:2rem; margin-bottom:10px; display:block;"></i> No online orders found!</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Clean URL after delete action
        if (window.history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.delete('msg');
            window.history.replaceState(null, '', url);
        }
    </script>

</body>

</html>