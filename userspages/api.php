<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "inventory_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Add a new product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $quantity = $_POST['quantity'];
    $price = $_POST['price'];
    $low_stock_threshold = $_POST['low_stock_threshold'];

    $sql = "INSERT INTO products (name, description, quantity, price, low_stock_threshold) VALUES ('$name', '$description', $quantity, $price, $low_stock_threshold)";
    if ($conn->query($sql)) {
        echo "Product added successfully!";
    } else {
        echo "Failed to add product.";
    }
}

// Update product quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quantity'])) {
    $id = $_POST['id'];
    $quantity = $_POST['quantity'];

    $sql = "UPDATE products SET quantity = $quantity WHERE id = $id";
    if ($conn->query($sql)) {
        echo "Product quantity updated successfully!";
    } else {
        echo "Failed to update product quantity.";
    }
}

// Update low stock threshold
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_threshold'])) {
    $id = $_POST['id'];
    $low_stock_threshold = $_POST['low_stock_threshold'];

    $sql = "UPDATE products SET low_stock_threshold = $low_stock_threshold WHERE id = $id";
    if ($conn->query($sql)) {
        echo "Low stock threshold updated successfully!";
    } else {
        echo "Failed to update low stock threshold.";
    }
}

// Delete a product
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];

    $sql = "DELETE FROM products WHERE id = $id";
    if ($conn->query($sql)) {
        echo "Product deleted successfully!";
    } else {
        echo "Failed to delete product.";
    }
}

// Fetch all products
$sql = "SELECT * FROM products";
$result = $conn->query($sql);
$products = [];

while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

$conn->close();
?>