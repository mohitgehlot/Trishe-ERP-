<?php
// grn_intake.php - Complete Modern Version with Sticker Printing
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* VARIABLES & RESET */
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --input-bg: #ffffff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --radius: 10px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --transition: all 0.2s ease-in-out;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding-bottom: 80px;
            line-height: 1.5;
            font-size: 14px;
        }

        .container {
            margin: 0 auto;
            padding: 10px;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 992px) {
            .container {
                margin-left: 0;
                padding: 10px;
                max-width: 100%;
            }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            color: var(--primary);
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 10px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .grid-layout {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            padding: 8px 10px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header i {
            color: var(--text-secondary);
        }

        .card-body {
            padding: 10px;
        }

        @media (max-width: 480px) {
            .card-body {
                padding: 15px;
            }
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 16px;
            margin-bottom: 10px;
        }

        @media (max-width: 576px) {
            .form-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
        }

        .form-group {
            margin-bottom: 16px;
            position: relative;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            font-size: 1rem;
            color: var(--text-main);
            background-color: var(--input-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            transition: var(--transition);
            appearance: none;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .form-control[readonly] {
            background-color: #f1f5f9;
            color: var(--text-secondary);
            cursor: not-allowed;
        }

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
            background-color: var(--primary-light);
            color: var(--primary);
        }

        .suggestion-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .seller-history-box {
            background-color: var(--primary-light);
            border: 1px solid #c7d2fe;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 5px;
            margin-bottom: 20px;
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .history-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .view-history-btn {
            font-size: 0.85rem;
            color: var(--primary);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .view-history-btn:hover {
            text-decoration: underline;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #b45309;
        }

        .badge-success {
            background-color: #d1fae5;
            color: #047857;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-decoration: none;
            gap: 8px;
        }

        @media (max-width: 576px) {
            .btn {
                width: 100%;
                margin-bottom: 10px;
            }

            .page-header .btn {
                width: auto;
                margin-bottom: 0;
            }
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            filter: brightness(90%);
            transform: translateY(-1px);
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        .btn-danger:hover {
            background-color: #dc2626;
        }

        .btn-outline {
            background: white;
            border: 1px solid var(--border);
            color: var(--text-main);
        }

        .btn-outline:hover {
            background-color: #f8fafc;
            border-color: var(--text-secondary);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .table-wrapper {
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow-x: auto;
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th {
            background-color: #f8fafc;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .tfoot-row td {
            background-color: #f8fafc;
            font-weight: 700;
            color: var(--text-main);
            border-top: 2px solid var(--border);
            font-size: 1rem;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: white;
            margin: 5vh auto;
            padding: 0;
            width: 90%;
            max-width: 800px;
            border-radius: 12px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.3s ease-out;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-content h3 {
            margin: 0;
            padding: 20px;
            border-bottom: 1px solid var(--border);
            font-size: 1.25rem;
        }

        .close-modal {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 24px;
            color: var(--text-secondary);
            cursor: pointer;
            line-height: 1;
        }

        .manual-link {
            color: var(--primary);
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: underline;
            display: inline-block;
            margin-top: 8px;
            font-weight: 500;
        }

        #line_total {
            color: var(--primary);
            font-weight: 800;
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header">
            <div class="page-title">
                <i class="fas fa-truck-loading"></i>
                GRN Entry (Raw Material)
            </div>
            <a href="inventory.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <form id="grnForm" autocomplete="off">
            <input type="hidden" name="items_json" id="items_json" value='[]'>
            <input type="hidden" name="seller_id" id="seller_id">
            <input type="hidden" name="action" value="create_grn">

            <div class="grid-layout">

                <div class="left-col">
                    <div class="card">
                        <div class="card-header"><i class="fas fa-user"></i> Seller Information</div>
                        <div class="card-body">

                            <div class="form-group autocomplete-wrapper">
                                <label>Seller Name *</label>
                                <input type="text" id="seller_name" name="seller_name" class="form-control" placeholder="Search seller..." required>
                                <div id="seller_list" class="autocomplete-list"></div>
                                <small id="new_seller_hint" style="display:none; color:var(--warning); margin-top:5px;">
                                    <i class="fas fa-plus-circle"></i> New Seller will be created.
                                </small>
                            </div>

                            <div id="seller_history_widget" class="seller-history-box">
                                <div class="history-stats">
                                    <span>Total Orders: <strong id="hist_count">0</strong></span>
                                    <span>Total Value: <strong>₹<span id="hist_val">0</span></strong></span>
                                </div>
                                <div class="view-history-btn" onclick="openHistoryModal()">
                                    <i class="fas fa-eye"></i> View Previous Transactions
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Vehicle No *</label>
                                    <input type="text" id="vehicle_no" name="vehicle_no" class="form-control" placeholder="RJ-XX-0000" required style="text-transform: uppercase;">
                                </div>
                                <div class="form-group">
                                    <label>Phone Number</label>
                                    <input type="text" id="phone" name="phone" class="form-control" placeholder="Mobile No" readonly>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Address</label>
                                <input type="text" id="seller_address" name="seller_address" class="form-control" placeholder="Village / City" readonly>
                            </div>

                            <div style="text-align:right;">
                                <span id="manual_entry_btn" class="manual-link" onclick="enableManualSeller()">
                                    New Seller? Enter Manually
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><i class="fas fa-wallet"></i> Payment Details</div>
                        <div class="card-body">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Payment Mode</label>
                                    <select id="payment_mode" name="payment_mode" class="form-control">
                                        <option value="Pending">Credit (Pending)</option>
                                        <option value="Cash">Cash</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                        <option value="UPI">UPI</option>
                                        <option value="Cheque">Cheque</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Date</label>
                                    <input type="date" id="payment_date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Ref No / Remarks</label>
                                <input type="text" id="payment_ref" name="payment_ref" class="form-control" placeholder="Transaction ID or Notes">
                            </div>

                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span style="font-size:0.9rem; font-weight:600;">Status:</span>
                                <span id="pay_status_badge" class="badge badge-warning">Unpaid</span>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="right-col">

                    <div class="card">
                        <div class="card-header"><i class="fas fa-seedling"></i> Add Seeds</div>
                        <div class="card-body">

                            <div class="form-group autocomplete-wrapper">
                                <label>Select Seed (Type to search) *</label>
                                <input type="text" id="seed_search" class="form-control" placeholder="e.g. Mustard, Peanut...">
                                <input type="hidden" id="selected_seed_id">
                                <div id="seed_list" class="autocomplete-list"></div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Category</label>
                                    <input type="text" id="seed_category" class="form-control" readonly tabindex="-1">
                                </div>
                                <div class="form-group">
                                    <label>Last Price (Ref)</label>
                                    <input type="text" id="last_price" class="form-control" readonly tabindex="-1">
                                </div>
                                <div class="form-group">
                                    <label>Quality Grade</label>
                                    <select id="seed_quality" class="form-control">
                                        <option value="A">Grade A (Best)</option>
                                        <option value="B">Grade B (Good)</option>
                                        <option value="C">Grade C (Average)</option>
                                        <option value="D">Grade D (Low)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>No. of Bags</label>
                                    <input type="number" id="seed_bags" class="form-control" placeholder="0">
                                </div>
                                <div class="form-group">
                                    <label>Price (₹/Qtl) *</label>
                                    <input type="number" id="seed_price" class="form-control" step="0.01" placeholder="0.00">
                                </div>
                                <div class="form-group">
                                    <label>Weight (Kg) *</label>
                                    <input type="number" id="seed_weight" class="form-control" step="0.01" placeholder="0.00">
                                </div>
                            </div>

                            <div class="form-group" style="text-align:right; font-size:1.1rem; font-weight:bold; color:var(--primary);">
                                Line Total: ₹ <span id="line_total">0.00</span>
                            </div>

                            <button type="button" id="add_item_btn" class="btn btn-primary" style="width:100%;">
                                <i class="fas fa-plus"></i> Add Item to List
                            </button>

                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><i class="fas fa-list"></i> Items List</div>
                        <div class="table-wrapper">
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
                                        <td colspan="6" style="text-align:center; color:#999; padding:20px;">No items added</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="tfoot-row">
                                        <td colspan="3" style="text-align:right;">Grand Total:</td>
                                        <td id="grand_weight">0.00 Kg</td>
                                        <td id="grand_value">₹ 0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <button type="submit" id="submit_grn_btn" class="btn btn-success" style="width:100%; padding:15px; font-size:1.1rem; margin-top:10px;">
                        <i class="fas fa-check-circle"></i> Save GRN Entry
                    </button>

                </div>
            </div>
        </form>
    </div>

    <div id="historyModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeHistoryModal()">&times;</span>
            <h3 style="margin-top:0;">Previous Orders: <span id="modal_seller_name" style="color:var(--primary);"></span></h3>
            <div style="overflow-y:auto; max-height:400px; border:1px solid var(--border); border-radius:8px;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead style="background:#f9fafb; position:sticky; top:0;">
                        <tr>
                            <th style="padding:10px; text-align:left;">Date</th>
                            <th style="padding:10px; text-align:left;">GRN No</th>
                            <th style="padding:10px; text-align:left;">Weight</th>
                            <th style="padding:10px; text-align:left;">Amount</th>
                            <th style="padding:10px; text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="history_table_body"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // --- NAYI SCRIPT: Print Stickers Function ---
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

                // History Widgets
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
                els.sellerAddr.readOnly = true;
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
                            els.histVal.innerText = data.stats.total_spent ? parseFloat(data.stats.total_spent).toFixed(2) : '0.00';
                            els.sellerWidget.style.display = 'block';

                            const tbody = document.getElementById('history_table_body');
                            tbody.innerHTML = '';
                            document.getElementById('modal_seller_name').innerText = els.sellerName.value;

                            if (data.history.length > 0) {
                                data.history.forEach(row => {
                                    // 🌟 HISTORY ME PRINT BUTTON 🌟
                                    tbody.innerHTML += `
                                        <tr>
                                            <td style="padding:10px; border-bottom:1px solid #eee;">${row.formatted_date}</td>
                                            <td style="padding:10px; border-bottom:1px solid #eee;">${row.grn_no}</td>
                                            <td style="padding:10px; border-bottom:1px solid #eee;">${row.total_weight_kg} Kg</td>
                                            <td style="padding:10px; border-bottom:1px solid #eee; font-weight:bold;">₹${parseFloat(row.total_value).toFixed(2)}</td>
                                            <td style="padding:10px; border-bottom:1px solid #eee; text-align:right;">
                                                <button class="btn-outline" style="padding:4px 8px; font-size:12px; cursor:pointer;" onclick="printAllStickers(${row.id})">
                                                    <i class="fas fa-tags"></i> Print
                                                </button>
                                            </td>
                                        </tr>`;
                                });
                            } else {
                                tbody.innerHTML = '<tr><td colspan="5" style="padding:10px; text-align:center;">No previous history found</td></tr>';
                            }
                        }
                    });
            }

            window.openHistoryModal = () => document.getElementById('historyModal').style.display = 'block';
            window.closeHistoryModal = () => document.getElementById('historyModal').style.display = 'none';

            window.enableManualSeller = function() {
                els.sellerId.value = '';
                els.sellerPhone.readOnly = false;
                els.sellerAddr.readOnly = false;
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
                        div.innerHTML = `<div><strong>${s.name}</strong></div><div class="suggestion-meta">${s.category} | Last: ₹${s.last_price}</div>`;
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
                for (let i = 0; i < items.length; i++) {
                    items[i].classList.remove('active');
                }
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

            // --- 4. ITEM LOGIC (ADD / CALCULATE / REMOVE) ---

            function calculateLine() {
                const p = parseFloat(els.price.value) || 0;
                const w = parseFloat(els.weight.value) || 0;
                const total = (w / 100) * p;
                els.lineTotal.textContent = total.toFixed(2);
            }

            els.price.addEventListener('input', calculateLine);
            els.weight.addEventListener('input', calculateLine);

            els.addBtn.addEventListener('click', function() {
                if (!els.seedId.value) {
                    alert('Please select a seed first');
                    return;
                }
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
                    els.tableBody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:#999; padding:20px;">No items added</td></tr>`;
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
                            <td><strong>${item.seed_name}</strong></td>
                            <td>${item.bags} Bags (${item.quality})</td>
                            <td>₹${item.price_per_qtl}</td>
                            <td>${item.weight_kg} Kg</td>
                            <td>₹${item.line_value.toFixed(2)}</td>
                            <td>
                                <button type="button" class="btn-danger" style="padding:4px 8px;" onclick="removeItem(${idx})"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    `;
                    els.tableBody.innerHTML += row;
                });

                els.grandWeight.textContent = gW.toFixed(2) + ' Kg';
                els.grandValue.textContent = '₹ ' + gV.toFixed(2);

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
                    els.payStatus.className = 'badge badge-warning';
                    els.payStatus.textContent = 'Unpaid (Credit)';
                } else {
                    els.payStatus.className = 'badge badge-success';
                    els.payStatus.textContent = 'Paid';
                }
            });

            // --- 6. FINAL FORM SUBMIT ---
            els.form.addEventListener('submit', function(e) {
                e.preventDefault();

                if (addedItems.length === 0) {
                    alert('Please add at least one item to the list.');
                    return;
                }
                if (!els.sellerName.value) {
                    alert('Please select or enter a seller.');
                    return;
                }

                if (!confirm('Confirm GRN Entry?')) return;

                const btn = els.submitBtn;
                const oldHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                const fd = new FormData(this);

                fetch('grn_handler.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            // 🌟 AUTO-PRINT POPUP AFTER SAVE 🌟
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