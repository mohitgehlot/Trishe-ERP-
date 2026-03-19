<?php
// process_raw_material.php - FULLY RESPONSIVE & LARGE UI
ob_start();
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Auth Error']);
        exit;
    }
    header('location:login.php');
    exit();
}

// Error Reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(0);
ini_set('display_errors', 0);

/* --- HELPER FUNCTIONS --- */
function convertToIST($datetime)
{
    if (empty($datetime)) return null;
    $date = new DateTime($datetime, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('Asia/Kolkata'));
    return $date->format('d M, h:i A');
}

// --- Update Seed Master Stats ---
function updateSeedMasterStats($conn, $seed_id)
{
    $sql_stats = "SELECT SUM(seed_qty) as total_in, SUM(oil_out) as total_oil, SUM(cake_out) as total_cake FROM seed_processing WHERE seed_id = ? AND status = 'completed'";
    $stmt = $conn->prepare($sql_stats);
    $stmt->bind_param("i", $seed_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();

    if ($row && $row['total_in'] > 0) {
        $t_in = $row['total_in'];
        $t_oil = $row['total_oil'];
        $t_cake = $row['total_cake'];
        $avg_oil = ($t_oil / $t_in) * 100;
        $avg_cake = ($t_cake / $t_in) * 100;

        $sql_update = "UPDATE seeds_master SET total_seed_crushed = ?, total_oil_produced = ?, total_cake_produced = ?, avg_oil_recovery = ?, avg_cake_recovery = ? WHERE id = ?";
        $stmtUpd = $conn->prepare($sql_update);
        $stmtUpd->bind_param("dddddi", $t_in, $t_oil, $t_cake, $avg_oil, $avg_cake, $seed_id);
        $stmtUpd->execute();
    }
}

function fetchSeeds($conn)
{
    $seeds = [];
    $stmt = $conn->prepare("SELECT id, name, current_stock as available_quantity FROM seeds_master WHERE current_stock > 0 ORDER BY name");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $seeds[] = $row;
        $stmt->close();
    }
    return $seeds;
}

function fetchMachines($conn)
{
    $machines = [];
    try {
        $res = $conn->query("SELECT id, name, model FROM machines WHERE is_active = 1");
        if ($res) while ($row = $res->fetch_assoc()) $machines[] = $row;
    } catch (Exception $e) {
    }
    if (empty($machines)) $machines[] = ['id' => 1, 'name' => 'Expeller 1', 'model' => 'Default'];
    return $machines;
}

function fetchRunningProcesses($conn)
{
    $rows = [];
    $sql = "SELECT sp.*, sm.name as seed_name, m.name as machine_name FROM seed_processing sp LEFT JOIN seeds_master sm ON sp.seed_id = sm.id LEFT JOIN machines m ON sp.machine_id = m.id WHERE sp.end_time IS NULL ORDER BY sp.start_time DESC";
    try {
        $res = $conn->query($sql);
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    } catch (Exception $e) {
    }
    return $rows;
}

function fetchCompletedProcesses($conn)
{
    $rows = [];
    $sql = "SELECT sp.*, sm.name as seed_name FROM seed_processing sp LEFT JOIN seeds_master sm ON sp.seed_id = sm.id WHERE sp.end_time IS NOT NULL AND (sp.oil_out <= 0 OR sp.oil_out IS NULL) ORDER BY sp.end_time DESC";
    try {
        $res = $conn->query($sql);
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    } catch (Exception $e) {
    }
    return $rows;
}

function fetchProcessHistory($conn)
{
    $rows = [];
    $sql = "SELECT sp.*, sm.name as seed_name FROM seed_processing sp LEFT JOIN seeds_master sm ON sp.seed_id = sm.id WHERE sp.status = 'completed' AND sp.oil_out > 0 ORDER BY sp.end_time DESC LIMIT 15";
    try {
        $res = $conn->query($sql);
        if ($res) while ($r = $res->fetch_assoc()) {
            $r['start_time'] = convertToIST($r['start_time']);
            $r['end_time'] = convertToIST($r['end_time']);
            $rows[] = $r;
        }
    } catch (Exception $e) {
    }
    return $rows;
}

// ================= AJAX HANDLERS =================

// A. ADD NEW SEED
if (isset($_POST['action']) && $_POST['action'] == 'add_seed') {
    ob_clean();
    header('Content-Type: application/json');
    $name = trim($_POST['s_name'] ?? '');
    $category = trim($_POST['s_category'] ?? '');
    $stock = floatval($_POST['s_stock'] ?? 0);
    if (empty($name) || empty($category)) {
        echo json_encode(['success' => false, 'error' => 'Name and Category required']);
        exit;
    }
    try {
        $check = $conn->query("SELECT id FROM seeds_master WHERE name = '$name'");
        if ($check->num_rows > 0) throw new Exception("Seed name already exists!");
        $stmt = $conn->prepare("INSERT INTO seeds_master (name, category, current_stock) VALUES (?, ?, ?)");
        $stmt->bind_param("ssd", $name, $category, $stock);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Seed Added Successfully']);
        } else {
            throw new Exception($stmt->error);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


// B. GET GRN BATCHES (Directly from inventory_grn_items)
if (isset($_POST['action']) && $_POST['action'] == 'get_grn_batches') {
    ob_clean(); header('Content-Type: application/json');
    $seed_id = (int)$_POST['seed_id'];
    
    // 🌟 FIX: Ab seedha 'inventory_grn_items' ki unique ID (igi.id) fetch hogi 🌟
    $sql = "SELECT igi.id as item_id, igi.weight_kg, ig.grn_no, igi.created_at 
            FROM inventory_grn_items igi 
            JOIN inventory_grn ig ON igi.grn_id = ig.id 
            WHERE igi.seed_id = ? 
            ORDER BY igi.id DESC LIMIT 30";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $seed_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $batches = [];
    while ($row = $res->fetch_assoc()) {
        $date = date('d-M', strtotime($row['created_at']));
        $item_id = $row['item_id'];
        $wt = round($row['weight_kg'], 2);
        
        // 🌟 Dropdown me aayega: "ID: 64 (Wt: 60.5 Kg) - 18-Mar" 🌟
        $row['display_text'] = "ID: {$item_id} (Wt: {$wt} Kg) - {$date}";
        
        // Machine table me ab trace ke liye ITEM-64 save hoga
        $row['batch_no'] = "ITEM-" . $item_id; 
        $batches[] = $row;
    }
    echo json_encode($batches);
    exit;
}

// C. START PROCESS
if (isset($_POST['start_process'])) {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $seed_id = (int)$_POST['seed_id'];
        $qty = (float)$_POST['seed_qty'];
        $machine_id = (int)$_POST['machine_id'];
        $linked_grn = $_POST['linked_grn_no'] ?? '';
        if ($seed_id <= 0 || $qty <= 0) throw new Exception('Invalid input');

        $checkStock = $conn->query("SELECT current_stock FROM seeds_master WHERE id = $seed_id")->fetch_assoc();
        if (!$checkStock || $qty > $checkStock['current_stock']) {
            throw new Exception("Insufficient Stock. Available: " . ($checkStock['current_stock'] ?? 0) . " kg");
        }

        $machName = 'Unknown';
        $mRow = $conn->query("SELECT name, model FROM machines WHERE id=$machine_id")->fetch_assoc();
        if ($mRow) $machName = $mRow['name'] . ' (' . $mRow['model'] . ')';

        $conn->begin_transaction();
        $batch_no = 'BATCH-' . date('Ymd') . '-' . rand(100, 999);
        $trace_info = "Linked GRN: " . $linked_grn;

        $stmt = $conn->prepare("INSERT INTO seed_processing (batch_no, seed_id, seed_qty, start_time, status, machine_id, machine_no, remarks) VALUES (?, ?, ?, NOW(), 'running', ?, ?, ?)");
        $stmt->bind_param("sidiss", $batch_no, $seed_id, $qty, $machine_id, $machName, $trace_info);
        if (!$stmt->execute()) throw new Exception("Process Start Failed");

        $stmtInv = $conn->prepare("INSERT INTO inventory (seed_id, product_type, transaction_type, quantity, batch_no, notes, transaction_date, created_by) VALUES (?, 'SEED', 'GRN_OUT', ?, ?, ?, NOW(), ?)");
        $stmtInv->bind_param("idssi", $seed_id, $qty, $batch_no, $trace_info, $_SESSION['admin_id']);
        if (!$stmtInv->execute()) throw new Exception("Inventory Deduction Failed");

        $stmtUpdateMaster = $conn->prepare("UPDATE seeds_master SET current_stock = current_stock - ? WHERE id = ?");
        $stmtUpdateMaster->bind_param("di", $qty, $seed_id);
        if (!$stmtUpdateMaster->execute()) throw new Exception("Failed to update Master Stock");

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Batch Started!']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// D. END PROCESS
if (isset($_POST['end_process'])) {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $batch_no = $_POST['batch_no'];
        $conn->query("UPDATE seed_processing SET end_time = NOW() WHERE batch_no = '$batch_no'");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// E. SAVE OUTPUT
if (isset($_POST['save-process'])) {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $batch_no = $_POST['batch_no'];
        $oil_out = (float)$_POST['oil_out'];
        $cake_out = (float)$_POST['cake_out'];
        $conn->begin_transaction();

        $stmtGet = $conn->prepare("SELECT seed_id, seed_qty, id FROM seed_processing WHERE batch_no = ?");
        $stmtGet->bind_param("s", $batch_no);
        $stmtGet->execute();
        $proc = $stmtGet->get_result()->fetch_assoc();
        if (!$proc) throw new Exception("Batch not found");

        $seed_id = $proc['seed_id'];
        $process_id = $proc['id'];
        $input_qty = (float)$proc['seed_qty'];
        $total_out = $oil_out + $cake_out;
        $loss = $input_qty - $total_out;
        $efficiency = ($input_qty > 0) ? ($oil_out / $input_qty) * 100 : 0;

        if ($loss < -5) throw new Exception("Error: Output is higher than Input.");

        $stmtUpd = $conn->prepare("UPDATE seed_processing SET oil_out = ?, cake_out = ?, loss_kg = ?, efficieny_percentage = ?, status = 'completed' WHERE batch_no = ?");
        $stmtUpd->bind_param("dddds", $oil_out, $cake_out, $loss, $efficiency, $batch_no);
        if (!$stmtUpd->execute()) throw new Exception("Update failed");

        $adminId = $_SESSION['admin_id'];
        $transaction_date = date('Y-m-d H:i:s');
        $stmtInsert = $conn->prepare("INSERT INTO raw_material_inventory (seed_id, product_type, batch_no, quantity, unit, transaction_type, source_type, reference_id, notes, transaction_date, created_by) VALUES (?, ?, ?, ?, 'KG', 'RAW_IN', 'PRODUCTION', ?, ?, ?, ?)");

        if ($oil_out > 0) {
            $pType = 'OIL';
            $note = "Oil from Batch $batch_no";
            $stmtInsert->bind_param("issdissi", $seed_id, $pType, $batch_no, $oil_out, $process_id, $note, $transaction_date, $adminId);
            $stmtInsert->execute();
        }
        if ($cake_out > 0) {
            $pType = 'CAKE';
            $note = "Cake from Batch $batch_no";
            $stmtInsert->bind_param("issdissi", $seed_id, $pType, $batch_no, $cake_out, $process_id, $note, $transaction_date, $adminId);
            $stmtInsert->execute();
        }

        updateSeedMasterStats($conn, $seed_id);
        $conn->commit();
        echo json_encode(['success' => true, 'message' => "Batch Saved!"]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$seeds = fetchSeeds($conn);
$machines = fetchMachines($conn);
$running_processes = fetchRunningProcesses($conn);
$complete_processes = fetchCompletedProcesses($conn);
$process_history = fetchProcessHistory($conn);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Production Floor | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* =========================================
           VARIABLES & RESET
           ========================================= */
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg-body: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #475569;
            --border: #cbd5e1;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --radius: 12px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            padding-left: 260px;
            /* Desktop Sidebar */
            padding-bottom: 80px;
            overflow-x: hidden;
            /* Prevents horizontal scroll on mobile */
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
        }

        /* =========================================
           BUTTONS & FORMS (LARGE TOUCH TARGETS)
           ========================================= */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            /* 16px is best for mobile inputs/buttons */
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: 0.2s;
            text-align: center;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-outline {
            background: white;
            border: 1px solid var(--border);
            color: var(--text-main);
        }

        .btn-outline:hover {
            background: #f8fafc;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 14px;
            /* Big padding for mobile */
            font-size: 16px;
            /* Prevents auto-zoom on iOS */
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: var(--text-main);
            outline: none;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        /* =========================================
           LAYOUT & CARDS
           ========================================= */
        .grid-layout {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 24px;
            align-items: start;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            margin-bottom: 24px;
            width: 100%;
        }

        .card-header {
            padding: 18px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 8px;
        }

        /* =========================================
           BATCH CARDS
           ========================================= */
        .batch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .batch-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            border-left: 5px solid var(--warning);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .batch-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-weight: 700;
            font-size: 18px;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-running {
            background: #e0f2fe;
            color: #0284c7;
        }

        .badge-weighing {
            background: #dcfce7;
            color: #15803d;
        }

        .batch-info {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 16px;
            color: var(--text-muted);
            line-height: 1.8;
            border: 1px solid #e2e8f0;
        }

        .batch-info strong {
            color: var(--text-main);
            font-size: 17px;
        }

        .live-stats {
            display: flex;
            gap: 10px;
            background: #f0fdf4;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #bbf7d0;
            text-align: center;
            margin-bottom: 20px;
        }

        .stat-box {
            flex: 1;
        }

        .stat-label {
            font-size: 13px;
            color: #166534;
            font-weight: 700;
            text-transform: uppercase;
        }

        .stat-val {
            font-size: 20px;
            font-weight: 700;
            color: #15803d;
            margin-top: 5px;
        }

        /* =========================================
           HISTORY TABLE (DESKTOP)
           ========================================= */
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            background: #f8fafc;
            padding: 15px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            font-size: 15px;
            vertical-align: middle;
        }

        /* =========================================
           MOBILE RESPONSIVE (THE MAGIC FIX)
           ========================================= */
        @media (max-width: 1024px) {
            body {
                padding-left: 0;
            }

            .grid-layout {
                grid-template-columns: 1fr;
            }

            .left-col {
                order: 1;
            }

            .right-col {
                order: 2;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 0px;
            }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                padding: 20px;
                gap: 15px;
            }

            .action-buttons {
                width: 100%;
            }

            .action-buttons .btn {
                width: 100%;
                padding: 14px;
            }

            /* Huge buttons for mobile */

            .batch-grid {
                grid-template-columns: 1fr;
            }

            /* Stack cards */

            .form-control {
                padding: 15px;
            }

            /* Bigger inputs */

            /* HISTORY TABLE TO CARDS (PREVENTS ZOOM ISSUE) */
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

            /* Hide headers */

            tr {
                margin-bottom: 15px;
                border: 1px solid var(--border);
                border-radius: 8px;
                padding: 10px;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            }

            td {
                border: none;
                border-bottom: 1px dashed var(--border);
                padding: 12px 5px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 15px;
                text-align: right;
            }

            td:last-child {
                border-bottom: none;
            }

            /* Add pseudo-labels */
            td::before {
                content: attr(data-label);
                font-weight: 700;
                color: var(--text-muted);
                text-transform: uppercase;
                font-size: 12px;
                text-align: left;
            }

            /* Output Entry Form Layout */
            .output-grid {
                display: flex;
                flex-direction: column;
                gap: 15px;
                margin-bottom: 15px;
            }
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: white;
            width: 100%;
            max-width: 400px;
            border-radius: var(--radius);
            overflow: hidden;
        }

        .modal-header {
            padding: 20px;
            display: flex;
            justify-content: space-between;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
        }

        .close-modal {
            font-size: 28px;
            cursor: pointer;
            color: var(--text-muted);
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header">
            <div class="page-title"><i class="fas fa-industry text-primary"></i> Production Floor</div>
            <div class="action-buttons">
                <button onclick="openSeedModal()" class="btn btn-primary"><i class="fas fa-seedling"></i> Add Seed</button>
                <a href="grn.php" class="btn btn-outline"><i class="fas fa-truck-loading"></i> Add GRN</a>
            </div>
        </div>

        <div class="grid-layout">

            <div class="left-col">
                <div class="card">
                    <div class="card-header"><i class="fas fa-play-circle text-primary"></i> Start New Batch</div>
                    <div class="card-body">
                        <form id="startForm">
                            <input type="hidden" name="action" value="start_process">
                            <input type="hidden" name="start_process" value="1">

                            <div class="form-group">
                                <label class="form-label">Raw Material (Seed)</label>
                                <select id="seed_select" name="seed_id" class="form-control" required>
                                    <option value="">-- Choose Seed --</option>
                                    <?php foreach ($seeds as $s): ?>
                                        <option value="<?= $s['id'] ?>" data-stock="<?= $s['available_quantity'] ?>">
                                            <?= $s['name'] ?> (Stock: <?= number_format($s['available_quantity'], 2) ?> kg)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Linked GRN (Optional)</label>
                                <select id="grn_select" name="linked_grn_no" class="form-control">
                                    <option value="">-- Auto / Any --</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Select Machine</label>
                                <select name="machine_id" class="form-control" required>
                                    <?php foreach ($machines as $m): ?>
                                        <option value="<?= $m['id'] ?>"><?= $m['name'] ?> (<?= $m['model'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Input Quantity (Kg)</label>
                                <input type="number" id="input_qty" name="seed_qty" class="form-control" step="0.01" placeholder="Weight in Kg" required>
                                <small id="stock_error" style="color:var(--danger); display:none; font-weight:600; margin-top:8px;">⚠️ Insufficient Stock!</small>
                            </div>

                            <button type="submit" id="startBtn" class="btn btn-primary" style="width:100%; margin-top:10px;"><i class="fas fa-power-off"></i> Start Machine</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><i class="fas fa-history text-muted"></i> Recent History</div>
                    <div class="card-body" style="padding: 10px;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Batch Info</th>
                                    <th>Input (Kg)</th>
                                    <th>Output (O/C)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($process_history)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center; color:var(--text-muted);">No history available</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($process_history as $h): ?>
                                    <tr>
                                        <td data-label="Batch Info">
                                            <div style="text-align:right;">
                                                <strong style="color:var(--text-main); font-size:16px;"><?= $h['batch_no'] ?></strong><br>
                                                <small style="color:var(--text-muted);"><?= $h['seed_name'] ?></small>
                                            </div>
                                        </td>
                                        <td data-label="Input (Kg)"><?= number_format($h['seed_qty'], 2) ?></td>
                                        <td data-label="Output (Oil/Cake)">
                                            <div style="text-align:right;">
                                                <span style="color:var(--success); font-weight:600;"><?= number_format($h['oil_out'], 2) ?></span> /
                                                <span style="color:var(--warning); font-weight:600;"><?= number_format($h['cake_out'], 2) ?></span><br>
                                                <small style="color:var(--danger);">Loss: <?= number_format($h['loss_kg'], 2) ?></small>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="right-col">

                <h3 style="font-size:1.2rem; margin-bottom:20px; color:var(--text-main);"><i class="fas fa-sync fa-spin text-primary"></i> Currently Running</h3>

                <div class="batch-grid">
                    <?php if (empty($running_processes)): ?>
                        <div class="card" style="grid-column:1/-1; text-align:center; padding:40px; color:var(--text-muted); border-style:dashed;">
                            <i class="fas fa-bed fa-2x" style="margin-bottom:10px; opacity:0.5;"></i><br>
                            No machines currently running.
                        </div>
                    <?php else: ?>
                        <?php foreach ($running_processes as $run): ?>
                            <div class="batch-card">
                                <div class="batch-header">
                                    <span><?= $run['batch_no'] ?></span>
                                    <span class="badge badge-running">Running</span>
                                </div>
                                <div class="batch-info">
                                    <div style="margin-bottom:10px;"><i class="fas fa-seedling text-success" style="width:20px;"></i> <strong><?= $run['seed_name'] ?></strong> (<?= $run['seed_qty'] ?> Kg)</div>
                                    <div style="margin-bottom:10px;"><i class="fas fa-cog text-muted" style="width:20px;"></i> <?= $run['machine_name'] ?></div>
                                    <div><i class="fas fa-clock text-muted" style="width:20px;"></i> Started: <?= convertToIST($run['start_time']) ?></div>
                                </div>
                                <form onsubmit="return stopBatch(event, this)">
                                    <input type="hidden" name="action" value="end_process">
                                    <input type="hidden" name="end_process" value="1">
                                    <input type="hidden" name="batch_no" value="<?= $run['batch_no'] ?>">
                                    <button class="btn btn-outline" style="width:100%; border-color:var(--warning); color:#b45309;">
                                        <i class="fas fa-hand-paper"></i> Stop Machine
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($complete_processes)): ?>
                    <h3 style="font-size:1.2rem; margin:30px 0 20px 0; color:#15803d;"><i class="fas fa-balance-scale"></i> Pending Weighing</h3>

                    <div class="batch-grid">
                        <?php foreach ($complete_processes as $comp): ?>
                            <div class="batch-card" style="border-left-color:var(--success);">
                                <div class="batch-header">
                                    <span><?= $comp['batch_no'] ?></span>
                                    <span class="badge badge-weighing">Stopped</span>
                                </div>
                                <div class="batch-info">
                                    <div style="text-align:center;">Input: <strong style="font-size:20px;"><?= $comp['seed_qty'] ?> Kg</strong> <br><small><?= $comp['seed_name'] ?></small></div>
                                </div>
                                <form class="saveForm" onsubmit="return saveBatch(event, this)">
                                    <input type="hidden" name="action" value="save-process">
                                    <input type="hidden" name="save-process" value="1">
                                    <input type="hidden" name="batch_no" value="<?= $comp['batch_no'] ?>">
                                    <input type="hidden" name="input_qty" value="<?= $comp['seed_qty'] ?>">

                                    <div class="output-grid" style="display:flex; gap:15px; margin-bottom:20px;">
                                        <div style="flex:1;">
                                            <label class="form-label" style="color:var(--success);">Oil (Kg)</label>
                                            <input type="number" name="oil_out" class="form-control" step="0.01" required oninput="calcStats(this)" placeholder="0.00">
                                        </div>
                                        <div style="flex:1;">
                                            <label class="form-label" style="color:var(--warning);">Cake (Kg)</label>
                                            <input type="number" name="cake_out" class="form-control" step="0.01" required oninput="calcStats(this)" placeholder="0.00">
                                        </div>
                                    </div>

                                    <div class="live-stats">
                                        <div class="stat-box">
                                            <div class="stat-label">Loss (Kg)</div>
                                            <div class="stat-val loss-val" style="color:var(--text-main);">-</div>
                                        </div>
                                        <div class="stat-box">
                                            <div class="stat-label">Recovery</div>
                                            <div class="stat-val eff-val" style="color:var(--primary);">-</div>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-success" style="width:100%;"><i class="fas fa-save"></i> Save Inventory</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div id="seedModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin:0; font-size:1.3rem; color:var(--text-main);">Add New Seed</h3>
                <span class="close-modal" onclick="closeSeedModal()">&times;</span>
            </div>
            <div class="card-body">
                <form id="seedForm">
                    <input type="hidden" name="action" value="add_seed">
                    <div class="form-group">
                        <label class="form-label">Seed Name</label>
                        <input type="text" name="s_name" class="form-control" placeholder="e.g. Yellow Mustard" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="s_category" class="form-control" required>
                            <option value="oilseed">Oilseed</option>
                            <option value="cereal">Cereal</option>
                            <option value="pulse">Pulse</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Opening Stock (Kg)</label>
                        <input type="number" name="s_stock" class="form-control" step="0.01" value="0">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:10px; padding:15px;">Save Seed</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // --- MODAL LOGIC ---
        function openSeedModal() {
            document.getElementById('seedModal').style.display = 'flex';
        }

        function closeSeedModal() {
            document.getElementById('seedModal').style.display = 'none';
        }
        window.onclick = function(e) {
            if (e.target == document.getElementById('seedModal')) closeSeedModal();
        }

        document.getElementById('seedForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button');
            btn.disabled = true;
            btn.innerHTML = 'Saving...';

            fetch('process_raw_material.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json()).then(res => {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        alert("Error: " + res.error);
                        btn.disabled = false;
                        btn.innerText = "Save Seed";
                    }
                });
        });

        // --- CALCULATION LOGIC ---
        function calcStats(input) {
            const form = input.closest('form');
            const inQty = parseFloat(form.querySelector('input[name="input_qty"]').value) || 0;
            const oil = parseFloat(form.querySelector('input[name="oil_out"]').value) || 0;
            const cake = parseFloat(form.querySelector('input[name="cake_out"]').value) || 0;

            const loss = inQty - (oil + cake);
            const eff = inQty > 0 ? (oil / inQty) * 100 : 0;

            const lEl = form.querySelector('.loss-val');
            lEl.innerText = loss.toFixed(2) + ' Kg';
            lEl.style.color = loss < 0 ? '#dc2626' : 'inherit'; // Red if output > input

            form.querySelector('.eff-val').innerText = eff.toFixed(1) + '%';
        }

        // --- STOP & SAVE ACTIONS ---
        function stopBatch(e, form) {
            e.preventDefault();
            if (!confirm("Are you sure you want to stop this machine?")) return;
            const btn = form.querySelector('button');
            btn.innerHTML = 'Stopping...';
            btn.disabled = true;

            fetch('process_raw_material.php', {
                    method: 'POST',
                    body: new FormData(form)
                })
                .then(r => r.json()).then(res => {
                    if (res.success) window.location.reload();
                });
        }

        function saveBatch(e, form) {
            e.preventDefault();
            const lossText = form.querySelector('.loss-val').innerText;
            const loss = parseFloat(lossText);

            if (loss < -5) {
                alert("Error: Output Weight is significantly higher than Input Weight. Please verify.");
                return;
            }
            if (!confirm("Save outputs and update inventory?")) return;

            const btn = form.querySelector('button');
            btn.innerHTML = 'Saving...';
            btn.disabled = true;

            fetch('process_raw_material.php', {
                    method: 'POST',
                    body: new FormData(form)
                })
                .then(r => r.json()).then(res => {
                    if (res.success) window.location.reload();
                    else {
                        alert(res.error);
                        btn.innerHTML = 'Save Inventory';
                        btn.disabled = false;
                    }
                });
        }

        // --- DYNAMIC LOADERS & VALIDATION ---
        document.getElementById('input_qty').addEventListener('input', function() {
            const select = document.getElementById('seed_select');
            if (select.selectedIndex <= 0) return;

            const stk = parseFloat(select.selectedOptions[0].getAttribute('data-stock')) || 0;
            const val = parseFloat(this.value) || 0;

            if (val > stk) {
                document.getElementById('stock_error').style.display = 'block';
                document.getElementById('startBtn').disabled = true;
            } else {
                document.getElementById('stock_error').style.display = 'none';
                document.getElementById('startBtn').disabled = false;
            }
        });

        document.getElementById('seed_select').addEventListener('change', function() {
            const sid = this.value;
            const qtyInput = document.getElementById('input_qty');
            qtyInput.value = '';
            qtyInput.dispatchEvent(new Event('input'));

            const gSel = document.getElementById('grn_select');
            if (!sid) {
                gSel.innerHTML = '<option value="">-- Auto / Any --</option>';
                return;
            }

            gSel.innerHTML = '<option>Loading batches...</option>';
            const fd = new FormData();
            fd.append('action', 'get_grn_batches');
            fd.append('seed_id', sid);

            fetch('process_raw_material.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json()).then(d => {
                    let h = '<option value="">-- Auto / Any --</option>';
                    d.forEach(i => h += `<option value="${i.batch_no}">${i.display_text}</option>`);
                    gSel.innerHTML = h;
                });
        });

        document.getElementById('startForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!confirm("Start machine processing?")) return;
            const btn = document.getElementById('startBtn');
            btn.innerHTML = 'Starting...';
            btn.disabled = true;

            fetch('process_raw_material.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json()).then(res => {
                    if (res.success) window.location.reload();
                    else {
                        alert(res.error);
                        btn.innerHTML = 'Start Machine';
                        btn.disabled = false;
                    }
                });
        });
    </script>
</body>

</html>