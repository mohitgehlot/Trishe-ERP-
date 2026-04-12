<?php
// finance.php - PRO VERSION (Clean Layout, Visual Dashboard Removed)
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

function formatCurrency($amount) {
    return '₹ ' . number_format((float)$amount, 2);
}

// ==========================================
// 🌟 AJAX REQUEST FOR SPECIFIC STATEMENT 🌟
// ==========================================
if (isset($_GET['get_statement'])) {
    $raw_id = $_GET['get_statement'];
    $type = substr($raw_id, 0, 3);
    $id = intval(substr($raw_id, 4));
    $txns = [];

    if ($type == 'ACC') {
        $q = $conn->query("SELECT transaction_date, type as txn_type, amount, description FROM account_transactions WHERE account_id = $id ORDER BY transaction_date DESC, id DESC");
    } else {
        $q = $conn->query("SELECT transaction_date, CASE WHEN type='SPEND' THEN 'DEBIT' ELSE 'CREDIT' END as txn_type, amount, description FROM cc_transactions WHERE card_id = $id ORDER BY transaction_date DESC, id DESC");
    }
    
    if ($q) {
        while($r = $q->fetch_assoc()) {
            $r['date_fmt'] = date('d M Y', strtotime($r['transaction_date']));
            $txns[] = $r;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($txns);
    exit;
}

// ==========================================
// 🌟 AJAX REQUEST FOR FULL EMI SCHEDULE 🌟
// ==========================================
if (isset($_GET['get_emi_schedule'])) {
    $id = intval($_GET['get_emi_schedule']);
    $q = $conn->query("SELECT * FROM loans_emi WHERE id = $id");
    
    if ($row = $q->fetch_assoc()) {
        $principal = floatval($row['principal_amount']);
        $emi = floatval($row['emi_amount']);
        $rate = floatval($row['interest_rate']);
        $tenure = intval($row['tenure_months']); 
        $start_date = $row['start_date'];

        $monthly_rate = ($rate / 12) / 100;
        $balance = $principal;
        $schedule = [];
        $current_date = $start_date;

        for ($i = 1; $i <= $tenure; $i++) {
            $interest = $balance > 0 ? ($balance * $monthly_rate) : 0;
            $principal_comp = $emi - $interest;
            $balance -= $principal_comp;

            $schedule[] = [
                'month' => date('d-M-Y', strtotime($current_date)), 
                'emi' => round($emi),
                'principal' => round($principal_comp),
                'interest' => round($interest),
                'balance' => round($balance)
            ];
            
            $current_date = date('Y-m-d', strtotime("+1 month", strtotime($current_date)));
        }
        
        header('Content-Type: application/json');
        echo json_encode($schedule);
        exit;
    }
}

// ==========================================
// DELETE ACTIONS
// ==========================================
if (isset($_GET['delete_acc'])) {
    $id = intval($_GET['delete_acc']);
    $conn->query("DELETE FROM account_transactions WHERE account_id = $id"); 
    $conn->query("DELETE FROM accounts WHERE id = $id");
    header("Location: finance.php?msg=Account Deleted Successfully");
    exit;
}

if (isset($_GET['delete_cc'])) {
    $id = intval($_GET['delete_cc']);
    $conn->query("DELETE FROM cc_transactions WHERE card_id = $id");
    $conn->query("DELETE FROM credit_cards WHERE id = $id");
    header("Location: finance.php?msg=Credit Card Deleted Successfully");
    exit;
}

if (isset($_GET['delete_fd'])) {
    $id = intval($_GET['delete_fd']);
    $conn->query("DELETE FROM fixed_deposits WHERE id = $id");
    header("Location: finance.php?msg=FD Deleted Successfully");
    exit;
}

// ==========================================
// 1. ACCOUNTS (ADD / EDIT)
// ==========================================
if (isset($_POST['add_account'])) {
    $name = trim($_POST['account_name']);
    $type = $_POST['account_type'];
    $bal = floatval($_POST['opening_balance']);

    $stmt = $conn->prepare("INSERT INTO accounts (account_name, account_type, balance) VALUES (?, ?, ?)");
    $stmt->bind_param("ssd", $name, $type, $bal);
    
    if ($stmt->execute()) {
        $acc_id = $stmt->insert_id;
        if ($bal > 0) {
            $desc = "Opening Balance";
            $txn = $conn->prepare("INSERT INTO account_transactions (account_id, type, amount, description, transaction_date) VALUES (?, 'CREDIT', ?, ?, CURDATE())");
            $txn->bind_param("ids", $acc_id, $bal, $desc);
            $txn->execute();
        }
        header("Location: finance.php?msg=Account Created");
        exit;
    }
}

if (isset($_POST['edit_account'])) {
    $id = intval($_POST['edit_acc_id']);
    $name = trim($_POST['edit_account_name']);
    $type = $_POST['edit_account_type'];

    $stmt = $conn->prepare("UPDATE accounts SET account_name = ?, account_type = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $type, $id);
    $stmt->execute();
    header("Location: finance.php?msg=Account Updated");
    exit;
}

// ==========================================
// 2. TRANSACTION (Money In / Out)
// ==========================================
if (isset($_POST['add_transaction'])) {
    $raw_acc = $_POST['account_id']; 
    $txn_type = $_POST['txn_type']; 
    $amount = floatval($_POST['amount']);
    $desc = trim($_POST['description']);
    $date = $_POST['txn_date'];

    $acc_type = substr($raw_acc, 0, 3);
    $acc_id = intval(substr($raw_acc, 4));

    $conn->begin_transaction();
    try {
        if ($acc_type == 'ACC') {
            if ($txn_type == 'CREDIT') {
                $conn->query("UPDATE accounts SET balance = balance + $amount WHERE id = $acc_id");
            } else {
                $conn->query("UPDATE accounts SET balance = balance - $amount WHERE id = $acc_id");
            }
            $stmt = $conn->prepare("INSERT INTO account_transactions (account_id, type, amount, description, transaction_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isdss", $acc_id, $txn_type, $amount, $desc, $date);
            $stmt->execute();
        } else {
            if ($txn_type == 'CREDIT') {
                $conn->query("UPDATE credit_cards SET available_limit = available_limit + $amount WHERE id = $acc_id");
                $stmt = $conn->prepare("INSERT INTO cc_transactions (card_id, type, amount, description, transaction_date) VALUES (?, 'PAYMENT', ?, ?, ?)");
            } else {
                $conn->query("UPDATE credit_cards SET available_limit = available_limit - $amount WHERE id = $acc_id");
                $stmt = $conn->prepare("INSERT INTO cc_transactions (card_id, type, amount, description, transaction_date) VALUES (?, 'SPEND', ?, ?, ?)");
            }
            $stmt->bind_param("idss", $acc_id, $amount, $desc, $date);
            $stmt->execute();
        }
        $conn->commit();
        header("Location: finance.php?msg=Transaction Saved");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        die("Error: " . $e->getMessage());
    }
}

// ==========================================
// 2.5 SELF TRANSFER (Contra Entry)
// ==========================================
if (isset($_POST['self_transfer'])) {
    $from_raw = $_POST['from_account'];
    $to_raw = $_POST['to_account'];
    $amount = floatval($_POST['transfer_amount']);
    $date = $_POST['transfer_date'];
    $base_desc = trim($_POST['transfer_desc']);

    if ($from_raw == $to_raw) {
        header("Location: finance.php?error=Cannot transfer to the same account!");
        exit;
    }

    $from_type = substr($from_raw, 0, 3);
    $from_id = intval(substr($from_raw, 4));
    
    $to_type = substr($to_raw, 0, 3);
    $to_id = intval(substr($to_raw, 4));

    $conn->begin_transaction();
    try {
        if ($from_type == 'ACC') {
            $conn->query("UPDATE accounts SET balance = balance - $amount WHERE id = $from_id");
            $desc1 = "Transfer Out: $base_desc";
            $conn->query("INSERT INTO account_transactions (account_id, type, amount, description, transaction_date) VALUES ($from_id, 'DEBIT', $amount, '$desc1', '$date')");
        } else {
            $conn->query("UPDATE credit_cards SET available_limit = available_limit - $amount WHERE id = $from_id");
            $desc1 = "CC Transfer to Bank: $base_desc";
            $conn->query("INSERT INTO cc_transactions (card_id, type, amount, description, transaction_date) VALUES ($from_id, 'SPEND', $amount, '$desc1', '$date')");
        }

        if ($to_type == 'ACC') {
            $conn->query("UPDATE accounts SET balance = balance + $amount WHERE id = $to_id");
            $desc2 = "Transfer In: $base_desc";
            $conn->query("INSERT INTO account_transactions (account_id, type, amount, description, transaction_date) VALUES ($to_id, 'CREDIT', $amount, '$desc2', '$date')");
        } else {
            $conn->query("UPDATE credit_cards SET available_limit = available_limit + $amount WHERE id = $to_id");
            $desc2 = "CC Bill Paid: $base_desc";
            $conn->query("INSERT INTO cc_transactions (card_id, type, amount, description, transaction_date) VALUES ($to_id, 'PAYMENT', $amount, '$desc2', '$date')");
        }

        $conn->commit();
        header("Location: finance.php?msg=Self Transfer Successful");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        die("Transfer Error: " . $e->getMessage());
    }
}

// ==========================================
// 3. LOAN & EMI
// ==========================================
if (isset($_POST['add_loan'])) {
    $loan_name = trim($_POST['loan_name']);
    $bank_name = trim($_POST['bank_name']);
    $principal = floatval($_POST['principal_amount']);
    $interest = floatval($_POST['interest_rate']);
    $tenure = intval($_POST['tenure_months']);
    $start_date = $_POST['start_date'];
    $emi = floatval($_POST['emi_amount']);
    $emi_date = $_POST['next_emi_date'];

    $stmt = $conn->prepare("INSERT INTO loans_emi (loan_name, bank_name, principal_amount, interest_rate, tenure_months, start_date, emi_amount, next_emi_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssddisds", $loan_name, $bank_name, $principal, $interest, $tenure, $start_date, $emi, $emi_date);
    $stmt->execute();
    header("Location: finance.php?msg=Loan Tracked");
    exit;
}

if (isset($_POST['pay_emi'])) {
    $loan_id = intval($_POST['pay_loan_id']);
    $acc_id = intval($_POST['emi_account_id']);
    $amount = floatval($_POST['pay_emi_amount']);
    
    $conn->begin_transaction();
    try {
        $conn->query("UPDATE accounts SET balance = balance - $amount WHERE id = $acc_id");
        $desc = "EMI Paid for Loan #$loan_id";
        $stmtT = $conn->prepare("INSERT INTO account_transactions (account_id, type, amount, description, transaction_date) VALUES (?, 'DEBIT', ?, ?, CURDATE())");
        $stmtT->bind_param("ids", $acc_id, $amount, $desc);
        $stmtT->execute();
        $conn->query("UPDATE loans_emi SET next_emi_date = DATE_ADD(next_emi_date, INTERVAL 1 MONTH), paid_installments = COALESCE(paid_installments, 0) + 1 WHERE id = $loan_id");
        $conn->commit();
        header("Location: finance.php?msg=EMI Paid Successfully");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        die("EMI Payment Failed");
    }
}

// ==========================================
// 4. CREDIT CARD ACTIONS 
// ==========================================
if (isset($_POST['add_cc'])) {
    $name = trim($_POST['card_name']);
    $bank = trim($_POST['cc_bank_name']);
    $limit = floatval($_POST['total_limit']);
    $outstanding = floatval($_POST['outstanding_balance']); 
    $available = $limit - $outstanding; 
    $s_date = intval($_POST['statement_date']);
    $d_date = intval($_POST['due_date']);
    
    $stmt = $conn->prepare("INSERT INTO credit_cards (card_name, bank_name, total_limit, available_limit, statement_date, due_date) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssddii", $name, $bank, $limit, $available, $s_date, $d_date);
    $stmt->execute();
    header("Location: finance.php?msg=Credit Card Added");
    exit;
}

if (isset($_POST['edit_cc'])) {
    $id = intval($_POST['edit_cc_id']);
    $name = trim($_POST['edit_card_name']);
    $bank = trim($_POST['edit_cc_bank_name']);
    $limit = floatval($_POST['edit_total_limit']);
    $outstanding = floatval($_POST['edit_outstanding']);
    $available = $limit - $outstanding; 
    
    $s_date = intval($_POST['edit_statement_date']);
    $d_date = intval($_POST['edit_due_date']);
    
    $stmt = $conn->prepare("UPDATE credit_cards SET card_name=?, bank_name=?, total_limit=?, available_limit=?, statement_date=?, due_date=? WHERE id=?");
    $stmt->bind_param("ssddiii", $name, $bank, $limit, $available, $s_date, $d_date, $id);
    $stmt->execute();
    header("Location: finance.php?msg=Credit Card Updated");
    exit;
}

if (isset($_POST['cc_action_submit'])) {
    $card_id = intval($_POST['action_card_id']);
    $type = $_POST['cc_action_type']; 
    $amount = floatval($_POST['cc_amount']);
    $desc = trim($_POST['cc_desc']);
    $acc_id = isset($_POST['cc_pay_account']) ? intval($_POST['cc_pay_account']) : 0;
    
    $conn->begin_transaction();
    try {
        if ($type == 'SPEND') {
            $conn->query("UPDATE credit_cards SET available_limit = available_limit - $amount WHERE id = $card_id");
            $conn->query("INSERT INTO cc_transactions (card_id, type, amount, description, transaction_date) VALUES ($card_id, 'SPEND', $amount, '$desc', CURDATE())");
            $msg = "Card Swiped Successfully";
        } elseif ($type == 'TRANSFER') {
            $conn->query("UPDATE credit_cards SET available_limit = available_limit - $amount WHERE id = $card_id");
            $conn->query("UPDATE accounts SET balance = balance + $amount WHERE id = $acc_id");
            $conn->query("INSERT INTO cc_transactions (card_id, type, amount, description, transaction_date) VALUES ($card_id, 'SPEND', $amount, 'CC to Bank: $desc', CURDATE())");
            $conn->query("INSERT INTO account_transactions (account_id, type, amount, description, transaction_date) VALUES ($acc_id, 'CREDIT', $amount, 'Funds from CC: $desc', CURDATE())");
            $msg = "Funds Transferred to Bank";
        } elseif ($type == 'PAY') {
            $conn->query("UPDATE accounts SET balance = balance - $amount WHERE id = $acc_id");
            $conn->query("UPDATE credit_cards SET available_limit = available_limit + $amount WHERE id = $card_id");
            $conn->query("INSERT INTO account_transactions (account_id, type, amount, description, transaction_date) VALUES ($acc_id, 'DEBIT', $amount, 'CC Bill Paid: $desc', CURDATE())");
            $conn->query("INSERT INTO cc_transactions (card_id, type, amount, description, transaction_date) VALUES ($card_id, 'PAYMENT', $amount, 'Bill Paid: $desc', CURDATE())");
            $msg = "Credit Card Bill Paid";
        }
        $conn->commit();
        header("Location: finance.php?msg=" . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        die("CC Transaction Failed: " . $e->getMessage());
    }
}

// ==========================================
// 5. FIXED DEPOSIT (ADD / EDIT)
// ==========================================
if (isset($_POST['add_fd'])) {
    $name = trim($_POST['fd_name']);
    $bank = trim($_POST['fd_bank_name']);
    $principal = floatval($_POST['fd_principal']);
    $roi = floatval($_POST['fd_roi']);
    $s_date = $_POST['fd_start_date'];
    $m_date = $_POST['fd_maturity_date'];
    
    $stmt = $conn->prepare("INSERT INTO fixed_deposits (fd_name, bank_name, principal_amount, interest_rate, start_date, maturity_date) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssddss", $name, $bank, $principal, $roi, $s_date, $m_date);
    $stmt->execute();
    header("Location: finance.php?msg=Fixed Deposit Tracked");
    exit;
}

if (isset($_POST['edit_fd'])) {
    $id = intval($_POST['edit_fd_id']);
    $name = trim($_POST['edit_fd_name']);
    $bank = trim($_POST['edit_fd_bank_name']);
    $principal = floatval($_POST['edit_fd_principal']);
    $roi = floatval($_POST['edit_fd_roi']);
    $s_date = $_POST['edit_fd_start_date'];
    $m_date = $_POST['edit_fd_maturity_date'];
    $stmt = $conn->prepare("UPDATE fixed_deposits SET fd_name=?, bank_name=?, principal_amount=?, interest_rate=?, start_date=?, maturity_date=? WHERE id=?");
    $stmt->bind_param("ssddssi", $name, $bank, $principal, $roi, $s_date, $m_date, $id);
    $stmt->execute();
    header("Location: finance.php?msg=Fixed Deposit Updated");
    exit;
}

// --- FETCH DATA FOR DASHBOARD ---
$accounts = [];
$total_bank = 0; $total_cash = 0;
$acc_query = $conn->query("SELECT * FROM accounts ORDER BY account_type ASC, account_name ASC");
if ($acc_query) {
    while ($row = $acc_query->fetch_assoc()) {
        $accounts[] = $row;
        if ($row['account_type'] == 'Bank') $total_bank += $row['balance'];
        if ($row['account_type'] == 'Cash') $total_cash += $row['balance'];
    }
}

$loans = [];
$total_emi_due = 0;
$loan_q = $conn->query("SELECT * FROM loans_emi WHERE status = 'Active' ORDER BY next_emi_date ASC");
if ($loan_q) {
    while ($row = $loan_q->fetch_assoc()) {
        $loans[] = $row;
        if (strtotime($row['next_emi_date']) <= strtotime('+7 days')) {
            $total_emi_due += $row['emi_amount'];
        }
    }
}

$credit_cards = [];
$total_cc_outstanding = 0;
$cc_q = $conn->query("SELECT * FROM credit_cards ORDER BY id ASC");
if ($cc_q) {
    while ($row = $cc_q->fetch_assoc()) {
        $credit_cards[] = $row;
        $used = $row['total_limit'] - $row['available_limit'];
        if($used > 0) $total_cc_outstanding += $used;
    }
}

$fds = [];
$total_fd_live = 0;
$fd_q = $conn->query("SELECT * FROM fixed_deposits WHERE status = 'Active' ORDER BY maturity_date ASC");
if ($fd_q) {
    $today = time();
    while ($row = $fd_q->fetch_assoc()) {
        $p = floatval($row['principal_amount']);
        $r = floatval($row['interest_rate']);
        $start = strtotime($row['start_date']);
        $end = strtotime($row['maturity_date']);
        $days_passed = ($today > $end) ? ($end - $start) / 86400 : ($today - $start) / 86400;
        $days_passed = max(0, $days_passed);
        
        $interest = ($p * $r * $days_passed) / (365 * 100);
        $live_value = $p + $interest;
        
        $row['live_value'] = $live_value;
        $row['interest_earned'] = $interest;
        $total_fd_live += $live_value;
        $fds[] = $row;
    }
}

$recent_txns = [];
$combined_query = "
    SELECT t.id, t.transaction_date, a.account_name as name, a.account_type as acc_type, t.description, t.amount, t.type as txn_type 
    FROM account_transactions t JOIN accounts a ON t.account_id = a.id 
    UNION ALL 
    SELECT cct.id, cct.transaction_date, cc.card_name as name, 'Credit Card' as acc_type, cct.description, cct.amount, CASE WHEN cct.type = 'SPEND' THEN 'DEBIT' ELSE 'CREDIT' END as txn_type 
    FROM cc_transactions cct JOIN credit_cards cc ON cct.card_id = cc.id 
    ORDER BY transaction_date DESC, id DESC LIMIT 20
";
$txn_q = $conn->query($combined_query);
if ($txn_q) while ($r = $txn_q->fetch_assoc()) $recent_txns[] = $r;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Finance & Banking | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_style.css">
    <style>
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 1.5rem; flex-shrink: 0; }
        .stat-info h4 { margin: 0 0 5px 0; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; }
        .stat-info h2 { margin: 0; color: var(--text-main); font-size: 1.25rem; font-weight: 800; }
        .layout-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 24px; align-items: start; }
        .acc-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; }
        .acc-box:hover { border-color: #cbd5e1; }
        .text-green { color: #16a34a; } .text-red { color: #dc2626; }
        .progress-bg { background: #e2e8f0; height: 8px; border-radius: 4px; width: 100%; margin-top: 8px; overflow: hidden; }
        .progress-bar { height: 100%; background: #3b82f6; border-radius: 4px; transition: width 0.5s ease; }
        .progress-bar.danger { background: #ef4444; } .progress-bar.warning { background: #f59e0b; }
        .stmt-table th { position: sticky; top: 0; background: #f8fafc; z-index: 10; padding:12px; border-bottom:2px solid #e2e8f0; text-align:left; color:#475569; font-size:0.85rem; text-transform:uppercase;}
        .stmt-table td { padding:12px; border-bottom:1px solid #f1f5f9; font-size:0.95rem; }
        @media(max-width: 1024px) { .layout-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <?php if(isset($_GET['msg'])): ?>
            <div class="alert" style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; margin-bottom:15px; padding:10px; border-radius:6px; font-weight:600;"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['msg']) ?></div>
        <?php endif; ?>
        <?php if(isset($_GET['error'])): ?>
            <div class="alert" style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; margin-bottom:15px; padding:10px; border-radius:6px; font-weight:600;"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_GET['error']) ?></div>
        <?php endif; ?>

        <div class="page-header card" style="padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 25px;">
            <h1 class="page-title"><i class="fas fa-wallet text-primary" style="margin-right:10px;"></i> Finance & Banking</h1>
            <div class="header-actions">
                <button class="btn btn-outline" onclick="openModal('modalAccount')"><i class="fas fa-university"></i> Bank/Cash</button>
                <button class="btn btn-outline" onclick="openModal('modalAddFD')" style="color:var(--success); border-color:var(--success);"><i class="fas fa-chart-line"></i> Add FD</button>
                <button class="btn btn-outline" onclick="openModal('modalAddCC')"><i class="fas fa-credit-card"></i> Add CC</button>
                <button class="btn btn-outline" onclick="openModal('modalSelfTransfer')" style="color:var(--warning); border-color:var(--warning);"><i class="fas fa-random"></i> Transfer</button>
                <button class="btn btn-primary" onclick="openModal('modalTxn')"><i class="fas fa-exchange-alt"></i> Entry In/Out</button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card" style="border-top: 4px solid #3b82f6;"><div class="stat-icon" style="background:#eff6ff; color:#3b82f6;"><i class="fas fa-university"></i></div><div class="stat-info"><h4>Bank Balance</h4><h2><?= formatCurrency($total_bank) ?></h2></div></div>
            <div class="stat-card" style="border-top: 4px solid #10b981;"><div class="stat-icon" style="background:#ecfdf5; color:#10b981;"><i class="fas fa-money-bill-wave"></i></div><div class="stat-info"><h4>Cash in Hand</h4><h2><?= formatCurrency($total_cash) ?></h2></div></div>
            <div class="stat-card" style="border-top: 4px solid #059669;"><div class="stat-icon" style="background:#d1fae5; color:#059669;"><i class="fas fa-chart-line"></i></div><div class="stat-info"><h4>Total FD Value</h4><h2><?= formatCurrency($total_fd_live) ?></h2></div></div>
            <div class="stat-card" style="border-top: 4px solid #8b5cf6;"><div class="stat-icon" style="background:#f3e8ff; color:#8b5cf6;"><i class="fas fa-credit-card"></i></div><div class="stat-info"><h4>CC Outstanding</h4><h2><?= formatCurrency($total_cc_outstanding) ?></h2></div></div>
            <div class="stat-card" style="border-top: 4px solid #ef4444;"><div class="stat-icon" style="background:#fef2f2; color:#ef4444;"><i class="fas fa-calendar-exclamation"></i></div><div class="stat-info"><h4>Next EMI (7 Days)</h4><h2 style="color:#ef4444;"><?= formatCurrency($total_emi_due) ?></h2></div></div>
        </div>

        <div class="layout-grid">
            <div>
                <div class="card">
                    <div class="card-header"><i class="fas fa-history text-muted" style="margin-right:8px;"></i> Combined Passbook</div>
                    <div class="table-wrap" style="border:none; box-shadow:none;">
                        <table>
                            <thead><tr><th>Date</th><th>Account/Card</th><th>Description</th><th style="text-align:right;">Amount</th></tr></thead>
                            <tbody>
                                <?php if(empty($recent_txns)): ?><tr><td colspan="4" style="text-align:center; padding:20px;">No transactions found.</td></tr>
                                <?php else: foreach($recent_txns as $t): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                                        <td><strong style="color:var(--text-main);"><?= htmlspecialchars($t['name']) ?></strong><br><small style="color:var(--text-muted);"><?= $t['acc_type'] ?></small></td>
                                        <td><?= htmlspecialchars($t['description']) ?></td>
                                        <td style="text-align:right; font-weight:700;" class="<?= $t['txn_type']=='CREDIT' ? 'text-green' : 'text-red' ?>">
                                            <?= $t['txn_type']=='CREDIT' ? '+' : '-' ?> <?= formatCurrency($t['amount']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div>
                <div class="card" style="margin-bottom:20px; border-color:#fca5a5;">
                    <div class="card-header" style="background:#fef2f2; color:#991b1b; border-bottom-color:#fca5a5;"><i class="fas fa-clock" style="margin-right:8px;"></i> Loans & EMIs
                        <button class="btn-icon" style="float:right; color:#991b1b; font-size:0.8rem;" onclick="openModal('modalLoan')"><i class="fas fa-plus"></i></button>
                    </div>
                    <div style="padding:15px;">
                        <?php if(empty($loans)): ?><div style="text-align:center; color:#94a3b8; padding:10px;">No active loans.</div>
                        <?php else: foreach($loans as $ln): $is_due = (strtotime($ln['next_emi_date']) <= strtotime('+7 days')); ?>
                            <div style="border-bottom:1px dashed #e2e8f0; padding-bottom:12px; margin-bottom:12px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <strong style="color:var(--text-main);"><?= htmlspecialchars($ln['loan_name']) ?></strong>
                                        <button class="btn-icon" style="color:var(--primary); font-size:0.8rem;" onclick="openEmiSchedule(<?= $ln['id'] ?>, '<?= addslashes($ln['loan_name']) ?>')" title="View Full EMI Schedule"><i class="fas fa-calendar-alt"></i></button>
                                    </div>
                                    <button class="btn-icon" style="color:#16a34a; font-size:0.8rem; background:#dcfce7; padding:2px 8px; border-radius:4px;" onclick="openPayEMI(<?= $ln['id'] ?>, '<?= htmlspecialchars($ln['loan_name']) ?>', <?= $ln['emi_amount'] ?>)">Pay</button>
                                </div>
                                <div style="display:flex; justify-content:space-between; font-size:0.85rem; color:#64748b; margin-top:5px;">
                                    <span><strong class="text-red"><?= formatCurrency($ln['emi_amount']) ?></strong></span>
                                    <span style="font-weight:600; padding:2px 8px; border-radius:4px; <?= $is_due ? 'background:#fee2e2; color:#dc2626;' : 'background:#f1f5f9;' ?>">
                                        Due: <?= date('d M Y', strtotime($ln['next_emi_date'])) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="card" style="margin-bottom:20px; border-color:#6ee7b7;">
                    <div class="card-header" style="background:#d1fae5; color:#065f46; border-bottom-color:#6ee7b7;">
                        <i class="fas fa-chart-line" style="margin-right:8px;"></i> Fixed Deposits (FDs)
                    </div>
                    <div style="padding:15px;">
                        <?php if(empty($fds)): ?>
                            <div style="text-align:center; color:#94a3b8; padding:10px;">No active FDs.</div>
                        <?php else: foreach($fds as $fd): 
                            $is_mature = (strtotime($fd['maturity_date']) <= time());
                        ?>
                            <div style="border-bottom:1px dashed #e2e8f0; padding-bottom:12px; margin-bottom:12px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <strong style="color:var(--text-main);"><?= htmlspecialchars($fd['fd_name']) ?></strong>
                                        <button class="btn-icon" style="color:var(--text-muted); font-size:0.7rem;" onclick='openEditFD(<?= json_encode($fd) ?>)' title="Edit FD"><i class="fas fa-edit"></i></button>
                                        <a href="?delete_fd=<?= $fd['id'] ?>" onclick="return confirm('Delete this FD?')" class="btn-icon" style="color:var(--danger); font-size:0.7rem;"><i class="fas fa-trash"></i></a>
                                    </div>
                                    <span style="font-size:0.8rem; font-weight:700; color:var(--primary);"><?= $fd['interest_rate'] ?>% ROI</span>
                                </div>
                                <div style="font-size:0.85rem; color:#64748b; margin-bottom:5px;"><i class="fas fa-university"></i> <?= htmlspecialchars($fd['bank_name']) ?></div>
                                <div style="display:flex; justify-content:space-between; font-size:0.8rem; background:#f8fafc; padding:8px; border-radius:6px; border:1px solid #e2e8f0;">
                                    <div>Invested: <br><strong style="color:#475569;">₹<?= number_format($fd['principal_amount']) ?></strong></div>
                                    <div>Interest Earned: <br><strong class="text-green">+₹<?= number_format($fd['interest_earned'], 2) ?></strong></div>
                                    <div style="text-align:right;">Live Value: <br><strong style="color:#0f172a; font-size:1rem;">₹<?= number_format($fd['live_value'], 2) ?></strong></div>
                                </div>
                                <div style="font-size:0.75rem; font-weight:600; text-align:right; margin-top:6px; <?= $is_mature ? 'color:var(--success);' : 'color:var(--text-muted);' ?>">
                                    <?= $is_mature ? '🎉 Matured On: ' : 'Maturity: ' ?> <?= date('d M Y', strtotime($fd['maturity_date'])) ?>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="card" style="margin-bottom:20px; border-color:#c7d2fe;">
                    <div class="card-header" style="background:#e0e7ff; color:#3730a3; border-bottom-color:#c7d2fe;">
                        <i class="fas fa-credit-card" style="margin-right:8px;"></i> Credit Cards
                    </div>
                    <div style="padding:15px;">
                        <?php if(empty($credit_cards)): ?>
                            <div style="text-align:center; color:#94a3b8; padding:10px;">No cards added.</div>
                        <?php else: foreach($credit_cards as $cc): 
                            $used = $cc['total_limit'] - $cc['available_limit'];
                            $pct = ($used / $cc['total_limit']) * 100;
                            $bar_class = 'progress-bar';
                            if($pct > 80) $bar_class .= ' danger';
                            elseif($pct > 50) $bar_class .= ' warning';
                        ?>
                            <div style="border-bottom:1px dashed #e2e8f0; padding-bottom:12px; margin-bottom:12px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <strong style="color:var(--text-main);"><?= htmlspecialchars($cc['card_name']) ?></strong>
                                        <button class="btn-icon" style="color:var(--text-muted); font-size:0.7rem;" onclick='openEditCC(<?= json_encode($cc) ?>)' title="Edit Card"><i class="fas fa-edit"></i></button>
                                        <a href="?delete_cc=<?= $cc['id'] ?>" onclick="return confirm('Delete this Credit Card? All its history will be lost.')" class="btn-icon" style="color:var(--danger); font-size:0.7rem;" title="Delete Card"><i class="fas fa-trash"></i></a>
                                        <button class="btn-icon" style="color:var(--primary); font-size:0.75rem;" onclick="openStatement('CC_<?= $cc['id'] ?>', '<?= addslashes($cc['card_name']) ?>')" title="View Passbook"><i class="fas fa-book-open"></i></button>
                                    </div>
                                    <button class="btn-icon" style="color:var(--primary); font-size:0.8rem; background:#f1f5f9; padding:2px 8px; border-radius:4px;" onclick="openCCAction(<?= $cc['id'] ?>, '<?= addslashes($cc['card_name']) ?>')">Action</button>
                                </div>
                                <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:#64748b;">
                                    <span>Used/Due: <strong class="text-red">₹<?= number_format($used) ?></strong></span>
                                    <span>Avail: <strong class="text-green">₹<?= number_format($cc['available_limit']) ?></strong></span>
                                </div>
                                <div class="progress-bg"><div class="<?= $bar_class ?>" style="width: <?= $pct ?>%;"></div></div>
                                <div style="font-size:0.75rem; color:#94a3b8; margin-top:5px; text-align:right;">Bill: <?= $cc['statement_date'] ?>th | Due: <?= $cc['due_date'] ?>th</div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="card" style="margin-bottom:20px;">
                    <div class="card-header"><i class="fas fa-piggy-bank text-warning" style="margin-right:8px;"></i> Liquid Accounts</div>
                    <div style="padding:15px;">
                        <?php foreach($accounts as $acc): ?>
                            <div class="acc-box">
                                <div>
                                    <div class="acc-name">
                                        <?= htmlspecialchars($acc['account_name']) ?>
                                        <div style="display:flex; gap:5px;">
                                            <button class="btn-icon" style="color:var(--text-muted); font-size:0.7rem;" onclick='openEditAccount(<?= json_encode($acc) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                                            <a href="?delete_acc=<?= $acc['id'] ?>" onclick="return confirm('Delete Account and ALL its history?')" class="btn-icon" style="color:var(--danger); font-size:0.7rem;" title="Delete"><i class="fas fa-trash"></i></a>
                                            <button class="btn-icon" style="color:var(--primary); font-size:0.75rem;" onclick="openStatement('ACC_<?= $acc['id'] ?>', '<?= addslashes($acc['account_name']) ?>')" title="View Passbook"><i class="fas fa-book-open"></i></button>
                                        </div>
                                    </div>
                                    <small style="color:var(--text-muted);"><i class="fas <?= $acc['account_type']=='Bank' ? 'fa-building' : 'fa-wallet' ?>"></i> <?= $acc['account_type'] ?></small>
                                </div>
                                <div class="acc-bal <?= $acc['balance'] >= 0 ? 'text-green' : 'text-red' ?>">
                                    <?= formatCurrency($acc['balance']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div id="modalStatement" class="global-modal">
        <div class="g-modal-content" style="max-width:600px;">
            <div class="g-modal-header">
                <h3 style="margin:0; color:var(--primary);"><i class="fas fa-book-open" style="margin-right:8px;"></i> <span id="stmt_name">Passbook</span></h3>
                <button type="button" class="g-close-btn" onclick="closeModal('modalStatement')">&times;</button>
            </div>
            <div class="g-modal-body" style="max-height: 450px; overflow-y: auto; padding:0;">
                <table class="stmt-table" style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th style="text-align:right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody id="stmt_body"></tbody>
                </table>
            </div>
            <div style="padding:15px; background:#f8fafc; border-top:1px solid #e2e8f0; text-align:right;">
                <button class="btn btn-outline" onclick="closeModal('modalStatement')">Close</button>
            </div>
        </div>
    </div>

    <div id="modalEmiSchedule" class="global-modal">
        <div class="g-modal-content" style="max-width:700px;">
            <div class="g-modal-header">
                <h3 style="margin:0; color:var(--primary);"><i class="fas fa-calendar-alt" style="margin-right:8px;"></i> Amortization Schedule: <span id="sch_name"></span></h3>
                <button type="button" class="g-close-btn" onclick="closeModal('modalEmiSchedule')">&times;</button>
            </div>
            <div class="g-modal-body" style="max-height: 500px; overflow-y: auto; padding:0;">
                <table class="stmt-table" style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>EMI</th>
                            <th>Towards Loan</th>
                            <th>Towards Interest</th>
                            <th>Outstanding Loan</th>
                        </tr>
                    </thead>
                    <tbody id="sch_body"></tbody>
                </table>
            </div>
            <div style="padding:15px; background:#f8fafc; border-top:1px solid #e2e8f0; text-align:right;">
                <button class="btn btn-outline" onclick="closeModal('modalEmiSchedule')">Close</button>
            </div>
        </div>
    </div>

    <div id="modalAddFD" class="global-modal">
        <div class="g-modal-content" style="max-width:400px;">
            <div class="g-modal-header"><h3 style="margin:0; color:var(--success);"><i class="fas fa-chart-line"></i> Track New FD</h3><button type="button" class="g-close-btn" onclick="closeModal('modalAddFD')">&times;</button></div>
            <div class="g-modal-body">
                <form method="POST">
                    <div class="form-group" style="margin-bottom:15px;"><label class="form-label">FD Name / Identifier</label><input type="text" name="fd_name" class="form-input" placeholder="e.g. LIC Tax Saver FD" required></div>
                    <div class="form-group" style="margin-bottom:15px;"><label class="form-label">Bank / Institution Name</label><input type="text" name="fd_bank_name" class="form-input" required></div>
                    <div style="display:flex; gap:10px; margin-bottom:15px;">
                        <div class="form-group" style="flex:2;"><label class="form-label">Principal Amount (₹)</label><input type="number" name="fd_principal" step="0.01" class="form-input" required></div>
                        <div class="form-group" style="flex:1;"><label class="form-label">ROI (%)</label><input type="number" name="fd_roi" step="0.01" class="form-input" required></div>
                    </div>
                    <div style="display:flex; gap:10px; margin-bottom:20px;">
                        <div class="form-group" style="flex:1;"><label class="form-label">Start Date</label><input type="date" name="fd_start_date" class="form-input" required></div>
                        <div class="form-group" style="flex:1;"><label class="form-label">Maturity Date</label><input type="date" name="fd_maturity_date" class="form-input" required></div>
                    </div>
                    <button type="submit" name="add_fd" class="btn btn-primary" style="width:100%; padding:10px; background:var(--success); border-color:var(--success);">Save FD</button>
                </form>
            </div>
        </div>
    </div>

    <div id="modalEditFD" class="global-modal">
        <div class="g-modal-content" style="max-width:400px;">
            <div class="g-modal-header"><h3 style="margin:0; color:var(--warning);"><i class="fas fa-edit"></i> Edit FD Details</h3><button type="button" class="g-close-btn" onclick="closeModal('modalEditFD')">&times;</button></div>
            <div class="g-modal-body">
                <form method="POST">
                    <input type="hidden" name="edit_fd_id" id="e_fd_id">
                    <div class="form-group" style="margin-bottom:15px;"><label class="form-label">FD Name / Identifier</label><input type="text" name="edit_fd_name" id="e_fd_name" class="form-input" required></div>
                    <div class="form-group" style="margin-bottom:15px;"><label class="form-label">Bank / Institution Name</label><input type="text" name="edit_fd_bank_name" id="e_fd_bank" class="form-input" required></div>
                    <div style="display:flex; gap:10px; margin-bottom:15px;">
                        <div class="form-group" style="flex:2;"><label class="form-label">Principal Amount (₹)</label><input type="number" name="edit_fd_principal" id="e_fd_prin" step="0.01" class="form-input" required></div>
                        <div class="form-group" style="flex:1;"><label class="form-label">ROI (%)</label><input type="number" name="edit_fd_roi" id="e_fd_roi" step="0.01" class="form-input" required></div>
                    </div>
                    <div style="display:flex; gap:10px; margin-bottom:20px;">
                        <div class="form-group" style="flex:1;"><label class="form-label">Start Date</label><input type="date" name="edit_fd_start_date" id="e_fd_start" class="form-input" required></div>
                        <div class="form-group" style="flex:1;"><label class="form-label">Maturity Date</label><input type="date" name="edit_fd_maturity_date" id="e_fd_mat" class="form-input" required></div>
                    </div>
                    <button type="submit" name="edit_fd" class="btn btn-primary" style="width:100%; padding:10px;">Update FD</button>
                </form>
            </div>
        </div>
    </div>

    <div id="modalAccount" class="global-modal"><div class="g-modal-content" style="max-width:400px;"><div class="g-modal-header"><h3 style="margin:0;"><i class="fas fa-plus"></i> Add Account/Drawer</h3><button type="button" class="g-close-btn" onclick="closeModal('modalAccount')">&times;</button></div><div class="g-modal-body"><form method="POST"><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Account Name</label><input type="text" name="account_name" class="form-input" required></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Account Type</label><select name="account_type" class="form-input"><option value="Bank">Bank Account</option><option value="Cash">Cash Drawer</option></select></div><div class="form-group" style="margin-bottom:20px;"><label class="form-label">Opening Balance (₹)</label><input type="number" name="opening_balance" step="0.01" class="form-input" value="0"></div><button type="submit" name="add_account" class="btn btn-primary" style="width:100%; padding:10px;">Save Account</button></form></div></div></div>
    
    <div id="modalEditAccount" class="global-modal"><div class="g-modal-content" style="max-width:400px;"><div class="g-modal-header"><h3 style="margin:0; color:var(--warning);"><i class="fas fa-edit"></i> Edit Account</h3><button type="button" class="g-close-btn" onclick="closeModal('modalEditAccount')">&times;</button></div><div class="g-modal-body"><form method="POST"><input type="hidden" name="edit_acc_id" id="e_acc_id"><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Account Name</label><input type="text" name="edit_account_name" id="e_acc_name" class="form-input" required></div><div class="form-group" style="margin-bottom:20px;"><label class="form-label">Account Type</label><select name="edit_account_type" id="e_acc_type" class="form-input"><option value="Bank">Bank Account</option><option value="Cash">Cash Drawer</option></select></div><button type="submit" name="edit_account" class="btn btn-primary" style="width:100%; padding:10px;">Update Account</button></form></div></div></div>
    
    <div id="modalSelfTransfer" class="global-modal"><div class="g-modal-content" style="max-width:400px;"><div class="g-modal-header"><h3 style="margin:0; color:var(--warning);"><i class="fas fa-random"></i> Self Transfer</h3><button type="button" class="g-close-btn" onclick="closeModal('modalSelfTransfer')">&times;</button></div><div class="g-modal-body"><form method="POST" onsubmit="return validateTransfer()"><div class="form-group" style="margin-bottom:15px;"><label class="form-label text-red">Send From (Money OUT)</label><select name="from_account" id="trans_from" class="form-input" required><option value="">-- Select Source --</option><optgroup label="Bank & Cash"><?php foreach($accounts as $acc) echo "<option value='ACC_{$acc['id']}'>{$acc['account_name']} (Bal: ₹{$acc['balance']})</option>"; ?></optgroup><optgroup label="Credit Cards"><?php foreach($credit_cards as $cc) echo "<option value='CC_{$cc['id']}'>{$cc['card_name']} (Avail: ₹{$cc['available_limit']})</option>"; ?></optgroup></select></div><div style="text-align:center; color:var(--text-muted); margin-bottom:15px;"><i class="fas fa-arrow-down"></i></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label text-green">Receive To (Money IN)</label><select name="to_account" id="trans_to" class="form-input" required><option value="">-- Select Destination --</option><optgroup label="Bank & Cash"><?php foreach($accounts as $acc) echo "<option value='ACC_{$acc['id']}'>{$acc['account_name']} (Bal: ₹{$acc['balance']})</option>"; ?></optgroup><optgroup label="Credit Cards"><?php foreach($credit_cards as $cc) echo "<option value='CC_{$cc['id']}'>{$cc['card_name']} (Avail: ₹{$cc['available_limit']})</option>"; ?></optgroup></select></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Amount (₹)</label><input type="number" name="transfer_amount" step="0.01" class="form-input" required></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Date</label><input type="date" name="transfer_date" class="form-input" value="<?= date('Y-m-d') ?>" required></div><div class="form-group" style="margin-bottom:20px;"><label class="form-label">Remarks</label><input type="text" name="transfer_desc" class="form-input" placeholder="e.g. CC to Bank / Cash Deposit" required></div><button type="submit" name="self_transfer" class="btn btn-warning" style="width:100%; padding:10px;"><i class="fas fa-check"></i> Complete Transfer</button></form></div></div></div>
    
    <div id="modalTxn" class="global-modal"><div class="g-modal-content" style="max-width:400px;"><div class="g-modal-header"><h3 style="margin:0;"><i class="fas fa-exchange-alt"></i> Entry In/Out</h3><button type="button" class="g-close-btn" onclick="closeModal('modalTxn')">&times;</button></div><div class="g-modal-body"><form method="POST"><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Select Account/Card</label><select name="account_id" class="form-input" required><option value="">-- Select --</option><optgroup label="Bank & Cash"><?php foreach($accounts as $acc) echo "<option value='ACC_{$acc['id']}'>{$acc['account_name']} (Bal: ₹{$acc['balance']})</option>"; ?></optgroup><optgroup label="Credit Cards"><?php foreach($credit_cards as $cc) echo "<option value='CC_{$cc['id']}'>{$cc['card_name']} (Avail: ₹{$cc['available_limit']})</option>"; ?></optgroup></select></div><div style="display:flex; gap:10px; margin-bottom:15px;"><div class="form-group" style="flex:1;"><label class="form-label">Transaction Type</label><select name="txn_type" class="form-input" required style="font-weight:700;"><option value="CREDIT" style="color:green;">+ Money IN (Add/Refund)</option><option value="DEBIT" style="color:red;">- Money OUT (Spend/Withdraw)</option></select></div><div class="form-group" style="flex:1;"><label class="form-label">Amount (₹)</label><input type="number" name="amount" step="0.01" class="form-input" required></div></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Date</label><input type="date" name="txn_date" class="form-input" value="<?= date('Y-m-d') ?>" required></div><div class="form-group" style="margin-bottom:20px;"><label class="form-label">Description / Remarks</label><input type="text" name="description" class="form-input" placeholder="e.g. Electricity Bill, Cash Deposit" required></div><button type="submit" name="add_transaction" class="btn btn-primary" style="width:100%; padding:10px;">Save Entry</button></form></div></div></div>
    
    <div id="modalAddCC" class="global-modal"><div class="g-modal-content" style="max-width:400px;"><div class="g-modal-header"><h3 style="margin:0; color:#3730a3;"><i class="fas fa-credit-card"></i> Add CC</h3><button type="button" class="g-close-btn" onclick="closeModal('modalAddCC')">&times;</button></div><div class="g-modal-body"><form method="POST"><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Card Nickname</label><input type="text" name="card_name" class="form-input" required></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Bank Name</label><input type="text" name="cc_bank_name" class="form-input" required></div><div style="display:flex; gap:10px; margin-bottom:5px;"><div class="form-group" style="flex:1;"><label class="form-label">Total Limit (₹)</label><input type="number" name="total_limit" id="add_cc_limit" step="0.01" class="form-input" required oninput="calcAddCC()"></div><div class="form-group" style="flex:1;"><label class="form-label text-red">Used / Due (₹)</label><input type="number" name="outstanding_balance" id="add_cc_out" step="0.01" value="0" class="form-input" required oninput="calcAddCC()"></div></div><div style="font-size:0.85rem; font-weight:700; color:var(--success); text-align:right; margin-bottom:15px;">Available Limit: ₹<span id="add_cc_avail">0.00</span></div><div style="display:flex; gap:10px; margin-bottom:20px;"><div class="form-group" style="flex:1;"><label class="form-label">Stmt Date (1-31)</label><input type="number" name="statement_date" min="1" max="31" class="form-input" required></div><div class="form-group" style="flex:1;"><label class="form-label">Due Date (1-31)</label><input type="number" name="due_date" min="1" max="31" class="form-input" required></div></div><button type="submit" name="add_cc" class="btn btn-primary" style="width:100%; padding:10px; background:#4f46e5;">Save Card</button></form></div></div></div>
    
    <div id="modalEditCC" class="global-modal"><div class="g-modal-content" style="max-width:400px;"><div class="g-modal-header"><h3 style="margin:0; color:var(--warning);"><i class="fas fa-edit"></i> Edit CC</h3><button type="button" class="g-close-btn" onclick="closeModal('modalEditCC')">&times;</button></div><div class="g-modal-body"><form method="POST"><input type="hidden" name="edit_cc_id" id="e_cc_id"><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Card Nickname</label><input type="text" name="edit_card_name" id="e_cc_name" class="form-input" required></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Bank Name</label><input type="text" name="edit_cc_bank_name" id="e_cc_bank" class="form-input" required></div><div style="display:flex; gap:10px; margin-bottom:5px;"><div class="form-group" style="flex:1;"><label class="form-label">Total Limit (₹)</label><input type="number" name="edit_total_limit" id="e_cc_limit" step="0.01" class="form-input" required oninput="calcEditCC()"></div><div class="form-group" style="flex:1;"><label class="form-label text-red">Current Used (₹)</label><input type="number" name="edit_outstanding" id="e_cc_out" step="0.01" class="form-input" required oninput="calcEditCC()"></div></div><div style="font-size:0.85rem; font-weight:700; color:var(--success); text-align:right; margin-bottom:15px;">Available Limit: ₹<span id="e_cc_avail">0.00</span></div><div style="display:flex; gap:10px; margin-bottom:20px;"><div class="form-group" style="flex:1;"><label class="form-label">Stmt Date (1-31)</label><input type="number" name="edit_statement_date" id="e_cc_sdate" min="1" max="31" class="form-input" required></div><div class="form-group" style="flex:1;"><label class="form-label">Due Date (1-31)</label><input type="number" name="edit_due_date" id="e_cc_ddate" min="1" max="31" class="form-input" required></div></div><button type="submit" name="edit_cc" class="btn btn-primary" style="width:100%; padding:10px;">Update Card</button></form></div></div></div>
    
    <div id="modalCCAction" class="global-modal"><div class="g-modal-content" style="max-width:400px;"><div class="g-modal-header"><h3 style="margin:0; color:#3730a3;"><i class="fas fa-bolt"></i> Card Action</h3><button type="button" class="g-close-btn" onclick="closeModal('modalCCAction')">&times;</button></div><div class="g-modal-body"><form method="POST"><input type="hidden" name="action_card_id" id="act_cc_id"><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Card</label><input type="text" id="act_cc_name" class="form-input" readonly style="background:#f1f5f9; color:#64748b;"></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Action Type</label><select name="cc_action_type" id="cc_action_type" class="form-input" onchange="toggleCCAccountField()" required style="font-weight:700;"><option value="SPEND" style="color:#dc2626;">Card Swiped (Bahar Kharcha)</option><option value="TRANSFER" style="color:#3b82f6;">Transfer to Bank (CC to Bank)</option><option value="PAY" style="color:#16a34a;">Pay CC Bill (Bank to CC)</option></select></div><div class="form-group" id="cc_pay_source" style="margin-bottom:15px; display:none;"><label class="form-label" id="cc_acc_label">Select Account</label><select name="cc_pay_account" class="form-input"><option value="">-- Select Bank/Cash --</option><?php foreach($accounts as $acc) echo "<option value='{$acc['id']}'>{$acc['account_name']} (Bal: ₹{$acc['balance']})</option>"; ?></select></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Amount (₹)</label><input type="number" name="cc_amount" step="0.01" class="form-input" required></div><div class="form-group" style="margin-bottom:20px;"><label class="form-label">Description</label><input type="text" name="cc_desc" class="form-input" placeholder="e.g. Cred Rent / Bill Clear" required></div><button type="submit" name="cc_action_submit" class="btn btn-primary" style="width:100%; padding:10px;">Confirm Action</button></form></div></div></div>

    <div id="modalLoan" class="global-modal"><div class="g-modal-content" style="max-width:450px;"><div class="g-modal-header"><h3 style="margin:0;"><i class="fas fa-hand-holding-usd"></i> Track New Loan</h3><button type="button" class="g-close-btn" onclick="closeModal('modalLoan')">&times;</button></div><div class="g-modal-body"><form method="POST"><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Loan Name</label><input type="text" name="loan_name" class="form-input" required></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-input" required></div><div style="display:flex; gap:10px; margin-bottom:15px;"><div class="form-group" style="flex:1;"><label class="form-label">Principal (₹)</label><input type="number" name="principal_amount" step="0.01" class="form-input" required></div><div class="form-group" style="flex:1;"><label class="form-label">EMI (₹)</label><input type="number" name="emi_amount" step="0.01" class="form-input" required></div></div><div style="display:flex; gap:10px; margin-bottom:15px;"><div class="form-group" style="flex:1;"><label class="form-label">Interest Rate (%)</label><input type="number" name="interest_rate" step="0.01" class="form-input" required></div><div class="form-group" style="flex:1;"><label class="form-label">Total Tenure (Months)</label><input type="number" name="tenure_months" class="form-input" required></div></div><div style="display:flex; gap:10px; margin-bottom:20px;"><div class="form-group" style="flex:1;"><label class="form-label">Loan Start Date</label><input type="date" name="start_date" class="form-input" required></div><div class="form-group" style="flex:1;"><label class="form-label">Next EMI Date</label><input type="date" name="next_emi_date" class="form-input" required></div></div><button type="submit" name="add_loan" class="btn btn-primary" style="width:100%; padding:10px;">Save Loan</button></form></div></div></div>

    <div id="modalPayEmi" class="global-modal"><div class="g-modal-content" style="max-width:400px;"><div class="g-modal-header"><h3 style="margin:0; color:#ef4444;"><i class="fas fa-check-circle"></i> Pay EMI</h3><button type="button" class="g-close-btn" onclick="closeModal('modalPayEmi')">&times;</button></div><div class="g-modal-body"><form method="POST"><input type="hidden" name="pay_loan_id" id="pe_loan_id"><div class="form-group" style="margin-bottom:15px;"><label class="form-label">Paying For</label><input type="text" id="pe_loan_name" class="form-input" readonly style="background:#f1f5f9;"></div><div class="form-group" style="margin-bottom:15px;"><label class="form-label">EMI Amount</label><input type="number" name="pay_emi_amount" id="pe_amount" step="0.01" class="form-input" required></div><div class="form-group" style="margin-bottom:20px;"><label class="form-label">Pay From Account</label><select name="emi_account_id" class="form-input" required><option value="">-- Select Bank/Cash --</option><?php foreach($accounts as $acc) echo "<option value='{$acc['id']}'>{$acc['account_name']} (Bal: ₹{$acc['balance']})</option>"; ?></select></div><button type="submit" name="pay_emi" class="btn btn-primary" style="width:100%; padding:10px; background:#ef4444; border-color:#ef4444;">Confirm Payment</button></form></div></div></div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        // --- SPECIFIC STATEMENT ---
        function openStatement(accId, name) {
            document.getElementById('stmt_name').innerText = name + " - Passbook";
            document.getElementById('stmt_body').innerHTML = '<tr><td colspan="3" style="text-align:center; padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
            openModal('modalStatement');

            fetch('finance.php?get_statement=' + accId)
            .then(res => res.json())
            .then(data => {
                let html = '';
                if(data.length === 0) {
                    html = '<tr><td colspan="3" style="text-align:center; padding:20px; color:#94a3b8;">No transactions found.</td></tr>';
                } else {
                    data.forEach(t => {
                        let colorClass = t.txn_type === 'CREDIT' ? 'text-green' : 'text-red';
                        let sign = t.txn_type === 'CREDIT' ? '+' : '-';
                        html += `<tr>
                            <td style="padding:12px; border-bottom:1px solid #f1f5f9;">${t.date_fmt}</td>
                            <td style="padding:12px; border-bottom:1px solid #f1f5f9;">${t.description}</td>
                            <td style="padding:12px; border-bottom:1px solid #f1f5f9; text-align:right; font-weight:700;" class="${colorClass}">${sign} ₹${parseFloat(t.amount).toFixed(2)}</td>
                        </tr>`;
                    });
                }
                document.getElementById('stmt_body').innerHTML = html;
            });
        }

        // --- EMI SCHEDULE CALCULATION ---
        function openEmiSchedule(loanId, name) {
            document.getElementById('sch_name').innerText = name;
            document.getElementById('sch_body').innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;"><i class="fas fa-spinner fa-spin"></i> Calculating Schedule...</td></tr>';
            openModal('modalEmiSchedule');

            fetch('finance.php?get_emi_schedule=' + loanId)
            .then(res => res.json())
            .then(data => {
                let html = '';
                if(data.length === 0) {
                    html = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;">Missing calculation details (Rate, Tenure, Date). Please update loan.</td></tr>';
                } else {
                    data.forEach(t => {
                        html += `<tr>
                            <td style="padding:12px; border-bottom:1px solid #f1f5f9;">${t.month}</td>
                            <td style="padding:12px; border-bottom:1px solid #f1f5f9; font-weight:700;">₹${t.emi.toLocaleString('en-IN')}</td>
                            <td style="padding:12px; border-bottom:1px solid #f1f5f9; color:#10b981;">₹${t.principal.toLocaleString('en-IN')}</td>
                            <td style="padding:12px; border-bottom:1px solid #f1f5f9; color:#ef4444;">₹${t.interest.toLocaleString('en-IN')}</td>
                            <td style="padding:12px; border-bottom:1px solid #f1f5f9; font-weight:700; color:#0f172a;">₹${t.balance.toLocaleString('en-IN')}</td>
                        </tr>`;
                    });
                }
                document.getElementById('sch_body').innerHTML = html;
            }).catch(err => {
                document.getElementById('sch_body').innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:red;">Failed to load schedule.</td></tr>';
            });
        }

        // --- EDIT TRIGGERS ---
        function openEditFD(fd) {
            document.getElementById('e_fd_id').value = fd.id;
            document.getElementById('e_fd_name').value = fd.fd_name;
            document.getElementById('e_fd_bank').value = fd.bank_name;
            document.getElementById('e_fd_prin').value = fd.principal_amount;
            document.getElementById('e_fd_roi').value = fd.interest_rate;
            document.getElementById('e_fd_start').value = fd.start_date;
            document.getElementById('e_fd_mat').value = fd.maturity_date;
            openModal('modalEditFD');
        }

        function openEditAccount(acc) {
            document.getElementById('e_acc_id').value = acc.id;
            document.getElementById('e_acc_name').value = acc.account_name;
            document.getElementById('e_acc_type').value = acc.account_type;
            openModal('modalEditAccount');
        }

        function openEditCC(cc) {
            document.getElementById('e_cc_id').value = cc.id;
            document.getElementById('e_cc_name').value = cc.card_name;
            document.getElementById('e_cc_bank').value = cc.bank_name;
            document.getElementById('e_cc_limit').value = cc.total_limit;
            let outstanding = parseFloat(cc.total_limit) - parseFloat(cc.available_limit);
            document.getElementById('e_cc_out').value = outstanding.toFixed(2);
            calcEditCC(); 
            document.getElementById('e_cc_sdate').value = cc.statement_date;
            document.getElementById('e_cc_ddate').value = cc.due_date;
            openModal('modalEditCC');
        }

        // --- LIVE CALCS & OTHERS ---
        function calcAddCC() {
            let limit = parseFloat(document.getElementById('add_cc_limit').value) || 0;
            let used = parseFloat(document.getElementById('add_cc_out').value) || 0;
            document.getElementById('add_cc_avail').innerText = (limit - used).toFixed(2);
        }
        function calcEditCC() {
            let limit = parseFloat(document.getElementById('e_cc_limit').value) || 0;
            let used = parseFloat(document.getElementById('e_cc_out').value) || 0;
            document.getElementById('e_cc_avail').innerText = (limit - used).toFixed(2);
        }

        function openPayEMI(id, name, amount) {
            document.getElementById('pe_loan_id').value = id;
            document.getElementById('pe_loan_name').value = name;
            document.getElementById('pe_amount').value = amount;
            openModal('modalPayEmi');
        }

        function openCCAction(id, name) {
            document.getElementById('act_cc_id').value = id;
            document.getElementById('act_cc_name').value = name;
            document.getElementById('cc_action_type').value = "SPEND";
            toggleCCAccountField();
            openModal('modalCCAction');
        }

        function toggleCCAccountField() {
            var type = document.getElementById('cc_action_type').value;
            var accField = document.getElementById('cc_pay_source');
            var accSelect = accField.querySelector('select');
            var accLabel = document.getElementById('cc_acc_label');

            if (type === 'PAY') {
                accField.style.display = 'block';
                accSelect.required = true;
                accLabel.innerText = "Pay Bill From Account (Money OUT)";
            } else if (type === 'TRANSFER') {
                accField.style.display = 'block';
                accSelect.required = true;
                accLabel.innerText = "Receive Money In Account (Money IN)";
            } else {
                accField.style.display = 'none';
                accSelect.required = false;
            }
        }

        function validateTransfer() {
            var from = document.getElementById('trans_from').value;
            var to = document.getElementById('trans_to').value;
            if(from === to) {
                alert("Source and Destination accounts cannot be the same!");
                return false;
            }
            return true;
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('global-modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>