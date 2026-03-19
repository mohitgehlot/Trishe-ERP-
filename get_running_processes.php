<?php
include 'config.php'; // Include database connection

// Handle "End Process" request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_no'])) {
    $batch_no = $_POST['batch_no'];

    // Update end_time in the database
    $query = "UPDATE processes SET end_time = NOW(), status = 'completed' WHERE batch_no = ?";
    $stmt = $mysqli->prepare($query);

    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $mysqli->error]);
        exit;
    }

    $stmt->bind_param('s', $batch_no);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => 'End time updated successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No rows updated']);
    }

    // Close statement
    $stmt->close();
    exit; // Stop further execution
}
// Fetch running processes (processes with no end_time)
$running_processes = [];
$stmt = $conn->prepare("SELECT * FROM raw_material_processing WHERE status = 'running' ORDER BY start_time DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $running_processes[] = $row;
}

if (empty($running_processes)) : ?>
    <p>No running processes.</p>
<?php else : ?>
    <?php foreach ($running_processes as $process) : ?>
        <?php $startTime = strtotime($process['start_time']); // Convert start time to Unix timestamp 
        ?>
        <div class="process-item"
            data-batch-no="<?php echo $process['batch_no']; ?>"
            data-raw-material-qty="<?php echo $process['raw_material_qty']; ?>"
            data-raw-material-name="<?php echo $process['raw_material_name']; ?>"
            data-start-time="<?php echo $startTime; ?>">
            <p><strong>Batch No:</strong> <?php echo $process['batch_no']; ?></p>
            <p><strong>Raw Material:</strong> <?php echo $process['raw_material_name']; ?></p>
            <p><strong>Quantity:</strong> <?php echo $process['raw_material_qty']; ?> kg</p>
            <p><strong>Start Time:</strong> <?php echo $process['start_time']; ?></p>
            <p><strong>Processing Time:</strong> <span class="timer">00:00:00</span></p>
            <!-- End Process Button -->
            <button class="end-process-btn" data-batch-no="<?php echo $process['batch_no']; ?>">End Process</button>

            <!-- Input Fields (Hidden Initially) -->
            <div class="input-fields" style="display: none;">
                <label for="product_weight_<?php echo $process['batch_no']; ?>">Product Weight (kg):</label>
                <input type="number" id="product_weight_<?php echo $process['batch_no']; ?>" class="product-weight" placeholder="Enter product weight">

                <label for="waste_weight_<?php echo $process['batch_no']; ?>">Waste Weight (kg):</label>
                <input type="number" id="waste_weight_<?php echo $process['batch_no']; ?>" class="waste-weight" placeholder="Enter waste weight" readonly>

                <button class="save-process-btn" data-batch-no="<?php echo $process['batch_no']; ?>">Save Process</button>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>