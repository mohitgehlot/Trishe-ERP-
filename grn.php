<?php
// grn_intake.php - PRO ERP LAYOUT (Hindi UI / Mandi Mode)
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

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
        body { overflow-x: hidden; background: #f1f5f9; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .page-header-box { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 10px; }
        
        .grid-layout { display: grid; grid-template-columns: 1.2fr 2fr; gap: 24px; align-items: start; min-width: 0; }
        .grid-layout>div { min-width: 0; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 15px; align-items: end; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 15px; }
        
        .autocomplete-wrapper { position: relative; }
        .autocomplete-list { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid var(--border); border-radius: 0 0 8px 8px; max-height: 250px; overflow-y: auto; z-index: 100; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); display: none; }
        .suggestion-item { padding: 12px 16px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; }
        .suggestion-item:last-child { border-bottom: none; }
        .suggestion-item:hover, .suggestion-item.active { background-color: #e0e7ff; color: var(--primary); }
        .suggestion-meta { font-size: 0.8rem; color: var(--text-muted); margin-top: 2px; }

        .seller-history-box { background-color: #eff6ff; border: 1px dashed #bfdbfe; border-radius: 8px; padding: 15px; margin-top: 5px; margin-bottom: 20px; display: none; animation: fadeIn 0.3s ease; }
        .history-stats { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.95rem; font-weight: 700; color: var(--text-main); }
        .view-history-btn { font-size: 0.85rem; color: var(--primary); font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; }
        .view-history-btn:hover { text-decoration: underline; }
        .manual-link { color: var(--primary); font-size: 0.85rem; cursor: pointer; text-decoration: underline; display: inline-block; margin-top: 8px; font-weight: 600; }
        
        #line_total { color: var(--primary); font-weight: 800; font-size: 1.2rem; }
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border: none; box-shadow: none; border-radius: 0; width: 100%; }
        .table-wrap table { min-width: 600px; width: 100%; }

        .adj-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 15px; display:flex; align-items:center; gap:15px; }
        .adj-box select { width: auto; flex-shrink: 0; }

        @media (max-width: 1024px) { .grid-layout { grid-template-columns: 1fr; } }
        @media (max-width: 768px) {
            .container { padding: 10px; width: 100vw; max-width: 100vw; }
            .form-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .grid-2 { grid-template-columns: 1fr; gap: 12px; }
            .adj-box { flex-direction: column; align-items: stretch; gap:10px; }
            
            .table-wrap table, .table-wrap thead, .table-wrap tbody, .table-wrap th, .table-wrap td, .table-wrap tr { display: block; width: 100%; }
            .table-wrap thead { display: none; }
            .table-wrap tr { margin-bottom: 15px; border: 1px solid var(--border); border-radius: 8px; background: #fff; padding: 10px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); }
            .table-wrap td { display: flex; justify-content: space-between; align-items: center; padding: 10px 5px !important; border-bottom: 1px dashed var(--border) !important; text-align: right; }
            .table-wrap td:last-child { border-bottom: none !important; display: flex; justify-content: flex-end; padding-top: 15px !important; }
            .table-wrap td::before { content: attr(data-label); font-weight: 700; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; text-align: left; }
            .table-wrap td:last-child::before { display: none; }
            .table-wrap tfoot tr { display: flex; flex-direction: column; background: #f8fafc; border-top: 2px solid var(--border); }
            .table-wrap tfoot td { border-bottom: none !important; padding: 10px !important; }
            .table-wrap tfoot td:empty { display: none; }
        }
        @media (max-width: 480px) { .form-grid { grid-template-columns: 1fr; } }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-truck-loading text-primary"></i> नई खरीद (मंडी एंट्री)</h1>
            <a href="inventory.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> वापस जाएं</a>
        </div>

        <form id="grnForm" autocomplete="off">
            <input type="hidden" name="items_json" id="items_json" value='[]'>
            <input type="hidden" name="seller_id" id="seller_id">
            <input type="hidden" name="action" value="create_grn">

            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header" style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                    <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                        <span><i class="fas fa-user text-primary" style="margin-right:8px;"></i> किसान/विक्रेता की जानकारी</span>
                        <span id="manual_entry_btn" class="manual-link" style="margin:0;" onclick="enableManualSeller()">नया किसान? खुद टाइप करें</span>
                    </div>
                </div>
                <div style="padding: 20px;">
                    <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr; align-items: start;">
                        <div class="form-group autocomplete-wrapper" style="margin:0;">
                            <label class="form-label">किसान का नाम *</label>
                            <input type="text" id="seller_name" name="seller_name" class="form-input" placeholder="नाम खोजें..." required>
                            <div id="seller_list" class="autocomplete-list"></div>
                            <small id="new_seller_hint" style="display:none; color:var(--warning); margin-top:5px; font-weight:600;"><i class="fas fa-plus-circle"></i> नया किसान अपने आप जुड़ जायेगा।</small>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">गाड़ी नंबर *</label>
                            <input type="text" id="vehicle_no" name="vehicle_no" class="form-input" placeholder="RJ-XX-0000" required style="text-transform: uppercase;">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">फ़ोन नंबर</label>
                            <input type="text" id="phone" name="phone" class="form-input" placeholder="मोबाइल नंबर" readonly style="background:#f8fafc; color:var(--text-muted);">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">पता (गाँव/शहर)</label>
                            <input type="text" id="seller_address" name="seller_address" class="form-input" placeholder="पता" readonly style="background:#f8fafc; color:var(--text-muted);">
                        </div>
                    </div>

                    <div id="seller_history_widget" class="seller-history-box" style="margin-top:15px; margin-bottom:0;">
                        <div class="history-stats">
                            <span>कुल पुराने बिल: <strong id="hist_count" style="color:var(--primary);">0</strong></span>
                            <span>कुल व्यापार: <strong style="color:var(--success);">₹<span id="hist_val">0</span></strong></span>
                        </div>
                        <div class="view-history-btn" onclick="openHistoryModal()">
                            <i class="fas fa-eye"></i> पुराने बिल देखें
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid-layout">
                
                <div class="left-col">
                    <div class="card" style="margin-bottom: 24px; position: sticky; top: 20px;">
                        <div class="card-header"><i class="fas fa-balance-scale text-warning" style="margin-right:8px;"></i> माल चढ़ाएं (तौल)</div>
                        <div style="padding: 20px;">

                            <div class="form-group autocomplete-wrapper" style="margin-bottom:15px;">
                                <label class="form-label">बीज चुनें (Select Seed) *</label>
                                <input type="text" id="seed_search" class="form-input" placeholder="जैसे: सरसों, मूंगफली...">
                                <input type="hidden" id="selected_seed_id">
                                <div id="seed_list" class="autocomplete-list"></div>
                            </div>

                            <div class="grid-2">
                                <div class="form-group" style="margin:0;">
                                    <label class="form-label">प्रकार (Category)</label>
                                    <input type="text" id="seed_category" class="form-input" readonly tabindex="-1" style="background:#f8fafc; color:var(--text-muted);">
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label class="form-label">पिछला भाव</label>
                                    <input type="text" id="last_price" class="form-input" readonly tabindex="-1" style="background:#f8fafc; color:var(--text-muted);">
                                </div>
                            </div>

                            <div class="grid-2" style="margin-top:15px;">
                                <div class="form-group" style="margin:0;">
                                    <label class="form-label">क्वालिटी (Quality)</label>
                                    <select id="seed_quality" class="form-input">
                                        <option value="A">ग्रेड A (सबसे बढ़िया)</option>
                                        <option value="B">ग्रेड B (अच्छा)</option>
                                        <option value="C">ग्रेड C (औसत)</option>
                                        <option value="D">ग्रेड D (हल्का)</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label class="form-label">भाव (₹/क्विंटल) *</label>
                                    <input type="number" id="seed_price" class="form-input" step="0.01" placeholder="0.00" oninput="calcItem()">
                                </div>
                            </div>

                            <div style="background: #fffbeb; border: 1px solid #fde68a; padding: 15px; border-radius: 8px; margin: 20px 0;">
                                <h4 style="margin:0 0 10px 0; color:#b45309; font-size:0.9rem;">बारदाना कटौती (Bag Deduction)</h4>
                                
                                <div class="form-group" style="margin-bottom:12px;">
                                    <label class="form-label">भुगतान का तरीका (Payment Rule) *</label>
                                    <select id="bag_rule" class="form-input" onchange="calcItem()" style="border-color:#f59e0b; background:#fff;">
                                        <option value="deduct">बिल से काटें (शुद्ध/Net वजन के पैसे)</option>
                                        <option value="no_deduct">ना काटें (पक्के/Gross वजन के पैसे)</option>
                                    </select>
                                </div>

                                <div class="grid-2" style="margin-bottom:10px;">
                                    <div class="form-group" style="margin:0;">
                                        <label class="form-label">कुल वजन (Gross) *</label>
                                        <input type="number" id="gross_weight" class="form-input" step="0.01" placeholder="तौल (Kg)" oninput="calcItem()">
                                    </div>
                                    <div class="form-group" style="margin:0;">
                                        <label class="form-label">बोरियों की संख्या</label>
                                        <input type="number" id="seed_bags" class="form-input" placeholder="कितनी बोरी" value="0" oninput="calcItem()">
                                    </div>
                                </div>
                                <div class="grid-2">
                                    <div class="form-group" style="margin:0;">
                                        <label class="form-label">एक बोरी का वजन (Kg)</label>
                                        <input type="number" id="bag_weight" class="form-input" step="0.001" placeholder="e.g. 0.500" value="0.5" oninput="calcItem()">
                                    </div>
                                    <div class="form-group" style="margin:0;">
                                        <label class="form-label">शुद्ध बीज (Net Kg)</label>
                                        <input type="text" id="net_weight" class="form-input" readonly tabindex="-1" style="background:#f8fafc; font-weight:700; color:var(--success);">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group" style="text-align:right; font-size:1.1rem; font-weight:700; color:var(--text-main); margin-top:10px;">
                                कुल रकम: ₹ <span id="line_total">0.00</span>
                            </div>

                            <button type="button" id="add_item_btn" class="btn btn-outline" style="width:100%; border-color:var(--primary); color:var(--primary); margin-top:10px;">
                                <i class="fas fa-arrow-right" style="margin-right:5px;"></i> पक्के बिल में जोड़ें (Add to Bill)
                            </button>
                        </div>
                    </div>
                </div>

                <div class="right-col">
                    <div class="card" style="margin-bottom: 24px;">
                        <div class="card-header"><i class="fas fa-file-invoice text-info" style="margin-right:8px;"></i> पक्का बिल (Invoice Summary)</div>
                        <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>माल (Item)</th>
                                        <th>जानकारी (Details)</th>
                                        <th>भाव (Rate)</th>
                                        <th>रकम (Total)</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="items_table_body">
                                    <tr>
                                        <td colspan="5" style="text-align:center; color:var(--text-muted); padding:30px;">अभी बिल में कोई माल नहीं जोड़ा गया है।</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr style="background:#f8fafc; border-top:2px solid var(--border);">
                                        <td data-label="Summary" colspan="2" style="text-align:right; font-weight:600; color:var(--text-muted);">कुल माल की कीमत:</td>
                                        <td data-label="Net Wt" id="grand_weight" style="font-weight:700;">0.00 Kg</td>
                                        <td data-label="Seed Amt" id="seed_value" style="font-weight:700;">₹ 0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr style="background:#fff;">
                                        <td colspan="3" style="text-align:right; font-weight:600; color:var(--text-muted);">गाड़ी भाड़ा (Freight):</td>
                                        <td id="disp_freight" style="font-weight:700; color:var(--text-muted);">₹ 0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr style="background:#fff;">
                                        <td colspan="3" style="text-align:right; font-weight:600; color:var(--text-muted);">नकद छूट (Cash Discount):</td>
                                        <td id="disp_cd" style="font-weight:700; color:var(--text-muted);">₹ 0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr style="background:#eef2ff; border-top:2px solid #a5b4fc;">
                                        <td colspan="3" style="text-align:right; font-weight:800; font-size:1.1rem; color:#4f46e5;">देय राशि (GRAND TOTAL):</td>
                                        <td id="grand_value" style="font-weight:800; font-size:1.2rem; color:var(--success);">₹ 0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="card" style="margin-bottom:0;">
                            <div class="card-header" style="font-size:0.95rem;"><i class="fas fa-calculator text-warning" style="margin-right:8px;"></i> अन्य खर्च / कटौती</div>
                            <div style="padding: 15px;">
                                <div class="form-group">
                                    <label class="form-label" style="font-size:0.8rem;">गाड़ी भाड़ा (Freight)</label>
                                    <div style="display:flex; gap:10px;">
                                        <select name="freight_type" id="freight_type" class="form-input" onchange="calcGrandTotal()">
                                            <option value="deduct">काटें (कारखाना देगा)</option>
                                            <option value="add">जोड़ें (किसान देगा)</option>
                                            <option value="none" selected>कुछ नहीं (None)</option>
                                        </select>
                                        <input type="number" step="0.01" name="freight_amount" id="freight_amt" class="form-input" placeholder="₹ Amount" value="0" oninput="calcGrandTotal()" style="width:100px;">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label class="form-label" style="font-size:0.8rem;">नकद छूट (Cash Discount)</label>
                                    <div style="display:flex; gap:10px;">
                                        <select name="cd_type" id="cd_type" class="form-input" onchange="calcGrandTotal()">
                                            <option value="percentage">प्रतिशत (%)</option>
                                            <option value="flat">सीधा रुपये (₹)</option>
                                        </select>
                                        <input type="number" step="0.01" name="cd_val" id="cd_val" class="form-input" placeholder="Value" value="0" oninput="calcGrandTotal()" style="width:100px;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card" style="margin-bottom:0;">
                            <div class="card-header" style="font-size:0.95rem;"><i class="fas fa-wallet text-success" style="margin-right:8px;"></i> भुगतान की जानकारी (Payment)</div>
                            <div style="padding: 15px;">
                                <div class="form-group">
                                    <label class="form-label" style="font-size:0.8rem;">तरीका और तारीख</label>
                                    <div style="display:flex; gap:10px;">
                                        <select id="payment_mode" name="payment_mode" class="form-input">
                                            <option value="Pending">उधार (Credit)</option>
                                            <option value="Cash">नकद (Cash)</option>
                                            <option value="Bank Transfer">बैंक ट्रांसफर (Bank)</option>
                                            <option value="UPI">यूपीआई (UPI)</option>
                                        </select>
                                        <input type="date" id="payment_date" name="payment_date" class="form-input" value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label class="form-label" style="font-size:0.8rem;">रिमार्क (Remarks)</label>
                                    <input type="text" id="payment_ref" name="payment_ref" class="form-input" placeholder="कोई नोट या जानकारी...">
                                </div>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                                    <span style="font-size:0.85rem; font-weight:700;">स्थिति (Status):</span>
                                    <span id="pay_status_badge" class="badge st-pending" style="padding:4px 8px; font-size:0.75rem;">भुगतान बाकी (Unpaid)</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="submit_grn_btn" class="btn btn-primary" style="width:100%; padding:18px; font-size:1.2rem; margin-top:24px; background:var(--success); border-color:var(--success); box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);">
                        <i class="fas fa-save" style="margin-right:8px;"></i> बिल सेव करें (Save Bill)
                    </button>

                </div>
            </div>
        </form>
    </div>

    <div id="historyModal" class="global-modal">
        <div class="g-modal-content" style="max-width: 800px; padding:0; overflow:hidden;">
            <div class="g-modal-header">
                <h3 style="margin:0; font-size:1.2rem; color:var(--text-main);"><i class="fas fa-history text-primary" style="margin-right:8px;"></i> पुराने बिल: <span id="modal_seller_name" style="color:var(--primary);"></span></h3>
                <span class="g-close-btn" onclick="closeHistoryModal()">&times;</span>
            </div>
            <div class="g-modal-body" style="padding:0;">
                <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0; max-height:400px; margin-bottom:0;">
                    <table style="width:100%;">
                        <thead style="background:#f8fafc; position:sticky; top:0;">
                            <tr>
                                <th>तारीख</th>
                                <th>बिल नंबर</th>
                                <th>वजन</th>
                                <th>रकम</th>
                                <th style="text-align:right;">एक्शन</th>
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

            window.printAllStickers = function(grnId) {
                if (!grnId) return;
                window.open(`print_engine.php?doc=grn_all_stickers&grn_id=${grnId}`, 'StickerPrint', 'width=400,height=500');
            };

            const allSeeds = <?php echo json_encode($seeds_with_prices); ?>;
            let addedItems = [];
            let sellerList = [];
            let currentFocus = -1;
            let debounceTimer;

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
                grossWt: document.getElementById('gross_weight'),
                bags: document.getElementById('seed_bags'),
                bagWt: document.getElementById('bag_weight'),
                netWt: document.getElementById('net_weight'),
                bagRule: document.getElementById('bag_rule'), 
                quality: document.getElementById('seed_quality'),
                lineTotal: document.getElementById('line_total'),

                addBtn: document.getElementById('add_item_btn'),
                tableBody: document.getElementById('items_table_body'),
                grandWeight: document.getElementById('grand_weight'),
                seedValueDisp: document.getElementById('seed_value'),
                
                dispFreight: document.getElementById('disp_freight'),
                dispCd: document.getElementById('disp_cd'),
                grandValueDisp: document.getElementById('grand_value'),

                fType: document.getElementById('freight_type'),
                fAmt: document.getElementById('freight_amt'),
                cdType: document.getElementById('cd_type'),
                cdVal: document.getElementById('cd_val'),

                payMode: document.getElementById('payment_mode'),
                payStatus: document.getElementById('pay_status_badge'),

                form: document.getElementById('grnForm'),
                submitBtn: document.getElementById('submit_grn_btn')
            };

            // --- 1. SELLER SEARCH LOGIC ---
            els.sellerName.addEventListener('input', function() {
                const val = this.value.trim();
                els.sellerId.value = '';
                els.sellerWidget.style.display = 'none';
                closeLists();

                if (val.length < 3) return;

                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    const fd = new FormData();
                    fd.append('action', 'seller_search');
                    fd.append('q', val);

                    fetch('grn_handler.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(data => { sellerList = data; renderSellerList(data); });
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
                
                // Fetch History
                const fd = new FormData();
                fd.append('action', 'get_seller_history');
                fd.append('seller_id', s.id);
                fetch('grn_handler.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            els.histCount.innerText = data.stats.total_count;
                            els.histVal.innerText = data.stats.total_spent ? parseFloat(data.stats.total_spent).toLocaleString('en-IN', {minimumFractionDigits: 2}) : '0.00';
                            els.sellerWidget.style.display = 'block';

                            const tbody = document.getElementById('history_table_body');
                            tbody.innerHTML = '';
                            document.getElementById('modal_seller_name').innerText = els.sellerName.value;

                            if (data.history.length > 0) {
                                data.history.forEach(row => {
                                    tbody.innerHTML += `
                                        <tr>
                                            <td data-label="तारीख" style="font-weight:600;">${row.formatted_date}</td>
                                            <td data-label="बिल नंबर"><strong style="color:var(--primary);">${row.grn_no}</strong></td>
                                            <td data-label="वजन">${row.total_weight_kg} Kg</td>
                                            <td data-label="रकम" style="font-weight:700; color:var(--success);">₹${parseFloat(row.total_value).toLocaleString('en-IN', {minimumFractionDigits: 2})}</td>
                                            <td data-label="एक्शन" style="text-align:right;">
                                                <button type="button" class="btn-icon" style="color:var(--info); font-size:1rem;" onclick="printAllStickers(${row.id})" title="Print Stickers"><i class="fas fa-tags"></i></button>
                                            </td>
                                        </tr>`;
                                });
                            } else {
                                tbody.innerHTML = '<tr><td colspan="5" style="padding:30px; text-align:center; color:var(--text-muted);">कोई पुराना बिल नहीं मिला।</td></tr>';
                            }
                        }
                    });

                els.vehicle.focus();
            }

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

            window.openHistoryModal = () => document.getElementById('historyModal').classList.add('active');
            window.closeHistoryModal = () => document.getElementById('historyModal').classList.remove('active');

            // --- 2. SEED LOGIC & BARDANA CALC ---
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
                        div.innerHTML = `<div><strong style="color:var(--text-main);">${s.name}</strong></div><div class="suggestion-meta">${s.category} | पिछला भाव: ₹${s.last_price}</div>`;
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
                els.grossWt.focus();
            }

            // 🌟 SMART CALCULATION LOGIC 🌟
            window.calcItem = function() {
                const gross = parseFloat(els.grossWt.value) || 0;
                const bags = parseInt(els.bags.value) || 0;
                const bWt = parseFloat(els.bagWt.value) || 0;
                const price = parseFloat(els.price.value) || 0;
                const rule = els.bagRule.value;

                const totalBagWeight = bags * bWt;
                let net = gross - totalBagWeight;
                if(net < 0) net = 0;

                els.netWt.value = net.toFixed(3);
                
                let payableWeight = (rule === 'deduct') ? net : gross;
                const total = (payableWeight / 100) * price;
                
                let displayHtml = total.toLocaleString('en-IN', {minimumFractionDigits: 2});
                
                if(rule === 'no_deduct' && bags > 0 && net > 0) {
                    let realCostQtl = (total / net) * 100;
                    displayHtml += `<br><span style="font-size:0.85rem; color:var(--danger); font-weight:600; display:block;">असली लागत (Real Cost): ₹${realCostQtl.toFixed(2)} /Qtl</span>`;
                }
                
                els.lineTotal.innerHTML = displayHtml;
            };

            function closeLists() {
                els.sellerList.innerHTML = ''; els.sellerList.style.display = 'none';
                els.seedList.innerHTML = ''; els.seedList.style.display = 'none';
            }

            document.addEventListener('click', function(e) {
                if (e.target !== els.sellerName && e.target !== els.seedSearch) closeLists();
            });

            // --- 3. ADD TO BILL & GRAND CALC ---
            els.addBtn.addEventListener('click', function() {
                if (!els.seedId.value) return alert('पहले बीज (Seed) चुनें!');

                const net = parseFloat(els.netWt.value) || 0;
                const price = parseFloat(els.price.value) || 0;
                const gross = parseFloat(els.grossWt.value) || 0;
                const rule = els.bagRule.value;

                if (net <= 0 || price <= 0 || gross <= 0) return alert('कृपया सही वजन और भाव डालें!');

                let payableWeight = (rule === 'deduct') ? net : gross;
                const lineVal = (payableWeight / 100) * price;
                let effectivePrice = (net > 0) ? (lineVal / net) * 100 : price;

                const item = {
                    seed_id: els.seedId.value,
                    seed_name: els.seedSearch.value,
                    quality: els.quality.value,
                    bags: els.bags.value || 0,
                    bag_wt: els.bagWt.value || 0,
                    gross_kg: gross,
                    price_per_qtl: price,
                    effective_price: effectivePrice, 
                    weight_kg: net,  
                    payable_weight: payableWeight,
                    line_value: lineVal,
                    bag_rule: rule
                };

                addedItems.push(item);
                renderTable();

                els.grossWt.value = ''; els.bags.value = '0'; els.netWt.value = '';
                els.lineTotal.textContent = '0.00'; 
                els.grossWt.focus();
            });

            function renderTable() {
                if (addedItems.length === 0) {
                    els.tableBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:30px;">अभी बिल में कोई माल नहीं जोड़ा गया है।</td></tr>`;
                    els.grandWeight.textContent = '0.00 Kg';
                    els.seedValueDisp.textContent = '₹ 0.00';
                    window.calcGrandTotal();
                    return;
                }

                els.tableBody.innerHTML = '';
                let gW = 0, gV = 0;

                addedItems.forEach((item, idx) => {
                    gW += item.weight_kg; 
                    gV += item.line_value;

                    let detailText = `Gr: ${item.gross_kg}kg<br>- ${item.bags} बोरी`;
                    if(item.bag_rule === 'no_deduct') {
                        detailText += `<br><span style="color:var(--danger); font-weight:700; font-size:0.75rem;">(पूरे वजन का पैसा)</span>`;
                    }

                    let rateText = `₹${item.price_per_qtl}`;
                    if(item.effective_price > item.price_per_qtl) {
                        rateText += `<br><small style="color:var(--danger);">असली: ₹${item.effective_price.toFixed(2)}</small>`;
                    }

                    const row = `
                        <tr>
                            <td data-label="माल"><strong style="color:var(--text-main);">${item.seed_name}</strong><br><small style="color:var(--success);">${item.weight_kg.toFixed(3)} Kg शुद्ध</small></td>
                            <td data-label="जानकारी" style="color:var(--text-muted); font-size:0.85rem;">${detailText}</td>
                            <td data-label="भाव" style="font-weight:600;">${rateText}</td>
                            <td data-label="रकम" style="font-weight:700; color:var(--text-main);">₹${item.line_value.toLocaleString('en-IN', {minimumFractionDigits: 2})}</td>
                            <td data-label="एक्शन" style="text-align:right;">
                                <button type="button" class="btn-icon delete" onclick="removeItem(${idx})" title="हटाएं"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    `;
                    els.tableBody.innerHTML += row;
                });

                els.grandWeight.textContent = gW.toFixed(3) + ' Kg';
                els.seedValueDisp.textContent = '₹ ' + gV.toLocaleString('en-IN', {minimumFractionDigits: 2});
                window.calcGrandTotal();
            }

            window.removeItem = function(idx) {
                if (confirm('क्या आप इसे हटाना चाहते हैं?')) { addedItems.splice(idx, 1); renderTable(); }
            };

            // --- 4. ADJUSTMENTS & FINAL TOTAL ---
            window.calcGrandTotal = function() {
                let seedVal = 0;
                addedItems.forEach(i => seedVal += i.line_value);

                let fType = els.fType.value;
                let fAmt = parseFloat(els.fAmt.value) || 0;
                let cdType = els.cdType.value;
                let cdVal = parseFloat(els.cdVal.value) || 0;

                let finalToPay = seedVal;

                // Freight Logic
                let freightActual = 0;
                if(fType === 'deduct') { 
                    freightActual = -fAmt; 
                    els.dispFreight.textContent = '- ₹ ' + fAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});
                    els.dispFreight.style.color = 'var(--danger)'; 
                } else if(fType === 'add') { 
                    freightActual = fAmt; 
                    els.dispFreight.textContent = '+ ₹ ' + fAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});
                    els.dispFreight.style.color = 'var(--success)';
                } else {
                    els.dispFreight.textContent = '₹ 0.00';
                    els.dispFreight.style.color = 'var(--text-muted)';
                }
                finalToPay += freightActual;

                // Cash Discount Logic 
                let cdAmt = 0;
                if(cdVal > 0) {
                    if(cdType === 'percentage') { cdAmt = (finalToPay * cdVal) / 100; }
                    else { cdAmt = cdVal; }
                    finalToPay -= cdAmt;
                }
                
                if(cdAmt > 0) {
                    els.dispCd.textContent = '- ₹ ' + cdAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});
                    els.dispCd.style.color = 'var(--danger)'; 
                } else {
                    els.dispCd.textContent = '₹ 0.00';
                    els.dispCd.style.color = 'var(--text-muted)';
                }

                els.grandValueDisp.textContent = '₹ ' + Math.max(0, finalToPay).toLocaleString('en-IN', {minimumFractionDigits: 2});
            };

            // --- 5. PAYMENT STATUS ---
            els.payMode.addEventListener('change', function() {
                if (this.value === 'Pending') {
                    els.payStatus.className = 'badge st-pending'; els.payStatus.textContent = 'भुगतान बाकी (Unpaid)';
                } else {
                    els.payStatus.className = 'badge st-completed'; els.payStatus.textContent = 'भुगतान पूरा (Paid)';
                }
            });

            // --- 6. SUBMIT ---
            els.form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (addedItems.length === 0) return alert('कृपया बिल में कम से कम एक माल जोड़ें!');
                if (!els.sellerName.value) return alert('कृपया किसान का नाम डालें!');
                if (!confirm('बिल सेव करें और स्टॉक अपडेट करें?')) return;

                document.getElementById('items_json').value = JSON.stringify(addedItems);

                const btn = els.submitBtn;
                const oldHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> सेव हो रहा है...';

                const fd = new FormData(this);

                fetch('grn_handler.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            if (confirm(`✅ बिल सेव हो गया! #${data.grn_no}\n\n🖨️ क्या आप बोरियों के स्टीकर (Stickers) प्रिंट करना चाहते हैं?`)) { printAllStickers(data.grn_id || data.id); }
                            window.location.reload();
                        } else throw new Error(data.error || 'Server Error');
                    })
                    .catch(err => { alert('Error: ' + err.message); btn.disabled = false; btn.innerHTML = oldHtml; });
            });
        });
    </script>
</body>
</html>