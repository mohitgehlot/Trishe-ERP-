<?php
include 'config.php';

$term = $_GET['term'] ?? '';
$term = trim($term);
$suggestions = [];

if ($term !== '') {
    $termEscaped = mysqli_real_escape_string($conn, $term);
    $sql = "SELECT name FROM products WHERE name LIKE '%$termEscaped%' LIMIT 10";
    $result = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($result)) {
        $suggestions[] = $row['name'];
    }
}

header('Content-Type: application/json');
echo json_encode($suggestions);
?>
