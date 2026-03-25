<?php
// grn_intake.php - MASTER CSS INTEGRATED
include 'config.php';
session_start();

// Security Check
if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

// Fetch Seeds Data for Autocomplete
$seeds_with_prices = [];
$stmt = $conn->prepare("
    SELECT 
        sm.id, sm.name, sm.category,
        COALESCE((SELECT price_per_qtl FROM inventory_grn_items igi JOIN inventory_grn ig ON igi.grn_id = ig.id WHERE igi.seed_id = sm.id ORDER BY ig.created_at DESC LIMIT 1), 0) as last_price
    FROM seeds_master sm
    ORDER BY sm.name
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $seeds_with_prices[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>New GRN Entry | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            overflow-x: hidden;
        }

        .page-header-box {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 24px;
            align-items: start;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 16px;
            margin-bottom: 15px;
        }

        /* Specific styles for Autocomplete */
        .autocomplete-wrapper {
            position: relative;
        }

        .autocomplete-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--border);
            border-radius: 0 0 8px 8px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 100;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            display: none;
        }

        .suggestion-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.95rem;
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-item:hover,
        .suggestion-item.active {
            background-color: #e0e7ff;
            color: var(--primary);
        }

        .suggestion-meta {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .seller-history-box {
            background-color: #eff6ff;
            border: 1px dashed #bfdbfe;
            border-radius: 8px;
            padding: 15px;
            margin-top: 5px;
            margin-bottom: 20px;
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .history-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .view-history-btn {
            font-size: 0.85rem;
            color: var(--primary);
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .view-history-btn:hover {
            text-decoration: underline;
        }

        .manual-link {
            color: var(--primary);
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: underline;
            display: inline-block;
            margin-top: 8px;
            font-weight: 600;
        }

        #line_total {
            color: var(--primary);
            font-weight: 800;
            font-size: 1.2rem;
        }

        @media (max-width: 1024px) {
            .grid-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body {
                padding-left: 0;
            }

            .container {
                padding: 15px;
            }

            .page-header-box {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .page-header-box .btn {
                width: 100%;
                justify-content: center;
            }

            .form-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
        }

        @media (max-width: 480px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-truck-loading text-primary"></i> GRN Entry (Raw Material)</h1>
            <a href="inventory.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Inventory</a>
        </div>

        <form id="grnForm" autocomplete="off">
            <input type="hidden" name="items_json" id="items_json" value='[]'>
            <input type="hidden" name="seller_id" id="seller_id">
            <input type="hidden" name="action" value="create_grn">

            <div class="grid-layout">

                <div class="left-col">
                    <div class="card" style="margin-bottom: 24px;">
                        <div class="card-header"><i class="fas fa-user text-primary"></i> Seller Information</div>
                        <div style="padding: 20px;">

                            <div class="form-group autocomplete-wrapper">
                                <label class="form-label">Seller Name *</label>
                                <input type="text" id="seller_name" name="seller_name" class="form-input" placeholder="Search seller..." required>
                                <div id="seller_list" class="autocomplete-list"></div>
                                <small id="new_seller_hint" style="display:none; color:var(--warning); margin-top:5px; font-weight:600;">
                                    <i class="fas fa-plus-circle"></i> New Seller will be created automatically.
                                </small>
                            </div>

                            <div id="seller_history_widget" class="seller-history-box">
                                <div class="history-stats">
                                    <span>Total Orders: <strong id="hist_count" style="color:var(--primary);">0</strong></span>
                                    <span>Total Value: <strong style="color:var(--success);">₹<span id="hist_val">0</span></strong></span>
                                </div>
                                <div class="view-history-btn" onclick="openHistoryModal()">
                                    <i class="fas fa-eye"></i> View Previous Transactions
                                </div>
                            </div>

                            <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                                <div class="form-group">
                                    <label class="form-label">Vehicle No *</label>
                                    <input type="text" id="vehicle_no" name="vehicle_no" class="form-input" placeholder="RJ-XX-0000" required style="text-transform: uppercase;">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" id="phone" name="phone" class="form-input" placeholder="Mobile No" readonly style="background:#f8fafc; color:var(--text-muted);">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Address</label>
                                <input type="text" id="seller_address" name="seller_address" class="form-input" placeholder="Village / City" readonly style="background:#f8fafc; color:var(--text-muted);">
                            </div>

                            <div style="text-align:right;">
                                <span id="manual_entry_btn" class="manual-link" onclick="enableManualSeller()">
                                    New Seller? Enter Manually
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><i class="fas fa-wallet text-success"></i> Payment Details</div>
                        <div style="padding: 20px;">
                            <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                                <div class="form-group">
                                    <label class="form-label">Payment Mode</label>
                                    <select id="payment_mode" name="payment_mode" class="form-input">
                                        <option value="Pending">Credit (Pending)</option>
                                        <option value="Cash">Cash</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                        <option value="UPI">UPI</option>
                                        <option value="Cheque">Cheque</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Date</label>
                                    <input type="date" id="payment_date" name="payment_date" class="form-input" value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Ref No / Remarks</label>
                                <input type="text" id="payment_ref" name="payment_ref" class="form-input" placeholder="Transaction ID or Notes">
                            </div>

                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px; padding-top:15px; border-top:1px dashed var(--border);">
                                <span style="font-size:0.95rem; font-weight:700; color:var(--text-main);">Status:</span>
                                <span id="pay_status_badge" class="badge st-pending" style="padding:6px 12px; font-size:0.85rem;">Unpaid (Credit)</span>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="right-col">

                    <div class="card" style="margin-bottom: 24px;">
                        <div class="card-header"><i class="fas fa-seedling text-warning"></i> Add Seeds</div>
                        <div style="padding: 20px;">

                            <div class="form-group autocomplete-wrapper">
                                <label class="form-label">Select Seed (Type to search) *</label>
                                <input type="text" id="seed_search" class="form-input" placeholder="e.g. Mustard, Peanut...">
                                <input type="hidden" id="selected_seed_id">
                                <div id="seed_list" class="autocomplete-list"></div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Category</label>
                                    <input type="text" id="seed_category" class="form-input" readonly tabindex="-1" style="background:#f8fafc; color:var(--text-muted);">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Last Price (Ref)</label>
                                    <input type="text" id="last_price" class="form-input" readonly tabindex="-1" style="background:#f8fafc; color:var(--text-muted);">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Quality Grade</label>
                                    <select id="seed_quality" class="form-input">
                                        <option value="A">Grade A (Best)</option>
                                        <option value="B">Grade B (Good)</option>
                                        <option value="C">Grade C (Avg)</option>
                                        <option value="D">Grade D (Low)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">No. of Bags</label>
                                    <input type="number" id="seed_bags" class="form-input" placeholder="0">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Price (₹/Qtl) *</label>
                                    <input type="number" id="seed_price" class="form-input" step="0.01" placeholder="0.00">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Weight (Kg) *</label>
                                    <input type="number" id="seed_weight" class="form-input" step="0.01" placeholder="0.00">
                                </div>
                            </div>

                            <div class="form-group" style="text-align:right; font-size:1.1rem; font-weight:700; color:var(--text-main); margin-top:10px;">
                                Line Total: ₹ <span id="line_total">0.00</span>
                            </div>

                            <button type="button" id="add_item_btn" class="btn btn-outline" style="width:100%; border-color:var(--primary); color:var(--primary); margin-top:10px;">
                                <i class="fas fa-plus-circle"></i> Add Item to List
                            </button>

                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><i class="fas fa-list text-info"></i> Items List</div>
                        <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Details</th>
                                        <th>Rate</th>
                                        <th>Weight</th>
                                        <th>Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="items_table_body">
                                    <tr>
                                        <td colspan="6" style="text-align:center; color:var(--text-muted); padding:30px;">No items added yet.</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr style="background:#f8fafc; border-top:2px solid var(--border);">
                                        <td colspan="3" style="text-align:right; font-weight:800;">Grand Total:</td>
                                        <td id="grand_weight" style="font-weight:700;">0.00 Kg</td>
                                        <td id="grand_value" style="font-weight:800; color:var(--success);">₹ 0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <button type="submit" id="submit_grn_btn" class="btn btn-primary" style="width:100%; padding:15px; font-size:1.1rem; margin-top:24px; background:var(--success); border-color:var(--success);">
                        <i class="fas fa-check-circle"></i> Save GRN Entry
                    </button>

                </div>
            </div>
        </form>
    </div>

    <div id="historyModal" class="global-modal">
        <div class="g-modal-content" style="max-width: 800px;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.2rem; color:var(--text-main);"><i class="fas fa-history text-primary" style="margin-right:8px;"></i> Previous Orders: <span id="modal_seller_name" style="color:var(--primary);"></span></h3>
                <span class="g-close-btn" onclick="closeHistoryModal()">&times;</span>
            </div>
            <div class="g-modal-body" style="padding:0;">
                <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0; max-height:400px; margin-bottom:0;">
                    <table style="width:100%;">
                        <thead style="background:#f8fafc; position:sticky; top:0;">
                            <tr>
                                <th>Date</th>
                                <th>GRN No</th>
                                <th>Weight</th>
                                <th>Amount</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="history_table_body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // --- Print Stickers Function ---
            window.printAllStickers = function(grnId) {
                if (!grnId) return;
                window.open(`print_engine.php?doc=grn_all_stickers&grn_id=${grnId}`, 'StickerPrint', 'width=400,height=500');
            };

            // --- DATA ---
            const allSeeds = <?php echo json_encode($seeds_with_prices); ?>;
            let addedItems = [];
            let sellerList = [];
            let currentFocus = -1;
            let debounceTimer;

            // --- DOM ELEMENTS ---
            const els = {
                sellerName: document.getElementById('seller_name'),
                sellerList: document.getElementById('seller_list'),
                sellerId: document.getElementById('seller_id'),
                sellerAddr: document.getElementById('seller_address'),
                sellerPhone: document.getElementById('phone'),
                vehicle: document.getElementById('vehicle_no'),
                manualBtn: document.getElementById('manual_entry_btn'),
                newSellerHint: document.getElementById('new_seller_hint'),

                sellerWidget: document.getElementById('seller_history_widget'),
                histCount: document.getElementById('hist_count'),
                histVal: document.getElementById('hist_val'),

                seedSearch: document.getElementById('seed_search'),
                seedList: document.getElementById('seed_list'),
                seedId: document.getElementById('selected_seed_id'),
                seedCat: document.getElementById('seed_category'),
                lastPrice: document.getElementById('last_price'),

                price: document.getElementById('seed_price'),
                weight: document.getElementById('seed_weight'),
                quality: document.getElementById('seed_quality'),
                bags: document.getElementById('seed_bags'),
                lineTotal: document.getElementById('line_total'),

                addBtn: document.getElementById('add_item_btn'),
                tableBody: document.getElementById('items_table_body'),
                grandWeight: document.getElementById('grand_weight'),
                grandValue: document.getElementById('grand_value'),

                payMode: document.getElementById('payment_mode'),
                payStatus: document.getElementById('pay_status_badge'),

                form: document.getElementById('grnForm'),
                submitBtn: document.getElementById('submit_grn_btn')
            };

            // --- 1. SELLER LOGIC (DEBOUNCED SEARCH + KEYBOARD) ---
            els.sellerName.addEventListener('input', function() {
                const val = this.value.trim();
                els.sellerId.value = '';
                els.sellerWidget.style.display = 'none';
                closeLists();

                if (val.length < 3) return;

                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    const formData = new FormData();
                    formData.append('action', 'seller_search');
                    formData.append('q', val);

                    fetch('grn_handler.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(r => r.json())
                        .then(data => {
                            sellerList = data;
                            renderSellerList(data);
                        });
                }, 300);
            });

            function renderSellerList(data) {
                if (!data.length) return;
                els.sellerList.style.display = 'block';
                els.sellerList.innerHTML = '';
                currentFocus = -1;

                data.forEach(s => {
                    const div = document.createElement('div');
                    div.className = 'suggestion-item';
                    div.innerHTML = `<div><strong>${s.name}</strong></div><div class="suggestion-meta">${s.phone || ''} | ${s.address || ''}</div>`;
                    div.addEventListener('click', () => selectSeller(s));
                    els.sellerList.appendChild(div);
                });
            }

            function selectSeller(s) {
                els.sellerName.value = s.name;
                els.sellerId.value = s.id;
                els.sellerPhone.value = s.phone;
                els.sellerAddr.value = s.address;

                els.sellerPhone.readOnly = true;
                els.sellerPhone.style.background = '#f8fafc';
                els.sellerAddr.readOnly = true;
                els.sellerAddr.style.background = '#f8fafc';
                els.newSellerHint.style.display = 'none';

                closeLists();
                fetchHistory(s.id);
                els.vehicle.focus();
            }

            // --- HISTORY LOGIC ---
            function fetchHistory(id) {
                const fd = new FormData();
                fd.append('action', 'get_seller_history');
                fd.append('seller_id', id);

                fetch('grn_handler.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            els.histCount.innerText = data.stats.total_count;
                            els.histVal.innerText = data.stats.total_spent ? parseFloat(data.stats.total_spent).toLocaleString('en-IN', {
                                minimumFractionDigits: 2
                            }) : '0.00';
                            els.sellerWidget.style.display = 'block';

                            const tbody = document.getElementById('history_table_body');
                            tbody.innerHTML = '';
                            document.getElementById('modal_seller_name').innerText = els.sellerName.value;

                            if (data.history.length > 0) {
                                data.history.forEach(row => {
                                    tbody.innerHTML += `
                                        <tr>
                                            <td style="font-weight:600;">${row.formatted_date}</td>
                                            <td><strong style="color:var(--primary);">${row.grn_no}</strong></td>
                                            <td>${row.total_weight_kg} Kg</td>
                                            <td style="font-weight:700; color:var(--success);">₹${parseFloat(row.total_value).toLocaleString('en-IN', {minimumFractionDigits: 2})}</td>
                                            <td style="text-align:right;">
                                                <button type="button" class="btn-icon" style="color:var(--info); font-size:1rem;" onclick="printAllStickers(${row.id})" title="Print Stickers">
                                                    <i class="fas fa-tags"></i>
                                                </button>
                                            </td>
                                        </tr>`;
                                });
                            } else {
                                tbody.innerHTML = '<tr><td colspan="5" style="padding:30px; text-align:center; color:var(--text-muted);">No previous history found</td></tr>';
                            }
                        }
                    });
            }

            window.openHistoryModal = () => document.getElementById('historyModal').classList.add('active');
            window.closeHistoryModal = () => document.getElementById('historyModal').classList.remove('active');

            window.enableManualSeller = function() {
                els.sellerId.value = '';
                els.sellerPhone.readOnly = false;
                els.sellerPhone.style.background = '#fff';
                els.sellerAddr.readOnly = false;
                els.sellerAddr.style.background = '#fff';
                els.sellerPhone.focus();
                els.newSellerHint.style.display = 'block';
                els.sellerWidget.style.display = 'none';
                closeLists();
            };

            els.sellerName.addEventListener('keydown', function(e) {
                handleKeyNav(e, els.sellerList, () => {
                    const items = els.sellerList.querySelectorAll('.suggestion-item');
                    if (currentFocus > -1 && items[currentFocus]) items[currentFocus].click();
                });
            });

            // --- 2. SEED LOGIC (LOCAL SEARCH + KEYBOARD) ---
            els.seedSearch.addEventListener('input', function() {
                const val = this.value.toLowerCase();
                closeLists();
                if (!val) return;

                currentFocus = -1;
                const matches = allSeeds.filter(s => s.name.toLowerCase().includes(val));

                if (matches.length > 0) {
                    els.seedList.style.display = 'block';
                    matches.forEach(s => {
                        const div = document.createElement('div');
                        div.className = 'suggestion-item';
                        div.innerHTML = `<div><strong style="color:var(--text-main);">${s.name}</strong></div><div class="suggestion-meta">${s.category} | Last: ₹${s.last_price}</div>`;
                        div.addEventListener('click', () => selectSeed(s));
                        els.seedList.appendChild(div);
                    });
                }
            });

            function selectSeed(s) {
                els.seedSearch.value = s.name;
                els.seedId.value = s.id;
                els.seedCat.value = s.category;
                els.lastPrice.value = '₹ ' + s.last_price;

                if (parseFloat(s.last_price) > 0) {
                    els.price.value = parseFloat(s.last_price).toFixed(2);
                }

                closeLists();
                els.price.focus();
            }

            els.seedSearch.addEventListener('keydown', function(e) {
                handleKeyNav(e, els.seedList, () => {
                    const items = els.seedList.querySelectorAll('.suggestion-item');
                    if (currentFocus > -1 && items[currentFocus]) items[currentFocus].click();
                });
            });

            // --- 3. KEYBOARD NAV HELPER ---
            function handleKeyNav(e, listContainer, enterCallback) {
                let items = listContainer.querySelectorAll('.suggestion-item');
                if (e.key === 'ArrowDown') {
                    currentFocus++;
                    addActive(items);
                } else if (e.key === 'ArrowUp') {
                    currentFocus--;
                    addActive(items);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    enterCallback();
                }
            }

            function addActive(items) {
                if (!items) return false;
                removeActive(items);
                if (currentFocus >= items.length) currentFocus = 0;
                if (currentFocus < 0) currentFocus = (items.length - 1);
                items[currentFocus].classList.add('active');
                items[currentFocus].scrollIntoView({
                    block: 'nearest'
                });
            }

            function removeActive(items) {
                for (let i = 0; i < items.length; i++) items[i].classList.remove('active');
            }

            function closeLists() {
                els.sellerList.innerHTML = '';
                els.sellerList.style.display = 'none';
                els.seedList.innerHTML = '';
                els.seedList.style.display = 'none';
            }

            document.addEventListener('click', function(e) {
                if (e.target !== els.sellerName && e.target !== els.seedSearch) closeLists();
                if (e.target == document.getElementById('historyModal')) closeHistoryModal();
            });
            // Also close modal on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === "Escape") closeHistoryModal();
            });

            // --- 4. ITEM LOGIC (ADD / CALCULATE / REMOVE) ---
            function calculateLine() {
                const p = parseFloat(els.price.value) || 0;
                const w = parseFloat(els.weight.value) || 0;
                const total = (w / 100) * p;
                els.lineTotal.textContent = total.toLocaleString('en-IN', {
                    minimumFractionDigits: 2
                });
            }

            els.price.addEventListener('input', calculateLine);
            els.weight.addEventListener('input', calculateLine);

            els.addBtn.addEventListener('click', function() {
                if (!els.seedId.value) return alert('Please select a seed first');

                const price = parseFloat(els.price.value);
                const weight = parseFloat(els.weight.value);

                if (!price || price <= 0) {
                    alert('Invalid Price');
                    els.price.focus();
                    return;
                }
                if (!weight || weight <= 0) {
                    alert('Invalid Weight');
                    els.weight.focus();
                    return;
                }

                const item = {
                    seed_id: els.seedId.value,
                    seed_name: els.seedSearch.value,
                    quality: els.quality.value,
                    bags: els.bags.value || 0,
                    price_per_qtl: price,
                    weight_kg: weight,
                    line_value: (weight / 100) * price
                };

                addedItems.push(item);
                renderTable();

                els.weight.value = '';
                els.bags.value = '';
                els.lineTotal.textContent = '0.00';
                els.seedSearch.value = '';
                els.seedId.value = '';
                els.seedSearch.focus();
            });

            function renderTable() {
                if (addedItems.length === 0) {
                    els.tableBody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:var(--text-muted); padding:30px;">No items added</td></tr>`;
                    els.grandWeight.textContent = '0.00 Kg';
                    els.grandValue.textContent = '₹ 0.00';
                    return;
                }

                els.tableBody.innerHTML = '';
                let gW = 0,
                    gV = 0;

                addedItems.forEach((item, idx) => {
                    gW += item.weight_kg;
                    gV += item.line_value;

                    const row = `
                        <tr>
                            <td><strong style="color:var(--text-main);">${item.seed_name}</strong></td>
                            <td style="color:var(--text-muted); font-size:0.9rem;">${item.bags} Bags (${item.quality})</td>
                            <td style="font-weight:600;">₹${item.price_per_qtl}</td>
                            <td style="font-weight:600;">${item.weight_kg} Kg</td>
                            <td style="font-weight:700; color:var(--text-main);">₹${item.line_value.toLocaleString('en-IN', {minimumFractionDigits: 2})}</td>
                            <td style="text-align:right;">
                                <button type="button" class="btn-icon delete" onclick="removeItem(${idx})" title="Remove"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    `;
                    els.tableBody.innerHTML += row;
                });

                els.grandWeight.textContent = gW.toFixed(2) + ' Kg';
                els.grandValue.textContent = '₹ ' + gV.toLocaleString('en-IN', {
                    minimumFractionDigits: 2
                });
                document.getElementById('items_json').value = JSON.stringify(addedItems);
            }

            window.removeItem = function(idx) {
                if (confirm('Remove item?')) {
                    addedItems.splice(idx, 1);
                    renderTable();
                }
            };

            // --- 5. PAYMENT STATUS UPDATE ---
            els.payMode.addEventListener('change', function() {
                if (this.value === 'Pending' || this.value === 'Credit') {
                    els.payStatus.className = 'badge st-pending';
                    els.payStatus.textContent = 'Unpaid (Credit)';
                } else {
                    els.payStatus.className = 'badge st-completed';
                    els.payStatus.textContent = 'Paid';
                }
            });

            // --- 6. FINAL FORM SUBMIT ---
            els.form.addEventListener('submit', function(e) {
                e.preventDefault();

                if (addedItems.length === 0) return alert('Please add at least one item to the list.');
                if (!els.sellerName.value) return alert('Please select or enter a seller.');
                if (!confirm('Confirm GRN Entry?')) return;

                const btn = els.submitBtn;
                const oldHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving Data...';

                const fd = new FormData(this);

                fetch('grn_handler.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            let grnIdForPrint = data.grn_id || data.id;
                            if (confirm(`✅ GRN Saved Successfully! #${data.grn_no}\n\n🖨️ Kya aap abhi sabhi items ke Bag Stickers print karna chahte hain?`)) {
                                printAllStickers(grnIdForPrint);
                            }
                            window.location.reload();
                        } else {
                            throw new Error(data.error || 'Server Error');
                        }
                    })
                    .catch(err => {
                        alert('Error: ' + err.message);
                        btn.disabled = false;
                        btn.innerHTML = oldHtml;
                    });
            });

        });
    </script>
</body>

</html>