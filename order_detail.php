<?php
include 'config.php';

$order_id = intval($_GET['id'] ?? 0);
if (!$order_id) {
  echo "Invalid order ID";
  exit;
}

$sql_order = "SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email, c.address AS customer_address
              FROM orders o
              LEFT JOIN customers c ON o.customer_id = c.id
              WHERE o.id = ?";
$stmt = $conn->prepare($sql_order);
$stmt->bind_param('i', $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
  echo "Order not found";
  exit;
}

$sql_items = "SELECT oi.*, p.image FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE order_id = ?";
$stmt = $conn->prepare($sql_items);
$stmt->bind_param('i', $order_id);
$stmt->execute();
$items = $stmt->get_result();
$stmt->close();
?>
<section class="order-two-col" aria-label="Compact order details">
    <!-- left: Customer details -->
  <div class="cust-col">
    <p><strong>Order No :</strong> <?= htmlspecialchars($order['order_no']) ?></p>
    <p><strong>Customer:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
    <p><strong>Phone:</strong> <?= htmlspecialchars($order['customer_phone']) ?></p>
    <p><strong>Email:</strong> <?= htmlspecialchars($order['customer_email']) ?></p>
    <p><strong>Address:</strong> <?= nl2br(htmlspecialchars($order['customer_address'])) ?></p>
  </div>
  <!-- Right: Payment details -->
  <div class="pay-col">
    <p><strong>Paid:</strong> ₹<?= number_format($order['paid_amount'], 2) ?></p>
    <p><strong>Due:</strong> ₹<?= number_format($order['due_amount'], 2) ?></p>
    <p><strong>Payment Status:</strong> <?= htmlspecialchars($order['payment_status']) ?></p>
    <p><strong>Order Status:</strong> <?= htmlspecialchars($order['status']) ?></p>
    <p><strong>Date:</strong> <?= htmlspecialchars(date('d M Y H:i', strtotime($order['created_at']))) ?></p>
  </div>
</section>
<form method="post" class="update-form" aria-label="Update payment and order status" style="min-width:260px;">
  <input type="hidden" name="order_id" value="<?= (int) $order['id']; ?>">
  <label for="paid_amount_<?= $order['id']; ?>">Paid Amount:</label>
  <input
    type="number"
    inputmode="numeric"
    pattern="\d*"
    step="1"
    min="0" max="<?= (int)$order['total']; ?>"
    name="paid_amount"
    id="paid_amount_<?= $order['id']; ?>"
    value="<?= (int)round($order['paid_amount']); ?>"
    required
    style="width:94px; margin-right:8px;" />

  <label for="order_status_<?= $order['id']; ?>">Status:</label>
  <select name="order_status" id="order_status_<?= $order['id']; ?>" style="margin-right:8px;">
    <option value="pending" <?= ($order['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
    <option value="ReadyToShip" <?= ($order['status'] === 'ReadyToShip') ? 'selected' : ''; ?>>ReadyToShip</option>
    <option value="Shipped" <?= ($order['status'] === 'Shipped') ? 'selected' : ''; ?>>Shipped</option>
    <option value="Delivered" <?= ($order['status'] === 'Delivered') ? 'selected' : ''; ?>>Delivered</option>
  </select>
  <button type="submit" name="update_order_details">Update</button>
</form>

<h3>Order Products</h3>
<table border="1" cellpadding="6" cellspacing="0" width="100%">
  <thead>
    <tr>
      <th>Image</th>
      <th>Name</th>
      <th>Price</th>
      <th>qty</th>
      <th>Total</th>
    </tr>
  </thead>
  <tbody>
    <?php while ($item = $items->fetch_assoc()): ?>
      <tr>
        <td><?php if ($item['image']): ?><img src="uploaded_img/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name_snapshot']) ?>" width="50" /><?php else: ?>-<?php endif; ?></td>
        <td><?= htmlspecialchars($item['name_snapshot']) ?></td>
        <td>₹<?= number_format($item['price_snapshot']) ?></td>
        <td><?= (int)$item['qty'] ?></td>
        <td>₹<?= number_format($item['line_total']) ?></td>
      </tr>
    <?php endwhile; ?>
  </tbody>
</table>