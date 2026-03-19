<?php
include 'config.php';
header('Content-Type: application/json');

$result = $conn->query("SELECT id, name FROM categories ORDER BY name");
$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}
echo json_encode($categories);
?>