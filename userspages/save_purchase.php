<?php
include 'config.php';

$rawMaterialId = $_POST['raw_material'];
$sellerName = $_POST['seller_name'];
$contactDetails = $_POST['contact_details'];
$quantity = $_POST['quantity'];
$purchaseDate = $_POST['purchase_date'];

// Check if seller exists
$stmt = $conn->prepare("SELECT id FROM sellers WHERE name = ?");
$stmt->bind_param("s", $sellerName);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $sellerId = $row['id'];
} else {
    // Insert new seller
    $stmt = $conn->prepare("INSERT INTO sellers (name, contact_details) VALUES (?, ?)");
    $stmt->bind_param("ss", $sellerName, $contactDetails);
    $stmt->execute();
    $sellerId = $stmt->insert_id;
}

// Save purchase details
$stmt = $conn->prepare("INSERT INTO purchases (raw_material_id, seller_id, quantity, purchase_date) VALUES (?, ?, ?, ?)");
$stmt->bind_param("iiis", $rawMaterialId, $sellerId, $quantity, $purchaseDate);
$stmt->execute();

echo "Purchase recorded successfully!";
$stmt->close();
$conn->close();
?>