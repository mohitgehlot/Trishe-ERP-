<?php
// sales_entry.php - DOUBLE COUNTING BUG FIXED (REMOVED STEP 6)
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

include 'config.php';
session_start();

ob_clean(); // Buffer clear karein
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized Access']);
    exit;
}

try {
    $action = $_POST['action'] ?? '';

    // --- 1. SEARCH CUSTOMER ---
    if ($action == 'search_customer') {
        $term = $_POST['term'] . '%';
        $stmt = $conn->prepare("SELECT id, name, phone FROM customers WHERE phone LIKE ? OR name LIKE ? LIMIT 5");
        if(!$stmt) throw new Exception("Search Query Failed: " . $conn->error);
        
        $stmt->bind_param("ss", $term, $term);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $data = [];
        while($r = $res->fetch_assoc()) {
            $data[] = [
                'id' => $r['id'],
                'label' => $r['name'] . ' (' . $r['phone'] . ')',
                'value' => $r['name'],
                'phone' => $r['phone']
            ];
        }
        echo json_encode($data);
        exit;
    }

    // --- 2. SAVE FINAL SALE ---
    elseif ($action == 'save_sale') {
        $cust_id = intval($_POST['customer_id'] ?? 0);
        $cust_name = trim($_POST['customer_name'] ?? 'Walk-in Customer');
        $cust_phone = trim($_POST['customer_phone'] ?? '');
        $pay_mode = trim($_POST['payment_mode'] ?? 'cash'); 
        $discount = floatval($_POST['discount'] ?? 0);
        $cart = json_decode($_POST['cart'] ?? '[]', true);
        $admin_id = $_SESSION['admin_id'];

        if (empty($cart)) throw new Exception("Cart is empty");

        $conn->begin_transaction();

        // 🌟 1. Customer Logic (Auto-Save Walk-in Customer) 🌟
        if ($cust_id == 0) {
            // Agar phone number dala hai to check karo database me hai kya
            if (!empty($cust_phone)) {
                $check = $conn->query("SELECT id FROM customers WHERE phone = '$cust_phone' LIMIT 1");
                if ($check && $check->num_rows > 0) {
                    $row = $check->fetch_assoc();
                    $cust_id = $row['id'];
                }
            }
            
            // Agar purana customer nahi mila, to NAYA CREATE karo
            if ($cust_id == 0 && strtolower($cust_name) !== 'walk-in customer') {
                $stmtNew = $conn->prepare("INSERT INTO customers (name, phone, created_at) VALUES (?, ?, NOW())");
                $stmtNew->bind_param("ss", $cust_name, $cust_phone);
                if ($stmtNew->execute()) {
                    $cust_id = $stmtNew->insert_id; // Nayi ID store karo
                }
            }
        }
        
        $sql_cust_id = ($cust_id > 0) ? $cust_id : NULL;

        // 2. Order Totals Calculate Karna
        $subtotal = 0;
        $total_tax = 0;
        foreach ($cart as $item) {
            $itemTotal = $item['price'] * $item['qty'];
            $basePrice = $itemTotal / (1 + ($item['gstrate'] / 100));
            $subtotal += $basePrice;
            $total_tax += ($itemTotal - $basePrice);
        }
        $final_amount = ($subtotal + $total_tax) - $discount;

        // 3. Unique Order Number & Enums
        $order_no = 'ORD-' . time() . rand(10, 99); 
        $db_status = 'Delivered'; 

        // 4. Payment Logic
        $payment_status = 'Paid';
        $paid_amount = $final_amount;

        if (strtolower($pay_mode) === 'credit' || strtolower($pay_mode) === 'due') {
            $payment_status = 'Pending';
            $paid_amount = 0;
            $pay_mode = 'Credit';
        }

        $stmtOrd = $conn->prepare("INSERT INTO orders (order_no, customer_id, subtotal, tax, discount, total, paid_amount, payment_status, payment_method, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        if(!$stmtOrd) throw new Exception("Order Table Error: " . $conn->error); 
        
        $stmtOrd->bind_param("sidddddsssi", $order_no, $sql_cust_id, $subtotal, $total_tax, $discount, $final_amount, $paid_amount, $payment_status, $pay_mode, $db_status, $admin_id);
        if (!$stmtOrd->execute()) throw new Exception("Order Save Failed: " . $stmtOrd->error);
        
        $order_id = $stmtOrd->insert_id;

        // 5. Order Items & Inventory
        $checkInv = $conn->query("SHOW COLUMNS FROM inventory_products LIKE 'customer_name'");
        $hasCustCol = ($checkInv && $checkInv->num_rows > 0);

        $stmtItem = $conn->prepare("INSERT INTO order_items (order_id, product_id, batch_no, qty, unit, price_snapshot, cost_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        if($hasCustCol) {
            $stmtInv = $conn->prepare("INSERT INTO inventory_products (product_id, batch_no, qty, unit, transaction_type, sale_price, customer_name, mfg_date) VALUES (?, 'SALE', ?, 'Pcs', 'SALE', ?, ?, NOW())");
        } else {
            $stmtInv = $conn->prepare("INSERT INTO inventory_products (product_id, batch_no, qty, unit, transaction_type) VALUES (?, 'SALE', ?, 'Pcs', 'SALE')");
        }

        foreach ($cart as $item) {
            $pid = intval($item['id']);
            $qty = floatval($item['qty']);
            $price = floatval($item['price']);
            $line_total = $qty * $price;
            
            $p_res = $conn->query("SELECT cost_price, unit FROM products WHERE id = $pid");
            $p_info = $p_res ? $p_res->fetch_assoc() : [];
            $cost_now = floatval($p_info['cost_price'] ?? 0);
            $unit_now = $p_info['unit'] ?? 'Pcs';

            $batch_sale = 'SALE';
            $stmtItem->bind_param("iissdssd", $order_id, $pid, $batch_sale, $qty, $unit_now, $price, $cost_now, $line_total);
            $stmtItem->execute();

            $inv_qty = -$qty;
            if($hasCustCol) {
                $stmtInv->bind_param("idds", $pid, $inv_qty, $price, $cust_name);
            } else {
                $stmtInv->bind_param("id", $pid, $inv_qty);
            }
            $stmtInv->execute();
        }

        // 🌟 STEP 6 REMOVED: No more direct update to customers.total_due for Credit orders.
        // It prevents the double counting bug entirely.

        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Sale Saved!', 
            'order_id' => $order_id, 
            'date' => date('d-M-Y h:i A'),
            'customer' => $cust_name,
            'items' => $cart,
            'totals' => ['sub' => $subtotal, 'disc' => $discount, 'tax' => $total_tax, 'grand' => $final_amount]
        ]);
    }
    
   // --- 3. HOLD SALE (Save Cart to DB) ---
    elseif ($action == 'hold_sale') {
        $cart_json = $_POST['cart']; // JSON string from JS
        $note = trim($_POST['note']);
        
        $stmt = $conn->prepare("INSERT INTO sales_holds (cart_data, note) VALUES (?, ?)");
        $stmt->bind_param("ss", $cart_json, $note);
        
        if($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Transaction Held!']);
        } else {
            throw new Exception("Failed to hold: " . $stmt->error);
        }
    }

    // --- 4. GET HELD LIST (Show in Modal) ---
    elseif ($action == 'get_holds') {
        $res = $conn->query("SELECT id, note, created_at FROM sales_holds ORDER BY id DESC");
        $holds = [];
        if($res) while($r = $res->fetch_assoc()) {
            $r['created_at'] = date('d M, h:i A', strtotime($r['created_at']));
            $holds[] = $r;
        }
        echo json_encode(['success' => true, 'data' => $holds]);
    }

    // --- 5. RECALL HOLD (Restore Cart) ---
    elseif ($action == 'recall_hold') {
        $id = intval($_POST['id']);
        
        $res = $conn->query("SELECT cart_data FROM sales_holds WHERE id = $id");
        if($res && $row = $res->fetch_assoc()) {
            $conn->query("DELETE FROM sales_holds WHERE id = $id");
            echo json_encode(['success' => true, 'cart' => json_decode($row['cart_data'], true)]);
        } else {
            throw new Exception("Hold record not found.");
        }
    }

} catch (Exception $e) {
    if(isset($conn)) $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>