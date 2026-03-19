<?php
include 'config.php';
$term = $_GET['term'] ?? '';

$sql = "SELECT o.id, o.order_no, o.total, o.created_at, COALESCE(c.name, 'Walk-in') as customer_name 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        WHERE o.order_no LIKE ? OR c.name LIKE ? 
        LIMIT 10";

$stmt = $conn->prepare($sql);
$search = "%$term%";
$stmt->bind_param("ss", $search, $search);
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
while($row = $result->fetch_assoc()) {
    $orders[] = $row;
}
echo json_encode($orders);
?>