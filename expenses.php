<?php
// expenses.php - MASTER CSS & GLOBAL SHORTCUT READY (Smart Edit Fixed)
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

function formatCurrency($amount)
{
    return '₹' . number_format($amount, 2);
}

// 1. FOLDERS CHECK
if (!file_exists('bills')) {
    mkdir('bills', 0777, true);
}

// ==========================================
// AJAX HANDLERS FOR GLOBAL MODALS
// ==========================================
if (isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');

    // Handle Global Vendor Add
    if ($_POST['action'] == 'save_global_vendor') {
        try {
            $v_name = trim($_POST['new_vendor_name']);
            $v_phone = trim($_POST['new_vendor_phone']);
            if (empty($v_name)) throw new Exception("Vendor Name is required");

            $stmt = $conn->prepare("INSERT INTO sellers (name, phone, category) VALUES (?, ?, 'Packaging')");
            $stmt->bind_param("ss", $v_name, $v_phone);
            if($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Handle Global Expense Add (With Image Upload)
    if ($_POST['action'] == 'save_global_expense') {
        try {
            $id = $_POST['expense_id'] ?? '';
            $date = $_POST['date'];
            $category = $_POST['category'];
            $vendor_id = $_POST['vendor_id'] ?? null;
            $amount = floatval($_POST['amount']);
            $status = $_POST['status'];
            $desc = $_POST['description'] ?? '';
            $pay_mode = $_POST['payment_mode'];
            $invoice = $_POST['invoice_no'];
            $due = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;
            $auth = $_POST['authorized_by'];

            if(empty($vendor_id) || $amount <= 0) throw new Exception("Vendor and Amount are required.");

            $bill_name = $_POST['existing_bill'] ?? '';
            if (isset($_FILES['bill_file']) && $_FILES['bill_file']['error'] == 0) {
                $ext = strtolower(pathinfo($_FILES['bill_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'png', 'pdf', 'jpeg'])) {
                    $bill_name = "Bill_" . time() . "." . $ext;
                    move_uploaded_file($_FILES['bill_file']['tmp_name'], "bills/" . $bill_name);
                }
            }

            if (!empty($id)) {
                $stmt = $conn->prepare("UPDATE factory_expenses SET date=?, category=?, vendor_id=?, amount=?, status=?, description=?, bill_file=?, payment_mode=?, invoice_no=?, due_date=?, authorized_by=? WHERE id=?");
                $stmt->bind_param("ssidsssssssi", $date, $category, $vendor_id, $amount, $status, $desc, $bill_name, $pay_mode, $invoice, $due, $auth, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO factory_expenses (date, category, vendor_id, amount, status, description, bill_file, payment_mode, invoice_no, due_date, authorized_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssidsssssss", $date, $category, $vendor_id, $amount, $status, $desc, $bill_name, $pay_mode, $invoice, $due, $auth);
            }

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}


// 4. DELETE
if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM factory_expenses WHERE id=" . intval($_GET['delete']));
    header("Location: expenses.php?msg=deleted");
    exit;
}

// 5. FETCH STATS
$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

$s_today = $conn->query("SELECT SUM(amount) as total FROM factory_expenses WHERE date = '$today'")->fetch_assoc()['total'] ?? 0;
$s_month = $conn->query("SELECT SUM(amount) as total FROM factory_expenses WHERE MONTH(date) = '$month' AND YEAR(date) = '$year'")->fetch_assoc()['total'] ?? 0;
$s_pending = $conn->query("SELECT SUM(amount) as total FROM factory_expenses WHERE status = 'Pending'")->fetch_assoc()['total'] ?? 0;
$s_total = $conn->query("SELECT SUM(amount) as total FROM factory_expenses")->fetch_assoc()['total'] ?? 0;

$vendors_res = $conn->query("SELECT id, name FROM sellers ORDER BY name ASC");

$search = $_GET['search'] ?? '';
$where = $search ? "WHERE v.name LIKE '%$search%' OR e.category LIKE '%$search%' OR e.invoice_no LIKE '%$search%'" : "";
$expenses = $conn->query("SELECT e.*, v.name as vendor_name FROM factory_expenses e LEFT JOIN sellers v ON e.vendor_id = v.id $where ORDER BY e.date DESC");

// Edit Fetch
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_data = $conn->query("SELECT * FROM factory_expenses WHERE id=" . intval($_GET['edit']))->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Expenses | Trishe Agro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="CSS/admin_style.css">

    <style>
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; overflow-x: hidden; }

        .page-header-box { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin: 0; }
        .action-buttons { display: flex; gap: 12px; }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: #fff; padding: 24px; border-radius: var(--radius); box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid var(--border); position: relative; overflow: hidden; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .stat-icon { position: absolute; top: 20px; right: 20px; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; opacity: 0.2; }
        
        .stat-card.blue .stat-icon { background: rgba(59, 130, 246, 0.1); color: #3b82f6; opacity: 1; }
        .stat-card.green .stat-icon { background: rgba(16, 185, 129, 0.1); color: #10b981; opacity: 1; }
        .stat-card.red .stat-icon { background: rgba(239, 68, 68, 0.1); color: #ef4444; opacity: 1; }
        .stat-card.orange .stat-icon { background: rgba(245, 158, 11, 0.1); color: #f59e0b; opacity: 1; }
        
        .stat-value { font-size: 1.8rem; font-weight: 800; margin-top: 10px; color: var(--text-main); }
        .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        /* Content Card */
        .filter-bar-wrap { padding: 15px 20px; border-bottom: 1px solid var(--border); display: flex; gap: 15px; background: #f8fafc; align-items: center; flex-wrap: wrap; }
        .search-input { flex: 1; min-width: 250px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; outline: none; background: #fff; transition: border-color 0.2s; font-size: 0.9rem; }

        .pay-mode { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; color: var(--text-muted); border: 1px solid var(--border); padding: 4px 10px; border-radius: 6px; font-weight: 600; }

        /* Fix Table Horizontal Scroll */
        .table-wrap table { min-width: 100% !important; }

        @media(max-width: 768px) {
            body { padding-left: 0; }
            .container { padding: 15px; }
            .page-header-box { flex-direction: column; align-items: stretch; text-align: center; }
            .action-buttons { flex-direction: column; width: 100%; }
            .action-buttons .btn { width: 100%; justify-content: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap:10px; }
            .stat-card { padding: 15px; }
            .stat-value { font-size: 1.4rem; }
            
            .filter-bar-wrap { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">

        <?php if (isset($_GET['msg'])): ?>
            <div class="alert" style="padding:15px; background:#dcfce7; color:#166534; border:1px solid #bbf7d0; border-radius:8px; margin-bottom:20px; font-weight:600;">
                <i class="fas fa-check-circle"></i> 
                <?= $_GET['msg'] == 'saved' ? 'Expense/Vendor saved successfully!' : 'Record deleted successfully!' ?>
            </div>
        <?php endif; ?>

        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-wallet text-primary" style="margin-right:10px;"></i> Expense Manager</h1>
            <div class="action-buttons">
                <button class="btn btn-outline" onclick="openGlobalVendorModal()"><i class="fas fa-user-plus"></i> Add Vendor</button>
                <button class="btn btn-primary" onclick="openGlobalExpenseModal()"><i class="fas fa-plus"></i> Add Expense</button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-value"><?= formatCurrency($s_today) ?></div>
                <div class="stat-label">Today's Expense</div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-value"><?= formatCurrency($s_month) ?></div>
                <div class="stat-label">Monthly Expense</div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-value"><?= formatCurrency($s_pending) ?></div>
                <div class="stat-label">Pending Payments</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                <div class="stat-value"><?= formatCurrency($s_total) ?></div>
                <div class="stat-label">Lifetime Total</div>
            </div>
        </div>

        <div class="card">
            <form method="GET" class="filter-bar-wrap">
                <input type="text" name="search" class="search-input" placeholder="Search by Vendor, Invoice No, Category..." value="<?= htmlspecialchars($search) ?>">
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 20px;"><i class="fas fa-search"></i> Search</button>
                    <?php if ($search): ?><a href="expenses.php" class="btn btn-outline" style="padding:10px 20px;">Reset</a><?php endif; ?>
                </div>
            </form>

            <div class="table-wrap" style="border:none; box-shadow:none; border-radius:0;">
                <table>
                    <thead>
                        <tr>
                            <th>Date / Invoice</th>
                            <th>Details</th>
                            <th>Amount</th>
                            <th>Status / Due</th>
                            <th>Payment Info</th>
                            <th>Auth By</th>
                            <th style="text-align:right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($expenses->num_rows > 0): while ($row = $expenses->fetch_assoc()):
                                $is_overdue = ($row['status'] == 'Pending' && $row['due_date'] && $row['due_date'] < date('Y-m-d'));
                        ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700; color:var(--text-main);"><?= date('d M Y', strtotime($row['date'])) ?></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600; margin-top:4px;">
                                            <?= $row['invoice_no'] ? '#' . $row['invoice_no'] : 'No Invoice' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($row['vendor_name'] ?? 'Unknown Vendor') ?></div>
                                        <div style="font-size:0.85rem; color:var(--text-muted); font-weight:500; margin-top:2px;"><?= htmlspecialchars($row['category']) ?></div>
                                        <?php if ($row['description']): ?>
                                            <div style="font-size:0.75rem; color:#94a3b8; margin-top:4px; font-style:italic;">"<?= htmlspecialchars($row['description']) ?>"</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight:800; font-size:1.05rem; color:var(--text-main);"><?= formatCurrency($row['amount']) ?></td>
                                    <td>
                                        <span class="badge <?= $row['status'] == 'Paid' ? 'st-completed' : ($is_overdue ? 'st-due' : 'st-pending') ?>" style="padding:5px 10px;">
                                            <?= $row['status'] ?>
                                        </span>
                                        <?php if ($row['status'] == 'Pending' && $row['due_date']): ?>
                                            <div style="font-size:0.75rem; font-weight:600; margin-top:6px; color:<?= $is_overdue ? 'var(--danger)' : 'var(--text-muted)' ?>">
                                                Due: <?= date('d M', strtotime($row['due_date'])) ?>
                                                <?= $is_overdue ? '(Overdue)' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="pay-mode">
                                            <i class="fas fa-<?= match ($row['payment_mode']) { 'Cash' => 'money-bill-wave', 'UPI' => 'mobile-alt', 'Bank Transfer' => 'university', 'Cheque' => 'money-check', default => 'wallet' }; ?>"></i>
                                            <?= $row['payment_mode'] ?>
                                        </div>
                                    </td>
                                    <td style="font-size:0.85rem; font-weight:600; color:var(--text-muted);"><?= htmlspecialchars($row['authorized_by'] ?? '-') ?></td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <?php if ($row['bill_file']): ?>
                                            <a href="bills/<?= $row['bill_file'] ?>" target="_blank" class="btn-icon" style="color:var(--info);" title="View Bill"><i class="fas fa-paperclip"></i></a>
                                        <?php endif; ?>
                                        <button onclick='editExpense(<?= json_encode($row) ?>)' class="btn-icon" style="color:var(--warning);" title="Edit"><i class="fas fa-edit"></i></button>
                                        <a href="?delete=<?= $row['id'] ?>" class="btn-icon delete" onclick="return confirm('Delete this expense?')" title="Delete"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                            <?php endwhile;
                        else: ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted); font-size:1.1rem;">No expenses found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        if(window.history.replaceState) {
            const url = new URL(window.location); url.searchParams.delete('msg'); window.history.replaceState(null, '', url);
        }

        // --- EDIT EXPENSE (Smart Error-Free Data Push) ---
        function editExpense(data) {
            openGlobalExpenseModal();
            
            const titleEl = document.querySelector('#globalExpenseModal .g-modal-header h3');
            if(titleEl) titleEl.innerHTML = '<i class="fas fa-edit text-warning" style="margin-right:8px;"></i> Edit Expense';
            
            const form = document.getElementById('globalExpenseForm');
            if(!form) return;

            // 1. Safely handle the hidden 'expense_id' field
            let idField = form.querySelector('input[name="expense_id"]');
            if(!idField) {
                idField = document.createElement('input');
                idField.type = 'hidden';
                idField.name = 'expense_id';
                form.appendChild(idField);
            }
            idField.value = data.id;

            // 2. Safe Data Filler Function
            const setVal = (selector, val) => {
                let el = form.querySelector(selector);
                if (el) {
                    el.value = val || '';
                    if(el.tagName === 'SELECT' && el.value !== val && val) {
                        for(let i=0; i < el.options.length; i++) {
                            if(el.options[i].value === val || el.options[i].text === val) {
                                el.selectedIndex = i;
                                break;
                            }
                        }
                    }
                }
            };

            // 3. Fill all fields safely
            setVal('input[name="date"]', data.date);
            setVal('select[name="category"]', data.category);
            setVal('select[name="vendor_id"]', data.vendor_id);
            setVal('input[name="amount"]', data.amount);
            setVal('select[name="status"]', data.status);
            setVal('input[name="description"]', data.description);
            setVal('select[name="payment_mode"]', data.payment_mode || 'Cash');
            setVal('input[name="invoice_no"]', data.invoice_no);
            setVal('input[name="due_date"]', data.due_date);
            setVal('input[name="authorized_by"]', data.authorized_by);

            // 4. Safely handle existing bill file hidden input
            let existingBillInput = form.querySelector('input[name="existing_bill"]');
            if(!existingBillInput) {
                existingBillInput = document.createElement('input');
                existingBillInput.type = 'hidden';
                existingBillInput.name = 'existing_bill';
                form.appendChild(existingBillInput);
            }
            existingBillInput.value = data.bill_file || '';
        }

        <?php if ($edit_data): ?> 
            editExpense(<?= json_encode($edit_data) ?>);
        <?php endif; ?>
    </script>
</body>
</html>