<?php
// ajax_order_details.php - GLOBAL ORDER VIEWER
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    die("<div style='padding:20px; color:red; text-align:center;'>Unauthorized Access!</div>");
}

$order_id = intval($_GET['id'] ?? 0);
if ($order_id <= 0) {
    die("<div style='padding:20px; color:red; text-align:center;'>Invalid Order ID!</div>");
}

// 1. Fetch Order & Customer Details
$order_sql = "SELECT o.*, c.name as cust_name, c.phone as cust_phone 
              FROM orders o 
              LEFT JOIN customers c ON o.customer_id = c.id 
              WHERE o.id = $order_id";
$order_res = $conn->query($order_sql);

if (!$order_res || $order_res->num_rows === 0) {
    die("<div style='padding:20px; color:red; text-align:center;'>Order not found!</div>");
}
$order = $order_res->fetch_assoc();

// 2. Fetch Order Items
$items_sql = "SELECT oi.*, p.name as product_name 
              FROM order_items oi 
              LEFT JOIN products p ON oi.product_id = p.id 
              WHERE oi.order_id = $order_id";
$items_res = $conn->query($items_sql);
?>

<div style="font-family: 'Inter', sans-serif; color: #334155;">
    <div style="display:flex; justify-content:space-between; border-bottom: 2px dashed #cbd5e1; padding-bottom: 15px; margin-bottom: 15px;">
        <div>
            <h3 style="margin:0; color:#0f172a; font-size:1.2rem;">Order #<?= $order['order_no'] ?></h3>
            <div style="font-size:0.85rem; color:#64748b; margin-top:5px;">
                Date: <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-weight:bold; color:#0f172a;"><?= htmlspecialchars($order['cust_name'] ?? 'Walk-in Customer') ?></div>
            <div style="font-size:0.85rem; color:#64748b;"><?= htmlspecialchars($order['cust_phone'] ?? '') ?></div>
            <div style="margin-top:5px;">
                <span style="background:#e0e7ff; color:#4338ca; padding:3px 8px; border-radius:4px; font-size:0.75rem; font-weight:bold;">
                    <?= strtoupper($order['payment_method']) ?>
                </span>
                <span style="background: <?= $order['payment_status']=='Paid'?'#dcfce7':'#fee2e2' ?>; color: <?= $order['payment_status']=='Paid'?'#166534':'#b91c1c' ?>; padding:3px 8px; border-radius:4px; font-size:0.75rem; font-weight:bold;">
                    <?= strtoupper($order['payment_status']) ?>
                </span>
            </div>
        </div>
    </div>

    <table style="width:100%; border-collapse:collapse; font-size:0.9rem; margin-bottom:20px;">
        <thead>
            <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1;">
                <th style="padding:10px; text-align:left;">Item</th>
                <th style="padding:10px; text-align:center;">Qty</th>
                <th style="padding:10px; text-align:right;">Rate</th>
                <th style="padding:10px; text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php while($item = $items_res->fetch_assoc()): ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:10px;">
                    <strong><?= htmlspecialchars($item['product_name'] ?? 'Unknown Item') ?></strong>
                </td>
                <td style="padding:10px; text-align:center;"><?= $item['qty'] ?> <?= $item['unit'] ?? 'Pcs' ?></td>
                <td style="padding:10px; text-align:right;">₹<?= number_format($item['price'] ?? $item['price_snapshot'], 2) ?></td>
                <td style="padding:10px; text-align:right; font-weight:bold;">₹<?= number_format($item['line_total'] ?? ($item['qty']*$item['price']), 2) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div style="float:right; width:200px; font-size:0.9rem;">
        <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
            <span style="color:#64748b;">Subtotal:</span>
            <strong>₹<?= number_format($order['subtotal'], 2) ?></strong>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
            <span style="color:#64748b;">Discount:</span>
            <strong style="color:#ef4444;">- ₹<?= number_format($order['discount'], 2) ?></strong>
        </div>
        <?php if($order['tax'] > 0): ?>
        <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
            <span style="color:#64748b;">Tax/GST:</span>
            <strong>+ ₹<?= number_format($order['tax'], 2) ?></strong>
        </div>
        <?php endif; ?>
        
        <div style="display:flex; justify-content:space-between; margin-top:10px; padding-top:10px; border-top:2px solid #0f172a; font-size:1.1rem;">
            <strong>Total:</strong>
            <strong style="color:#059669;">₹<?= number_format($order['total'], 2) ?></strong>
        </div>
    </div>
    <div style="clear:both;"></div>
</div>