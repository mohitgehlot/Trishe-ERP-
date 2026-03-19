<?php
include 'config.php';
session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_all_mrp'])) {
    $oil_id = (int)$_POST['oil_id'];
    $new_base_price = (float)$_POST['new_base_price'];
    $profit_margin = (float)$_POST['profit_margin'];
    $packing_costs = json_decode($_POST['packing_costs'], true);
    
    try {
        $conn->begin_transaction();
        
        // Update base product price
        $update_base = $conn->prepare("UPDATE products SET base_price = ? WHERE id = ?");
        $update_base->bind_param('di', $new_base_price, $oil_id);
        $update_base->execute();
        
        // Get all packing products for this oil
        $get_packing = $conn->prepare("SELECT * FROM packing_products WHERE base_product_id = ?");
        $get_packing->bind_param('i', $oil_id);
        $get_packing->execute();
        $packing_products = $get_packing->get_result();
        
        $updated_count = 0;
        while ($product = $packing_products->fetch_assoc()) {
            $size_key = $product['packing_size'] . ' ' . $product['packing_unit'];
            $packing_cost = $packing_costs[$size_key] ?? getPackagingCost($product['packing_size'], $product['packing_unit']);
            
            // Calculate new MRP
            $size_multiplier = $product['packing_unit'] === 'LITER' ? 
                (float)$product['packing_size'] * 0.92 : (float)$product['packing_size'];
            $cost_price = ($new_base_price * $size_multiplier) + $packing_cost;
            $new_mrp = $cost_price * (1 + ($profit_margin / 100));
            
            // Update packing product MRP
            $update_mrp = $conn->prepare("UPDATE packing_products SET mrp = ? WHERE id = ?");
            $update_mrp->bind_param('di', $new_mrp, $product['id']);
            $update_mrp->execute();
            $updated_count++;
        }
        
        $conn->commit();
        $response = [
            'success' => true,
            'message' => "Successfully updated base price and $updated_count packing product MRPs"
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Error: ' . $e->getMessage();
    }
}

echo json_encode($response);

function getPackagingCost($size, $unit) {
    $costs = [
        '1 LITER' => 10,
        '2 LITER' => 15,
        '5 LITER' => 30, 
        '15 LITER' => 100,
        '15 KG' => 125
    ];
    return $costs["$size $unit"] ?? 20;
}
?>