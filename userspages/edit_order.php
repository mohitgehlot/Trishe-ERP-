<?php
// Start the session
session_start();

// Include the database connection file
include 'config.php';

// Check if the edit parameter is set in the URL
if (isset($_GET['edit'])) {
    $order_id = $_GET['edit'];

    // Fetch the order details from the database
    $select_order = mysqli_query($conn, "SELECT * FROM `orders` WHERE order_id = '$order_id'") or die('Query failed');

    if (mysqli_num_rows($select_order) > 0) {
        $fetch_order = mysqli_fetch_assoc($select_order);
    } else {
        // Redirect if the order is not found
        header('Location: orders.php');
        exit();
    }
} else {
    // Redirect if no edit parameter is set
    header('Location: orders.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $total_amount = mysqli_real_escape_string($conn, $_POST['total_amount']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $payment_status = mysqli_real_escape_string($conn, $_POST['payment_status']);
    $order_date = mysqli_real_escape_string($conn, $_POST['order_date']);

    // Update the order in the database
    $update_order = mysqli_query($conn, "UPDATE `orders` SET 
        name = '$name', 
        address = '$address', 
        total_amount = '$total_amount', 
        payment_method = '$payment_method', 
        payment_status = '$payment_status', 
        order_date = '$order_date' 
        WHERE order_id = '$order_id'") or die('Query failed');

    if ($update_order) {
        $_SESSION['message'] = 'Order updated successfully!';
        header('Location: orders.php');
        exit();
    } else {
        $_SESSION['message'] = 'Failed to update order.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Order</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <h1>Edit Order</h1>
        <form action="" method="post">
            <div class="form-group">
                <label for="name">User Name:</label>
                <input type="text" id="name" name="name" value="<?php echo $fetch_order['name']; ?>" required>
            </div>
            <div class="form-group">
                <label for="address">Address:</label>
                <input type="text" id="address" name="address" value="<?php echo $fetch_order['address']; ?>" required>
            </div>
            <div class="form-group">
                <label for="total_amount">Total Amount:</label>
                <input type="number" id="total_amount" name="total_amount" value="<?php echo $fetch_order['total_amount']; ?>" required>
            </div>
            <div class="form-group">
                <label for="payment_method">Payment Method:</label>
                <select id="payment_method" name="payment_method" required>
                    <option value="Credit Card" <?php echo ($fetch_order['payment_method'] == 'Credit Card') ? 'selected' : ''; ?>>Credit Card</option>
                    <option value="PayPal" <?php echo ($fetch_order['payment_method'] == 'PayPal') ? 'selected' : ''; ?>>PayPal</option>
                    <option value="Cash on Delivery" <?php echo ($fetch_order['payment_method'] == 'Cash on Delivery') ? 'selected' : ''; ?>>Cash on Delivery</option>
                </select>
            </div>
            <div class="form-group">
                <label for="payment_status">Payment Status:</label>
                <select id="payment_status" name="payment_status" required>
                    <option value="Pending" <?php echo ($fetch_order['payment_status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="Completed" <?php echo ($fetch_order['payment_status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                    <option value="Failed" <?php echo ($fetch_order['payment_status'] == 'Failed') ? 'selected' : ''; ?>>Failed</option>
                </select>
            </div>
            <div class="form-group">
                <label for="order_date">Order Date:</label>
                <input type="datetime-local" id="order_date" name="order_date" value="<?php echo date('Y-m-d\TH:i', strtotime($fetch_order['order_date'])); ?>" required>
            </div>
            <div class="form-group">
                <button type="submit" class="btn">Update Order</button>
                <a href="orders.php" class="btn">Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>