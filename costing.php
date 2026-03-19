<?php
// costing.php - UPDATED with Summary Table
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
    <title>Cost Management | Trishe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --bg: #f3f4f6;
            --white: #ffffff;
            --border: #e5e7eb;
            --text: #1f2937;
        }

        .container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
            padding-left: 40px;
        }

        @media(max-width:1000px) {
            .container {
                grid-template-columns: 1fr;
            }

            body {
                padding-left: 20px;
            }
        }

        .card {
            background: var(--white);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 5px;
            color: #6b7280;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 0.9rem;
        }

        .row {
            display: flex;
            gap: 10px;
        }

        .col {
            flex: 1;
        }

        .cost-table {
            width: 100%;
            border-collapse: collapse;
        }

        .cost-table td {
            padding: 10px 0;
            border-bottom: 1px dashed #eee;
            font-size: 0.95rem;
        }

        .val {
            text-align: right;
            font-family: monospace;
            font-weight: 600;
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn:hover {
            background: #4338ca;
        }

        /* Summary Table Styles */
        .summary-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 20px 0 0 40px;
            margin-top: 20px;
        }

        .search-box {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .product-table {
            width: 100%;
            border-collapse: collapse;
        }

        .product-table th {
            text-align: left;
            padding: 12px 10px;
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #64748b;
        }

        .product-table td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
        }

        .badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
        }

        .badge-profit {
            background: #3a8b56;
            color: #4dab6a;
        }

        .badge-loss {
            background: #880d0d;
            color: #991b1b;
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="card">
            <h2 style="font-size:1.2rem; margin-top:0;">1. Live Calculation</h2>
            <div class="form-group">
                <label>Select Product</label>
                <select id="prod_select" class="form-control" onchange="loadProductData()">
                    <option value="">-- Choose Oil Product --</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= $p['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row">
                <div class="col form-group">
                    <label>Seed Rate (₹/Kg)</label>
                    <input type="number" id="seed_rate" class="form-control" oninput="calculate()">
                </div>
                <div class="col form-group">
                    <label>Cake Rate (₹/Kg)</label>
                    <input type="number" id="cake_rate" class="form-control" value="25" oninput="calculate()">
                </div>
            </div>
            <div class="row">
                <div class="col form-group">
                    <label>Extraction (%)</label>
                    <input type="number" id="extraction" class="form-control" oninput="calculate()">
                    <small id="yield_source"></small>
                </div>
                <div class="col form-group">
                    <label>Processing (₹/Kg)</label>
                    <input type="number" id="proc_cost" class="form-control" oninput="calculate()">
                </div>
            </div>
            <div class="form-group" style="background:#f0f9ff; padding:10px; border-radius:6px; border:1px solid #bae6fd;">
                <label>Packaging Cost (Auto from BOM)</label>
                <input type="text" id="pack_cost_disp" class="form-control" readonly style="background:transparent; border:none; font-weight:bold; color:#0369a1;">
                <input type="hidden" id="pack_cost">
            </div>
        </div>

        <div class="card" style="background:#fafafa;">
            <h2 style="font-size:1.2rem; margin-top:0;">2. Cost Analysis</h2>
            <div id="result_area" style="opacity:0.3;">
                <table class="cost-table">
                    <tr>
                        <td>Seed Needed <br><small id="seed_req_txt"></small></td>
                        <td class="val">₹<span id="res_seed_cost">0.00</span></td>
                    </tr>
                    <tr>
                        <td>Total Processing</td>
                        <td class="val">₹<span id="res_proc_cost">0.00</span></td>
                    </tr>
                    <tr>
                        <td style="color:#ef4444;">Less: Cake Recovery</td>
                        <td class="val" style="color:#ef4444;">- ₹<span id="res_cake_val">0.00</span></td>
                    </tr>
                    <tr style="font-weight:bold;">
                        <td>Net Oil Cost</td>
                        <td class="val">₹<span id="res_net_oil">0.00</span></td>
                    </tr>
                    <tr>
                        <td>Total Packaging</td>
                        <td class="val">₹<span id="res_pack">0.00</span></td>
                    </tr>
                    <tr style="border-top:2px solid #ddd; background:#eef2ff;">
                        <td style="font-size:1.1rem;"><strong>EST. FINAL COST</strong></td>
                        <td class="val" style="font-size:1.3rem; color:var(--primary);">₹<span id="res_final">0.00</span></td>
                    </tr>
                </table>
                <div style="margin-top:20px;">
                    <label style="font-weight:600; font-size:0.85rem;">New Selling Price</label>
                    <input type="number" id="selling_price" class="form-control" style="font-size:1.2rem; font-weight:bold; color:#16a34a; margin-top:5px;">
                    <div style="margin-top:10px; display:flex; justify-content:space-between;">
                        <span>Est. Profit Margin:</span><span id="profit_disp" style="font-weight:bold;">₹0.00 (0%)</span>
                    </div>
                    <button class="btn" style="margin-top:15px;" onclick="saveSettings()">Save & Update Price</button>
                </div>
            </div>
        </div>
    </div>

    <div class="summary-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0;">Product Costing Summary</h3>
            <input type="text" id="tableSearch" class="search-box" style="width:250px; margin-bottom:0;" placeholder="Search product name..." onkeyup="searchTable()">
        </div>
        <div style="overflow-x:auto;">
            <table class="product-table" id="summaryTable">
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
                    <?php foreach ($products as $p):
                        $cost = floatval($p['cost_price']);
                        $sell = floatval($p['base_price']);
                        $profit = $sell - $cost;
                        $margin = ($sell > 0) ? ($profit / $sell * 100) : 0;
                    ?>
                        <tr>
                            <td style="font-weight:600;"><?= $p['name'] ?></td>
                            <td><?= $p['seed_name'] ?: 'N/A' ?></td>
                            <td class="val">₹<?= number_format($cost, 2) ?></td>
                            <td class="val">₹<?= number_format($sell, 2) ?></td>
                            <td class="val" style="color: <?= $profit >= 0 ? '#166534' : '#991b1b' ?>;">
                                ₹<?= number_format($profit, 2) ?>
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

    <script>
        let currentOilWeight = 0;

        function loadProductData() {
            const pid = document.getElementById('prod_select').value;
            if (!pid) return;

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
                        document.getElementById('yield_source').innerText = res.debug_msg;
                        document.getElementById('result_area').style.opacity = 1;
                        calculate();
                    }
                });
        }

        function calculate() {
            // FIX: Agar weight 0 hai, toh kam se kam 1 Kg maan kar calculate karega
            let calcWeight = currentOilWeight > 0 ? currentOilWeight : 1;

            const seedRate = parseFloat(document.getElementById('seed_rate').value) || 0;
            const cakeRate = parseFloat(document.getElementById('cake_rate').value) || 0;
            const extraction = parseFloat(document.getElementById('extraction').value) || 40;
            const procCostPerKg = parseFloat(document.getElementById('proc_cost').value) || 0;
            const packCost = parseFloat(document.getElementById('pack_cost').value) || 0;

            // Agar extraction 0 ho jaye toh Infinity error na aaye
            if (extraction <= 0) return;

            // Calculation formulas
            const seedNeeded = calcWeight / (extraction / 100);
            const cakeGenerated = seedNeeded - calcWeight;

            const totalSeedCost = seedNeeded * seedRate;
            const totalProcCost = seedNeeded * procCostPerKg;
            const totalCakeRecovery = cakeGenerated * cakeRate;
            const netOilCost = (totalSeedCost + totalProcCost) - totalCakeRecovery;
            const finalCost = netOilCost + packCost;

            // HTML Update karna
            // Warning dikhaye agar weight 0 hai Database me
            let weightWarning = currentOilWeight <= 0 ? " <span style='color:red;font-size:10px;'>(Weight 0 in DB, Calculated for 1 Unit)</span>" : "";

            document.getElementById('seed_req_txt').innerHTML = `${seedNeeded.toFixed(2)} Kg Seed` + weightWarning;
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
            pEl.style.color = profit >= 0 ? '#166534' : '#991b1b';
        }

        document.getElementById('selling_price').addEventListener('input', calculate);

        function saveSettings() {
            const pid = document.getElementById('prod_select').value;
            if (!pid) return;

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
                        alert("Costing updated!");
                        location.reload(); // Table को अपडेट करने के लिए रीलोड
                    }
                });
        }

        function searchTable() {
            const input = document.getElementById("tableSearch");
            const filter = input.value.toLowerCase();
            const table = document.getElementById("summaryTable");
            const tr = table.getElementsByTagName("tr");

            for (let i = 1; i < tr.length; i++) {
                let td = tr[i].getElementsByTagName("td")[0];
                if (td) {
                    let txtValue = td.textContent || td.innerText;
                    tr[i].style.display = txtValue.toLowerCase().indexOf(filter) > -1 ? "" : "none";
                }
            }
        }
    </script>
</body>

</html>