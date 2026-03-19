<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Inventory</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
        .container { padding: 20px; }
        h1 { text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        .low-stock { background-color: #ffcccc; }
        .nav { background: #333; color: #fff; padding: 10px; text-align: center; }
        .nav a { color: #fff; text-decoration: none; margin: 0 10px; }
        form { margin-bottom: 20px; }
        input[type="text"], input[type="number"] { padding: 5px; margin-right: 10px; }
        button { padding: 5px 10px; }
        .settings-popup { display: none; position: fixed; top: 0; right: 0; width: 300px; height: 100%; background: #fff; box-shadow: -2px 0 5px rgba(0, 0, 0, 0.2); padding: 20px; z-index: 1000; }
        .settings-popup.active { display: block; }
        .settings-popup h2 { margin-top: 0; }
        .settings-popup label { display: block; margin: 10px 0 5px; }
        .settings-popup input { width: 100%; padding: 5px; margin-bottom: 10px; }
        .settings-popup button { width: 100%; padding: 10px; }
        .overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 999; }
        .overlay.active { display: block; }
        @media (max-width: 768px) {
            table, thead, tbody, th, td, tr { display: block; }
            th { position: absolute; top: -9999px; left: -9999px; }
            tr { border: 1px solid #ccc; }
            td { border: none; border-bottom: 1px solid #eee; position: relative; padding-left: 50%; }
            td:before { position: absolute; left: 6px; width: 45%; padding-right: 10px; white-space: nowrap; content: attr(data-label); }
        }
    </style>
</head>
<body>
    <div class="nav">
        <a href="index.php">Dashboard</a>
        <a href="manage.php">Manage Inventory</a>
    </div>
    <div class="container">
        <h1>Manage Inventory</h1>
        <form method="POST" action="api.php">
            <input type="hidden" name="add_product" value="1">
            <input type="text" name="name" placeholder="Product Name" required>
            <input type="text" name="description" placeholder="Description">
            <input type="number" name="quantity" placeholder="Quantity" required>
            <input type="number" name="price" placeholder="Price" step="0.01" required>
            <input type="number" name="low_stock_threshold" placeholder="Low Stock Threshold" required>
            <button type="submit">Add Product</button>
        </form>

        <h2>Product List</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                include 'api.php';
                foreach ($products as $product) {
                    $lowStockClass = ($product['quantity'] <= $product['low_stock_threshold']) ? 'low-stock' : '';
                    echo "<tr class='$lowStockClass'>
                            <td data-label='ID'>{$product['id']}</td>
                            <td data-label='Name'>{$product['name']}</td>
                            <td data-label='Description'>{$product['description']}</td>
                            <td data-label='Quantity'>{$product['quantity']}</td>
                            <td data-label='Price'>\${$product['price']}</td>
                            <td data-label='Action'>
                                <form method='POST' action='api.php' style='display:inline;'>
                                    <input type='hidden' name='update_quantity' value='1'>
                                    <input type='hidden' name='id' value='{$product['id']}'>
                                    <input type='number' name='quantity' value='{$product['quantity']}' required>
                                    <button type='submit'>Update</button>
                                </form>
                                <button onclick='openSettings({$product['id']}, {$product['low_stock_threshold']})'>Settings</button>
                                <a href='manage.php?delete_id={$product['id']}' onclick='return confirm(\"Are you sure?\")'>Delete</a>
                            </td>
                          </tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <!-- Settings Popup -->
    <div class="overlay" id="overlay"></div>
    <div class="settings-popup" id="settingsPopup">
        <h2>Update Low Stock Threshold</h2>
        <form method="POST" action="api.php" id="thresholdForm">
            <input type="hidden" name="update_threshold" value="1">
            <input type="hidden" name="id" id="productId">
            <label for="low_stock_threshold">Low Stock Threshold:</label>
            <input type="number" name="low_stock_threshold" id="lowStockThreshold" required>
            <button type="submit">Save</button>
        </form>
        <button onclick="closeSettings()">Close</button>
    </div>

    <script>
        // Open settings popup
        function openSettings(id, threshold) {
            document.getElementById('productId').value = id;
            document.getElementById('lowStockThreshold').value = threshold;
            document.getElementById('settingsPopup').classList.add('active');
            document.getElementById('overlay').classList.add('active');
        }

        // Close settings popup
        function closeSettings() {
            document.getElementById('settingsPopup').classList.remove('active');
            document.getElementById('overlay').classList.remove('active');
        }
    </script>
</body>
</html>