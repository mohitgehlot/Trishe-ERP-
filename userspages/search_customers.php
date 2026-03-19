<?php
include 'config.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

$query = $_GET['q'] ?? '';

if (strlen($query) < 1) {
    echo json_encode([]);
    exit;
}

// Search for customers by name or phone
$search_query = "%$query%";
$stmt = $conn->prepare("SELECT id, name, phone, email FROM customers 
                       WHERE name LIKE ? OR phone LIKE ? 
                       ORDER BY name LIMIT 10");
$stmt->bind_param("ss", $search_query, $search_query);
$stmt->execute();
$result = $stmt->get_result();

$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}

echo json_encode($customers);
$stmt->close();
$conn->close();
?>