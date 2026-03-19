<?php
// seller_details.php - SUPPLIER KHATA WITH FULL GRN DETAILS
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'];
if (!isset($admin_id)) {
    header('location:login.php');
    exit();
}

// ================= AJAX: FETCH GRN DETAILS =================
if (isset($_POST['action']) && $_POST['action'] == 'get_grn_details') {
    ob_clean();
    header('Content-Type: application/json');
    $grn_id = (int)$_POST['grn_id'];

    // Fetch GRN Master Info
    $q1 = "SELECT ig.*, s.name as seller_name FROM inventory_grn ig LEFT JOIN sellers s ON ig.seller_id = s.id WHERE ig.id = $grn_id";
    $res1 = $conn->query($q1);
    
    if (!$res1 || $res1->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'GRN not found']);
        exit;
    }
    $grn = $res1->fetch_assoc();

    // Fetch GRN Items
    $q2 = "SELECT i.*, sm.name as seed_name, sm.category 
           FROM inventory i 
           LEFT JOIN seeds_master sm ON i.seed_id = sm.id 
           WHERE i.reference_id = $grn_id AND i.transaction_type = 'GRN_IN'";
    $res2 = $conn->query($q2);
    
    $items = [];
    if($res2) {
        while ($row = $res2->fetch_assoc()) {
            $rate = isset($row['price_per_qtl']) ? $row['price_per_qtl'] : (isset($row['rate']) ? $row['rate'] : 0);
            $total = isset($row['line_value']) ? $row['line_value'] : (isset($row['total_amount']) ? $row['total_amount'] : ($row['quantity'] * $rate));
            
            $items[] = [
                'seed_name' => $row['seed_name'] ?? 'Unknown Item',
                'category' => $row['category'] ?? '-',
                'weight_kg' => $row['quantity'],
                'price_per_qtl' => $rate,
                'line_value' => $total
            ];
        }
    }
    
    echo json_encode(['success' => true, 'grn' => $grn, 'items' => $items]);
    exit;
}
// ===========================================================

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('location:admin_inventory.php');
    exit();
}

$seller_id = (int)$_GET['id'];

// 1. Fetch Seller Details
$seller_res = $conn->query("SELECT * FROM sellers WHERE id = $seller_id");
if (!$seller_res || $seller_res->num_rows == 0) {
    echo "<h3 style='text-align:center; margin-top:50px;'>Seller not found!</h3>";
    exit();
}
$seller = $seller_res->fetch_assoc();

// 2. Fetch Seller Stats
$stats = $conn->query("
    SELECT COUNT(*) as total_grns, 
           COALESCE(SUM(total_weight_kg), 0) as total_weight, 
           COALESCE(SUM(total_value), 0) as total_value 
    FROM inventory_grn 
    WHERE seller_id = $seller_id
")->fetch_assoc();

// 3. Fetch GRN History for this Seller
$grn_history = $conn->query("
    SELECT * FROM inventory_grn 
    WHERE seller_id = $seller_id 
    ORDER BY created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($seller['name']) ?> - Khata | Trishe ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #4f46e5; --primary-hover: #4338ca;
            --bg-body: #f1f5f9; --card-bg: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b;
            --border: #e2e8f0; --success: #10b981; --warning: #f59e0b;
            --radius: 12px; --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; } 
        
        body {
            font-family: 'Inter', sans-serif; background: var(--bg-body); color: var(--text-main);
            padding-left: 260px; padding-bottom: 60px; overflow-x: hidden;
        }

        .container { width: 100%; max-width: 1200px; margin: 0 auto; padding: 20px; }

        /* HEADER */
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px; background: var(--card-bg); padding: 20px;
            border-radius: var(--radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border);
        }
        
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; border: none; text-decoration: none; transition: 0.2s; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-outline { background: white; border: 1px solid var(--border); color: var(--text-main); }
        .btn-outline:hover { background: #f8fafc; }
        .btn-sm { padding: 6px 12px; font-size: 0.85rem; }

        /* PROFILE & STATS */
        .profile-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 24px; }
        
        .card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border); padding: 20px; }
        
        .profile-card { display: flex; flex-direction: column; gap: 15px; }
        .profile-avatar { width: 60px; height: 60px; background: #e0e7ff; color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: 700; margin-bottom: 10px; }
        
        .stats-inner-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .stat-box { background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid var(--border); text-align: center; }
        .stat-val { font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-bottom: 5px; }
        .stat-lbl { font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }

        /* TABLE */
        .card-header { font-weight: 700; font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 15px; display:flex; align-items:center; gap:8px;}
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; min-width: 600px; }
        th { padding: 12px; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); background: #f8fafc; }
        td { padding: 15px 12px; border-bottom: 1px solid var(--border); font-size: 0.95rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f8fafc; }

        /* MODAL */
        .modal { display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 15px; }
        .modal-content { background: white; width: 100%; max-width: 700px; border-radius: var(--radius); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh; }
        .modal-header { padding: 20px; border-bottom: 1px solid var(--border); background: #f8fafc; display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 20px; overflow-y: auto; }
        .close-modal { font-size: 1.8rem; cursor: pointer; color: var(--text-muted); line-height: 1; }

        /* MOBILE RESPONSIVE */
        @media (max-width: 1024px) { body { padding-left: 0; } }
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .page-header { flex-direction: column; align-items: stretch; text-align: center; gap:15px; }
            .profile-grid { grid-template-columns: 1fr; }
            .stats-inner-grid { grid-template-columns: 1fr; }
            
            /* Table to Cards */
            table, thead, tbody, th, td, tr { display: block; width: 100%; }
            thead { display: none; }
            tr { margin-bottom: 15px; border: 1px solid var(--border); border-radius: 10px; padding: 5px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
            td { border: none; border-bottom: 1px dashed var(--border); padding: 12px 10px 12px 40%; position: relative; text-align: right; display: flex; justify-content: flex-end; align-items: center; min-height: 45px; }
            td:last-child { border-bottom: none; display: block; padding: 15px 10px 5px; text-align: center;}
            td:last-child .btn { width: 100%; justify-content: center; }
            td::before { content: attr(data-label); position: absolute; left: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem; text-align: left;}
            td:last-child::before { display: none; }
        }

        /* PRINT STYLES */
        @media print {
            body * { visibility: hidden; }
            #grnModalBody, #grnModalBody * { visibility: visible; }
            #grnModalBody { position: absolute; left: 0; top: 0; width: 100%; padding: 0; margin: 0; }
            .modal-content { box-shadow: none; border: none; }
            .btn { display: none !important; }
            td::before { display: none !important; } 
            td, th, tr, table, thead, tbody { display: table-cell; } 
            tr { display: table-row; }
            table { display: table; width: 100%; border: 1px solid #ccc; }
            th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title" style="margin:0;">
                <i class="fas fa-book-open text-primary"></i> Supplier Khata
            </h1>
            <a href="admin_inventory.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>

        <div class="profile-grid">
            <div class="card profile-card">
                <div class="profile-avatar">
                    <?= strtoupper(substr($seller['name'], 0, 1)) ?>
                </div>
                <div>
                    <h2 style="margin:0 0 5px 0; font-size:1.4rem; color:var(--text-main);"><?= htmlspecialchars($seller['name']) ?></h2>
                    <p style="margin:0; color:var(--text-muted); font-size:0.95rem;">
                        <i class="fas fa-phone fa-sm"></i> <?= htmlspecialchars($seller['phone'] ?? 'No Phone added') ?>
                    </p>
                </div>
                <div style="margin-top:10px; border-top:1px dashed var(--border); padding-top:15px;">
                    <p style="margin:0 0 5px 0; font-size:0.9rem; color:var(--text-muted);">Category</p>
                    <strong style="background:#e2e8f0; padding:4px 10px; border-radius:6px; font-size:0.85rem; color:var(--text-main);"><?= htmlspecialchars($seller['category'] ?? 'General') ?></strong>
                </div>
                <div style="margin-top:10px;">
                    <p style="margin:0 0 5px 0; font-size:0.9rem; color:var(--text-muted);">Address</p>
                    <strong style="font-size:0.95rem; color:var(--text-main);"><?= htmlspecialchars($seller['address'] ?? 'N/A') ?></strong>
                </div>
            </div>

            <div class="card">
                <div class="stats-inner-grid" style="height:100%; display:flex; gap:15px;">
                    <div class="stat-box" style="flex:1; display:flex; flex-direction:column; justify-content:center;">
                        <div class="stat-val"><?= number_format($stats['total_grns']) ?></div>
                        <div class="stat-lbl">Total Deliveries (GRNs)</div>
                    </div>
                    <div class="stat-box" style="flex:1; display:flex; flex-direction:column; justify-content:center;">
                        <div class="stat-val"><?= number_format((float)$stats['total_weight'], 0) ?> <span style="font-size:1rem;">Kg</span></div>
                        <div class="stat-lbl">Total Weight Supplied</div>
                    </div>
                    <div class="stat-box" style="flex:1; display:flex; flex-direction:column; justify-content:center; border-color:#bbf7d0; background:#f0fdf4;">
                        <div class="stat-val" style="color:var(--success);">₹ <?= number_format((float)$stats['total_value'], 0) ?></div>
                        <div class="stat-lbl" style="color:#166534;">Total Purchase Value</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-truck-loading text-muted"></i> Delivery History (GRNs)</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>GRN No</th>
                            <th>Vehicle No</th>
                            <th>Total Weight</th>
                            <th style="text-align:right;">Amount (₹)</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($grn_history && $grn_history->num_rows > 0): ?>
                            <?php while ($row = $grn_history->fetch_assoc()): ?>
                                <tr>
                                    <td data-label="Date"><?= date('d M Y, h:i A', strtotime($row['created_at'])) ?></td>
                                    <td data-label="GRN No"><strong style="color:var(--primary);"><?= $row['grn_no'] ?></strong></td>
                                    <td data-label="Vehicle No"><?= htmlspecialchars($row['vehicle_no'] ?? '-') ?></td>
                                    <td data-label="Total Weight"><strong><?= number_format((float)$row['total_weight_kg'], 2) ?> kg</strong></td>
                                    <td data-label="Amount" style="text-align:right; font-weight:700; color:var(--success);">₹ <?= number_format((float)$row['total_value'], 2) ?></td>
                                    <td data-label="Action" style="text-align:right;">
                                        <button class="btn btn-outline btn-sm" onclick="viewGRNDetails(<?= $row['id'] ?>)">
                                            <i class="fas fa-file-invoice"></i> View Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center; padding:30px; color:var(--text-muted);">No GRN history found for this supplier.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="grnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin:0; font-size:1.3rem; color:var(--text-main);">GRN Receipt Details</h3>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="grnModalBody">
                <div style="text-align:center; padding:40px;"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i></div>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('grnModal');
        const modalBody = document.getElementById('grnModalBody');

        function viewGRNDetails(grn_id) {
            modal.style.display = 'flex';
            modalBody.innerHTML = '<div style="text-align:center; padding:40px;"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p style="margin-top:10px; color:var(--text-muted);">Loading Receipt...</p></div>';

            let fd = new FormData();
            fd.append('action', 'get_grn_details');
            fd.append('grn_id', grn_id);

            // Fetch from the same page
            fetch('seller_details.php?id=<?= $seller_id ?>', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    const g = res.grn;
                    let itemsHtml = '';
                    
                    if(res.items.length > 0) {
                        res.items.forEach(item => {
                            itemsHtml += `
                                <tr>
                                    <td><strong>${item.seed_name}</strong><br><small style="color:#64748b;">${item.category}</small></td>
                                    <td>₹${parseFloat(item.price_per_qtl).toFixed(2)}</td>
                                    <td>${parseFloat(item.weight_kg).toFixed(2)} kg</td>
                                    <td style="text-align:right; font-weight:bold;">₹${parseFloat(item.line_value).toFixed(2)}</td>
                                </tr>
                            `;
                        });
                    } else {
                        itemsHtml = `<tr><td colspan="4" style="text-align:center; padding:15px; color:#999;">No items detailed in this GRN.</td></tr>`;
                    }

                    // Modern Receipt Layout
                    modalBody.innerHTML = `
                        <div id="printArea">
                            <div style="text-align:center; margin-bottom:20px; border-bottom:2px dashed #ccc; padding-bottom:15px;">
                                <h2 style="margin:0; color:var(--text-main);">TRISHE AGRO</h2>
                                <p style="margin:5px 0 0; color:var(--text-muted);">Goods Receipt Note (Inward)</p>
                            </div>
                            
                            <div style="display:flex; justify-content:space-between; margin-bottom:10px; font-size:0.95rem;">
                                <div><span style="color:var(--text-muted);">GRN No:</span> <strong>${g.grn_no}</strong></div>
                                <div><span style="color:var(--text-muted);">Date:</span> <strong>${new Date(g.created_at).toLocaleDateString()}</strong></div>
                            </div>
                            
                            <div style="display:flex; justify-content:space-between; margin-bottom:20px; font-size:0.95rem;">
                                <div><span style="color:var(--text-muted);">Seller:</span> <strong>${g.seller_name}</strong></div>
                                <div><span style="color:var(--text-muted);">Vehicle:</span> <strong>${g.vehicle_no || '-'}</strong></div>
                            </div>
                            
                            <table style="width:100%; border-collapse:collapse; font-size:0.9rem; margin-bottom:20px;">
                                <thead>
                                    <tr style="border-bottom:2px solid #ccc; text-align:left;">
                                        <th style="padding:10px 0;">Item Name</th>
                                        <th>Rate</th>
                                        <th>Weight</th>
                                        <th style="text-align:right;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>${itemsHtml}</tbody>
                                <tfoot>
                                    <tr style="border-top:2px solid #ccc; font-size:1.1rem;">
                                        <td colspan="2" style="padding:15px 0; font-weight:bold;">GRAND TOTAL:</td>
                                        <td style="font-weight:bold;">${parseFloat(g.total_weight_kg).toFixed(2)} kg</td>
                                        <td style="text-align:right; font-weight:bold; color:var(--success);">₹${parseFloat(g.total_value).toFixed(2)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div style="display:flex; gap:10px; justify-content:flex-end;">
                            <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print Receipt</button>
                            <button class="btn btn-primary" onclick="closeModal()">Close</button>
                        </div>
                    `;
                } else {
                    modalBody.innerHTML = `<div style="color:red; text-align:center; padding:20px;">Error: ${res.error}</div>`;
                }
            })
            .catch(err => { 
                console.error(err);
                modalBody.innerHTML = `<div style="color:red; text-align:center; padding:20px;">Network Error occurred.</div>`; 
            });
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        // Close on outside click
        window.onclick = function(e) {
            if (e.target == modal) closeModal();
        }
    </script>
</body>
</html>