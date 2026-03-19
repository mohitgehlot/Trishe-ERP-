<?php
include 'config.php';
header('Content-Type: application/json');

$category_id = (int)($_GET['category_id'] ?? 0);
$subcategories = [];

if ($category_id > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM sub_categories WHERE category_id = ? ORDER BY name");
    $stmt->bind_param('i', $category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $subcategories[] = $row;
    }
}

echo json_encode($subcategories);
?>