<?php
// get_order_details.php
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
if (!$order_id) {
    http_response_code(400);
    exit('Invalid order ID');
}

// Fetch complete order details
$stmt = $conn->prepare("SELECT o.*, c.name AS customer_name, c.phone, c.email, c.address 
                       FROM orders o 
                       LEFT JOIN customers c ON o.customer_id = c.id 
                       WHERE o.id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    http_response_code(404);
    exit('Order not found');
}

// Fetch order items
$stmtItems = $conn->prepare("SELECT oi.*, p.image 
                            FROM order_items oi 
                            LEFT JOIN products p ON oi.product_id = p.id 
                            WHERE oi.order_id = ?");
$stmtItems->bind_param("i", $order_id);
$stmtItems->execute();
$items = $stmtItems->get_result()->fetch_all(MYSQLI_ASSOC);

// Output HTML for modal
?>
<div class="modal-sections" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    
    <!-- Left Column: Customer Information -->
    <div class="modal-section">
        <h3>Customer Information</h3>
        <p><strong>Name:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($order['email']) ?></p>
        <p><strong>Phone:</strong> <?= htmlspecialchars($order['phone']) ?></p>
        <p><strong>Address:</strong> <?= htmlspecialchars($order['address']) ?></p>
        
        <!-- Update Form integrated here -->
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.2);">
            <h4 style="margin-bottom: 15px; color: white;">Update Order</h4>
            <form method="post" class="update-form" action="admin_orders.php" style="display: flex; gap: 12px; align-items: end; flex-wrap: wrap;">
                <input type="hidden" name="order_id" value="<?= $order['id']; ?>">
                
                <!-- Paid Amount Field - Only show if not 0 -->
                <?php if ($order['paid_amount'] > 0): ?>
                <div style="flex: 1; min-width: 120px;">
                    <label for="paid_amount_<?= $order['id']; ?>" style="display: block; margin-bottom: 5px; font-weight: 600; color: white; font-size: 12px;">Paid Amount:</label>
                    <input type="number" name="paid_amount" id="paid_amount_<?= $order['id']; ?>"
                        value="<?= number_format($order['paid_amount'], 2); ?>" step="0.01" min="0" max="<?= $order['total']; ?>"
                        style="width: 100%; padding: 8px; border-radius: var(--radius-sm); border: 1px solid rgba(255,255,255,0.4); background: rgba(255,255,255,0.1); color: white; font-size: 14px;">
                </div>
                <?php endif; ?>
                
                <!-- Status Field -->
                <div style="flex: 1; min-width: 150px;">
                    <label for="order_status_<?= $order['id']; ?>" style="display: block; margin-bottom: 5px; font-weight: 600; color: white; font-size: 12px;">Status:</label>
                    <select name="order_status" id="order_status_<?= $order['id']; ?>"
                        style="width: 100%; padding: 8px; border-radius: var(--radius-sm); border: 1px solid rgba(255,255,255,0.4); background: rgba(255,255,255,0.1); color: white; font-size: 14px;">
                        <option value="pending" <?= $order['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="ReadyToShip" <?= $order['status'] === 'ReadyToShip' ? 'selected' : ''; ?>>Ready To Ship</option>
                        <option value="Shipped" <?= $order['status'] === 'Shipped' ? 'selected' : ''; ?>>Shipped</option>
                        <option value="Delivered" <?= $order['status'] === 'Delivered' ? 'selected' : ''; ?>>Delivered</option>
                    </select>
                </div>
                
                <!-- Update Button -->
                <div style="flex-shrink: 0;">
                    <button type="submit" name="update_order_details"
                        style="background: linear-gradient(135deg, var(--accent), #3b82f6); color: white; border: none; border-radius: var(--radius-sm); padding: 8px 16px; font-weight: 600; cursor: pointer; height: 36px; font-size: 14px; white-space: nowrap;">
                        Update
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Right Column: Order Details -->
    <div class="modal-section">
        <h3>Order Details</h3>
        <p><strong>Order #:</strong> <?= htmlspecialchars($order['order_no']) ?></p>
        <p><strong>Status:</strong> <span class="badge <?= $order['status'] ?>"><?= ucfirst($order['status']) ?></span></p>
        <p><strong>Payment Status:</strong> <span class="badge <?= $order['payment_status'] ?>"><?= ucfirst($order['payment_status']) ?></span></p>
        <p><strong>Total:</strong> ₹<?= number_format($order['total'], 2) ?></p>
        <p><strong>Paid:</strong> ₹<?= number_format($order['paid_amount'], 2) ?></p>
        <p><strong>Due:</strong> ₹<?= number_format($order['due_amount'], 2) ?></p>
        <p><strong>Payment Method:</strong> <?= htmlspecialchars($order['payment_method']) ?></p>
        <?php if (!empty($order['notes'])): ?>
            <p><strong>Notes:</strong> <?= htmlspecialchars($order['notes']) ?></p>
        <?php endif; ?>
    </div>

    <!-- Order Items - Full Width -->
    <div class="modal-section" style="grid-column: 1 / -1;">
        <h3>Order Items (<?= count($items) ?> items)</h3>
        <table class="order-summary">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td style="display: flex; align-items: center; gap: 10px;">
                            <?php if ($item['image']): ?>
                                <img src="uploaded_img/<?= $item['image'] ?>" alt="<?= htmlspecialchars($item['name_snapshot']) ?>"
                                    style="width: 40px; height: 40px; object-fit: cover; border-radius: 8px;">
                            <?php endif; ?>
                            <span><?= htmlspecialchars($item['name_snapshot']) ?></span>
                        </td>
                        <td>₹<?= number_format($item['price_snapshot'], 2) ?></td>
                        <td><?= $item['qty'] ?></td>
                        <td>₹<?= number_format($item['line_total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <!-- Order Summary Row -->
                <tr style="background: rgba(79, 142, 247, 0.1);">
                    <td colspan="3" style="text-align: right; font-weight: bold;">Subtotal:</td>
                    <td style="font-weight: bold;">₹<?= number_format($order['subtotal'], 2) ?></td>
                </tr>
                <?php if ($order['tax'] > 0): ?>
                    <tr style="background: rgba(79, 142, 247, 0.05);">
                        <td colspan="3" style="text-align: right;">Tax:</td>
                        <td>₹<?= number_format($order['tax'], 2) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($order['discount'] > 0): ?>
                    <tr style="background: rgba(79, 142, 247, 0.05);">
                        <td colspan="3" style="text-align: right;">Discount:</td>
                        <td>-₹<?= number_format($order['discount'], 2) ?></td>
                    </tr>
                <?php endif; ?>
                <tr style="background: rgba(16, 185, 129, 0.1);">
                    <td colspan="3" style="text-align: right; font-weight: bold; font-size: 16px;">Total:</td>
                    <td style="font-weight: bold; font-size: 16px;">₹<?= number_format($order['total'], 2) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<style>
.update-form {
    background: rgba(255, 255, 255, 0.1);
    padding: 15px;
    border-radius: var(--radius-sm);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.update-form input:focus,
.update-form select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(79, 142, 247, 0.1);
}

@media (max-width: 768px) {
    .modal-sections {
        grid-template-columns: 1fr;
    }
    
    .update-form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .update-form > div {
        min-width: 100%;
        margin-bottom: 10px;
    }
}
</style>

<?php
$stmt->close();
$stmtItems->close();
?>