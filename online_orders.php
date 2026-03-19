<?php
require 'vendor/autoload.php';
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'] ?? 1; // Fallback admin ID
$errors = [];
$success_msg = "";

// Ensure uploads directories exist
if (!is_dir('uploads/labels')) {
    mkdir('uploads/labels', 0777, true);
}

// -------------------------------------------------------------
// STEP 1: UPLOAD, PARSE PDF & SHOW PREVIEW
// -------------------------------------------------------------
if (isset($_FILES['bulk_pdf']) && $_FILES['bulk_pdf']['error'] === UPLOAD_ERR_OK) {
    $temp_path = 'uploads/temp_bulk_' . time() . '.pdf';
    move_uploaded_file($_FILES['bulk_pdf']['tmp_name'], $temp_path);

    $parser = new \Smalot\PdfParser\Parser();
    try {
        $pdf = $parser->parseFile($temp_path);
        $pages = $pdf->getPages();

        $preview_data = [];

        foreach ($pages as $index => $page) {
            $text = $page->getText();
            $page_num = $index + 1;

            $oid = '';
            $sku = '';
            $cname = 'Unknown Customer';
            $caddress = '';
            $tot = 0;
            $ino = 'N/A';
            $tno = 'N/A';
            $order_date = date('Y-m-d');

            // 1. ORDER NO & SKU EXTRACTION
            if (preg_match('/"([^"]+)"\s*,\s*"[^"]*"\s*,\s*"\d+"\s*,\s*"[^"]*"\s*,\s*"(\d{10,})\s*(\d*)"/', $text, $table_match)) {
                $sku = trim($table_match[1]);
                $oid = trim($table_match[2]);
                if (!empty($table_match[3])) {
                    $oid .= '_' . trim($table_match[3]);
                }
            } else {
                preg_match('/(?:Purchase Order No\.|Order No\.)[^\d]*(\d{10,})/i', $text, $oid_match);
                $oid = $oid_match[1] ?? '';
                preg_match('/SKU.*?[\r\n]+.*?([a-zA-Z0-9_-]{5,15})/s', $text, $sku_match);
                $sku = trim($sku_match[1] ?? '');
            }

            // 2. CUSTOMER NAME & ADDRESS EXTRACTION
            if (preg_match('/Customer Address["\s]*(.*?)["\s]*If undelivered/is', $text, $cust_block)) {
                $clean_text = str_replace('"', '', $cust_block[1]);
                $lines = preg_split('/[\r\n]+/', trim($clean_text));
                $lines = array_filter(array_map('trim', $lines));
                $lines = array_values($lines);
                if (count($lines) > 0) {
                    $cname = $lines[0];
                    array_shift($lines);
                    $caddress = implode(', ', $lines);
                }
            }

            // 3. TOTAL AMOUNT EXTRACTION
            if (preg_match('/"Total"(.*?)(?=Sold by)/is', $text, $total_block)) {
                if (preg_match_all('/Rs\.?\s*([0-9\.]+)/i', $total_block[1], $amounts)) {
                    $tot = end($amounts[1]);
                }
            }
            if (empty($tot)) {
                if (preg_match_all('/Rs\.?\s*([0-9\.]+)/i', $text, $all_amounts)) {
                    $tot_candidates = array_slice($all_amounts[1], -3);
                    $tot = max($tot_candidates);
                } else {
                    $tot = 0;
                }
            }

            // 4. ONLINE ORDER DETAILS (Invoice, Tracking, Date)
            preg_match('/Invoice No\.[\r\n\s]*([a-zA-Z0-9]+)/i', $text, $invoice_match);
            $ino = trim($invoice_match[1] ?? 'N/A');

            preg_match('/(\d{13,16})/', $text, $tracking_match);
            $tno = trim($tracking_match[1] ?? 'N/A');

            preg_match('/Order Date[\r\n\s]*(\d{2}\.\d{2}\.\d{4})/i', $text, $date_match);
            if (!empty($date_match[1])) {
                $order_date = date('Y-m-d', strtotime(str_replace('.', '-', $date_match[1])));
            }

            // 5. PAYMENT STATUS & METHOD
            $is_prepaid = (stripos($text, 'Do not collect cash') !== false);
            preg_match('/Delhivery|BlueDart|Ecom Express|Xpressbees|Shadowfax/i', $text, $courier_match);
            $courier = $courier_match[0] ?? 'Other';

            if (!empty($oid)) {
                $check = mysqli_query($conn, "SELECT id FROM orders WHERE order_no = '$oid'");
                if (mysqli_num_rows($check) > 0) {
                    $errors[] = "Order #$oid pehle se database mein hai (Skipped).";
                    continue;
                }

                $preview_data[] = [
                    'page_num' => $page_num,
                    'order_no' => $oid,
                    'customer_name' => $cname,
                    'address' => $caddress,
                    'sku' => $sku,
                    'total' => $tot,
                    'payment_status' => $is_prepaid ? 'Paid' : 'Pending',
                    'payment_method' => $is_prepaid ? 'upi' : 'cash',
                    'courier' => $courier,
                    'invoice_no' => $ino,
                    'tracking_no' => $tno,
                    'order_date' => $order_date
                ];
            }
        }

        if (empty($preview_data)) {
            $errors[] = "Koi naya order nahi mila ya pattern match nahi hua.";
        } else {
            $_SESSION['bulk_preview'] = $preview_data;
            $_SESSION['temp_pdf_path'] = $temp_path;
        }
    } catch (Exception $e) {
        $errors[] = "Failed to process PDF: " . $e->getMessage();
    }
}

// -------------------------------------------------------------
// STEP 2: SAVE TO ALL 4 TABLES & MOVE PDF
// -------------------------------------------------------------
if (isset($_POST['confirm_save']) && isset($_SESSION['bulk_preview'])) {
    $saved_count = 0;
    $posted_orders = $_POST['orders'];

    // Move Temporary PDF to Permanent Folder
    $temp_pdf = $_SESSION['temp_pdf_path'];
    $permanent_pdf = 'uploads/labels/bulk_labels_' . time() . '.pdf';
    $pdf_moved = false;

    if (file_exists($temp_pdf)) {
        if (rename($temp_pdf, $permanent_pdf)) {
            $pdf_moved = true; // File successfully move ho gayi
        }
    }

    foreach ($posted_orders as $data) {
        $order_no = mysqli_real_escape_string($conn, $data['order_no']);
        $cname = mysqli_real_escape_string($conn, $data['customer_name']);
        $caddress = mysqli_real_escape_string($conn, $data['address']);
        $sku = mysqli_real_escape_string($conn, $data['sku']);
        $total = mysqli_real_escape_string($conn, $data['total']);
        $p_status = mysqli_real_escape_string($conn, $data['payment_status']);
        $p_method = mysqli_real_escape_string($conn, $data['payment_method']);

        // Online order specific data
        $invoice_no = mysqli_real_escape_string($conn, $data['invoice_no']);
        $tracking_no = mysqli_real_escape_string($conn, $data['tracking_no']);
        $courier = mysqli_real_escape_string($conn, $data['courier']);
        $order_date = mysqli_real_escape_string($conn, $data['order_date']);

        // Exact PDF Path with Page Number (e.g., uploads/labels/bulk_labels_123.pdf#page=2)
        $page_num = mysqli_real_escape_string($conn, $data['page_num']);
        $exact_pdf_path = $pdf_moved ? ($permanent_pdf . '#page=' . $page_num) : '';

        // 1. CUSTOMER LOGIC
        $cust_check = mysqli_query($conn, "SELECT id FROM customers WHERE name = '$cname'");
        if (mysqli_num_rows($cust_check) > 0) {
            $customer_id = mysqli_fetch_assoc($cust_check)['id'];
            mysqli_query($conn, "UPDATE customers SET address = '$caddress' WHERE id = '$customer_id' AND (address IS NULL OR address = '')");
        } else {
            mysqli_query($conn, "INSERT INTO customers (name, address, status, total_due, empty_tins) VALUES ('$cname', '$caddress', 'active', 0, 0)");
            $customer_id = mysqli_insert_id($conn);
        }

        // 2. ORDER LOGIC
        $ins_order = "INSERT INTO orders (customer_id, order_no, subtotal, tax, discount, total, payment_method, status, payment_status, paid_amount, created_by) 
                      VALUES ('$customer_id', '$order_no', '$total', 0, 0, '$total', '$p_method', 'Pending', '$p_status', " . ($p_status == 'Paid' ? $total : 0) . ", '$admin_id')";

        if (mysqli_query($conn, $ins_order)) {
            $db_order_id = mysqli_insert_id($conn);
            $saved_count++;

            // 3. PRODUCT & ORDER ITEMS LOGIC
            $product_id = 0;
            $cost_price = 0;
            $prod_name = "Meesho Item - $sku";
            $prod_check = mysqli_query($conn, "SELECT id, name, cost_price FROM products WHERE description = '$sku'");

            if (mysqli_num_rows($prod_check) > 0) {
                $p_row = mysqli_fetch_assoc($prod_check);
                $product_id = $p_row['id'];
                $prod_name = mysqli_real_escape_string($conn, $p_row['name']);
                $cost_price = $p_row['cost_price'] ?? 0;
            } else {
                $auto_prod_query = "INSERT INTO products (name, description, base_price, is_active) VALUES ('$prod_name', '$sku', '$total', 1)";
                if (mysqli_query($conn, $auto_prod_query)) {
                    $product_id = mysqli_insert_id($conn);
                } else {
                    $product_id = 1;
                }
            }

            $ins_item = "INSERT INTO order_items (order_id, product_id, name_snapshot, price_snapshot, qty, cost_price, line_total, batch_no, unit) 
                         VALUES ('$db_order_id', '$product_id', '$prod_name', '$total', 1, '$cost_price', '$total', 'SALE', '0')";
            mysqli_query($conn, $ins_item);

            // 4. ONLINE ORDERS LOGIC (Now includes label_pdf_path)
            $ins_online = "INSERT INTO online_orders (order_id, invoice_no, tracking_no, courier_company, order_date, label_pdf_path) 
                           VALUES ('$order_no', '$invoice_no', '$tracking_no', '$courier', '$order_date', '$exact_pdf_path')";
            mysqli_query($conn, $ins_online);
        }
    }

    $success_msg = "$saved_count naye orders successfully database mein save ho gaye! (PDFs bhi save ho gayi)";

    // Cleanup temporary file only if it wasn't moved (just as a failsafe)
    if (!$pdf_moved && file_exists($temp_pdf)) {
        unlink($temp_pdf);
    }
    unset($_SESSION['bulk_preview']);
    unset($_SESSION['temp_pdf_path']);
}

// Cancel Action
if (isset($_POST['cancel_preview'])) {
    if (file_exists($_SESSION['temp_pdf_path'])) {
        unlink($_SESSION['temp_pdf_path']);
    }
    unset($_SESSION['bulk_preview']);
    unset($_SESSION['temp_pdf_path']);
    header("Location: online_orders.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Bulk Order Upload | Trishe ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --border: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
            --radius: 12px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            padding-left: 260px;
            padding-bottom: 60px;
        }

        .container {
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            background: var(--card);
            padding: 15px 20px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            color: #fff;
        }

        .btn-primary {
            background: var(--primary);
        }

        .btn-success {
            background: var(--success);
        }

        .btn-danger {
            background: var(--danger);
        }

        .preview-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            background: var(--card);
            padding: 15px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .preview-left {
            flex: 1;
            border-right: 2px dashed var(--border);
            padding-right: 15px;
        }

        .preview-right {
            flex: 1;
            padding-left: 15px;
        }

        .form-group {
            margin-bottom: 12px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: bold;
            color: #64748b;
            margin-bottom: 4px;
        }

        .form-input {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-family: inherit;
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header">
            <h2 style="font-size:1.3rem; margin:0;"><i class="fas fa-file-pdf"></i> Smart Label Upload</h2>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $err) echo "<div><i class='fas fa-exclamation-circle'></i> $err</div>"; ?>
            </div>
        <?php endif; ?>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success_msg ?></div>
        <?php endif; ?>

        <?php if (!isset($_SESSION['bulk_preview'])): ?>
            <div style="background:#fff; padding:40px; text-align:center; border-radius:12px; border:2px dashed var(--border);">
                <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: var(--primary); margin-bottom: 15px;"></i>
                <h3>Upload Meesho Labels (PDF)</h3>
                <form method="POST" enctype="multipart/form-data" style="margin-top:20px;">
                    <input type="file" name="bulk_pdf" accept=".pdf" required style="margin-bottom:15px;"><br>
                    <button type="submit" class="btn btn-primary">Scan & Preview</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['bulk_preview'])): ?>
            <div class="alert" style="background: #fff3cd; color: #856404; font-weight:bold;">
                <i class="fas fa-eye"></i> Details verify karein. Data save karte hi ye PDF automatically database me attach ho jayegi.
            </div>

            <form method="POST">
                <?php foreach ($_SESSION['bulk_preview'] as $i => $row): ?>
                    <div class="preview-row">
                        <div class="preview-left">
                            <iframe src="preview_pdf.php#page=<?= $row['page_num'] ?>&view=FitH"
                                width="100%"
                                height="350px"
                                style="border:1px solid #ccc; border-radius:8px;">
                            </iframe>
                        </div>

                        <div class="preview-right">

                            <input type="hidden" name="orders[<?= $i ?>][page_num]" value="<?= htmlspecialchars($row['page_num']) ?>">
                            <input type="hidden" name="orders[<?= $i ?>][invoice_no]" value="<?= htmlspecialchars($row['invoice_no']) ?>">
                            <input type="hidden" name="orders[<?= $i ?>][tracking_no]" value="<?= htmlspecialchars($row['tracking_no']) ?>">
                            <input type="hidden" name="orders[<?= $i ?>][order_date]" value="<?= htmlspecialchars($row['order_date']) ?>">
                            <input type="hidden" name="orders[<?= $i ?>][courier]" value="<?= htmlspecialchars($row['courier']) ?>">

                            <div style="display:flex; gap:10px;">
                                <div class="form-group" style="flex:1;">
                                    <label>Order No.</label>
                                    <input type="text" name="orders[<?= $i ?>][order_no]" value="<?= htmlspecialchars($row['order_no']) ?>" class="form-input" readonly>
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label>Total Amount (Rs)</label>
                                    <input type="text" name="orders[<?= $i ?>][total]" value="<?= htmlspecialchars($row['total']) ?>" class="form-input">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Customer Name</label>
                                <input type="text" name="orders[<?= $i ?>][customer_name]" value="<?= htmlspecialchars($row['customer_name']) ?>" class="form-input">
                            </div>

                            <div class="form-group">
                                <label>Delivery Address</label>
                                <textarea name="orders[<?= $i ?>][address]" class="form-input" rows="2"><?= htmlspecialchars($row['address']) ?></textarea>
                            </div>

                            <div class="form-group">
                                <label>SKU (Matched with Product Description)</label>
                                <input type="text" name="orders[<?= $i ?>][sku]" value="<?= htmlspecialchars($row['sku']) ?>" class="form-input" style="border-color:var(--primary); background:#f0fdf4;">
                            </div>

                            <div style="display:flex; gap:10px;">
                                <div class="form-group" style="flex:1;">
                                    <label>Payment Method</label>
                                    <input type="text" name="orders[<?= $i ?>][payment_method]" value="<?= htmlspecialchars($row['payment_method']) ?>" class="form-input">
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label>Status</label>
                                    <input type="text" name="orders[<?= $i ?>][payment_status]" value="<?= htmlspecialchars($row['payment_status']) ?>" class="form-input">
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="position: sticky; bottom: 20px; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 -4px 10px rgba(0,0,0,0.1); display:flex; justify-content:flex-end; gap:15px;">
                    <button type="submit" name="cancel_preview" class="btn btn-danger"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" name="confirm_save" class="btn btn-success"><i class="fas fa-check-double"></i> Confirm & Save All</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

</body>

</html>