<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'store.php';
include 'encryption.php';

// Check if POST request is received
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if required fields are set
    if (!isset($_POST['card_number'], $_POST['card_holder'], $_POST['expiry_month'], $_POST['expiry_year'],$_POST['cvv'])) {
        die("Error: Missing required fields");
    }

    // Encrypt data
    $card_number = encryptData($_POST['card_number']);
    $card_holder = encryptData($_POST['card_holder']);
    $expiry_month = encryptData($_POST['expiry_month']);
    $expiry_year = encryptData($_POST['expiry_year']);
    $cvv = encryptData($_POST['cvv']);

    // Prepare SQL statement
    $sql = "INSERT INTO credit_cards (card_number, card_holder, expiry_month, expiry_year,cvv) VALUES (?, ?, ?, ?,?)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Error in preparing statement: " . $conn->error);
    }

    $stmt->bind_param("ssss", $card_number, $card_holder, $expiry_month ,$expiry_year, $cvv);

    // Execute and check for errors
    if ($stmt->execute()) {
        echo "<script>alert('Card stored successfully!'); window.location.href='index.html';</script>";
    } else {
        die("Error executing statement: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();
}
?>
