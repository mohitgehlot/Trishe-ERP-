<?php
// pos.php - INTEGRATED WITH MASTER CSS
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        /* POS SPECIFIC LAYOUT OVERRIDES */
        body { 
            height: 100vh; overflow: hidden; 
            padding-bottom: 0; /* Remove default bottom padding for POS */
        }

        .pos-container { display: flex; height: 100vh; overflow: hidden; width: 100%; }

        .pos-main { flex: 1; padding: 15px; overflow-y: auto; display: flex; flex-direction: column; padding-bottom: 20px; }

        /* Search & Filter Section */
        .search-section { background: var(--bg-card); padding: 15px; border-radius: var(--radius); margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); border: 1px solid var(--border); }
        .search-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .search-group { flex: 1; min-width: 150px; }
        .search-input { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; background: #f8fafc; outline: none; font-size: 0.95rem; transition: 0.2s; }
        .search-input:focus { border-color: var(--primary); background: #fff; }

        /* Category Tabs */
        .cat-tabs { display: flex; gap: 10px; margin-top: 15px; overflow-x: auto; padding-bottom: 5px; }
        .cat-btn { padding: 8px 16px; background: #e2e8f0; border-radius: 20px; font-size: 0.85rem; font-weight: 700; color: #475569; cursor: pointer; border: none; white-space: nowrap; transition: 0.2s; }
        .cat-btn:hover { background: #cbd5e1; }
        .cat-btn.active { background: var(--primary); color: white; }

        /* Stats Row */
        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat-box { background: var(--bg-card); padding: 15px; border-radius: var(--radius); border: 1px solid var(--border); text-align: center; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .stat-num { font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin-bottom: 4px; }
        .stat-label { font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }

        /* Products Grid */
        .products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 15px; padding-bottom: 20px; }
        .product-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 15px; display: flex; flex-direction: column; justify-content: space-between; height: 100%; min-height: 160px; position: relative; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: 0.2s; }
        .product-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-color: #cbd5e1; }
        
        .type-badge { position: absolute; top: 10px; right: 10px; font-size: 0.65rem; padding: 3px 8px; border-radius: 12px; background: #f1f5f9; text-transform: uppercase; font-weight: 800; color: #64748b; }
        .product-name { font-weight: 700; font-size: 0.95rem; margin-bottom: 8px; color: var(--text-main); margin-top: 15px; line-height: 1.3; }
        .product-price { font-weight: 800; color: var(--primary); font-size: 1.1rem; }

        /* Stock Status overrides for POS cards */
        .stock-available { color: #059669; font-weight: 700; font-size: 0.8rem; margin-top: 5px; }
        .stock-low { color: #d97706; font-weight: 700; font-size: 0.8rem; margin-top: 5px; }
        .stock-out { color: #dc2626; font-weight: 700; font-size: 0.8rem; margin-top: 5px; }

        /* Cart Sidebar */
        .cart-sidebar { width: 380px; background: var(--bg-card); border-left: 1px solid var(--border); display: flex; flex-direction: column; height: 100vh; right: 0; top: 0; z-index: 200; box-shadow: -2px 0 10px rgba(0, 0, 0, 0.05); transition: transform 0.3s ease-in-out; }
        
        .cart-header { padding: 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .cart-items { flex: 1; overflow-y: auto; padding: 15px; }
        .cart-item { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px dashed var(--border); }
        .cart-item strong { color: var(--text-main); font-size: 0.95rem; }
        
        .qty-btn { width: 32px; height: 32px; background: #f1f5f9; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; font-weight: bold; color: var(--text-main); transition: 0.2s; }
        .qty-btn:hover { background: #e2e8f0; }

        .cart-totals { padding: 20px; background: #f8fafc; border-top: 1px solid var(--border); }
        .total-row { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 8px; color: var(--text-muted); font-weight: 500; }
        .total-row.last { font-size: 1.3rem; font-weight: 800; border-top: 1px solid var(--border); padding-top: 12px; margin-top: 12px; color: var(--text-main); }
        
        .checkout-btn { width: 100%; padding: 16px; background: var(--primary); color: white; border: none; font-weight: 800; font-size: 1.1rem; border-radius: 8px; cursor: pointer; margin-top: 10px; transition: 0.2s; box-shadow: 0 4px 6px rgba(79, 70, 229, 0.2); }
        .checkout-btn:hover { background: var(--primary-hover); transform: translateY(-1px); }

        .pos-tools { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 15px; border-top: 1px solid var(--border); background: white; }
        .tool-btn { padding: 10px 5px; background: #f1f5f9; border: 1px solid transparent; border-radius: 8px; font-size: 0.75rem; font-weight: 600; display: flex; flex-direction: column; align-items: center; gap: 5px; cursor: pointer; color: var(--text-muted); transition: 0.2s; }
        .tool-btn:hover { background: #e2e8f0; color: var(--text-main); }
        .tool-btn i { font-size: 1.2rem; color: var(--text-main); }

        /* CHECKOUT MODAL FIXES */
        .g-modal-body input, .g-modal-body select { margin-bottom: 15px; }
        
        @media(max-width: 992px) {
            .pos-container { flex-direction: column; }
            .pos-main { padding-bottom: 80px; }
            .cart-sidebar { width: 100%; height: 85vh; bottom: 0; top: auto; border-radius: 20px 20px 0 0; position: fixed; transform: translateY(110%); box-shadow: 0 -5px 20px rgba(0,0,0,0.15); }
            .cart-sidebar.open { transform: translateY(0); }
            /* Add a toggle button for mobile cart */
            .mobile-cart-toggle { display: flex; position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 100; background: var(--primary); color: white; padding: 15px 30px; border-radius: 30px; font-weight: 700; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.4); border: none; align-items: center; gap: 10px; cursor: pointer; }
        }

        @media(min-width: 993px) {
            .mobile-cart-toggle { display: none; }
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="pos-container">
        
        <div class="pos-main">
            <div class="search-section">
                <div class="search-row">
                    <div class="search-group">
                        <input type="text" id="searchInput" placeholder="Search product name..." class="search-input">
                    </div>
                    <div class="search-group" style="display:flex; gap:10px;">
                        <input type="text" id="barcodeInput" placeholder="Scan barcode..." class="search-input">
                        <button class="btn btn-primary" onclick="searchByBarcode()" style="padding:12px 20px;"><i class="fas fa-barcode"></i></button>
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
                    <div class="stat-label">Orders Today</div>
                </div>
                <div class="stat-box">
                    <div class="stat-num" style="color: var(--success);">₹<?= number_format($todaySales['revenue'] ?? 0, 0) ?></div>
                    <div class="stat-label">Revenue Today</div>
                </div>
                <div class="stat-box">
                    <div class="stat-num" style="color: var(--warning);"><?= (int)($lowStock['count'] ?? 0) ?></div>
                    <div class="stat-label">Low Stock Items</div>
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
                            <div class="<?= $stockClass ?>"><i class="fas fa-cubes" style="margin-right:4px;"></i> Stock: <?= $stockQty + 0 ?></div>
                        </div>
                        <?php if ($stockQty > 0): ?>
                            <button class="btn btn-outline" style="margin-top:12px; width:100%; border-color:var(--border); color:var(--text-main);" onclick="addToCart(<?= (int)$p['id'] ?>)">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        <?php else: ?>
                             <button class="btn btn-outline" style="margin-top:12px; width:100%; background:#f1f5f9; color:#94a3b8; border-color:#e2e8f0; cursor:not-allowed;" disabled>
                                Out of Stock
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>

        <button class="mobile-cart-toggle" onclick="toggleMobileCart()">
            <i class="fas fa-shopping-basket"></i> View Cart (<span id="mobileCartCount">0</span>)
        </button>

        <div class="cart-sidebar" id="cartSidebar">
            <div class="cart-header">
                <h2 style="margin:0; font-size:1.2rem; font-weight:800; color:var(--text-main);">Current Sale</h2>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span id="cartCount" class="badge" style="background:#e0e7ff; color:var(--primary); font-size:0.8rem; padding:4px 10px;">0 Items</span>
                    <button class="btn-icon mobile-cart-toggle" style="display:none; width:30px; height:30px;" onclick="toggleMobileCart()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            
            <div class="cart-items" id="cartItems"></div>
            
            <div class="cart-totals">
                <div class="total-row"><span>Subtotal (Base Value)</span><span id="subtotal">₹0.00</span></div>
                <div class="total-row"><span style="color:var(--success);">GST (5% Included)</span><span id="taxAmount" style="color:var(--success);">₹0.00</span></div>
                <div class="total-row" style="align-items:center;">
                    <span>Discount</span>
                    <input type="number" id="discountInput" class="form-input" style="width:80px; text-align:right; padding:5px;" value="0" oninput="updateCartDisplay()">
                </div>
                <div class="total-row last"><span>Grand Total</span><span id="grandTotal">₹0.00</span></div>
                <button class="checkout-btn" onclick="openCheckoutModal()"><i class="fas fa-credit-card" style="margin-right:8px;"></i> Proceed to Pay</button>
            </div>
            
            <div class="pos-tools">
                <button class="tool-btn" onclick="clearCart()"><i class="fas fa-trash text-danger"></i> Clear</button>
                <button class="tool-btn" onclick="holdTransaction()"><i class="fas fa-pause text-warning"></i> Hold</button>
                <button class="tool-btn" onclick="loadHeldTransactions()"><i class="fas fa-history text-info"></i> Recall</button>
                <button class="tool-btn" onclick="printLastInvoice()"><i class="fas fa-print text-primary"></i> Print Last</button>
            </div>
        </div>
    </div>

    <div class="global-modal" id="checkoutModal">
        <div class="g-modal-content" style="max-width: 450px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.2rem;"><i class="fas fa-cash-register text-primary" style="margin-right:8px;"></i> Complete Payment</h3>
                <button class="g-close-btn" type="button" onclick="closeCheckoutModal()">&times;</button>
            </div>
            <div class="g-modal-body">
                <form id="checkoutForm" onsubmit="processPayment(event)">
                    <input type="hidden" id="selectedCustId" value="0">
                    
                    <div class="form-group" style="position:relative;">
                        <input type="text" id="custSearch" class="form-input" placeholder="Search Registered Customer..." oninput="suggestCustomer(this.value)">
                        <div id="custSuggestions" style="display:none; position:absolute; top:100%; left:0; width:100%; background:#fff; border:1px solid var(--border); box-shadow:0 4px 6px rgba(0,0,0,0.1); border-radius:6px; max-height:150px; overflow-y:auto; z-index:10;"></div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                        <input type="text" id="custName" class="form-input" placeholder="Walk-in Name" required>
                        <input type="text" id="custPhone" class="form-input" placeholder="Phone Number">
                    </div>
                    
                    <select id="paymentMethod" class="form-input">
                        <option value="Cash">Cash Payment</option>
                        <option value="UPI">UPI / Scanner</option>
                        <option value="Credit">Udhaar (Credit Ledger)</option>
                    </select>
                    
                    <div style="background:#f0fdf4; padding:15px; border-radius:8px; border:1px solid #bbf7d0; text-align:center; margin-bottom:15px;">
                        <div style="font-size:0.8rem; font-weight:700; color:#166534; margin-bottom:5px;">AMOUNT TO RECEIVE</div>
                        <input type="number" id="paidAmount" class="form-input" step="0.01" required style="font-size:1.5rem; font-weight:800; color:var(--success); text-align:center; background:transparent; border:none; padding:0;">
                    </div>
                    
                    <button type="submit" class="checkout-btn" style="margin-top:0;"><i class="fas fa-check-circle"></i> Confirm & Print Bill</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        let cart = {};
        let lastOrderId = null;

        // Mobile Cart Toggle
        function toggleMobileCart() {
            const sidebar = document.getElementById('cartSidebar');
            sidebar.classList.toggle('open');
            // Show/Hide close button inside header based on screen size
            const closeBtn = sidebar.querySelector('.mobile-cart-toggle.btn-icon');
            if(window.innerWidth <= 992) {
                closeBtn.style.display = 'block';
            } else {
                closeBtn.style.display = 'none';
            }
        }

        function filterType(type, btn) {
            document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            document.querySelectorAll('.product-card').forEach(card => {
                const itemType = card.dataset.type.toLowerCase();
                const itemName = card.dataset.name.toLowerCase();

                if (type === 'all') {
                    card.style.display = 'flex';
                }
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
// --- CUSTOMER AUTOSUGGESTION LOGIC ---
        let custTimeout = null;
        
        function suggestCustomer(term) {
            const suggBox = document.getElementById('custSuggestions');
            
            if (term.length < 2) {
                suggBox.style.display = 'none';
                if (term.length === 0) {
                    document.getElementById('selectedCustId').value = "0"; // Reset ID if empty
                }
                return;
            }

            clearTimeout(custTimeout);
            custTimeout = setTimeout(() => {
                const fd = new FormData();
                fd.append('action', 'search_customer');
                fd.append('term', term);

                fetch('sales_entry.php', {
                    method: 'POST',
                    body: fd
                })
                .then(res => res.json())
                .then(data => {
                    if (data.length > 0) {
                        let html = '';
                        data.forEach(cust => {
                            // Bachao against special quotes in names
                            const safeName = cust.value.replace(/'/g, "\\'");
                            html += `
                                <div style="padding:10px 15px; border-bottom:1px solid var(--border); cursor:pointer; transition:0.2s;" 
                                     onmouseover="this.style.background='#f1f5f9'" 
                                     onmouseout="this.style.background='transparent'"
                                     onclick="selectCustomer(${cust.id}, '${safeName}', '${cust.phone}')">
                                    <strong style="color:var(--text-main);">${cust.value}</strong><br>
                                    <small style="color:var(--text-muted);"><i class="fas fa-phone" style="font-size:10px; margin-right:4px;"></i>${cust.phone || 'No Number'}</small>
                                </div>`;
                        });
                        suggBox.innerHTML = html;
                        suggBox.style.display = 'block';
                    } else {
                        suggBox.innerHTML = '<div style="padding:15px; color:var(--text-muted); font-size:0.9rem; text-align:center;">No customer found. <br><small>Will create new.</small></div>';
                        suggBox.style.display = 'block';
                    }
                }).catch(err => console.log("Suggestion Error:", err));
            }, 300); // 300ms delay to stop server overload
        }

        function selectCustomer(id, name, phone) {
            document.getElementById('selectedCustId').value = id;
            document.getElementById('custName').value = name;
            document.getElementById('custPhone').value = phone;
            document.getElementById('custSearch').value = name; // Update search box
            
            document.getElementById('custSuggestions').style.display = 'none';
        }
        function updateCartDisplay() {
            const container = document.getElementById('cartItems');
            let html = '',
                subtotalBase = 0,
                totalTax = 0,
                grandTotalNoDisc = 0;

            for (const [id, item] of Object.entries(cart)) {
                const itemTotalInclTax = parseFloat(item.price) * parseInt(item.qty);
                const gstRate = parseFloat(item.gstrate) || 0;
                const itemBasePrice = itemTotalInclTax / (1 + (gstRate / 100));
                const itemTaxAmount = itemTotalInclTax - itemBasePrice;

                subtotalBase += itemBasePrice;
                totalTax += itemTaxAmount;
                grandTotalNoDisc += itemTotalInclTax;

                html += `<div class="cart-item">
                    <div style="flex:1;">
                        <strong>${item.name}</strong><br>
                        <small style="color:var(--text-muted); font-weight:500;">₹${parseFloat(item.price).toFixed(2)} x ${item.qty} <span style="font-size:10px;">(Inc. ${gstRate}% GST)</span></small>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button class="qty-btn" onclick="updateQty(${id}, -1)">-</button> 
                        <span style="display:inline-block; width:20px; text-align:center; font-weight:800; font-size:1rem;">${item.qty}</span> 
                        <button class="qty-btn" onclick="updateQty(${id}, 1)">+</button>
                    </div>
                </div>`;
            }

            if(!html) {
                html = '<div style="text-align:center;color:#94a3b8;padding:40px 20px;"><i class="fas fa-shopping-basket fa-3x" style="margin-bottom:15px; opacity:0.3;"></i><br><span style="font-weight:500;">Cart is empty</span></div>';
            }
            container.innerHTML = html; 

            const disc = parseFloat(document.getElementById('discountInput').value) || 0;
            const finalGrandTotal = grandTotalNoDisc - disc;

            document.getElementById('subtotal').textContent = '₹' + subtotalBase.toFixed(2);
            document.getElementById('taxAmount').textContent = '₹' + totalTax.toFixed(2);
            document.getElementById('grandTotal').textContent = '₹' + finalGrandTotal.toFixed(2);
            
            const countText = Object.keys(cart).length;
            document.getElementById('cartCount').textContent = countText + ' Items';
            document.getElementById('mobileCartCount').textContent = countText; // Update mobile button

            const paidInput = document.getElementById('paidAmount');
            if (paidInput) paidInput.value = finalGrandTotal.toFixed(2);
        }

        function addToCart(productId) {
            const card = document.querySelector(`.product-card[data-id="${productId}"]`);
            if (!card) return;
            const name = card.dataset.name,
                price = parseFloat(card.dataset.price),
                stock = parseFloat(card.dataset.stock),
                gstrate = parseFloat(card.dataset.gstrate);

            if (cart[productId]) {
                if (cart[productId].qty >= stock) return alert('Stock limit reached!');
                cart[productId].qty++;
            } else {
                cart[productId] = {
                    id: productId, name: name, price: price, qty: 1, stock: stock, gstrate: gstrate
                };
            }
            updateCartDisplay();
        }

        function updateQty(id, change) {
            cart[id].qty += change;
            if (cart[id].qty <= 0) delete cart[id];
            updateCartDisplay();
        }

        function openCheckoutModal() {
            if (Object.keys(cart).length === 0) return alert('Cart is empty. Please add products first.');
            document.getElementById('paidAmount').value = document.getElementById('grandTotal').innerText.replace('₹', '');
            document.getElementById('checkoutModal').classList.add('active');
        }

        function closeCheckoutModal() {
            document.getElementById('checkoutModal').classList.remove('active');
        }

        function processPayment(e) {
            e.preventDefault();
            
            const btn = e.target.querySelector('.checkout-btn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

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
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }).catch(err => {
                alert("Network Error. Please try again.");
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        function printLastInvoice() {
            if (lastOrderId) window.open('print_engine.php?doc=pos_invoice&id=' + lastOrderId, 'ThermalPrint', 'width=400,height=600');
            else alert("No recent invoice found in this session.");
        }

        function clearCart() {
            if(confirm("Are you sure you want to clear the cart?")) {
                cart = {};
                document.getElementById('discountInput').value = 0;
                updateCartDisplay();
            }
        }

        document.getElementById('searchInput').addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            document.querySelectorAll('.product-card').forEach(card => {
                card.style.display = card.dataset.name.toLowerCase().includes(term) ? 'flex' : 'none';
            });
        });
        
        // Close modal on outside click
        window.onclick = function(e) {
            const modal = document.getElementById('checkoutModal');
            if (e.target == modal) {
                closeCheckoutModal();
            }
        }
    </script>
</body>
</html>