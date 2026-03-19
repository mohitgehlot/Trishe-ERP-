<?php
// grn_handler.php - FINAL MERGED VERSION (Inventory Fix + History Logic)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';

// JSON Only
ini_set('display_errors', '0'); 
error_reporting(E_ALL); 
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'seller_search':
            handleSellerSearch();
            break;
        case 'create_grn':
            handleCreateGRN();
            break;
        case 'get_grn_details':
            handleGetGRNDetails();
            break;
        case 'get_seller_history': // <--- YE MISSING THA, AB ADD KAR DIYA HE
            handleGetSellerHistory();
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    error_log("GRN Error: " . $e->getMessage());
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

// ---------------- Handlers ----------------

function handleSellerSearch() {
    global $conn;
    $q = trim($_POST['q'] ?? '');
    if (strlen($q) < 3) { echo json_encode([]); return; }

    $searchTerm = "%$q%";
    $stmt = $conn->prepare("SELECT id, name, address, phone ,category FROM sellers WHERE category = 'Seeds' AND (name LIKE ? OR phone LIKE ?) LIMIT 10");
    $stmt->bind_param("ss", $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sellers = [];
    while ($row = $result->fetch_assoc()) { $sellers[] = $row; }
    echo json_encode($sellers);
}

function handleGetSellerHistory() {
    global $conn;
    $sellerId = (int)$_POST['seller_id'];

    // 1. Get Stats (Total GRNs & Total Value)
    $stmtStats = $conn->prepare("SELECT COUNT(id) as total_count, SUM(total_value) as total_spent FROM inventory_grn WHERE seller_id = ?");
    $stmtStats->bind_param("i", $sellerId);
    $stmtStats->execute();
    $stats = $stmtStats->get_result()->fetch_assoc();

    // 2. Get Last 10 Transactions
    $stmtList = $conn->prepare("
        SELECT id, grn_no, total_weight_kg, total_value, created_at 
        FROM inventory_grn 
        WHERE seller_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmtList->bind_param("i", $sellerId);
    $stmtList->execute();
    $result = $stmtList->get_result();
    
    $history = [];
    while($row = $result->fetch_assoc()) {
        $row['formatted_date'] = date('d M Y', strtotime($row['created_at']));
        $history[] = $row;
    }

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'history' => $history
    ]);
}

function handleCreateGRN() {
    global $conn;

    // 1. Inputs
    $sellerName = trim($_POST['seller_name'] ?? '');
    $vehicleNo  = trim($_POST['vehicle_no'] ?? '');
    $itemsJson  = $_POST['items_json'] ?? '[]';
    
    $payMode    = $_POST['payment_mode'] ?? 'Pending';
    $payDate    = $_POST['payment_date'] ?? date('Y-m-d');
    $payRef     = $_POST['payment_ref'] ?? '';
    $payRemarks = $_POST['payment_remarks'] ?? '';

    if ($sellerName === '' || $vehicleNo === '') {
        throw new Exception('Seller name and vehicle number required');
    }

    $items = json_decode($itemsJson, true);
    if (empty($items)) throw new Exception('No items added');

    $conn->begin_transaction();

    try {
        // 2. Seller Logic (Auto Create or Select)
        $sellerId = null;
        $sellerPhone = trim($_POST['phone'] ?? '');
        $sellerAddress = trim($_POST['seller_address'] ?? '');

        // Check if seller exists by Name
        $stmt = $conn->prepare("SELECT id FROM sellers WHERE name = ? LIMIT 1");
        $stmt->bind_param("s", $sellerName);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $sellerId = (int)$row['id'];
            // Update details if provided
            if($sellerAddress || $sellerPhone) {
                $stmtUpd = $conn->prepare("UPDATE sellers SET address = ?, phone = ? WHERE id = ?");
                $stmtUpd->bind_param("ssi", $sellerAddress, $sellerPhone, $sellerId);
                $stmtUpd->execute();
            }
        } else {
            // New Seller Insert
            $stmt = $conn->prepare("INSERT INTO sellers (name, address, phone, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("sss", $sellerName, $sellerAddress, $sellerPhone);
            $stmt->execute();
            $sellerId = (int)$conn->insert_id;
        }

        // 3. Totals Calculation
        $totalWeight = 0.0;
        $totalValue  = 0.0;
        foreach ($items as $item) {
            $totalWeight += (float)$item['weight_kg'];
            $lineVal = ((float)$item['weight_kg'] / 100) * (float)$item['price_per_qtl'];
            $totalValue += $lineVal;
        }

        // 4. Create GRN Header
        $grnNo = 'GRN-' . date('Ymd') . '-' . rand(1000, 9999);
        $adminId = $_SESSION['admin_id'];

        $stmt = $conn->prepare("INSERT INTO inventory_grn (grn_no, seller_id, vehicle_no, total_weight_kg, total_value, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sisddi", $grnNo, $sellerId, $vehicleNo, $totalWeight, $totalValue, $adminId);
        $stmt->execute();
        $grnId = (int)$conn->insert_id;

        // --- PREPARED STATEMENTS FOR ITEMS ---

        // A. inventory_grn_items (Details Table)
        $stmtItem = $conn->prepare("INSERT INTO inventory_grn_items (grn_id, seed_id, price_per_qtl, weight_kg, line_value, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        
        // B. inventory (Main Stock Ledger - Table Name FIXED)
        $stmtInv = $conn->prepare("
            INSERT INTO inventory 
            (seed_id, product_type, transaction_type, batch_no, quantity, unit, price_per_unit, total_value, source_type, reference_id, created_by, transaction_date, quality_grade, bags_count) 
            VALUES 
            (?, 'SEED', 'GRN_IN', ?, ?, 'KG', ?, ?, 'GRN', ?, ?, NOW(), ?, ?)
        ");

        // C. seeds_master (Current Stock Update)
        $stmtMaster = $conn->prepare("UPDATE seeds_master SET current_stock = current_stock + ? WHERE id = ?");

        foreach ($items as $item) {
            $seedId = (int)$item['seed_id'];
            $priceQtl = (float)$item['price_per_qtl'];
            $weight = (float)$item['weight_kg'];
            $lineVal = ($weight / 100) * $priceQtl;
            $pricePerKg = $weight > 0 ? ($lineVal / $weight) : 0;
            
            $quality = $item['quality'] ?? 'A';
            $bags = (int)($item['bags'] ?? 0);

            // Execute A
            $stmtItem->bind_param("iiddd", $grnId, $seedId, $priceQtl, $weight, $lineVal);
            $stmtItem->execute();

            // Execute B (Insert into inventory)
            $stmtInv->bind_param("isdddiisi", $seedId, $grnNo, $weight, $pricePerKg, $lineVal, $grnId, $adminId, $quality, $bags);
            $stmtInv->execute();

            // Execute C
            $stmtMaster->bind_param("di", $weight, $seedId);
            $stmtMaster->execute();
        }

        // 5. Expense Entry
        $expStatus = ($payMode === 'Pending' || $payMode === 'Credit') ? 'Pending' : 'Paid';
        $desc = "GRN #$grnNo - $sellerName ($vehicleNo)";
        
        $stmtExp = $conn->prepare("INSERT INTO factory_expenses (date, category, vendor_id, amount, status, payment_mode, description, grn_id) VALUES (?, 'Raw Material', ?, ?, ?, ?, ?, ?)");
        $stmtExp->bind_param("sidsssi", $payDate, $sellerId, $totalValue, $expStatus, $payMode, $desc, $grnId);
        $stmtExp->execute();

        $conn->commit();

        echo json_encode([
            'success' => true,
            'grn_no' => $grnNo,
            'message' => 'GRN created successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

function handleGetGRNDetails() {
    global $conn;
    $grnId = (int)$_POST['grn_id'];
    
    $stmt = $conn->prepare("SELECT ig.*, s.name as seller_name FROM inventory_grn ig JOIN sellers s ON ig.seller_id = s.id WHERE ig.id = ?");
    $stmt->bind_param("i", $grnId);
    $stmt->execute();
    $header = $stmt->get_result()->fetch_assoc();

    if(!$header) throw new Exception("GRN Not Found");

    $stmt = $conn->prepare("SELECT igi.*, s.name as seed_name FROM inventory_grn_items igi JOIN seeds_master s ON igi.seed_id = s.id WHERE igi.grn_id = ?");
    $stmt->bind_param("i", $grnId);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $items = [];
    while($row = $res->fetch_assoc()) { $items[] = $row; }

    echo json_encode(['success' => true, 'grn' => $header, 'items' => $items]);
}
?>