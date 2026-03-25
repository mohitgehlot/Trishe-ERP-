<?php
// daily_prices.php - Full Width Terminal Layout (MASTER CSS)
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

                        $calc_weight = ($oil_weight > 0) ? $oil_weight : 1;
                        $active_yield = ($master_yield > 0) ? $master_yield : 40;

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
    if ($prev == 0 || $cur == $prev) return '<span style="color:var(--text-muted); font-size:0.75rem; font-weight:600;"> 0.00</span>';
    $diff = $cur - $prev;
    return $diff > 0 ? '<span style="color:var(--success); font-size:0.75rem; font-weight:700;">▲ +' . number_format($diff, 2) . '</span>' : '<span style="color:var(--danger); font-size:0.75rem; font-weight:700;">▼ ' . number_format(abs($diff), 2) . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Wide Market Terminal | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        /* 100% WIDTH CONTAINER */
        .page-wrapper {
            padding: 20px 0 0 20px;
            box-sizing: border-box;
            max-width: 1500px;
            margin: 0 auto;
        }

        .custom-ticker-wrap {
            background: #0f172a;
            color: white;
            padding: 12px 0;
            overflow: hidden;
            white-space: nowrap;
            margin: -20px -20px 25px -20px;
            border-bottom: 3px solid #334155;
        }

        .ticker-content {
            display: inline-block;
            padding-left: 100%;
            animation: ticker 25s linear infinite;
        }

        .ticker-item {
            display: inline-block;
            margin-right: 50px;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
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
        /* 🌟 FIX: Adjusted Grid to give table more space and prevent squeezing */
        .top-grid {
            display: grid;
            grid-template-columns: 1.4fr 1.6fr;
            gap: 24px;
            margin-bottom: 24px;
            align-items: start;
        }

        .bottom-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            align-items: start;
            margin-bottom: 40px;
        }

        .card {
            background: var(--bg-card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        /* 🌟 FIX: Force hide horizontal scroll */
        .table-wrap {
            overflow-x: hidden !important;
        }

        .table-wrap table {
            min-width: 100% !important;
        }

        /* CUSTOM INPUTS FOR TABLE */
        /* 🌟 FIX: Slightly smaller width to fit nicely */
        .input-controls {
            display: inline-flex;
            border: 1px solid var(--border);
            border-radius: 6px;
            height: 35px;
            width: 105px;
            overflow: hidden;
            background: white;
        }

        .ctrl-btn {
            background: #f1f5f9;
            color: var(--text-muted);
            border: none;
            width: 28px;
            cursor: pointer;
            font-weight: 800;
            font-size: 1.1rem;
            transition: 0.2s;
        }

        .ctrl-btn:hover {
            background: #e2e8f0;
            color: var(--text-main);
        }

        .val-input {
            flex: 1;
            text-align: center;
            border: none;
            font-size: 0.95rem;
            font-weight: 700;
            outline: none;
            width: 100%;
            color: var(--primary);
        }

        .fab-save {
            position: fixed;
            bottom: 25px;
            right: 35px;
            background: var(--primary);
            color: white;
            padding: 15px 35px;
            border-radius: 50px;
            font-weight: 800;
            font-size: 1.05rem;
            border: none;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
            z-index: 100;
            transition: transform 0.2s;
            letter-spacing: 0.5px;
        }

        .fab-save:hover {
            transform: translateY(-2px);
            background: var(--primary-hover);
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

        .val-td {
            text-align: right;
            font-family: monospace;
            font-weight: 800;
            color: var(--text-main);
            font-size: 1.1rem;
        }

        @media(max-width: 1024px) {

            .top-grid,
            .bottom-grid {
                grid-template-columns: 1fr;
            }

            .page-wrapper {
                margin-left: 0;
                width: 100%;
                padding-right: 20px;
            }

            .fab-save {
                right: 20px;
                bottom: 20px;
                padding: 12px 25px;
                font-size: 0.95rem;
            }

            .table-wrap {
                overflow-x: auto !important;
            }

            /* Allow scroll only on small screens if needed */
        }

        @media(max-width: 768px) {
            .page-wrapper {
                padding: 20px 10px 0 10px;
            }

            .custom-ticker-wrap {
                margin: -20px -10px 20px -10px;
            }

            .card-header {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                text-align: center;
            }

            .card-header button {
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
                    <span class="ticker-item"><i class="fas fa-circle" style="font-size:0.5rem; color:var(--success); margin-right:5px; vertical-align:middle;"></i> <?= htmlspecialchars($t_s['name']) ?>: <span style="color:#fbbf24;">₹<?= number_format($t_s['current_market_rate'], 2) ?></span></span>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; padding:15px; border-radius:8px; margin-bottom:20px; font-weight:700; font-size:0.95rem;">
                <i class="fas fa-check-circle" style="margin-right:8px;"></i> <?= $success_msg ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="top-grid">

                <div class="card">
                    <div class="card-header" style="padding: 12px 15px; background: #f8fafc; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 0.9rem; display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size:1.1rem; font-weight:800; color:var(--text-main);"><i class="fas fa-store text-warning" style="margin-right:8px;"></i> Market Rates</span>
                        <button type="submit" name="sync_machine_yield" class="btn btn-primary" style="padding:6px 12px; font-size:0.85rem;" onclick="return confirm('Sync yields with real-time machine data?')">
                            <i class="fas fa-sync" style="margin-right:5px;"></i> Sync Yields
                        </button>
                    </div>
                    <div class="table-wrap" style="border:none; border-radius:0; box-shadow:none;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="background: #f8fafc; padding: 10px 15px; text-align: left; font-size: 0.7rem; color: #64748b; text-transform: uppercase;">Commodity</th>
                                    <th style="background: #f8fafc; padding: 10px 15px; text-align: left; font-size: 0.7rem; color: #64748b; text-transform: uppercase;">Seed Rate</th>
                                    <th style="background: #f8fafc; padding: 10px 15px; text-align: left; font-size: 0.7rem; color: #64748b; text-transform: uppercase;">Cake Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($seeds as $s): ?>
                                    <tr>
                                        <input type="hidden" id="hidden_yield_<?= $s['id'] ?>" value="<?= floatval($s['avg_oil_recovery']) ?>">
                                        <td style="padding: 8px 15px; border-bottom: 1px solid #f1f5f9;">
                                            <div style="font-weight:800; font-size:1rem; color:var(--text-main);"><?= htmlspecialchars($s['name']) ?></div>
                                            <div style="margin-top:4px;"><?= getTrendHtml($s['current_market_rate'], $s['previous_market_rate']) ?></div>
                                        </td>
                                        <td style="padding: 8px 15px; border-bottom: 1px solid #f1f5f9;">
                                            <div class="input-controls">
                                                <button type="button" class="ctrl-btn" onclick="adjustVal('seed_<?= $s['id'] ?>', -1)">-</button>
                                                <input type="number" step="0.01" name="seed_rate[<?= $s['id'] ?>]" id="seed_<?= $s['id'] ?>" class="val-input" value="<?= number_format($s['current_market_rate'], 2, '.', '') ?>">
                                                <button type="button" class="ctrl-btn" onclick="adjustVal('seed_<?= $s['id'] ?>', 1)">+</button>
                                            </div>
                                        </td>
                                        <td style="padding: 8px 15px; border-bottom: 1px solid #f1f5f9;">
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
                </div>

                <div class="card">
                    <div class="card-header" style="padding: 12px 15px; background: #f8fafc; border-bottom: 1px solid var(--border); font-size:1.1rem; font-weight:800; color:var(--text-main);"><i class="fas fa-chart-line text-info" style="margin-right:8px;"></i> Historical Trends (₹/Kg)</div>
                    <div id="priceTrendChart" style="padding: 15px;"></div>
                </div>
            </div>

            <div class="bottom-grid">
                <div class="card">
                    <div class="card-header" style="padding: 12px 15px; background: #f8fafc; border-bottom: 1px solid var(--border); font-size:1.1rem; font-weight:800; color:var(--text-main);"><i class="fas fa-calculator text-success" style="margin-right:8px;"></i> Live Profit Analyzer</div>
                    <div style="padding: 20px; display: flex; gap: 24px; align-items: start; flex-wrap:wrap;">
                        <div style="flex: 1; min-width:250px;">
                            <label class="form-label" style="margin-bottom:8px;">SELECT PRODUCT TO TEST</label>
                            <select id="analyzer_product" class="form-input" onchange="loadAnalyzerData()" style="height: 45px; font-weight: 700; border: 2px solid var(--primary); font-size:1rem;">
                                <option value="">-- Choose Product --</option>
                                <?php foreach ($all_products as $ap) echo "<option value='{$ap['id']}'>{$ap['name']}</option>"; ?>
                            </select>
                        </div>
                        <div id="analyzer_results" style="flex: 2; min-width:300px; display:none; background:#f8fafc; border-radius:8px; padding:20px; border: 1px solid var(--border);">
                            <div id="an_yield_msg" style="text-align:center; font-size:0.85rem; font-weight:800; color:var(--success); margin-bottom:15px; text-transform:uppercase; letter-spacing:0.5px;"></div>
                            <table class="cost-table">
                                <tr>
                                    <td><span style="font-weight:600;">Seed Cost</span> <small id="an_seed_req" style="color:var(--text-muted); font-weight:700;"></small></td>
                                    <td class="val-td" id="an_seed_cost"></td>
                                </tr>
                                <tr>
                                    <td><span style="font-weight:600;">Processing Cost</span></td>
                                    <td class="val-td" id="an_proc_cost"></td>
                                </tr>
                                <tr>
                                    <td style="color:var(--danger); font-weight:600;">Cake Recovery</td>
                                    <td class="val-td" id="an_cake_val" style="color:var(--danger)"></td>
                                </tr>
                                <tr style="border-top:2px solid var(--border);">
                                    <td style="font-size:1.2rem; color:var(--primary); font-weight:900; padding-top:15px;">FINAL COST</td>
                                    <td class="val-td" id="an_final_cost" style="font-size:1.5rem; color:var(--primary); padding-top:15px;"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; justify-content: center; opacity: 0.4; flex-direction:column;">
                    <i class="fas fa-bolt fa-4x text-primary" style="margin-bottom:15px;"></i>
                    <p style="font-weight: 800; font-size:1.2rem; text-transform:uppercase; letter-spacing:1px; margin:0;">Global Sync Active</p>
                </div>
            </div>

            <button type="submit" name="update_prices" class="fab-save">
                <i class="fas fa-sync" style="margin-right:8px;"></i> EXECUTE MARKET TRADE
            </button>
        </form>
    </div>

    <script>
        var options = {
            series: <?= json_encode($chart_series) ?>,
            chart: {
                height: 380,
                type: 'line',
                fontFamily: 'Inter, sans-serif',
                toolbar: {
                    show: false
                }
            },
            stroke: {
                curve: 'smooth',
                width: 3
            },
            colors: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
            xaxis: {
                labels: {
                    show: false
                }
            },
            legend: {
                position: 'top',
                fontWeight: 700
            },
            tooltip: {
                theme: 'dark',
                y: {
                    formatter: v => "₹" + v.toFixed(2)
                }
            },
            grid: {
                borderColor: '#e2e8f0',
                strokeDashArray: 4
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
            if (!pid) {
                document.getElementById('analyzer_results').style.display = 'none';
                currentAnalyzer.active = false;
                return;
            }
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

            let calcWeight = currentAnalyzer.weight > 0 ? currentAnalyzer.weight : 1;
            if (masterYield <= 0) return;

            const seedNeeded = calcWeight / (masterYield / 100);
            const cakeGen = seedNeeded - calcWeight;
            const finalCost = (seedNeeded * seedRate) + (seedNeeded * currentAnalyzer.proc_cost) - (cakeGen * cakeRate) + currentAnalyzer.pack_cost;

            let weightWarning = currentAnalyzer.weight <= 0 ? " <span style='color:var(--danger); display:block; margin-top:5px;'>(Weight 0, Calc for 1 Unit)</span>" : "";

            document.getElementById('an_yield_msg').innerHTML = `<i class="fas fa-check-circle"></i> Syncing with Yield: ${masterYield}%` + weightWarning;
            document.getElementById('an_seed_req').innerText = `(${seedNeeded.toFixed(2)}Kg)`;
            document.getElementById('an_seed_cost').innerText = `₹${(seedNeeded * seedRate).toFixed(2)}`;
            document.getElementById('an_proc_cost').innerText = `₹${(seedNeeded * currentAnalyzer.proc_cost).toFixed(2)}`;
            document.getElementById('an_cake_val').innerText = `-₹${(cakeGen * cakeRate).toFixed(2)}`;
            document.getElementById('an_final_cost').innerText = `₹${finalCost.toFixed(2)}`;
        }
    </script>
</body>

</html>