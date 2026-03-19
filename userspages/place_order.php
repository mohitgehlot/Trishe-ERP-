<?php
include 'config.php';
session_start();

// Get order data from the form
$items = json_decode($_POST['items'], true);
$paymentMethod = $_POST['payment_method'];
$customerName = $_POST['customer_name'] ?? 'Guest';

// Insert the order into the database
$conn->query("INSERT INTO orders (total_price, payment_method, customer_name) VALUES (0, '$paymentMethod', '$customerName')");
$orderId = $conn->insert_id; // Get the ID of the newly created order
$totalPrice = 0;

// Process each item in the order
foreach ($items as $item) {
    $productId = $item['id'];
    $quantity = $item['quantity'];

    // Fetch product details
    $priceResult = $conn->query("SELECT price, Stock FROM products WHERE id = $productId");
    $priceRow = $priceResult->fetch_assoc();

    // Check if there is enough stock
    if ($priceRow['Stock'] < $quantity) {
        echo "Not enough stock for " . $item['name'];
        exit();
    }

    // Calculate subtotal
    $itemPrice = $priceRow['price'] * $quantity;

    // Insert order item into the database
    $conn->query("INSERT INTO order_items (order_id, product_id, quantity, subtotal) VALUES ($orderId, $productId, $quantity, $itemPrice)");
    $totalPrice += $itemPrice;

    // Update product stock
    $newStock = $priceRow['Stock'] - $quantity;
    $conn->query("UPDATE products SET Stock = $newStock WHERE id = $productId");
}

// Update the total price of the order
$conn->query("UPDATE orders SET total_price = $totalPrice WHERE id = $orderId");

// Return success message
echo "Order placed successfully! Total: €$totalPrice";

$conn->close();
?>