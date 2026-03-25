<?php
// costing.php - INTEGRATED WITH MASTER CSS
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

// 1. FETCH ALL PRODUCTS FOR LIST & TABLE
$products = [];
// Table query includes seeds master to show seed names
$sql = "SELECT p.*, sm.name as seed_name, sm.id as seed_id_from_master 
        FROM products p 
        LEFT JOIN seeds_master sm ON p.seed_id = sm.id 
        WHERE p.is_active = 1 ORDER BY p.name";
$res = $conn->query($sql);
if ($res) while ($r = $res->fetch_assoc()) $products[] = $r;

// 2. AJAX HANDLER: Get Calculation Data
if (isset($_POST['action']) && $_POST['action'] == 'get_cost_data') {
    ob_clean();
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    $prod_id = intval($_POST['prod_id']);

    try {
        $prod = $conn->query("SELECT * FROM products WHERE id = $prod_id")->fetch_assoc();
        if (!$prod) throw new Exception("Product not found");
        $seed_id = intval($prod['seed_id']);

        $seed_rate = 0;
        $live_yield = 0;
        $proc_cost = 3.00;

        if ($seed_id > 0) {
            $sql_rate = "SELECT price_per_qtl/100 as last_rate FROM inventory_grn_items WHERE seed_id = $seed_id ORDER BY id DESC LIMIT 1";
            $res_rate = $conn->query($sql_rate);
            if ($res_rate && $res_rate->num_rows > 0) {
                $d = $res_rate->fetch_assoc();
                $seed_rate = floatval($d['last_rate'] ?? 0);
            }

            $sql_master = "SELECT avg_oil_recovery, processing_cost FROM seeds_master WHERE id = $seed_id";
            $res_master = $conn->query($sql_master);
            if ($res_master && $res_master->num_rows > 0) {
                $m_data = $res_master->fetch_assoc();
                $live_yield = floatval($m_data['avg_oil_recovery']);
                if (floatval($m_data['processing_cost']) > 0) $proc_cost = floatval($m_data['processing_cost']);
            }
        }

        $manual_yield = floatval($prod['extraction_yield'] ?? 0);
        $final_extraction = ($live_yield > 0) ? $live_yield : (($manual_yield > 0) ? $manual_yield : 40);
        $source_msg = ($live_yield > 0) ? "✅ Live Data ($live_yield%)" : "⚠️ Manual Used";

        // Packaging Cost
        $pack_cost = 0;
        $sql_rec = "SELECT pr.qty_needed, ip.avg_price, ip.last_price 
                    FROM product_recipes pr 
                    JOIN inventory_packaging ip ON pr.packaging_id = ip.id 
                    WHERE pr.raw_material_id = $prod_id";
        $res_rec = $conn->query($sql_rec);
        if ($res_rec) {
            while ($row = $res_rec->fetch_assoc()) {
                $p_price = ($row['avg_price'] > 0) ? $row['avg_price'] : $row['last_price'];
                $pack_cost += ($row['qty_needed'] * $p_price);
            }
        }

        echo json_encode([
            'success' => true,
            'prod_name' => $prod['name'],
            'oil_weight' => floatval($prod['weight']),
            'seed_rate' => $seed_rate,
            'pack_cost' => $pack_cost,
            'extraction' => $final_extraction,
            'proc_cost' => $proc_cost,
            'mrp' => floatval($prod['base_price']),
            'debug_msg' => $source_msg
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 3. AJAX HANDLER: Save Cost Settings
if (isset($_POST['action']) && $_POST['action'] == 'save_costing') {
    ob_clean();
    header('Content-Type: application/json');

    $pid = intval($_POST['pid']);
    $extract = floatval($_POST['extraction']);
    $proc = floatval($_POST['proc_cost']);
    $final_cost = floatval($_POST['final_cost']);
    $selling_price = floatval($_POST['selling_price']);

    $conn->begin_transaction();
    try {
        $p_row = $conn->query("SELECT seed_id FROM products WHERE id = $pid")->fetch_assoc();
        $seed_id = $p_row['seed_id'];

        if ($seed_id > 0) {
            $stmt1 = $conn->prepare("UPDATE seeds_master SET processing_cost = ? WHERE id = ?");
            $stmt1->bind_param("di", $proc, $seed_id);
            $stmt1->execute();
        }

        $stmt2 = $conn->prepare("UPDATE products SET cost_price=?, base_price=? WHERE id=?");
        $stmt2->bind_param("ddi",  $final_cost, $selling_price, $pid);
        $stmt2->execute();

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Cost Management | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

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
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
            align-items: start;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .cost-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .cost-table td {
            padding: 12px 0;
            border-bottom: 1px dashed var(--border);
            color: var(--text-main);
        }

        .cost-table .val {
            text-align: right;
            font-weight: 700;
            font-family: monospace;
            font-size: 1.05rem;
        }

        .badge-profit {
            background: #dcfce7 !important;
            color: #166534 !important;
            border: 1px solid #bbf7d0;
        }

        .badge-loss {
            background: #fee2e2 !important;
            color: #991b1b !important;
            border: 1px solid #fca5a5;
        }

        @media(max-width:1024px) {
            .grid-layout {
                grid-template-columns: 1fr;
            }
        }

        @media(max-width: 768px) {
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            /* Stack inputs on small screens */
            .search-box {
                width: 100% !important;
                margin-top: 10px;
            }
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-calculator text-primary" style="margin-right:10px;"></i> Cost & Price Management</h1>
            <div style="color:var(--text-muted); font-size:0.9rem; font-weight:500;">Calculate dynamic landing cost based on live raw material rates.</div>
        </div>

        <div class="grid-layout">
            <div class="card">
                <div class="card-header"><i class="fas fa-sliders-h text-warning" style="margin-right:8px;"></i> 1. Live Calculation</div>
                <div style="padding: 20px;">
                    <div class="form-group" style="margin-bottom:15px;">
                        <label class="form-label">Select Product</label>
                        <select id="prod_select" class="form-input" onchange="loadProductData()">
                            <option value="">-- Choose Oil Product --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-grid">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Seed Rate (₹/Kg)</label>
                            <input type="number" id="seed_rate" class="form-input" oninput="calculate()">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Cake Rate (₹/Kg)</label>
                            <input type="number" id="cake_rate" class="form-input" value="25" oninput="calculate()">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Extraction (%)</label>
                            <input type="number" id="extraction" class="form-input" oninput="calculate()">
                            <small id="yield_source" style="display:block; margin-top:5px; font-weight:600;"></small>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Processing Cost (₹/Kg)</label>
                            <input type="number" id="proc_cost" class="form-input" oninput="calculate()">
                        </div>
                    </div>

                    <div class="form-group" style="background:#f0f9ff; padding:15px; border-radius:6px; border:1px solid #bae6fd; margin-top:15px;">
                        <label class="form-label" style="color:#0369a1;">Packaging Cost (Auto from BOM)</label>
                        <input type="text" id="pack_cost_disp" class="form-input" readonly style="background:transparent; border:none; font-weight:800; color:#0369a1; padding:0; font-size:1.1rem;">
                        <input type="hidden" id="pack_cost">
                    </div>
                </div>
            </div>

            <div class="card" style="background:#f8fafc;">
                <div class="card-header" style="background:transparent; border-bottom:1px solid var(--border);"><i class="fas fa-chart-line text-success" style="margin-right:8px;"></i> 2. Cost Analysis</div>
                <div style="padding: 20px;">
                    <div id="result_area" style="opacity:0.3; transition:opacity 0.3s;">
                        <table class="cost-table">
                            <tr>
                                <td><span style="font-weight:600;">Seed Needed</span><br><small id="seed_req_txt" style="color:var(--text-muted);"></small></td>
                                <td class="val">₹<span id="res_seed_cost">0.00</span></td>
                            </tr>
                            <tr>
                                <td><span style="font-weight:600;">Total Processing</span></td>
                                <td class="val">₹<span id="res_proc_cost">0.00</span></td>
                            </tr>
                            <tr>
                                <td style="color:var(--danger); font-weight:600;">Less: Cake Recovery</td>
                                <td class="val" style="color:var(--danger);">- ₹<span id="res_cake_val">0.00</span></td>
                            </tr>
                            <tr style="font-weight:700;">
                                <td>Net Oil Cost</td>
                                <td class="val">₹<span id="res_net_oil">0.00</span></td>
                            </tr>
                            <tr>
                                <td><span style="font-weight:600;">Total Packaging</span></td>
                                <td class="val">₹<span id="res_pack">0.00</span></td>
                            </tr>
                            <tr style="border-top:2px solid var(--border); background:#eef2ff;">
                                <td style="font-size:1.1rem; padding:15px 10px;"><strong>EST. FINAL COST</strong></td>
                                <td class="val" style="font-size:1.3rem; color:var(--primary); padding:15px 10px;">₹<span id="res_final">0.00</span></td>
                            </tr>
                        </table>

                        <div style="margin-top:25px; border-top:1px dashed var(--border); padding-top:20px;">
                            <label class="form-label" style="color:var(--text-main);">New Selling Price (MRP)</label>
                            <input type="number" id="selling_price" class="form-input" style="font-size:1.5rem; font-weight:800; color:var(--success); padding:15px;">
                            <div style="margin-top:15px; display:flex; justify-content:space-between; align-items:center; background:#fff; padding:10px 15px; border-radius:6px; border:1px solid var(--border);">
                                <span style="font-weight:600; color:var(--text-muted);">Est. Profit Margin:</span>
                                <span id="profit_disp" style="font-weight:800; font-size:1.1rem;">₹0.00 (0%)</span>
                            </div>
                            <button class="btn btn-primary" style="margin-top:20px; width:100%; padding:14px; font-size:1.1rem;" onclick="saveSettings()">
                                <i class="fas fa-save" style="margin-right:8px;"></i> Save & Update Price
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center;"><i class="fas fa-list text-info" style="margin-right:8px;"></i> Product Costing Summary</div>
                <input type="text" id="tableSearch" class="form-input" style="width:250px; padding:8px 12px; margin-bottom:0;" placeholder="Search product name..." onkeyup="searchTable()">
            </div>
            <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                <table class="table" id="summaryTable">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Raw Material</th>
                            <th>Current Cost</th>
                            <th>Selling Price</th>
                            <th>Profit/Unit</th>
                            <th>Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding:30px; color:var(--text-muted);">No products available.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($products as $p):
                            $cost = floatval($p['cost_price']);
                            $sell = floatval($p['base_price']);
                            $profit = $sell - $cost;
                            $margin = ($sell > 0) ? ($profit / $sell * 100) : 0;
                        ?>
                            <tr>
                                <td style="font-weight:700; color:var(--text-main);"><?= htmlspecialchars($p['name']) ?></td>
                                <td><?= htmlspecialchars($p['seed_name']) ?: '<span style="color:#94a3b8;">N/A</span>' ?></td>
                                <td style="font-weight:600;">₹<?= number_format($cost, 2) ?></td>
                                <td style="font-weight:700; color:var(--primary);">₹<?= number_format($sell, 2) ?></td>
                                <td style="font-weight:700; color: <?= $profit >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                    <?= $profit >= 0 ? '+' : '' ?>₹<?= number_format($profit, 2) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $margin >= 0 ? 'badge-profit' : 'badge-loss' ?>">
                                        <?= number_format($margin, 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        let currentOilWeight = 0;

        function loadProductData() {
            const pid = document.getElementById('prod_select').value;
            if (!pid) {
                document.getElementById('result_area').style.opacity = 0.3;
                return;
            }

            fetch('costing.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=get_cost_data&prod_id=${pid}`
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        currentOilWeight = res.oil_weight;
                        document.getElementById('seed_rate').value = res.seed_rate;
                        document.getElementById('extraction').value = res.extraction;
                        document.getElementById('proc_cost').value = res.proc_cost;
                        document.getElementById('pack_cost').value = res.pack_cost;
                        document.getElementById('pack_cost_disp').value = "₹" + res.pack_cost.toFixed(2);
                        document.getElementById('selling_price').value = res.mrp;

                        let ySrc = document.getElementById('yield_source');
                        ySrc.innerText = res.debug_msg;
                        ySrc.style.color = res.debug_msg.includes('Live') ? 'var(--success)' : 'var(--warning)';

                        document.getElementById('result_area').style.opacity = 1;
                        calculate();
                    }
                });
        }

        function calculate() {
            // If weight is 0 in DB, calculate for 1 Unit safely
            let calcWeight = currentOilWeight > 0 ? currentOilWeight : 1;

            const seedRate = parseFloat(document.getElementById('seed_rate').value) || 0;
            const cakeRate = parseFloat(document.getElementById('cake_rate').value) || 0;
            const extraction = parseFloat(document.getElementById('extraction').value) || 40;
            const procCostPerKg = parseFloat(document.getElementById('proc_cost').value) || 0;
            const packCost = parseFloat(document.getElementById('pack_cost').value) || 0;

            if (extraction <= 0) return;

            // Calculation formulas
            const seedNeeded = calcWeight / (extraction / 100);
            const cakeGenerated = seedNeeded - calcWeight;

            const totalSeedCost = seedNeeded * seedRate;
            const totalProcCost = seedNeeded * procCostPerKg;
            const totalCakeRecovery = cakeGenerated * cakeRate;
            const netOilCost = (totalSeedCost + totalProcCost) - totalCakeRecovery;
            const finalCost = netOilCost + packCost;

            let weightWarning = currentOilWeight <= 0 ? " <span style='color:var(--danger);font-size:11px;display:block;'>(Calculated for 1 Unit - Weight missing in DB)</span>" : "";

            document.getElementById('seed_req_txt').innerHTML = `${seedNeeded.toFixed(2)} Kg` + weightWarning;
            document.getElementById('res_seed_cost').innerText = totalSeedCost.toFixed(2);
            document.getElementById('res_proc_cost').innerText = totalProcCost.toFixed(2);
            document.getElementById('res_cake_val').innerText = totalCakeRecovery.toFixed(2);
            document.getElementById('res_net_oil').innerText = netOilCost.toFixed(2);
            document.getElementById('res_pack').innerText = packCost.toFixed(2);
            document.getElementById('res_final').innerText = finalCost.toFixed(2);

            // Profit Calculation
            const sellPrice = parseFloat(document.getElementById('selling_price').value) || 0;
            const profit = sellPrice - finalCost;
            const margin = sellPrice > 0 ? (profit / sellPrice * 100) : 0;

            const pEl = document.getElementById('profit_disp');
            pEl.innerText = `₹${profit.toFixed(2)} (${margin.toFixed(1)}%)`;
            pEl.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)';
        }

        document.getElementById('selling_price').addEventListener('input', calculate);

        function saveSettings() {
            const pid = document.getElementById('prod_select').value;
            if (!pid) return alert("Please select a product first.");

            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'save_costing');
            fd.append('pid', pid);
            fd.append('extraction', document.getElementById('extraction').value);
            fd.append('proc_cost', document.getElementById('proc_cost').value);
            fd.append('final_cost', document.getElementById('res_final').innerText);
            fd.append('selling_price', document.getElementById('selling_price').value);

            fetch('costing.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert("Costing & Price updated successfully!");
                        location.reload();
                    } else {
                        alert("Error: " + res.error);
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                }).catch(err => {
                    alert("Network Error");
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        }

        function searchTable() {
            const input = document.getElementById("tableSearch");
            const filter = input.value.toLowerCase();
            const table = document.getElementById("summaryTable");
            const tr = table.getElementsByTagName("tr");

            // Start from 1 to skip table header
            for (let i = 1; i < tr.length; i++) {
                let td = tr[i].getElementsByTagName("td")[0]; // Search only in Product Name column
                if (td) {
                    let txtValue = td.textContent || td.innerText;
                    tr[i].style.display = txtValue.toLowerCase().indexOf(filter) > -1 ? "" : "none";
                }
            }
        }
    </script>
</body>

</html>