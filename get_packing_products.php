<?php
include 'config.php';
header('Content-Type: application/json');

if (isset($_GET['oil_id'])) {
    $oil_id = (int)$_GET['oil_id'];
    $stmt = $conn->prepare("SELECT * FROM packing_products WHERE base_product_id = ?");
    $stmt->bind_param('i', $oil_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    echo json_encode($products);
} else {
    echo json_encode([]);
}
?>