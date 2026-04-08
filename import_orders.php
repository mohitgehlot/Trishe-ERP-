<?php
// import_orders.php - MANUAL PAST ORDER ENTRY (VIRTUAL COLUMN BUG FIXED)
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit;
}

// ==========================================
// 🌟 AJAX BACKEND 1: CUSTOMER SEARCH 🌟
// ==========================================
if (isset($_POST['action']) && $_POST['action'] === 'search_customer') {
    ob_clean();
    header('Content-Type: application/json');
    $term = $conn->real_escape_string($_POST['term']) . '%';
    
    $stmt = $conn->prepare("SELECT id, name, phone FROM customers WHERE phone LIKE ? OR name LIKE ? LIMIT 5");
    $stmt->bind_param("ss", $term, $term);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $data = [];
    while($r = $res->fetch_assoc()) {
        $data[] = [
            'id' => $r['id'],
            'value' => $r['name'],
            'phone' => $r['phone']
        ];
    }
    echo json_encode($data);
    exit;
}

// ==========================================
// 🌟 AJAX BACKEND 2: SAVE THE ORDER 🌟
// ==========================================
if (isset($_POST['action']) && $_POST['action'] === 'save_past_order') {
    ob_clean(); 
    header('Content-Type: application/json; charset=utf-8');
    
    $order_date = trim($_POST['order_date']) . ' ' . date('H:i:s'); 
    $cust_id = intval($_POST['customer_id'] ?? 0);
    $cust_name = trim($_POST['customer_name'] ?? 'Walk-in Customer');
    $cust_phone = trim($_POST['customer_phone'] ?? '');
    
    $pay_mode = trim($_POST['payment_mode'] ?? 'cash'); // 'cash', 'upi', 'card', 'Credit'
    $pay_status = trim($_POST['payment_status'] ?? 'Paid'); // 'Paid', 'Pending'
    $discount = floatval($_POST['discount'] ?? 0);
    
    $update_stock = isset($_POST['update_stock']) && $_POST['update_stock'] == '1' ? true : false;
    $cart = json_decode($_POST['cart'] ?? '[]', true);
    $admin_id = $_SESSION['admin_id'];

    if (empty($cart) || empty($cust_name)) {
        echo json_encode(['success' => false, 'error' => 'Cart is empty or Customer Name missing!']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 🌟 1. Customer Check or Create 🌟
        if ($cust_id == 0) {
            if (!empty($cust_phone)) {
                $check = $conn->query("SELECT id FROM customers WHERE phone = '" . $conn->real_escape_string($cust_phone) . "' LIMIT 1");
                if ($check && $check->num_rows > 0) {
                    $row = $check->fetch_assoc();
                    $cust_id = $row['id'];
                }
            }
            if ($cust_id == 0 && strtolower($cust_name) !== 'walk-in customer') {
                $stmtNew = $conn->prepare("INSERT INTO customers (name, phone, created_at) VALUES (?, ?, ?)");
                $stmtNew->bind_param("sss", $cust_name, $cust_phone, $order_date);
                if ($stmtNew->execute()) {
                    $cust_id = $stmtNew->insert_id; 
                }
            }
        }
        $sql_cust_id = ($cust_id > 0) ? $cust_id : NULL;

        // 🌟 2. Order Totals Calculate 🌟
        $subtotal = 0;
        $total_tax = 0;
        
        foreach ($cart as $item) {
            $p_id = intval($item['p_id']);
            $qty = floatval($item['qty']);
            $price_incl_tax = floatval($item['rate']);
            
            $p_data = $conn->query("SELECT COALESCE(tax_rate, 5) as tax_rate FROM products WHERE id = $p_id")->fetch_assoc();
            $gst_rate = floatval($p_data['tax_rate'] ?? 5);

            $itemTotal = $price_incl_tax * $qty;
            $basePrice = $itemTotal / (1 + ($gst_rate / 100));
            
            $subtotal += $basePrice;
            $total_tax += ($itemTotal - $basePrice);
        }
        
        $final_amount = ($subtotal + $total_tax) - $discount;
        if($final_amount < 0) $final_amount = 0;

        // 🌟 3. Payment Logic & Status 🌟
        $db_status = 'Delivered'; 
        
        if (strtolower($pay_mode) === 'credit' || strtolower($pay_status) === 'pending') {
            $payment_status = 'Pending';
            $paid_amount = 0;
            $pay_mode = 'Credit'; 
        } else {
            $payment_status = 'Paid';
            $paid_amount = $final_amount;
        }

        $order_no = 'ORD-' . time() . rand(10, 99); 

        // 🌟 4. Insert into 'orders' table (EXACT COLUMNS TO AVOID VIRTUAL COLUMN ERROR) 🌟
        $stmtOrd = $conn->prepare("INSERT INTO orders (order_no, customer_id, subtotal, tax, discount, total, paid_amount, payment_status, payment_method, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if(!$stmtOrd) throw new Exception("Order Table Error: " . $conn->error); 
        
        $stmtOrd->bind_param("sidddddsssss", $order_no, $sql_cust_id, $subtotal, $total_tax, $discount, $final_amount, $paid_amount, $payment_status, $pay_mode, $db_status, $admin_id, $order_date);
        if (!$stmtOrd->execute()) throw new Exception("Order Save Failed: " . $stmtOrd->error);
        
        $new_order_id = $stmtOrd->insert_id;

        // 🌟 5. Insert Items & Inventory 🌟
        $checkInv = $conn->query("SHOW COLUMNS FROM inventory_products LIKE 'customer_name'");
        $hasCustCol = ($checkInv && $checkInv->num_rows > 0);

        $stmtItem = $conn->prepare("INSERT INTO order_items (order_id, product_id, batch_no, qty, unit, price_snapshot, cost_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        if($hasCustCol) {
            $stmtInv = $conn->prepare("INSERT INTO inventory_products (product_id, batch_no, qty, unit, transaction_type, sale_price, customer_name, mfg_date, created_at) VALUES (?, 'SALE', ?, 'Pcs', 'SALE', ?, ?, ?, ?)");
        } else {
            $stmtInv = $conn->prepare("INSERT INTO inventory_products (product_id, batch_no, qty, unit, transaction_type, created_at) VALUES (?, 'SALE', ?, 'Pcs', 'SALE', ?)");
        }

        foreach ($cart as $item) {
            $pid = intval($item['p_id']);
            $qty = floatval($item['qty']);
            $price = floatval($item['rate']);
            $line_total = $qty * $price;
            
            $p_res = $conn->query("SELECT cost_price, unit FROM products WHERE id = $pid");
            $p_info = $p_res ? $p_res->fetch_assoc() : [];
            $cost_now = floatval($p_info['cost_price'] ?? 0);
            $unit_now = $p_info['unit'] ?? 'Pcs';
            $batch_sale = 'SALE';
            
            // Insert order_items
            $stmtItem->bind_param("iissdssd", $new_order_id, $pid, $batch_sale, $qty, $unit_now, $price, $cost_now, $line_total);
            $stmtItem->execute();

            // Insert inventory (ONLY if checkbox is ticked)
            if ($update_stock) {
                $inv_qty = -$qty;
                if($hasCustCol) {
                    $stmtInv->bind_param("iddsss", $pid, $inv_qty, $price, $cust_name, $order_date, $order_date);
                } else {
                    $stmtInv->bind_param("ids", $pid, $inv_qty, $order_date);
                }
                $stmtInv->execute();
            }
        }

        $conn->commit();
        echo json_encode(['success' => true, 'msg' => "Order #$order_no Saved Successfully!"]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch all active products for Dropdown
$products = [];
$p_query = $conn->query("SELECT id, name, base_price FROM products WHERE is_active = 1 ORDER BY name ASC");
while ($row = $p_query->fetch_assoc()) {
    $products[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manual Order Entry | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_style.css">
    <style>
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .page-header { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin: 0; }
        
        .card { background: #fff; border-radius: 8px; border: 1px solid var(--border); padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-title { font-size: 1.1rem; font-weight: 700; border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 15px; color: var(--primary); }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }

        /* Dynamic Table */
        .item-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .item-table th { background: #f8fafc; padding: 12px; text-align: left; font-weight: 600; color: #475569; border-bottom: 2px solid var(--border); }
        .item-table td { padding: 10px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .item-table input, .item-table select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; outline: none; }
        .item-table input:focus, .item-table select:focus { border-color: var(--primary); }
        .remove-btn { color: var(--danger); background: #fee2e2; border: none; width: 35px; height: 35px; border-radius: 6px; cursor: pointer; font-size: 1rem; transition: 0.2s; }
        .remove-btn:hover { background: var(--danger); color: white; }

        .totals-box { background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid var(--border); }
        .total-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-weight: 600; color: #475569; font-size: 1rem; align-items: center; }
        .total-row.grand { font-size: 1.4rem; font-weight: 800; color: var(--success); border-top: 1px dashed #cbd5e1; padding-top: 10px; margin-top: 5px; }

        /* Suggestions */
        .suggestions { position: absolute; background: white; width: 100%; border: 1px solid #cbd5e1; border-top: none; border-radius: 0 0 6px 6px; max-height: 150px; overflow-y: auto; z-index: 50; display: none; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
        .s-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        .s-item:hover { background-color: #f8fafc; color: var(--primary); }

        .btn-large { padding: 15px; font-size: 1.1rem; width: 100%; justify-content: center; font-weight: 800; }
    </style>
</head>
<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-keyboard text-primary" style="margin-right:10px;"></i> Manual Past Order Entry</h1>
            <a href="admin_orders.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Orders</a>
        </div>

        <form id="orderForm" onsubmit="saveOrder(event)">
            
            <div class="card" style="background: #eef2ff; border-color: #c7d2fe;">
                <div class="grid-2" style="align-items: center;">
                    <div>
                        <label class="form-label" style="color: var(--primary); font-weight:800;">Order Date (Stays selected) *</label>
                        <input type="date" id="order_date" name="order_date" class="form-input" style="font-size:1.1rem; font-weight:700; border-color:var(--primary);" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div style="background:#fff; padding:15px; border-radius:8px; border:1px solid #c7d2fe;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:700; color:#b45309;">
                            <input type="checkbox" id="update_stock" value="1" style="width:20px; height:20px;">
                            Deduct Inventory Stock?
                        </label>
                        <small style="display:block; margin-left:30px; margin-top:5px; color:#64748b;">(Leave unchecked to protect current live stock).</small>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-user"></i> Customer Details</div>
                <div class="grid-3">
                    <div class="form-group" style="position:relative;">
                        <label class="form-label">Search Old Customer</label>
                        <input type="text" id="custSearch" class="form-input" placeholder="Type Name or Phone..." oninput="suggestCustomer(this.value)" autocomplete="off">
                        <input type="hidden" id="cust_id" value="0">
                        <div id="custSuggestions" class="suggestions"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Customer Name *</label>
                        <input type="text" id="cust_name" class="form-input" placeholder="Walk-in Customer" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" id="cust_phone" class="form-input" placeholder="Optional">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title" style="display:flex; justify-content:space-between; border:none; margin-bottom:0;">
                    <span><i class="fas fa-shopping-cart"></i> Order Items</span>
                    <button type="button" class="btn" style="background:#10b981;" onclick="addRow()"><i class="fas fa-plus"></i> Add Row</button>
                </div>
                
                <table class="item-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Product *</th>
                            <th style="width: 15%;">Qty *</th>
                            <th style="width: 20%;">Rate (₹) *</th>
                            <th style="width: 20%;">Total (₹)</th>
                            <th style="width: 5%;"></th>
                        </tr>
                    </thead>
                    <tbody id="cartBody">
                        </tbody>
                </table>
            </div>

            <div class="grid-2">
                <div class="card">
                    <div class="card-title"><i class="fas fa-wallet"></i> Payment Info</div>
                    <div class="form-group">
                        <label class="form-label">Payment Mode</label>
                        <select id="pay_mode" class="form-input">
                            <option value="cash">Cash</option>
                            <option value="upi">UPI</option>
                            <option value="card">Card / Bank Transfer</option>
                            <option value="Credit">Credit (Udhaari)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Status</label>
                        <select id="pay_status" class="form-input" style="font-weight:700;">
                            <option value="Paid" style="color:green;">Paid</option>
                            <option value="Pending" style="color:red;">Pending (Add to Dues)</option>
                        </select>
                    </div>
                </div>

                <div class="card totals-box">
                    <div class="total-row">
                        <span>Gross Total:</span>
                        <span id="disp_gross">₹0.00</span>
                    </div>
                    <div class="total-row" style="align-items:center;">
                        <span>Discount (₹):</span>
                        <input type="number" id="discount" class="form-input" style="width:100px; padding:5px; text-align:right;" value="0" oninput="calculateGrandTotal()">
                    </div>
                    <div class="total-row grand">
                        <span>Net Final Amount:</span>
                        <span id="disp_net">₹0.00</span>
                    </div>

                    <button type="submit" id="saveBtn" class="btn btn-primary btn-large" style="margin-top:20px;">
                        <i class="fas fa-save"></i> Save Order
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        const products = <?php echo json_encode($products); ?>;
        
        // --- CUSTOMER AUTOSUGGESTION (FIXED ROUTING) ---
        let custTimeout = null;
        function suggestCustomer(term) {
            const suggBox = document.getElementById('custSuggestions');
            if (term.length < 2) {
                suggBox.style.display = 'none';
                if(term.length === 0) document.getElementById('cust_id').value = "0";
                return;
            }

            clearTimeout(custTimeout);
            custTimeout = setTimeout(() => {
                const fd = new FormData();
                fd.append('action', 'search_customer');
                fd.append('term', term);

                // FIXED: Directing to itself (import_orders.php) instead of sales_entry.php
                fetch('import_orders.php', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.length > 0) {
                        let html = '';
                        data.forEach(cust => {
                            const safeName = cust.value ? cust.value.replace(/'/g, "\\'") : 'Unknown';
                            const phone = cust.phone ? cust.phone : '';
                            html += `<div class="s-item" onclick="selectCustomer(${cust.id}, '${safeName}', '${phone}')">
                                    <strong>${safeName}</strong> <small>(${phone || 'No Phone'})</small>
                                </div>`;
                        });
                        suggBox.innerHTML = html;
                        suggBox.style.display = 'block';
                    } else {
                        suggBox.style.display = 'none';
                    }
                }).catch(e => console.log(e));
            }, 300);
        }

        function selectCustomer(id, name, phone) {
            document.getElementById('cust_id').value = id;
            document.getElementById('cust_name').value = name;
            document.getElementById('cust_phone').value = phone;
            document.getElementById('custSearch').value = ''; 
            document.getElementById('custSuggestions').style.display = 'none';
        }

        // --- DYNAMIC CART ROWS ---
        let rowCount = 0;

        function addRow() {
            rowCount++;
            const tbody = document.getElementById('cartBody');
            const tr = document.createElement('tr');
            tr.id = 'row_' + rowCount;

            let optionsHtml = '<option value="">-- Select Product --</option>';
            products.forEach(p => {
                optionsHtml += `<option value="${p.id}" data-rate="${p.base_price}">${p.name}</option>`;
            });

            tr.innerHTML = `
                <td>
                    <select class="p_id" onchange="autoFillRate(this)" required>
                        ${optionsHtml}
                    </select>
                </td>
                <td><input type="number" class="p_qty" step="0.01" value="1" min="0.01" required oninput="calculateGrandTotal()"></td>
                <td><input type="number" class="p_rate" step="0.01" required oninput="calculateGrandTotal()"></td>
                <td style="font-weight:700; font-size:1.1rem; color:var(--text-main);"><span class="p_total">₹0.00</span></td>
                <td><button type="button" class="remove-btn" onclick="removeRow(${rowCount})"><i class="fas fa-times"></i></button></td>
            `;
            tbody.appendChild(tr);
        }

        function removeRow(id) {
            document.getElementById('row_' + id).remove();
            calculateGrandTotal();
        }

        function autoFillRate(selectElement) {
            const tr = selectElement.closest('tr');
            const rateInput = tr.querySelector('.p_rate');
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            
            if(selectedOption.value !== "") {
                rateInput.value = selectedOption.getAttribute('data-rate');
            } else {
                rateInput.value = "";
            }
            calculateGrandTotal();
        }

        function calculateGrandTotal() {
            let gross = 0;
            const rows = document.querySelectorAll('#cartBody tr');
            
            rows.forEach(tr => {
                const qty = parseFloat(tr.querySelector('.p_qty').value) || 0;
                const rate = parseFloat(tr.querySelector('.p_rate').value) || 0;
                const lineTotal = qty * rate;
                
                tr.querySelector('.p_total').innerText = '₹' + lineTotal.toFixed(2);
                gross += lineTotal;
            });

            const discount = parseFloat(document.getElementById('discount').value) || 0;
            let net = gross - discount;
            if(net < 0) net = 0;

            document.getElementById('disp_gross').innerText = '₹' + gross.toFixed(2);
            document.getElementById('disp_net').innerText = '₹' + net.toFixed(2);
        }

        // --- SUBMIT LOGIC (FIXED ROUTING) ---
        function saveOrder(e) {
            e.preventDefault();

            let cartData = [];
            const rows = document.querySelectorAll('#cartBody tr');
            
            if(rows.length === 0) {
                alert("Please add at least one product to the order!");
                return;
            }

            let isValid = true;
            rows.forEach(tr => {
                const p_id = tr.querySelector('.p_id').value;
                const qty = tr.querySelector('.p_qty').value;
                const rate = tr.querySelector('.p_rate').value;

                if(p_id === "" || qty <= 0 || rate <= 0) isValid = false;

                cartData.push({ p_id: p_id, qty: qty, rate: rate });
            });

            if(!isValid) {
                alert("Please fill all product details correctly.");
                return;
            }

            const btn = document.getElementById('saveBtn');
            const ogText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'save_past_order');
            fd.append('order_date', document.getElementById('order_date').value);
            fd.append('update_stock', document.getElementById('update_stock').checked ? 1 : 0);
            
            fd.append('customer_id', document.getElementById('cust_id').value);
            fd.append('customer_name', document.getElementById('cust_name').value);
            fd.append('customer_phone', document.getElementById('cust_phone').value);
            
            fd.append('payment_mode', document.getElementById('pay_mode').value);
            fd.append('payment_status', document.getElementById('pay_status').value);
            fd.append('discount', document.getElementById('discount').value);
            
            fd.append('cart', JSON.stringify(cartData));

            // FIXED: Directing to itself (import_orders.php)
            fetch('import_orders.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if(res.success) {
                    alert("✅ " + res.msg);
                    clearFormKeepDate();
                } else {
                    alert("❌ Error: " + res.error);
                }
                btn.innerHTML = ogText;
                btn.disabled = false;
            }).catch(err => {
                alert("❌ Network Error!");
                btn.innerHTML = ogText;
                btn.disabled = false;
            });
        }

        function clearFormKeepDate() {
            document.getElementById('cust_id').value = "0";
            document.getElementById('custSearch').value = "";
            document.getElementById('cust_name').value = "";
            document.getElementById('cust_phone').value = "";
            document.getElementById('discount').value = "0";
            
            document.getElementById('cartBody').innerHTML = ""; 
            addRow(); 
            calculateGrandTotal();
            
            document.getElementById('cust_name').focus();
        }

        window.onload = function() {
            addRow();
        };
    </script>
</body>
</html>