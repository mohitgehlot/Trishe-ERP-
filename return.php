<?php
// returns.php
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit;
}

$messages = [];

// Handle return processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_return'])) {
    $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
    $return_qty = filter_input(INPUT_POST, 'return_qty', FILTER_VALIDATE_INT);
    $return_reason = filter_input(INPUT_POST, 'return_reason', FILTER_SANITIZE_STRING);
    $refund_amount = filter_input(INPUT_POST, 'refund_amount', FILTER_VALIDATE_FLOAT);

    if ($order_id && $product_id && $return_qty > 0) {
        $conn->begin_transaction();
        
        try {
            // 1. Get original order item details
            $stmt = $conn->prepare("
                SELECT oi.price, oi.quantity, o.total as order_total 
                FROM order_items oi 
                JOIN orders o ON oi.order_id = o.id 
                WHERE oi.order_id = ? AND oi.product_id = ?
            ");
            $stmt->bind_param("ii", $order_id, $product_id);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$item) {
                throw new Exception("Order item not found");
            }

            // 2. Insert return record
            $stmt = $conn->prepare("
                INSERT INTO order_returns (order_id, product_id, return_qty, return_reason, refund_amount, processed_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiisdi", $order_id, $product_id, $return_qty, $return_reason, $refund_amount, $_SESSION['admin_id']);
            $stmt->execute();
            $return_id = $conn->insert_id;
            $stmt->close();

            // 3. Update product stock
            $stmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
            $stmt->bind_param("ii", $return_qty, $product_id);
            $stmt->execute();
            $stmt->close();

            // 4. Record stock history
            $stmt = $conn->prepare("
                INSERT INTO stock_history (product_id, change_type, previous_qty, new_qty, reference_id, notes)
                SELECT id, 'return', stock_qty - ?, stock_qty, ?, 'Product Return - Order #$order_id'
                FROM products WHERE id = ?
            ");
            $stmt->bind_param("iii", $return_qty, $return_id, $product_id);
            $stmt->execute();
            $stmt->close();

            // 5. Process refund if applicable
            if ($refund_amount > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO payments (order_id, amount, payment_method, reference, status, type, created_by)
                    VALUES (?, ?, 'refund', ?, 'completed', 'refund', ?)
                ");
                $reference = 'REF_' . $order_id . '_' . $return_id;
                $stmt->bind_param("idsi", $order_id, $refund_amount, $reference, $_SESSION['admin_id']);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $messages[] = "Return processed successfully. Refund: ₹" . number_format($refund_amount, 2);

        } catch (Exception $e) {
            $conn->rollback();
            $messages[] = "Error processing return: " . $e->getMessage();
        }
    } else {
        $messages[] = "Invalid return data";
    }
}

// Fetch recent orders for returns
$orders = $conn->query("
    SELECT o.id, o.order_no, o.total, o.created_at, c.name as customer_name
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.id 
    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY o.created_at DESC 
    LIMIT 100
");

// Fetch return history
$returns = $conn->query("
    SELECT r.*, o.order_no, p.name as product_name, c.name as customer_name
    FROM order_returns r
    JOIN orders o ON r.order_id = o.id
    JOIN products p ON r.product_id = p.id
    LEFT JOIN customers c ON o.customer_id = c.id
    ORDER BY r.created_at DESC
    LIMIT 50
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Returns Management - Trishe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin_style.css" />
    <link rel="stylesheet" href="css/admin_common.css">
</head>
<body>
    <?php include 'admin_header.php'; ?>

    <?php if (!empty($messages)): ?>
        <?php foreach ($messages as $msg): ?>
            <div class="alert <?= strpos($msg, 'Error') !== false ? 'error' : '' ?>"><?= htmlspecialchars($msg); ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="shell">
        <!-- Left Sidebar -->
        <div class="left-side glass">
            <div class="management-section">
                <h3 class="section-title">Process Return</h3>
                <form method="post" class="return-form">
                    <div class="form-group">
                        <label for="order_search">Search Order</label>
                        <input type="text" id="order_search" class="form-control" placeholder="Enter order number..." autocomplete="off">
                        <div id="order_suggestions" class="autocomplete-suggestions"></div>
                    </div>

                    <div id="order_details" style="display: none;">
                        <div class="form-group">
                            <label for="product_select">Select Product</label>
                            <select id="product_select" class="form-control" required>
                                <option value="">Choose product...</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="return_qty">Return Quantity</label>
                            <input type="number" id="return_qty" name="return_qty" class="form-control" min="1" required>
                        </div>

                        <div class="form-group">
                            <label for="return_reason">Return Reason</label>
                            <select id="return_reason" name="return_reason" class="form-control" required>
                                <option value="">Select reason...</option>
                                <option value="defective">Defective Product</option>
                                <option value="wrong_item">Wrong Item Shipped</option>
                                <option value="customer_change">Customer Changed Mind</option>
                                <option value="damaged">Damaged in Transit</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="refund_amount">Refund Amount (₹)</label>
                            <input type="number" id="refund_amount" name="refund_amount" class="form-control" step="0.01" min="0" required>
                        </div>

                        <input type="hidden" id="order_id" name="order_id">
                        <input type="hidden" id="product_id" name="product_id">

                        <button type="submit" name="process_return" class="btn btn-primary">
                            <i class="fas fa-undo"></i> Process Return
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-container">
            <div class="main-inner glass">
                <div class="page-header">
                    <h1 class="page-title">Returns Management</h1>
                </div>

                <!-- Return History -->
                <div class="management-section">
                    <h3 class="section-title">Recent Returns</h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Return ID</th>
                                    <th>Order No</th>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Refund Amount</th>
                                    <th>Reason</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($return = $returns->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?= $return['id'] ?></td>
                                    <td><?= htmlspecialchars($return['order_no']) ?></td>
                                    <td><?= htmlspecialchars($return['product_name']) ?></td>
                                    <td><?= $return['return_qty'] ?></td>
                                    <td>₹<?= number_format($return['refund_amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($return['return_reason']) ?></td>
                                    <td><?= date('M d, Y H:i', strtotime($return['created_at'])) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Sidebar -->
        <div class="right-side glass">
            <div class="management-section">
                <h3 class="section-title">Return Statistics</h3>
                <div class="stats-grid">
                    <?php
                    $today_returns = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(refund_amount), 0) as amount FROM order_returns WHERE DATE(created_at) = CURDATE()")->fetch_assoc();
                    $month_returns = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(refund_amount), 0) as amount FROM order_returns WHERE MONTH(created_at) = MONTH(CURDATE())")->fetch_assoc();
                    ?>
                    <div class="stat-item">
                        <span class="stat-value"><?= $today_returns['count'] ?></span>
                        <span class="stat-label">Today's Returns</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value">₹<?= number_format($today_returns['amount'], 2) ?></span>
                        <span class="stat-label">Today's Refunds</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?= $month_returns['count'] ?></span>
                        <span class="stat-label">Monthly Returns</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const orderSearch = document.getElementById('order_search');
            const orderSuggestions = document.getElementById('order_suggestions');
            const orderDetails = document.getElementById('order_details');
            const productSelect = document.getElementById('product_select');
            const returnQty = document.getElementById('return_qty');
            const refundAmount = document.getElementById('refund_amount');

            let debounceTimer;

            orderSearch.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const query = this.value.trim();

                orderSuggestions.innerHTML = '';
                orderSuggestions.style.display = 'none';

                if (query.length < 2) return;

                debounceTimer = setTimeout(() => {
                    fetch(`search_orders.php?term=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(orders => {
                            if (!orders.length) {
                                orderSuggestions.style.display = 'none';
                                return;
                            }

                            orderSuggestions.innerHTML = '';
                            orders.forEach(order => {
                                const div = document.createElement('div');
                                div.className = 'autocomplete-suggestion';
                                div.innerHTML = `
                                    <strong>${order.order_no}</strong> - ${order.customer_name} 
                                    <br><small>₹${order.total} - ${new Date(order.created_at).toLocaleDateString()}</small>
                                `;
                                div.addEventListener('click', () => {
                                    loadOrderDetails(order.id);
                                    orderSearch.value = order.order_no;
                                    orderSuggestions.style.display = 'none';
                                });
                                orderSuggestions.appendChild(div);
                            });
                            orderSuggestions.style.display = 'block';
                        });
                }, 300);
            });

            function loadOrderDetails(orderId) {
                fetch(`get_order_details.php?order_id=${orderId}`)
                    .then(res => res.json())
                    .then(order => {
                        document.getElementById('order_id').value = orderId;
                        
                        productSelect.innerHTML = '<option value="">Choose product...</option>';
                        order.items.forEach(item => {
                            const option = document.createElement('option');
                            option.value = item.product_id;
                            option.textContent = `${item.product_name} (Available: ${item.quantity})`;
                            option.dataset.price = item.price;
                            option.dataset.maxQty = item.quantity;
                            productSelect.appendChild(option);
                        });

                        orderDetails.style.display = 'block';
                    });
            }

            productSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                if (selectedOption.value) {
                    document.getElementById('product_id').value = selectedOption.value;
                    returnQty.max = selectedOption.dataset.maxQty;
                    returnQty.value = 1;
                    updateRefundAmount();
                }
            });

            returnQty.addEventListener('input', updateRefundAmount);

            function updateRefundAmount() {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                if (selectedOption.value && returnQty.value) {
                    const price = parseFloat(selectedOption.dataset.price);
                    const qty = parseInt(returnQty.value);
                    refundAmount.value = (price * qty).toFixed(2);
                }
            }

            // Hide suggestions when clicking outside
            document.addEventListener('click', (e) => {
                if (!orderSuggestions.contains(e.target) && e.target !== orderSearch) {
                    orderSuggestions.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>