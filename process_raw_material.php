<?php
// process_raw_material.php - FULLY RESPONSIVE & MASTER CSS INTEGRATED
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

// B. GET GRN BATCHES
if (isset($_POST['action']) && $_POST['action'] == 'get_grn_batches') {
    ob_clean();
    header('Content-Type: application/json');
    $seed_id = (int)$_POST['seed_id'];

    $sql = "SELECT igi.id as item_id, igi.weight_kg, igi.used_weight_kg, 
                   (igi.weight_kg - igi.used_weight_kg) as available_kg, 
                   ig.grn_no, igi.created_at 
            FROM inventory_grn_items igi 
            JOIN inventory_grn ig ON igi.grn_id = ig.id 
            WHERE igi.seed_id = ? AND (igi.weight_kg - igi.used_weight_kg) > 0 
            ORDER BY igi.created_at ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $seed_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $batches = [];
    while ($row = $res->fetch_assoc()) {
        $date = date('d-M', strtotime($row['created_at']));
        $item_id = $row['item_id'];
        $avail = round($row['available_kg'], 2);

        $row['display_text'] = "Bag ID: {$item_id} (Left: {$avail} Kg) - {$date}";
        $row['batch_no'] = "ITEM-" . $item_id;
        $row['available_kg'] = $avail;
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

        // Minus from Bag logic
        if (!empty($linked_grn) && strpos($linked_grn, 'ITEM-') === 0) {
            $item_id = (int)str_replace('ITEM-', '', $linked_grn);
            if ($item_id > 0) {
                $bag_check = $conn->query("SELECT (weight_kg - used_weight_kg) as avail FROM inventory_grn_items WHERE id = $item_id")->fetch_assoc();
                if ($bag_check && $qty > $bag_check['avail']) {
                    throw new Exception("Error: This specific bag only has " . $bag_check['avail'] . " Kg left! Please reduce input quantity.");
                }

                $stmtUpdateBag = $conn->prepare("UPDATE inventory_grn_items SET used_weight_kg = used_weight_kg + ? WHERE id = ?");
                $stmtUpdateBag->bind_param("di", $qty, $item_id);
                $stmtUpdateBag->execute();
            }
        }

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
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
        }

        .action-buttons {
            display: flex;
            gap: 12px;
        }

        /* GRID LAYOUT (Adjusted for History taking less space) */
        .grid-layout {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 24px;
            align-items: start;
        }

        .left-col,
        .right-col {
            min-width: 0;
            width: 100%;
        }

        /* BATCH CARDS (Running / Pending) */
        .batch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }

        .batch-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            border-left: 4px solid var(--warning);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
        }

        .batch-card.ready {
            border-left-color: var(--success);
        }

        .batch-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .batch-info {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 0.95rem;
            color: var(--text-muted);
            border: 1px solid var(--border);
        }

        .batch-info strong {
            color: var(--text-main);
            font-size: 1rem;
        }

        .live-stats {
            display: flex;
            gap: 10px;
            background: #f0fdf4;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #bbf7d0;
            text-align: center;
            margin-bottom: 15px;
        }

        .stat-box {
            flex: 1;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #166534;
            font-weight: 700;
            text-transform: uppercase;
        }

        .stat-val {
            font-size: 1.2rem;
            font-weight: 800;
            color: #15803d;
            margin-top: 5px;
        }

        .output-grid {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        /* 🌟 FIX: Remove horizontal scroll for table with few columns */
        .table-wrap table {
            min-width: 100% !important;
        }

        /* RESPONSIVENESS */
        @media (max-width: 1024px) {
            .grid-layout {
                grid-template-columns: 1fr;
            }

            .left-col {
                order: 2;
            }

            .right-col {
                order: 1;
            }

            /* Tablet par running jobs pehle dikhenge */
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .page-header-box {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .action-buttons {
                flex-direction: column;
                width: 100%;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            .batch-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-industry text-primary" style="margin-right:10px;"></i> Production Floor</h1>
            <div class="action-buttons">
                <button onclick="openStartModal()" class="btn btn-primary" style="background: var(--warning); border-color: var(--warning); color: #fff;">
                    <i class="fas fa-play-circle"></i> Start Batch
                </button>
                <button onclick="openSeedModal()" class="btn btn-primary"><i class="fas fa-seedling"></i> Add Seed</button>
                <a href="grn.php" class="btn btn-outline"><i class="fas fa-truck-loading"></i> Add GRN</a>
            </div>
        </div>

        <div class="grid-layout">

            <div class="left-col">
                <div class="card">
                    <div class="card-header"><i class="fas fa-history text-muted"></i> Recent History</div>
                    <div class="table-wrap" style="border:none; border-radius:0; box-shadow:none; max-height:400px;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Batch Info</th>
                                    <th>Input</th>
                                    <th style="text-align:right;">Output</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($process_history)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center; color:var(--text-muted); padding:30px;">No history available</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($process_history as $h): ?>
                                    <tr>
                                        <td>
                                            <strong style="color:var(--primary); font-size:0.95rem;"><?= $h['batch_no'] ?></strong><br>
                                            <small style="color:var(--text-main); font-weight:600;"><?= $h['seed_name'] ?></small>
                                        </td>
                                        <td style="font-weight:600;"><?= number_format($h['seed_qty'], 2) ?> Kg</td>
                                        <td style="text-align:right;">
                                            <span style="color:var(--success); font-weight:700;" title="Oil"><?= number_format($h['oil_out'], 2) ?></span> /
                                            <span style="color:var(--warning); font-weight:700;" title="Cake"><?= number_format($h['cake_out'], 2) ?></span><br>
                                            <small style="color:var(--danger); font-weight:600;">Loss: <?= number_format($h['loss_kg'], 2) ?></small>
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
                        <div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--text-muted); border:2px dashed var(--border); border-radius:8px; background:white;">
                            <i class="fas fa-bed fa-2x" style="margin-bottom:15px; opacity:0.5;"></i><br>
                            No machines currently running.
                        </div>
                    <?php else: ?>
                        <?php foreach ($running_processes as $run): ?>
                            <div class="batch-card">
                                <div class="batch-header">
                                    <span><?= $run['batch_no'] ?></span>
                                    <span class="badge st-processing">Running</span>
                                </div>
                                <div class="batch-info">
                                    <div style="margin-bottom:8px;"><i class="fas fa-seedling text-success" style="width:20px;"></i> <strong><?= $run['seed_name'] ?></strong> (<?= $run['seed_qty'] ?> Kg)</div>
                                    <div style="margin-bottom:8px;"><i class="fas fa-cog text-muted" style="width:20px;"></i> <?= $run['machine_name'] ?></div>
                                    <div><i class="fas fa-clock text-muted" style="width:20px;"></i> Started: <?= convertToIST($run['start_time']) ?></div>
                                </div>
                                <form onsubmit="return stopBatch(event, this)">
                                    <input type="hidden" name="action" value="end_process">
                                    <input type="hidden" name="end_process" value="1">
                                    <input type="hidden" name="batch_no" value="<?= $run['batch_no'] ?>">
                                    <button class="btn btn-outline" style="width:100%; border-color:var(--warning); color:var(--warning);">
                                        <i class="fas fa-hand-paper"></i> Stop Machine
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($complete_processes)): ?>
                    <h3 style="font-size:1.2rem; margin:30px 0 20px 0; color:var(--success);"><i class="fas fa-balance-scale"></i> Pending Weighing</h3>

                    <div class="batch-grid">
                        <?php foreach ($complete_processes as $comp): ?>
                            <div class="batch-card ready">
                                <div class="batch-header">
                                    <span><?= $comp['batch_no'] ?></span>
                                    <span class="badge st-pending">Stopped</span>
                                </div>
                                <div class="batch-info" style="text-align:center;">
                                    Input: <strong style="font-size:1.3rem; color:var(--text-main);"><?= $comp['seed_qty'] ?> Kg</strong> <br>
                                    <small><?= $comp['seed_name'] ?></small>
                                </div>
                                <form class="saveForm" onsubmit="return saveBatch(event, this)">
                                    <input type="hidden" name="action" value="save-process">
                                    <input type="hidden" name="save-process" value="1">
                                    <input type="hidden" name="batch_no" value="<?= $comp['batch_no'] ?>">
                                    <input type="hidden" name="input_qty" value="<?= $comp['seed_qty'] ?>">

                                    <div class="output-grid">
                                        <div style="flex:1;">
                                            <label class="form-label" style="color:var(--success);">Oil (Kg)</label>
                                            <input type="number" name="oil_out" class="form-input" step="0.01" required oninput="calcStats(this)" placeholder="0.00">
                                        </div>
                                        <div style="flex:1;">
                                            <label class="form-label" style="color:var(--warning);">Cake (Kg)</label>
                                            <input type="number" name="cake_out" class="form-input" step="0.01" required oninput="calcStats(this)" placeholder="0.00">
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

    

    <div id="seedModal" class="global-modal">
        <div class="g-modal-content" style="max-width:400px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.2rem; color:var(--text-main);"><i class="fas fa-seedling text-primary" style="margin-right:8px;"></i> Add New Seed</h3>
                <span class="g-close-btn" onclick="closeSeedModal()">&times;</span>
            </div>
            <div class="g-modal-body">
                <form id="seedForm">
                    <input type="hidden" name="action" value="add_seed">
                    <div class="form-group">
                        <label class="form-label">Seed Name</label>
                        <input type="text" name="s_name" class="form-input" placeholder="e.g. Yellow Mustard" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="s_category" class="form-input" required>
                            <option value="oilseed">Oilseed</option>
                            <option value="cereal">Cereal</option>
                            <option value="pulse">Pulse</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Opening Stock (Kg)</label>
                        <input type="number" name="s_stock" class="form-input" step="0.01" value="0">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:20px; padding:12px;">Save Seed</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // --- MODAL LOGIC ---
        function openSeedModal() {
            document.getElementById('seedModal').classList.add('active');
        }

        function closeSeedModal() {
            document.getElementById('seedModal').classList.remove('active');
        }

        function openStartModal() {
            document.getElementById('globalStartModal').classList.add('active');
        }

        function closeStartModal() {
            document.getElementById('startModal').classList.remove('active');
        }

        window.onclick = function(e) {
            if (e.target == document.getElementById('seedModal')) closeSeedModal();
            if (e.target == document.getElementById('startModal')) closeStartModal();
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
                        btn.innerHTML = '<i class="fas fa-save"></i> Save Inventory';
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
                        btn.innerHTML = '<i class="fas fa-power-off"></i> Start Machine';
                        btn.disabled = false;
                    }
                });
        });
    </script>
</body>

</html>