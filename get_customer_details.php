<?php
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    exit('Unauthorized');
}

$customer_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Fetch customer details with all related data
$customer = $conn->query("
    SELECT 
        c.*,
        cg.name as group_name,
        cg.discount_percent,
        cl.points,
        cl.tier,
        cl.joined_date,
        cl.referral_code,
        cl.total_referrals
    FROM customers c
    LEFT JOIN customer_groups cg ON c.group_id = cg.id
    LEFT JOIN customer_loyalty cl ON c.id = cl.customer_id
    WHERE c.id = $customer_id
")->fetch_assoc();

// Fetch recent orders
$orders = $conn->query("
    SELECT * FROM orders 
    WHERE customer_id = $customer_id 
    ORDER BY created_at DESC 
    LIMIT 10
");

// Fetch activity log
$activities = $conn->query("
    SELECT * FROM customer_activity 
    WHERE customer_id = $customer_id 
    ORDER BY created_at DESC 
    LIMIT 20
");

// Fetch notes
$notes = $conn->query("
    SELECT cn.*, a.username as added_by_name 
    FROM customer_notes cn
    LEFT JOIN admins a ON cn.added_by = a.id
    WHERE cn.customer_id = $customer_id 
    ORDER BY cn.created_at DESC
");

// Fetch addresses
$addresses = $conn->query("
    SELECT * FROM customer_addresses 
    WHERE customer_id = $customer_id 
    ORDER BY is_default DESC
");
?>

<div class="customer-details">
    <!-- Header -->
    <div class="details-header">
        <div class="customer-name">
            <h3><?= htmlspecialchars($customer['name']) ?></h3>
            <span class="customer-status status-<?= $customer['status'] ?>">
                <?= ucfirst($customer['status']) ?>
            </span>
        </div>
        <div class="customer-id">ID: #<?= $customer['id'] ?></div>
    </div>

    <!-- Quick Info Grid -->
    <div class="info-grid">
        <div class="info-card">
            <div class="info-label">Contact</div>
            <div class="info-value">
                <div><i class="fas fa-phone"></i> <?= $customer['phone'] ?></div>
                <div><i class="fas fa-envelope"></i> <?= $customer['email'] ?: 'N/A' ?></div>
            </div>
        </div>
        
        <div class="info-card">
            <div class="info-label">Loyalty Program</div>
            <div class="info-value">
                <div><span class="tier-badge tier-<?= strtolower($customer['tier']) ?>"><?= $customer['tier'] ?></span></div>
                <div>Points: <?= number_format($customer['points']) ?></div>
                <div>Referrals: <?= $customer['total_referrals'] ?></div>
            </div>
        </div>
        
        <div class="info-card">
            <div class="info-label">Group & Discount</div>
            <div class="info-value">
                <div><?= $customer['group_name'] ?: 'No Group' ?></div>
                <div>Discount: <?= $customer['discount_percent'] ?>%</div>
            </div>
        </div>
        
        <div class="info-card">
            <div class="info-label">Important Dates</div>
            <div class="info-value">
                <div>Registered: <?= date('d M Y', strtotime($customer['created_at'])) ?></div>
                <div>DOB: <?= $customer['dob'] ? date('d M', strtotime($customer['dob'])) : 'N/A' ?></div>
                <div>Anniversary: <?= $customer['anniversary'] ? date('d M', strtotime($customer['anniversary'])) : 'N/A' ?></div>
            </div>
        </div>
    </div>

    <!-- Addresses -->
    <?php if ($addresses->num_rows > 0): ?>
    <div class="details-section">
        <h4>Saved Addresses</h4>
        <div class="addresses-grid">
            <?php while($address = $addresses->fetch_assoc()): ?>
            <div class="address-card <?= $address['is_default'] ? 'default' : '' ?>">
                <div class="address-type"><?= ucfirst($address['address_type']) ?></div>
                <div class="address-details">
                    <?= nl2br(htmlspecialchars($address['address'])) ?><br>
                    <?= $address['city'] ?>, <?= $address['state'] ?> - <?= $address['pincode'] ?>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Orders -->
    <?php if ($orders->num_rows > 0): ?>
    <div class="details-section">
        <h4>Recent Orders</h4>
        <table class="mini-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Payment</th>
                </tr>
            </thead>
            <tbody>
                <?php while($order = $orders->fetch_assoc()): ?>
                <tr>
                    <td>#<?= $order['id'] ?></td>
                    <td><?= date('d M Y', strtotime($order['created_at'])) ?></td>
                    <td>₹<?= number_format($order['total'], 2) ?></td>
                    <td><span class="status-<?= $order['status'] ?>"><?= $order['status'] ?></span></td>
                    <td><?= $order['payment_method'] ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Activity Timeline -->
    <?php if ($activities->num_rows > 0): ?>
    <div class="details-section">
        <h4>Recent Activity</h4>
        <div class="activity-timeline">
            <?php while($activity = $activities->fetch_assoc()): ?>
            <div class="timeline-item">
                <div class="timeline-time"><?= timeAgo($activity['created_at']) ?></div>
                <div class="timeline-content">
                    <div class="timeline-action"><?= $activity['action'] ?></div>
                    <div class="timeline-details"><?= htmlspecialchars($activity['details']) ?></div>
                    <div class="timeline-ip">IP: <?= $activity['ip_address'] ?></div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Notes -->
    <?php if ($notes->num_rows > 0): ?>
    <div class="details-section">
        <h4>Staff Notes</h4>
        <?php while($note = $notes->fetch_assoc()): ?>
        <div class="note-item">
            <div class="note-header">
                <span class="note-author"><?= htmlspecialchars($note['added_by_name']) ?></span>
                <span class="note-date"><?= date('d M Y H:i', strtotime($note['created_at'])) ?></span>
            </div>
            <div class="note-content"><?= nl2br(htmlspecialchars($note['note'])) ?></div>
        </div>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.customer-details { padding: 20px; }
.details-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.customer-name { display: flex; align-items: center; gap: 10px; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 30px; }
.info-card { background: rgba(255,255,255,0.03); padding: 15px; border-radius: 8px; }
.info-label { font-size: 0.9rem; color: #888; margin-bottom: 5px; }
.info-value { font-size: 1rem; }
.details-section { margin-top: 30px; }
.details-section h4 { color: #ffd700; margin-bottom: 15px; }
.addresses-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
.address-card { background: rgba(255,255,255,0.03); padding: 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); }
.address-card.default { border-color: #ffd700; }
.address-type { font-weight: bold; margin-bottom: 10px; color: #ffd700; }
.mini-table { width: 100%; border-collapse: collapse; }
.mini-table th, .mini-table td { padding: 10px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1); }
.note-item { background: rgba(255,255,255,0.02); padding: 15px; border-radius: 8px; margin-bottom: 10px; }
.note-header { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.9rem; }
.note-author { color: #ffd700; }
.note-date { color: #888; }
.note-content { line-height: 1.6; }
</style>