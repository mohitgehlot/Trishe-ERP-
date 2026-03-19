<?php
include 'config.php'; // Include database connection

if (isset($_GET['query'])) {
    $query = $conn->real_escape_string($_GET['query']);
    $sql = "SELECT id, name, quantity, low_stock_alarm, seller_id FROM raw_materials WHERE name LIKE '%$query%' LIMIT 10";
    $result = $conn->query($sql);

    $suggestions = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $suggestions[] = $row;
        }
    }
    echo json_encode($suggestions); // Return suggestions as JSON
    exit;
}

if (isset($_GET['id'])) {
    $id = $conn->real_escape_string($_GET['id']);
    $sql = "SELECT id, name, quantity, low_stock_alarm, seller_id FROM raw_materials WHERE id = $id";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        echo json_encode($result->fetch_assoc()); // Return raw material details as JSON
    } else {
        echo json_encode([]); // Return empty array if no data found
    }
    exit;
}
?>