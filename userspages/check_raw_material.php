<?php
include 'config.php'; // Include database connection

if (isset($_GET['name'])) {
   $name = $_GET['name'];

   $stmt = $conn->prepare("SELECT id FROM raw_materials WHERE name = ?");
   $stmt->bind_param("s", $name);
   $stmt->execute();
   $result = $stmt->get_result();

   echo json_encode(['exists' => $result->num_rows > 0]);
}
?>