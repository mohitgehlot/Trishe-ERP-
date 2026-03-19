<?php
include 'config.php'; // Include database connection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $name = $_POST['name'];
   $quantity = $_POST['quantity'];
   $low_stock_alarm = $_POST['low_stock_alarm'];
   $seller_id = $_POST['seller_id'];

   // Validate inputs
   if (empty($name) || empty($quantity) || empty($low_stock_alarm) || empty($seller_id)) {
      echo json_encode(['success' => false, 'message' => 'All fields are required.']);
      exit();
   }

   // Insert into database
   $stmt = $conn->prepare("INSERT INTO raw_materials (name, quantity, low_stock_alarm, added_on, seller_id) VALUES (?, ?, ?, NOW(), ?)");
   $stmt->bind_param("sddi", $name, $quantity, $low_stock_alarm, $seller_id);

   if ($stmt->execute()) {
      echo json_encode(['success' => true]);
   } else {
      echo json_encode(['success' => false, 'message' => 'Failed to add raw material.']);
   }

   $stmt->close();
} else {
   echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>