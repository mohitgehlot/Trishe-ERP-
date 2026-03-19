<?php
// print_engine.php - UNIVERSAL RENDERER (Supports Job Work, POS Invoices & GRN Stickers)
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized Access");
}

$doc_type = $_GET['doc'] ?? 'job_sticker';
$ref_id = $conn->real_escape_string($_GET['id'] ?? '');

// =========================================================================
// 🌟 NAYA BLOCK: BATCH STICKER PRINTING (Template DB se nahi uthayega) 🌟
// =========================================================================
if ($doc_type === 'grn_all_stickers') {
    $grn_id = intval($_GET['grn_id']);

    // 🌟 FIX: SQL mein 'igi.id as item_id' add kiya gaya hai 🌟
    $sql = "SELECT ig.grn_no, DATE(ig.created_at) as grn_date, ig.id as grn_id_num, 
            igi.id as item_id, igi.bags, igi.weight_kg, sm.name as seed_name 
            FROM inventory_grn_items igi 
            JOIN inventory_grn ig ON igi.grn_id = ig.id 
            JOIN seeds_master sm ON igi.seed_id = sm.id 
            WHERE ig.id = $grn_id";

    $res = $conn->query($sql);

    if (!$res || $res->num_rows == 0) {
        die("<h3 style='font-family:sans-serif; padding:20px;'>No items found for this GRN.</h3>");
    }
?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>GRN Stickers - Batch Print</title>
        <style>
            /* Thermal Sticker Size: 50mm x 25mm */
            @media print {
                @page {
                    margin: 0;
                    size: 50mm 25mm;
                }

                body {
                    margin: 0;
                    padding: 0;
                }

                .no-print {
                    display: none;
                }
            }

            body {
                font-family: 'Helvetica', Arial, sans-serif;
                background: #fff;
                margin: 0;
            }

            .sticker-container {
                width: 48mm;
                height: 23mm;
                padding: 1mm;
                box-sizing: border-box;
                border: 1px dashed #ccc;
                /* Print me border nahi dikhega */
                page-break-after: always;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                text-align: center;
                overflow: hidden;
            }

            .brand {
                font-size: 8px;
                font-weight: bold;
                text-transform: uppercase;
                color: #555;
            }

            .item-name {
                font-size: 12px;
                font-weight: 900;
                margin: 1px 0;
                border-top: 1px solid #000;
                border-bottom: 1px solid #000;
                width: 100%;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .details {
                font-size: 9px;
                font-weight: bold;
                margin-top: 1px;
            }

            .barcode-text {
                font-size: 10px;
                margin-top: 1px;
                font-weight: bold;
                letter-spacing: 0.5px;
            }
        </style>
    </head>

    <body onload="window.print(); setTimeout(() => window.close(), 500);">

        <div class="no-print" style="padding:15px; background:#f3f4f6; text-align:center; margin-bottom:20px; border-bottom:1px solid #ddd;">
            <h3 style="margin:0;">🖨️ Generating Batch Stickers...</h3>
            <p style="margin:5px 0 0; font-size:12px; color:#666;">Please make sure your thermal printer is set to 50x25mm label size.</p>
        </div>

        <?php
        // Har item ke liye loop
        while ($row = $res->fetch_assoc()):
            $item_name = $row['seed_name'];
            $bags = max(1, intval($row['bags'])); // Kam se kam 1 sticker niklega
            $total_wt = floatval($row['weight_kg']);
            $wt_per_bag = round($total_wt / $bags, 2); // Ek bag ka average wazan
            $grn_no = $row['grn_no'];
            $date = date('d/m/Y', strtotime($row['grn_date']));

            // Har bag ke liye ek sticker print
            for ($i = 1; $i <= $bags; $i++):
        ?>
                <div class="sticker-container">
                    <div class="brand">TRISHE AGRO - ITEM #<?= $row['item_id'] ?></div>
                    <div class="item-name"><?= strtoupper($item_name) ?></div>
                    <div class="details">Bag: <?= $i ?>/<?= $bags ?> | Wt: <?= $wt_per_bag ?> Kg</div>
                    <div class="barcode-text">*<?= $grn_no ?>*</div>
                </div>
        <?php
            endfor;
        endwhile;
        ?>

    </body>

    </html>
<?php
    exit; // Yahan processing stop karni hai
}


// =========================================================================
// STANDARD TEMPLATE PRINTING (Purana Code Niche Hai)
// =========================================================================

// 1. Get Template Layout from Database
$t_res = $conn->query("SELECT * FROM print_templates WHERE doc_type = '$doc_type' ORDER BY id DESC LIMIT 1");
if (!$t_res || $t_res->num_rows == 0) {
    die("<h3 style='font-family:sans-serif; padding:20px;'>Template for '$doc_type' not created yet.</h3>");
}
$template = $t_res->fetch_assoc();
$layout = json_decode($template['layout_data'], true);

$data = [];

// A. JOB WORK STICKER DATA
if ($doc_type == 'job_sticker') {
    $q = $conn->query("SELECT s.*, c.name as customer_name FROM service_orders s LEFT JOIN customers c ON s.user_id = c.id WHERE s.id = '$ref_id'");
    if ($r = $q->fetch_assoc()) {
        $data = [
            '[JOB_ID]' => $r['id'],
            '[CUSTOMER_NAME]' => htmlspecialchars($r['customer_name'] ?? 'Walk-in'),
            '[SEED]' => htmlspecialchars($r['seed_type']),
            '[WEIGHT]' => number_format($r['weight_kg'], 2),
            '[TOTAL]' => number_format($r['total_amount'], 2),
            '[DATE]' => date('d/m/y H:i', strtotime($r['service_date'])),
            '[TRISHE AGRO]' => 'TRISHE AGRO'
        ];
    }
}
// B. POS INVOICE / BILL DATA 
elseif ($doc_type == 'pos_invoice') {
    $order_query = $conn->query("SELECT * FROM orders WHERE order_no = '$ref_id' OR id = '$ref_id' LIMIT 1");

    if ($order_query && $order_query->num_rows > 0) {
        $order = $order_query->fetch_assoc();
        $numeric_id = $order['id'];

        $item_list_text = "";
        $items_query = $conn->query("SELECT * FROM order_items WHERE order_id = '$numeric_id'");

        while ($item = $items_query->fetch_assoc()) {
            $product_id = $item['product_id'];
            $prod_q = $conn->query("SELECT name FROM products WHERE id = '$product_id' LIMIT 1");
            $prod_data = $prod_q->fetch_assoc();

            $i_name  = $prod_data['name'] ?? $item['name_snapshot'] ?? 'Unknown Item';
            $i_qty   = $item['qty'] ?? 1;
            $i_price = $item['price_snapshot'] ?? 0;
            $i_amt   = $item['line_total'] ?? ($i_qty * $i_price);

            $display_name = mb_strimwidth(trim($i_name), 0, 15, "..");
            $display_name = str_pad($display_name, 16, " ");
            $qty_str   = str_pad($i_qty, 3, " ", STR_PAD_LEFT);
            $price_str = str_pad(number_format($i_price, 0), 6, " ", STR_PAD_LEFT);
            $amt_str   = str_pad(number_format($i_amt, 0), 7, " ", STR_PAD_LEFT);

            $item_list_text .= "$display_name$qty_str$price_str$amt_str\n";
        }

        $data = [
            '[TRISHE AGRO]' => 'TRISHE AGRO',
            '[DATE]' => date('d-M-Y', strtotime($order['created_at'])),
            '[TIME]' => date('h:i A', strtotime($order['created_at'])),
            '[BILL_NO]' => $order['order_no'],
            '[CUST_NAME]' => !empty($order['customer_name']) ? htmlspecialchars($order['customer_name']) : 'Walk-in',
            '[CUST_PHONE]' => !empty($order['customer_phone']) ? htmlspecialchars($order['customer_phone']) : '',
            '[SUBTOTAL]' => number_format($order['subtotal'] ?? 0, 2),
            '[DISCOUNT]' => number_format($order['discount'] ?? 0, 2),
            '[GRAND_TOTAL]' => number_format($order['total'] ?? 0, 2),
            '[ITEM_LIST]' => rtrim($item_list_text)
        ];
    } else {
        die("Order No: $ref_id not found in orders table.");
    }
}
// C. GRN RECEIPT DATA 
elseif ($doc_type == 'grn_receipt') {
    $grn_query = $conn->query("SELECT ig.*, s.name as seller_name FROM inventory_grn ig LEFT JOIN sellers s ON ig.seller_id = s.id WHERE ig.id = '$ref_id' LIMIT 1");
    $grn = $grn_query->fetch_assoc();

    $items_query = $conn->query("SELECT igi.*, sm.name as seed_name FROM inventory_grn_items igi JOIN seeds_master sm ON igi.seed_id = sm.id WHERE igi.grn_id = '$ref_id'");

    $item_list_text = "";
    while ($item = $items_query->fetch_assoc()) {
        $name = mb_strimwidth($item['seed_name'], 0, 14, "..");
        $name = str_pad($name, 14, " ");
        $qty = str_pad(number_format($item['weight_kg'], 0), 5, " ", STR_PAD_LEFT);
        $rate = str_pad(number_format($item['price_per_qtl'], 0), 6, " ", STR_PAD_LEFT);
        $total = str_pad(number_format($item['line_value'], 0), 7, " ", STR_PAD_LEFT);
        $item_list_text .= "$name $qty $rate $total\n";
    }

    $data = [
        '[TRISHE AGRO]'    => 'TRISHE AGRO',
        '[GRN_NO]'         => $grn['grn_no'],
        '[SUPPLIER_NAME]'  => $grn['seller_name'],
        '[VEHICLE_NO]'     => $grn['vehicle_no'],
        '[DATE]'           => date('d/m/y', strtotime($grn['created_at'])),
        '[TIME]'           => date('h:i A', strtotime($grn['created_at'])),
        '[ITEM_LIST]'      => rtrim($item_list_text),
        '[GRAND_TOTAL]'    => number_format($grn['total_value'], 2)
    ];
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Print</title>
    <style>
        @page {
            margin: 0;
            size: <?= $template['width_mm'] ?>mm <?= $template['height_mm'] == 0 ? 'auto' : $template['height_mm'] . 'mm' ?>;
        }

        body {
            margin: 0;
            padding: 0;
            width: <?= $template['width_mm'] ?>mm;
            height: <?= $template['height_mm'] == 0 ? 'auto' : $template['height_mm'] . 'mm' ?>;
            position: relative;
            font-family: monospace;
            color: #000;
            overflow: hidden;
            box-sizing: border-box;
        }

        .print-element {
            position: absolute;
            white-space: pre-wrap;
            line-height: 1.2;
            box-sizing: border-box;
        }
    </style>
</head>

<body onload="window.print(); setTimeout(() => window.close(), 500);">
    <?php
    if (is_array($layout)) {
        foreach ($layout as $item) {
            $text = str_replace(array_keys($data), array_values($data), $item['text']);
            echo "<div class='print-element' style='left: {$item['left']}; top: {$item['top']}; font-size: {$item['fontSize']}; font-weight: {$item['fontWeight']}; width: " . ($item['width'] ?? 'auto') . "; text-align: " . ($item['textAlign'] ?? 'left') . ";'>{$text}</div>";
        }
    }
    ?>
</body>

</html>