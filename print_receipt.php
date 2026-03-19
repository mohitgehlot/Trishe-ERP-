<?php
// print_receipt.php
require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['orderData'])) {
    http_response_code(400);
    exit('Invalid data');
}

$orderData = $input['orderData'];
$invoiceNumber = 'INV-' . date('Ymd-') . time();

// Save to DB (optional)
$stmt = $conn->prepare("
    INSERT INTO orders (invoicenumber, customername, subtotal, tax, discount, total, paymentmethod, notes, createdat) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->bind_param('sssddsss', 
    $invoiceNumber, 
    $orderData['customer']['name'], 
    $orderData['totals']['subtotal'], 
    $orderData['totals']['tax'], 
    $orderData['totals']['discount'], 
    $orderData['totals']['grandTotal'], 
    $orderData['payment']['method'], 
    $orderData['notes']
);
$stmt->execute();
$orderId = $conn->insert_id;

// Generate receipt HTML for browser print
$receiptHtml = generateReceiptHTML($orderData, $invoiceNumber);
echo json_encode(['success' => true, 'receiptHtml' => $receiptHtml, 'orderId' => $orderId]);

function generateReceiptHTML($orderData, $invoiceNumber) {
    $gstRate = 5; // Ya DB se fetch karo
    $itemsHtml = '';
    
    foreach ($orderData['items'] as $item) {
        $priceWithGst = $item['price'];
        $priceWithoutGst = $priceWithGst * 100 / (100 + $gstRate);
        $itemGst = $priceWithGst - $priceWithoutGst;
        
        $itemsHtml .= "
        <div class='receipt-line'>
            <div style='flex:1;font-size:11px'>
                <div>" . htmlspecialchars($item['name']) . "</div>
                <div>" . $item['quantity'] . " x ₹" . number_format($priceWithoutGst, 2) . " + GST ₹" . number_format($itemGst * $item['quantity'], 2) . "</div>
            </div>
            <div>₹" . number_format($item['lineTotal'], 2) . "</div>
        </div>";
    }
    
    return "
    <div class='receipt-center'>
        <div style='font-weight:800;font-size:16px;margin-bottom:2px'>TRISHE AGRO FARM</div>
        <div style='font-size:12px'>Jaipur, Rajasthan</div>
        <div style='font-size:11px'>GSTIN: 08XXXXXXXXXX</div>
        <div style='font-size:11px'>Ph: 9876543210</div>
        <hr class='receipt-hr'/>
        <div style='font-weight:700;font-size:14px'>INVOICE: $invoiceNumber</div>
        <div style='font-size:11px'>" . date('d-m-Y H:i:s') . "</div>
        <div style='font-size:11px'>Customer: " . htmlspecialchars($orderData['customer']['name']) . "</div>
    </div>
    <hr class='receipt-hr'/>
    $itemsHtml
    <hr class='receipt-hr'/>
    <div class='receipt-line'><div>Subtotal (Excl GST)</div><div>₹" . number_format($orderData['totals']['subtotal'], 2) . "</div></div>
    <div class='receipt-line'><div>GST (5%)</div><div>₹" . number_format($orderData['totals']['tax'], 2) . "</div></div>
    <div class='receipt-line'><div>Discount</div><div>-₹" . number_format($orderData['totals']['discount'], 2) . "</div></div>
    <hr class='receipt-hr'/>
    <div class='receipt-line' style='font-weight:800;font-size:16px'>
        <div>Total Amount</div><div>₹" . number_format($orderData['totals']['grandTotal'], 2) . "</div>
    </div>
    <hr class='receipt-hr'/>
    <div class='receipt-line' style='font-size:12px'>
        <div>Paid: ₹" . number_format($orderData['payment']['amountPaid'], 2) . "</div>
        <div>Change: ₹" . number_format($orderData['payment']['changeGiven'], 2) . "</div>
    </div>
    <hr class='receipt-hr'/>
    <div class='receipt-center' style='font-size:10px;margin-top:10px'>
        <div>Payment: " . ucfirst($orderData['payment']['method']) . "</div>
        <div>Thank you for your business!</div>
    </div>";
}
?>
