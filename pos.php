<?php
// pos.php - UPDATED WITH SEEDS & RAW MATERIAL SUPPORT
require_once 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// Initialize cart
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$sqlProducts = "
    SELECT 
        p.id, 
        p.name, 
        p.base_price as mrp, 
        COALESCE(p.tax_rate, 5) as gstrate, 
        p.barcode, 
        p.product_type,
        CASE 
            /* 1. SEEDS: Get directly from current_stock in seeds_master */
            WHEN LOWER(p.product_type) = 'seed' THEN (
                SELECT COALESCE(sm.current_stock, 0) 
                FROM seeds_master sm 
                WHERE sm.id = p.seed_id
            )
            
            /* 2. CAKE: Calculate from raw_material_inventory */
            WHEN LOWER(p.product_type) = 'cake' THEN (
                SELECT COALESCE(SUM(CASE 
                    WHEN rmi.transaction_type IN ('RAW_IN', 'ADJUSTMENT_IN') THEN rmi.quantity 
                    WHEN rmi.transaction_type IN ('RAW_OUT', 'ADJUSTMENT_OUT') THEN -ABS(rmi.quantity)
                    ELSE 0 END), 0) 
                FROM raw_material_inventory rmi 
                WHERE rmi.seed_id = p.seed_id 
                AND rmi.product_type = 'CAKE'
            )

            /* 3. RAW OIL (Loose Oil): Map to 'OIL' and calculate */
            WHEN LOWER(p.product_type) = 'raw_oil' THEN (
                SELECT COALESCE(SUM(CASE 
                    WHEN rmi.transaction_type IN ('RAW_IN', 'ADJUSTMENT_IN') THEN rmi.quantity 
                    WHEN rmi.transaction_type IN ('RAW_OUT', 'ADJUSTMENT_OUT') THEN -ABS(rmi.quantity)
                    ELSE 0 END), 0) 
                FROM raw_material_inventory rmi 
                WHERE rmi.seed_id = p.seed_id 
                AND rmi.product_type = 'OIL'
            )
            
            /* 4. PACKAGED OIL: Sum from inventory_products */
            ELSE (
                SELECT COALESCE(SUM(CASE 
                    WHEN ip.transaction_type IN ('PRODUCTION', 'RETURN', 'PURCHASE') THEN ip.qty 
                    WHEN ip.transaction_type IN ('SALE') THEN -ABS(ip.qty)
                    ELSE 0 END), 0) 
                FROM inventory_products ip 
                WHERE ip.product_id = p.id
            )
        END as stockqty
    FROM products p 
    WHERE p.is_active = 1
    ORDER BY p.product_type ASC, p.name ASC
";
$products = $conn->query($sqlProducts);
if (!$products) {
    die("❌ Query Failed: " . $conn->error);
}

// Stats
$todaySales = ['orders' => 0, 'revenue' => 0];
$todayResult = $conn->query("SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM orders WHERE DATE(created_at) = CURDATE()");
if ($todayResult) {
    $todaySales = $todayResult->fetch_assoc();
}

$lowStock = ['count' => 0];
$lowResult = $conn->query("SELECT COUNT(*) as count FROM products WHERE min_stock > 0");
if ($lowResult) {
    $lowStock = $lowResult->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Trishe POS System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --pos-bg: #f3f4f6;
            --pos-card-bg: #ffffff;
            --pos-text: #1f2937;
            --pos-accent: #3b82f6;
            --pos-border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--pos-bg);
            margin: 0;
            padding: 0;
            height: 100vh;
            overflow: hidden;
        }

        .pos-container {
            display: flex;
            height: 100vh;
            overflow: hidden;
            width: 100%;
        }

        .pos-main {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            padding-bottom: 80px;
        }

        /* Search & Filter Section */
        .search-section {
            background: var(--pos-card-bg);
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .search-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .search-group {
            flex: 1;
            min-width: 150px;
        }

        .search-input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--pos-border);
            border-radius: 8px;
            background: #f9fafb;
            outline: none;
        }

        /* Category Tabs */
        .cat-tabs {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            overflow-x: auto;
            padding-bottom: 5px;
        }

        .cat-btn {
            padding: 6px 15px;
            background: #e5e7eb;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            white-space: nowrap;
            transition: 0.2s;
        }

        .cat-btn.active {
            background: var(--pos-accent);
            color: white;
        }

        .btn-pos {
            background: var(--pos-accent);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* Stats & Grid */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .stat-box {
            background: var(--pos-card-bg);
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--pos-border);
            text-align: center;
        }

        .stat-num {
            font-size: 16px;
            font-weight: 700;
            color: var(--pos-text);
        }

        .stat-label {
            font-size: 11px;
            color: #6b7280;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
            padding-bottom: 20px;
        }

        .product-card {
            background: var(--pos-card-bg);
            border: 1px solid var(--pos-border);
            border-radius: 10px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            min-height: 140px;
            position: relative;
        }

        .type-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            font-size: 8px;
            padding: 2px 5px;
            border-radius: 10px;
            background: #f3f4f6;
            text-transform: uppercase;
            font-weight: 800;
            color: #6b7280;
        }

        .product-name {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 5px;
            color: var(--pos-text);
            margin-top: 10px;
        }

        .product-price {
            font-weight: 700;
            color: var(--pos-accent);
            font-size: 14px;
        }

        .product-stock {
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 4px;
            display: inline-block;
            margin-top: 5px;
        }

        .stock-available {
            background: #d1fae5;
            color: #065f46;
        }

        .stock-low {
            background: #fef3c7;
            color: #92400e;
        }

        .stock-out {
            background: #fee2e2;
            color: #991b1b;
            opacity: 0.6;
        }

        /* Cart Sidebar */
        .cart-sidebar {
            width: 350px;
            background: var(--pos-card-bg);
            border-left: 1px solid var(--pos-border);
            display: flex;
            flex-direction: column;
            height: 100vh;
            right: 0;
            top: 0;
            z-index: 2000;
            box-shadow: -2px 0 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out;
        }

        @media(max-width: 992px) {
            .pos-container {
                flex-direction: column;
            }

            .cart-sidebar {
                width: 100%;
                height: 85vh;
                bottom: 0;
                border-radius: 15px 15px 0 0;
                position: fixed;
                transform: translateY(110%);
            }

            .cart-sidebar.open {
                transform: translateY(0);
            }
        }

        .cart-header {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #eee;
        }

        .qty-btn {
            width: 28px;
            height: 28px;
            background: #f3f4f6;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .cart-totals {
            padding: 15px;
            background: #f9fafb;
            border-top: 1px solid #eee;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .total-row.last {
            font-size: 16px;
            font-weight: 700;
            border-top: 1px solid #ddd;
            padding-top: 8px;
            margin-top: 8px;
        }

        .checkout-btn {
            width: 100%;
            padding: 12px;
            background: var(--pos-accent);
            color: white;
            border: none;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
        }

        .pos-tools {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 5px;
            padding: 10px;
            border-top: 1px solid #eee;
        }

        .tool-btn {
            padding: 8px;
            background: #f3f4f6;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 3000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: white;
            width: 90%;
            max-width: 400px;
            padding: 20px;
            border-radius: 12px;
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="pos-container">
        <div class="pos-main">
            <div class="search-section">
                <div class="search-row">
                    <div class="search-group"><input type="text" id="searchInput" placeholder="Search product name..." class="search-input"></div>
                    <div class="search-group" style="display:flex; gap:5px;">
                        <input type="text" id="barcodeInput" placeholder="Scan barcode..." class="search-input">
                        <button class="btn-pos" onclick="searchByBarcode()"><i class="fas fa-barcode"></i></button>
                    </div>
                </div>
                <div class="cat-tabs">
                    <button class="cat-btn active" onclick="filterType('all', this)">All Items</button>
                    <button class="cat-btn" onclick="filterType('oil', this)">Oils</button>
                    <button class="cat-btn" onclick="filterType('seed', this)">Seeds</button>
                    <button class="cat-btn" onclick="filterType('cake', this)">Cake / Feed</button>
                    <button class="cat-btn" onclick="filterType('other', this)">Others</button>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-num"><?= (int)($todaySales['orders'] ?? 0) ?></div>
                    <div class="stat-label">Orders</div>
                </div>
                <div class="stat-box">
                    <div class="stat-num" style="color: #10b981;">₹<?= number_format($todaySales['revenue'] ?? 0, 0) ?></div>
                    <div class="stat-label">Revenue</div>
                </div>
                <div class="stat-box">
                    <div class="stat-num" style="color: #f59e0b;"><?= (int)($lowStock['count'] ?? 0) ?></div>
                    <div class="stat-label">Low Stock</div>
                </div>
            </div>

            <div class="products-grid" id="productsGrid">
                <?php while ($p = $products->fetch_assoc()):
                    $stockQty = (float)($p['stockqty'] ?? 0);
                    $stockClass = $stockQty <= 0 ? 'stock-out' : ($stockQty <= 5 ? 'stock-low' : 'stock-available');
                    $type = strtolower($p['product_type'] ?? 'other');
                ?>
                    <div class="product-card"
                        data-id="<?= (int)$p['id'] ?>"
                        data-name="<?= e($p['name']) ?>"
                        data-price="<?= (float)($p['mrp'] ?? 0) ?>"
                        data-gstrate="<?= (float)($p['gstrate'] ?? 5) ?>"
                        data-stock="<?= $stockQty ?>"
                        data-type="<?= $type ?>"
                        data-barcode="<?= e($p['barcode'] ?? '') ?>">

                        <span class="type-badge"><?= $type ?></span>
                        <div>
                            <div class="product-name"><?= e($p['name']) ?></div>
                            <div class="product-price">₹<?= number_format((float)$p['mrp'], 2) ?></div>
                            <div class="product-stock <?= $stockClass ?>">Stock: <?= $stockQty + 0 ?></div>
                        </div>
                        <?php if ($stockQty > 0): ?>
                            <button class="btn-pos" style="margin-top:8px; width:100%; justify-content:center; font-size:12px;" onclick="addToCart(<?= (int)$p['id'] ?>)">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>

        <div class="cart-sidebar" id="cartSidebar">
            <div class="cart-header">
                <h2 style="margin:0; font-size:16px;">Current Sale</h2>
                <span id="cartCount" style="font-size:12px; background:#e5e7eb; padding:2px 8px; border-radius:10px;">0 Items</span>
            </div>
            <div class="cart-items" id="cartItems"></div>
            <div class="cart-totals">
                <div class="total-row"><span>Subtotal (Base Value)</span><span id="subtotal">₹0.00</span></div>
                <div class="total-row"><span style="color:#10b981; font-weight:600;">GST (5% Included)</span><span id="taxAmount" style="color:#10b981; font-weight:600;">₹0.00</span></div>
                <div class="total-row"><span>Discount</span><input type="number" id="discountInput" style="width:60px; text-align:right;" value="0" oninput="updateCartDisplay()"></div>
                <div class="total-row last"><span>Grand Total</span><span id="grandTotal">₹0.00</span></div>
                <button class="checkout-btn" onclick="openCheckoutModal()">Pay Now <i class="fas fa-arrow-right"></i></button>
            </div>
            <div class="pos-tools">
                <button class="tool-btn" onclick="clearCart()"><i class="fas fa-trash"></i> Clear</button>
                <button class="tool-btn" onclick="holdTransaction()"><i class="fas fa-pause"></i> Hold</button>
                <button class="tool-btn" onclick="loadHeldTransactions()"><i class="fas fa-history"></i> Recall</button>
                <button class="tool-btn" onclick="printLastInvoice()"><i class="fas fa-print"></i> Print Last</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="checkoutModal">
        <div class="modal-content">
            <h3 style="margin-top:0;">Payment Details</h3>
            <form id="checkoutForm" onsubmit="processPayment(event)">
                <input type="hidden" id="selectedCustId" value="0">
                <input type="text" id="custSearch" class="search-input" placeholder="Search Customer..." oninput="suggestCustomer(this.value)" style="margin-bottom:10px;">
                <div id="custSuggestions" style="display:none; background:#fff; border:1px solid #ddd; max-height:100px; overflow-y:auto; margin-bottom:10px;"></div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">
                    <input type="text" id="custName" class="search-input" placeholder="Name" required>
                    <input type="text" id="custPhone" class="search-input" placeholder="Phone">
                </div>
                <select id="paymentMethod" class="search-input" style="margin-bottom:10px;">
                    <option value="Cash">Cash</option>
                    <option value="UPI">UPI / Online</option>
                    <option value="Credit">Udhaar (Credit)</option>
                </select>
                <input type="number" id="paidAmount" class="search-input" step="0.01" placeholder="Amount Received" required style="margin-bottom:20px;">
                <button type="submit" class="checkout-btn">Complete & Print</button>
                <button type="button" class="btn-pos" style="background:#6b7280; width:100%; margin-top:10px; justify-content:center;" onclick="closeCheckoutModal()">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        let cart = {};
        let lastOrderId = null;

        function filterType(type, btn) {
            document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            document.querySelectorAll('.product-card').forEach(card => {
                const itemType = card.dataset.type.toLowerCase();
                const itemName = card.dataset.name.toLowerCase();

                if (type === 'all') {
                    card.style.display = 'flex';
                }
                // Cake/Khal के लिए विशेष चेकिंग
                else if (type === 'cake') {
                    if (itemType === 'cake' || itemName.includes('cake') || itemName.includes('khal')) {
                        card.style.display = 'flex';
                    } else {
                        card.style.display = 'none';
                    }
                } else if (itemType === type) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function updateCartDisplay() {
            const container = document.getElementById('cartItems');
            let html = '',
                subtotalBase = 0,
                totalTax = 0,
                grandTotalNoDisc = 0;

            for (const [id, item] of Object.entries(cart)) {
                // Total price including tax
                const itemTotalInclTax = item.price * item.qty;

                // 5% INCLUSIVE GST FORMULA (Amount / 1.05)
                const itemBasePrice = itemTotalInclTax / 1.05;
                const itemTaxAmount = itemTotalInclTax - itemBasePrice;

                subtotalBase += itemBasePrice;
                totalTax += itemTaxAmount;
                grandTotalNoDisc += itemTotalInclTax;

                html += `<div class="cart-item">
                    <div style="flex:1;"><strong>${item.name}</strong><br><small>₹${item.price} x ${item.qty}</small></div>
                    <div><button class="qty-btn" onclick="updateQty(${id}, -1)">-</button> <span style="display:inline-block; width:20px; text-align:center; font-weight:bold;">${item.qty}</span> <button class="qty-btn" onclick="updateQty(${id}, 1)">+</button></div>
                </div>`;
            }
            container.innerHTML = html || '<div style="text-align:center;color:#999;padding:20px;"><i class="fas fa-shopping-basket fa-2x" style="margin-bottom:10px; opacity:0.5;"></i><br>Cart is empty</div>';

            const disc = parseFloat(document.getElementById('discountInput').value) || 0;
            const grand = grandTotalNoDisc - disc;

            document.getElementById('subtotal').textContent = '₹' + subtotalBase.toFixed(2);
            document.getElementById('taxAmount').textContent = '₹' + totalTax.toFixed(2);
            document.getElementById('grandTotal').textContent = '₹' + grand.toFixed(2);
            document.getElementById('cartCount').textContent = Object.keys(cart).length + ' Items';
        }

        function addToCart(productId) {
            const card = document.querySelector(`.product-card[data-id="${productId}"]`);
            if (!card) return;
            const name = card.dataset.name,
                price = parseFloat(card.dataset.price),
                stock = parseFloat(card.dataset.stock),
                gstrate = parseFloat(card.dataset.gstrate);

            if (cart[productId]) {
                if (cart[productId].qty >= stock) return alert('Stock limit!');
                cart[productId].qty++;
            } else {
                cart[productId] = {
                    id: productId,
                    name: name,
                    price: price,
                    qty: 1,
                    stock: stock,
                    gstrate: gstrate
                };
            }
            updateCartDisplay();
        }

        function updateCartDisplay() {
            const container = document.getElementById('cartItems');
            let html = '',
                subtotalBase = 0,
                totalTax = 0,
                grandTotalNoDisc = 0;

            for (const [id, item] of Object.entries(cart)) {
                // 1. Total Price (MRP x Quantity) -> Jaise 105 * 1 = 105
                const itemTotalInclTax = parseFloat(item.price) * parseInt(item.qty);

                // 2. Product ka GST Rate (Jaise 5%)
                const gstRate = parseFloat(item.gstrate) || 0;

                // 3. EXACT INCLUSIVE FORMULA: Base = Total / (1 + (GST / 100))
                // Example: 105 / (1 + 0.05) = 105 / 1.05 = 100
                const itemBasePrice = itemTotalInclTax / (1 + (gstRate / 100));

                // 4. Tax = Total - Base -> Example: 105 - 100 = 5
                const itemTaxAmount = itemTotalInclTax - itemBasePrice;

                // Grand totals mein jodna
                subtotalBase += itemBasePrice;
                totalTax += itemTaxAmount;
                grandTotalNoDisc += itemTotalInclTax;

                html += `<div class="cart-item">
                    <div style="flex:1;">
                        <strong>${item.name}</strong><br>
                        <small style="color:#6b7280;">₹${parseFloat(item.price).toFixed(2)} x ${item.qty} <span style="font-size:10px;">(Inc. ${gstRate}% GST)</span></small>
                    </div>
                    <div>
                        <button class="qty-btn" onclick="updateQty(${id}, -1)">-</button> 
                        <span style="display:inline-block; width:20px; text-align:center; font-weight:bold;">${item.qty}</span> 
                        <button class="qty-btn" onclick="updateQty(${id}, 1)">+</button>
                    </div>
                </div>`;
            }

            container.innerHTML = html || '<div style="text-align:center;color:#999;padding:20px;"><i class="fas fa-shopping-basket fa-2x" style="margin-bottom:10px; opacity:0.5;"></i><br>Cart is empty</div>';

            // Discount minus karna
            const disc = parseFloat(document.getElementById('discountInput').value) || 0;
            const finalGrandTotal = grandTotalNoDisc - disc;

            // Screen par values dikhana (toFixed(2) lagaya hai taaki exact decimals aayein)
            document.getElementById('subtotal').textContent = '₹' + subtotalBase.toFixed(2);
            document.getElementById('taxAmount').textContent = '₹' + totalTax.toFixed(2);
            document.getElementById('grandTotal').textContent = '₹' + finalGrandTotal.toFixed(2);
            document.getElementById('cartCount').textContent = Object.keys(cart).length + ' Items';

            // Modal mein pay amount auto-fill karna
            const paidInput = document.getElementById('paidAmount');
            if (paidInput) paidInput.value = finalGrandTotal.toFixed(2);
        }

        function updateQty(id, change) {
            cart[id].qty += change;
            if (cart[id].qty <= 0) delete cart[id];
            updateCartDisplay();
        }

        function openCheckoutModal() {
            if (Object.keys(cart).length === 0) return alert('Empty cart');
            document.getElementById('paidAmount').value = document.getElementById('grandTotal').innerText.replace('₹', '');
            document.getElementById('checkoutModal').classList.add('active');
        }

        function closeCheckoutModal() {
            document.getElementById('checkoutModal').classList.remove('active');
        }

        function processPayment(e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('action', 'save_sale');
            fd.append('customer_id', document.getElementById('selectedCustId').value);
            fd.append('customer_name', document.getElementById('custName').value);
            fd.append('customer_phone', document.getElementById('custPhone').value);
            fd.append('payment_mode', document.getElementById('paymentMethod').value);
            fd.append('discount', document.getElementById('discountInput').value);
            fd.append('cart', JSON.stringify(Object.values(cart)));

            fetch('sales_entry.php', {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(res => {
                if (res.success) {
                    lastOrderId = res.order_id || res.id;
                    window.open('print_engine.php?doc=pos_invoice&id=' + lastOrderId, 'ThermalPrint', 'width=400,height=600');
                    location.reload();
                } else {
                    alert("Error: " + res.error);
                }
            });
        }

        function printLastInvoice() {
            if (lastOrderId) window.open('print_engine.php?doc=pos_invoice&id=' + lastOrderId, 'ThermalPrint', 'width=400,height=600');
            else alert("No invoice found");
        }

        function clearCart() {
            cart = {};
            updateCartDisplay();
        }

        document.getElementById('searchInput').addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            document.querySelectorAll('.product-card').forEach(card => {
                card.style.display = card.dataset.name.toLowerCase().includes(term) ? 'flex' : 'none';
            });
        });
    </script>
</body>

</html>