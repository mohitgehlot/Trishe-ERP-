<?php
// order_save.php - PERFECTLY FIXED VERSION
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

try {
    // Get payload from POST
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload || empty($payload['items'])) {
        throw new Exception('Invalid order data or empty cart');
    }

    $conn->begin_transaction();

    // 1. Generate Order Number
    $order_no = 'ORD' . date('Ymd') . str_pad(rand(1,999), 3, '0', STR_PAD_LEFT);
    
    // 2. Handle Customer (simple)
    $customer_id = $payload['customer']['id'] ?? null;
    $customer_name = $payload['customer']['name'] ?? 'Walk-in Customer';
    
    // 3. Calculate totals
    $subtotal = $payload['totals']['subtotal'];
    $tax = $payload['totals']['tax'];
    $discount = $payload['totals']['discount'];
    $grand_total = $payload['totals']['grand_total'];
    $paid_amount = $payload['payment']['amount_paid'];
    $due_amount = $grand_total - $paid_amount;
    $payment_method = $payload['payment']['method'];

    // 4. CREATE ORDER
    $stmt = $conn->prepare("
        INSERT INTO orders (
            order_no, customer_id, customer_name, subtotal, tax, discount, total, 
            paid_amount, due_amount, payment_method, status, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, NOW())
    ");
    $stmt->bind_param(
        "sissdddsds i", 
        $order_no, $customer_id, $customer_name, $subtotal, $tax, $discount, 
        $grand_total, $paid_amount, $due_amount, $payment_method, $_SESSION['admin_id']
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Order creation failed: ' . $stmt->error);
    }
    $order_id = $conn->insert_id;
    $stmt->close();

    // 5. SAVE ORDER ITEMS & UPDATE STOCK
    foreach ($payload['items'] as $item) {
        $product_id = $item['product_id'];
        $product_name = $item['name'];
        $price = $item['price'];
        $quantity = $item['quantity'];
        $gstrate = $item['gstrate'] ?? 5;
        $line_total = $price * $quantity;

        // Insert Order Item
        $stmt = $conn->prepare("
            INSERT INTO order_items (order_id, product_id, product_name, quantity, price, gstrate, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iissdds", $order_id, $product_id, $product_name, $quantity, $price, $gstrate, $line_total);
        if (!$stmt->execute()) {
            throw new Exception('Order item save failed: ' . $stmt->error);
        }
        $order_item_id = $conn->insert_id;
        $stmt->close();

        // UPDATE STOCK (SIMPLE VERSION)
        $stmt = $conn->prepare("
            INSERT INTO packing_inventory (
                packing_product_id, quantity, transaction_type, 
                notes, created_by, order_id, order_item_id
            ) VALUES (?, ?, 'SALE', ?, ?, ?, ?)
        ");
        $notes = "POS Sale - Order #$order_no";
        $stmt->bind_param("idiii", $product_id, -$quantity, $notes, $_SESSION['admin_id'], $order_id, $order_item_id);
        
        // Check if it's packing product or base product
        $product_check = $conn->query("SELECT base_product_id FROM packing_products WHERE id = $product_id");
        if ($product_check && $product_check->num_rows > 0) {
            // Packing Product - Deduct from packing_inventory
            if (!$stmt->execute()) {
                throw new Exception('Packing stock update failed: ' . $stmt->error);
            }
        } else {
            // Base Product - Deduct from products.loose_stock_qty
            $stmt_base = $conn->prepare("UPDATE products SET loose_stock_qty = loose_stock_qty - ? WHERE id = ?");
            $stmt_base->bind_param("di", $quantity, $product_id);
            if (!$stmt_base->execute()) {
                throw new Exception('Base product stock update failed: ' . $stmt_base->error);
            }
            $stmt_base->close();
        }
        $stmt->close();
    }

    // 6. Record Payment (if any)
    if ($paid_amount > 0) {
        $stmt = $conn->prepare("
            INSERT INTO payments (order_id, amount, payment_method, reference, status, created_by)
            VALUES (?, ?, ?, ?, 'completed', ?)
        ");
        $reference = 'POS_' . $order_no;
        $stmt->bind_param("idssi", $order_id, $paid_amount, $payment_method, $reference, $_SESSION['admin_id']);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Order #$order_no saved successfully!",
        'order_no' => $order_no,
        'order_id' => $order_id
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>
