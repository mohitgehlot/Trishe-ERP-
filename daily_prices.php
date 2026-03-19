<?php
// daily_prices.php - Full Width Terminal Layout
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit;
}

$success_msg = '';

// =======================================================
// 1. AJAX: FETCH PRODUCT DATA
// =======================================================
if (isset($_POST['action']) && $_POST['action'] == 'get_calc_data') {
    ob_clean();
    header('Content-Type: application/json');
    $prod_id = intval($_POST['prod_id']);

    try {
        $prod = $conn->query("SELECT id, seed_id, weight, base_price FROM products WHERE id = $prod_id")->fetch_assoc();
        $seed_id = intval($prod['seed_id']);

        $proc_cost = 3.00;
        if ($seed_id > 0) {
            $sm = $conn->query("SELECT processing_cost FROM seeds_master WHERE id = $seed_id")->fetch_assoc();
            $proc_cost = floatval($sm['processing_cost'] ?? 3.00);
        }

        $pack_cost = 0;
        $pack_q = $conn->query("SELECT pr.qty_needed, ip.last_price FROM product_recipes pr JOIN inventory_packaging ip ON pr.packaging_id = ip.id WHERE pr.raw_material_id = $prod_id AND pr.item_type = 'PACKING'");
        if ($pack_q) while ($pk = $pack_q->fetch_assoc()) $pack_cost += (floatval($pk['qty_needed']) * floatval($pk['last_price']));

        echo json_encode([
            'success' => true,
            'seed_id' => $seed_id,
            'weight' => floatval($prod['weight']),
            'pack_cost' => $pack_cost,
            'proc_cost' => $proc_cost,
            'current_sell' => floatval($prod['base_price'])
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

// =======================================================
// 2. SYNC YIELD FROM MACHINES
// =======================================================
if (isset($_POST['sync_machine_yield'])) {
    $conn->begin_transaction();
    try {
        $calc_sql = "SELECT seed_id, (SUM(oil_out) / SUM(seed_qty)) * 100 AS actual_yield 
                     FROM seed_processing 
                     WHERE status = 'completed' AND seed_qty > 0 AND oil_out > 0 
                     GROUP BY seed_id";
        $calc_res = $conn->query($calc_sql);

        $updated_count = 0;
        if ($calc_res && $calc_res->num_rows > 0) {
            while ($row = $calc_res->fetch_assoc()) {
                $s_id = intval($row['seed_id']);
                $actual_yield = round(floatval($row['actual_yield']), 2);
                if ($actual_yield > 0 && $actual_yield <= 100) {
                    $conn->query("UPDATE seeds_master SET avg_oil_recovery = $actual_yield WHERE id = $s_id");
                    $updated_count++;
                }
            }
        }
        $conn->commit();
        $success_msg = $updated_count > 0 ? "Synced Machine Yields for $updated_count seeds!" : "No data to sync.";
    } catch (Exception $e) {
        $conn->rollback();
        $success_msg = "Error: " . $e->getMessage();
    }
}

// =======================================================
// 3. BULK PRICE UPDATE
// =======================================================
if (isset($_POST['update_prices'])) {
    $seed_rates = $_POST['seed_rate'] ?? [];
    $cake_rates = $_POST['cake_rate'] ?? [];

    $conn->begin_transaction();
    try {
        if (is_array($seed_rates)) {
            foreach ($seed_rates as $seed_id => $new_seed_rate) {
                $s_id = intval($seed_id);
                $n_rate = floatval($new_seed_rate);
                $c_rate = isset($cake_rates[$s_id]) ? floatval($cake_rates[$s_id]) : 0;

                $old_data_q = $conn->query("SELECT current_market_rate, current_cake_rate, processing_cost, avg_oil_recovery FROM seeds_master WHERE id = $s_id");
                $old_data = $old_data_q->fetch_assoc();

                $prev_seed = floatval($old_data['current_market_rate']);
                $prev_cake = floatval($old_data['current_cake_rate']);
                $proc_cost = floatval($old_data['processing_cost']);
                $master_yield = floatval($old_data['avg_oil_recovery']);

                $conn->query("UPDATE seeds_master SET previous_market_rate = $prev_seed, previous_cake_rate = $prev_cake, current_market_rate = $n_rate, current_cake_rate = $c_rate, last_updated = NOW() WHERE id = $s_id");

                $products = $conn->query("SELECT id, weight, cost_price, base_price FROM products WHERE seed_id = $s_id AND is_active = 1 AND product_type = 'oil'");
                if ($products) {
                    while ($p = $products->fetch_assoc()) {
                        $pid = $p['id'];
                        $oil_weight = floatval($p['weight']);

                        // 🌟 FIX: Agar weight 0 hai, toh 1 Kg maan lo taaki calculation 0 na ho 🌟
                        $calc_weight = ($oil_weight > 0) ? $oil_weight : 1;

                        $active_yield = ($master_yield > 0) ? $master_yield : 40;

                        // 🌟 FIX: current oil_weight ki jagah calc_weight use kiya gaya hai 🌟
                        $seed_needed = $calc_weight / ($active_yield / 100);
                        $cake_generated = $seed_needed - $calc_weight;

                        $net_oil_cost = ($seed_needed * $n_rate) + ($seed_needed * $proc_cost) - ($cake_generated * $c_rate);
                        $pack_cost = 0;
                        $pack_q = $conn->query("SELECT pr.qty_needed, ip.last_price FROM product_recipes pr JOIN inventory_packaging ip ON pr.packaging_id = ip.id WHERE pr.raw_material_id = $pid AND pr.item_type = 'PACKING'");
                        if ($pack_q) while ($pk = $pack_q->fetch_assoc()) $pack_cost += (floatval($pk['qty_needed']) * floatval($pk['last_price']));

                        $new_final_cost = $net_oil_cost + $pack_cost;
                        $old_cost = floatval($p['cost_price']);
                        $old_sell = floatval($p['base_price']);
                        $old_profit = ($old_sell > 0 && $old_cost > 0) ? ($old_sell - $old_cost) : 0;
                        $new_sell_price = ($old_profit <= 0) ? ($new_final_cost * 1.10) : ($new_final_cost + $old_profit);

                        $conn->query("UPDATE products SET cost_price = $new_final_cost, base_price = $new_sell_price WHERE id = $pid");
                    }
                }
            }
        }
        $conn->commit();
        $success_msg = "Market Trade Executed Successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $success_msg = "Error updating prices.";
    }
}

$seeds = [];
$res = $conn->query("SELECT * FROM seeds_master ORDER BY name");
if ($res) while ($r = $res->fetch_assoc()) $seeds[] = $r;

$all_products = [];
$p_res = $conn->query("SELECT id, name FROM products WHERE is_active = 1 AND product_type = 'oil' ORDER BY name");
if ($p_res) while ($pr = $p_res->fetch_assoc()) $all_products[] = $pr;

$chart_series = [];
foreach ($seeds as $s) {
    $sid = $s['id'];
    $history_q = $conn->query("SELECT price_per_qtl/100 as rate FROM inventory_grn_items WHERE seed_id = $sid ORDER BY id DESC LIMIT 10");
    $prices = [];
    while ($h = $history_q->fetch_assoc()) {
        $prices[] = floatval($h['rate']);
    }
    $prices = array_reverse($prices);
    if (!empty($prices)) {
        $chart_series[] = ['name' => $s['name'], 'data' => $prices];
    }
}

function getTrendHtml($current, $previous)
{
    $cur = floatval($current);
    $prev = floatval($previous);
    if ($prev == 0 || $cur == $prev) return '<span style="color:#9ca3af; font-size:0.75rem;"> 0.00</span>';
    $diff = $cur - $prev;
    return $diff > 0 ? '<span style="color:#10b981; font-size:0.75rem;">▲ +' . number_format($diff, 2) . '</span>' : '<span style="color:#ef4444; font-size:0.75rem;">▼ ' . number_format(abs($diff), 2) . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Wide Market Terminal | Trishe Agro</title>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --bg-body: #f1f5f9;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            margin: 0;
            padding: 0 0 80px 0;
        }

        /* 100% WIDTH CONTAINER */
        .page-wrapper {
            padding: 20px 0 0 60px;
            box-sizing: border-box;
        }

        .custom-ticker-wrap {
            background: #0f172a;
            color: white;
            padding: 10px 0;
            overflow: hidden;
            white-space: nowrap;
            margin: -20px -20px 20px -20px;
            border-bottom: 2px solid #334155;
        }

        .ticker-content {
            display: inline-block;
            padding-left: 100%;
            animation: ticker 30s linear infinite;
        }

        .ticker-item {
            display: inline-block;
            margin-right: 40px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        @keyframes ticker {
            0% {
                transform: translateX(0);
            }

            100% {
                transform: translateX(-100%);
            }
        }

        /* GRID SYSTEM FOR SINGLE VIEW */
        .top-grid {
            display: grid;
            grid-template-columns: 1.2fr 1.8fr;
            gap: 20px;
            margin-bottom: 20px;
            align-items: stretch;
        }

        .bottom-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            align-items: start;
        }

        .card {
            background: white;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .card-header {
            padding: 12px 15px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .market-table {
            width: 100%;
            border-collapse: collapse;
        }

        .market-table th {
            background: #f8fafc;
            padding: 10px 15px;
            text-align: left;
            font-size: 0.7rem;
            color: #64748b;
            text-transform: uppercase;
        }

        .market-table td {
            padding: 8px 15px;
            border-bottom: 1px solid #f1f5f9;
        }

        .input-controls {
            display: inline-flex;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            height: 30px;
            width: 110px;
            overflow: hidden;
        }

        .ctrl-btn {
            background: #f8fafc;
            border: none;
            width: 28px;
            cursor: pointer;
            font-weight: bold;
        }

        .val-input {
            flex: 1;
            text-align: center;
            border: none;
            font-size: 0.85rem;
            font-weight: 600;
            outline: none;
            width: 100%;
        }

        .btn-sync {
            background: #10b981;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.8rem;
        }

        .fab-save {
            position: fixed;
            bottom: 20px;
            right: 30px;
            background: var(--primary);
            color: white;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
            z-index: 100;
        }

        .cost-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .cost-table td {
            padding: 7px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .val-td {
            text-align: right;
            font-family: monospace;
            font-weight: 700;
            color: #1e293b;
        }

        @media(max-width: 1200px) {

            .top-grid,
            .bottom-grid {
                grid-template-columns: 1fr;
            }

            .page-wrapper {
                margin-left: 0;
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="page-wrapper">
        <div class="custom-ticker-wrap">
            <div class="ticker-content">
                <?php foreach ($seeds as $t_s): ?>
                    <span class="ticker-item"><?= htmlspecialchars($t_s['name']) ?>: ₹<?= number_format($t_s['current_market_rate'], 2) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div style="background: #dcfce7; color: #166534; padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; font-size: 0.9rem;">
                <i class="fas fa-check-circle"></i> <?= $success_msg ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="top-grid">
                <div class="card">
                    <div class="card-header">
                        <span><i class="fas fa-store"></i> Market Rates</span>
                        <button type="submit" name="sync_machine_yield" class="btn-sync" onclick="return confirm('Sync yields?')">Sync Yields</button>
                    </div>
                    <table class="market-table">
                        <thead>
                            <tr>
                                <th>Commodity</th>
                                <th>Seed Rate</th>
                                <th>Cake Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($seeds as $s): ?>
                                <tr>
                                    <input type="hidden" id="hidden_yield_<?= $s['id'] ?>" value="<?= floatval($s['avg_oil_recovery']) ?>">
                                    <td>
                                        <div style="font-weight:700; font-size:0.9rem;"><?= htmlspecialchars($s['name']) ?></div>
                                        <?= getTrendHtml($s['current_market_rate'], $s['previous_market_rate']) ?>
                                    </td>
                                    <td>
                                        <div class="input-controls">
                                            <button type="button" class="ctrl-btn" onclick="adjustVal('seed_<?= $s['id'] ?>', -1)">-</button>
                                            <input type="number" step="0.01" name="seed_rate[<?= $s['id'] ?>]" id="seed_<?= $s['id'] ?>" class="val-input" value="<?= number_format($s['current_market_rate'], 2, '.', '') ?>">
                                            <button type="button" class="ctrl-btn" onclick="adjustVal('seed_<?= $s['id'] ?>', 1)">+</button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-controls">
                                            <button type="button" class="ctrl-btn" onclick="adjustVal('cake_<?= $s['id'] ?>', -1)">-</button>
                                            <input type="number" step="0.01" name="cake_rate[<?= $s['id'] ?>]" id="cake_<?= $s['id'] ?>" class="val-input" value="<?= number_format($s['current_cake_rate'], 2, '.', '') ?>">
                                            <button type="button" class="ctrl-btn" onclick="adjustVal('cake_<?= $s['id'] ?>', 1)">+</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Historical Trends (₹/Kg)</div>
                    <div id="priceTrendChart" style="padding: 10px;"></div>
                </div>
            </div>

            <div class="bottom-grid">
                <div class="card">
                    <div class="card-header"><i class="fas fa-calculator"></i> Live Profit Analyzer</div>
                    <div style="padding: 15px; display: flex; gap: 20px; align-items: start;">
                        <div style="flex: 1;">
                            <label style="font-size: 0.75rem; font-weight: 700; color: #64748b;">SELECT PRODUCT TO TEST</label>
                            <select id="analyzer_product" onchange="loadAnalyzerData()" style="margin-top:5px; height: 40px; font-weight: 700; border: 2px solid var(--primary);">
                                <option value="">-- Choose Product --</option>
                                <?php foreach ($all_products as $ap) echo "<option value='{$ap['id']}'>{$ap['name']}</option>"; ?>
                            </select>
                        </div>
                        <div id="analyzer_results" style="flex: 2; display:none; background:#f8fafc; border-radius:8px; padding:15px; border: 1px solid #e2e8f0;">
                            <div id="an_yield_msg" style="text-align:center; font-size:0.8rem; font-weight:800; color:#059669; margin-bottom:10px;"></div>
                            <table class="cost-table">
                                <tr>
                                    <td>Seed Cost <small id="an_seed_req"></small></td>
                                    <td class="val-td" id="an_seed_cost"></td>
                                </tr>
                                <tr>
                                    <td>Processing Cost</td>
                                    <td class="val-td" id="an_proc_cost"></td>
                                </tr>
                                <tr>
                                    <td>Cake Recovery</td>
                                    <td class="val-td" id="an_cake_val" style="color:#ef4444"></td>
                                </tr>
                                <tr style="font-size:1.1rem; color:var(--primary); font-weight:900;">
                                    <td>FINAL COST</td>
                                    <td class="val-td" id="an_final_cost"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; justify-content: center; opacity: 0.6;">
                    <i class="fas fa-bolt fa-3x text-primary"></i>
                    <p style="margin-left: 10px; font-weight: 600;">Global Sync Active</p>
                </div>
            </div>

            <button type="submit" name="update_prices" class="fab-save"><i class="fas fa-sync"></i> UPDATE GLOBAL PRICES</button>
        </form>
    </div>

    <script>
        var options = {
            series: <?= json_encode($chart_series) ?>,
            chart: {
                height: 350,
                type: 'line',
                toolbar: {
                    show: false
                }
            },
            stroke: {
                curve: 'smooth',
                width: 3
            },
            colors: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444'],
            xaxis: {
                labels: {
                    show: false
                }
            },
            tooltip: {
                y: {
                    formatter: v => "₹" + v.toFixed(2)
                }
            }
        };
        new ApexCharts(document.querySelector("#priceTrendChart"), options).render();

        let currentAnalyzer = {
            active: false
        };

        function adjustVal(id, amt) {
            let el = document.getElementById(id);
            el.value = (parseFloat(el.value) + amt).toFixed(2);
            if (currentAnalyzer.active) runLiveCalculation();
        }

        function loadAnalyzerData() {
            const pid = document.getElementById('analyzer_product').value;
            if (!pid) return;
            const fd = new FormData();
            fd.append('action', 'get_calc_data');
            fd.append('prod_id', pid);
            fetch('daily_prices.php', {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(res => {
                if (res.success) {
                    currentAnalyzer = {
                        active: true,
                        ...res
                    };
                    document.getElementById('analyzer_results').style.display = 'block';
                    runLiveCalculation();
                }
            });
        }

        function runLiveCalculation() {
            const sid = currentAnalyzer.seed_id;
            const seedRate = parseFloat(document.getElementById('seed_' + sid).value) || 0;
            const cakeRate = parseFloat(document.getElementById('cake_' + sid).value) || 0;
            const masterYield = parseFloat(document.getElementById('hidden_yield_' + sid).value) || 40;

            // 🌟 FIX: Agar weight 0 hai, toh usko 1 maan lo 🌟
            let calcWeight = currentAnalyzer.weight > 0 ? currentAnalyzer.weight : 1;

            // Zero se divide hone se rokne ke liye
            if (masterYield <= 0) return;

            const seedNeeded = calcWeight / (masterYield / 100);
            const cakeGen = seedNeeded - calcWeight;
            const finalCost = (seedNeeded * seedRate) + (seedNeeded * currentAnalyzer.proc_cost) - (cakeGen * cakeRate) + currentAnalyzer.pack_cost;

            let weightWarning = currentAnalyzer.weight <= 0 ? " <span style='color:red;'>(Weight 0, Calc for 1 Unit)</span>" : "";

            document.getElementById('an_yield_msg').innerHTML = `Syncing with Yield: ${masterYield}%` + weightWarning;
            document.getElementById('an_seed_req').innerText = `(${seedNeeded.toFixed(2)}Kg)`;
            document.getElementById('an_seed_cost').innerText = `₹${(seedNeeded * seedRate).toFixed(2)}`;
            document.getElementById('an_proc_cost').innerText = `₹${(seedNeeded * currentAnalyzer.proc_cost).toFixed(2)}`;
            document.getElementById('an_cake_val').innerText = `-₹${(cakeGen * cakeRate).toFixed(2)}`;
            document.getElementById('an_final_cost').innerText = `₹${finalCost.toFixed(2)}`;
        }
    </script>
</body>

</html>