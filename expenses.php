<?php
// =================================================================
// 1. BACKEND LOGIC
// =================================================================
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

// 2. HANDLE NEW VENDOR (NOW SAVING TO SELLERS TABLE)
if (isset($_POST['save_vendor'])) {
    $v_name = trim($_POST['new_vendor_name']);
    $v_phone = trim($_POST['new_vendor_phone']);

    // Default category 'Expense' ya 'Both' set kar rahe hain taaki pata rahe
    if (!empty($v_name)) {
        // Check columns of your sellers table. Assuming: name, phone, category
        $stmt = $conn->prepare("INSERT INTO sellers (name, phone, category) VALUES (?, ?, 'Packaging')");
        $stmt->bind_param("ss", $v_name, $v_phone);
        $stmt->execute();
    }
    header("Location: expenses.php");
    exit;
}

// 3. HANDLE EXPENSE (ADD/UPDATE)
$msg = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_expense'])) {
    $id = $_POST['expense_id'];
    $date = $_POST['date'];
    $category = $_POST['category'];
    $vendor_id = $_POST['vendor_id'] ?? null; // Now refers to sellers.id
    $amount = $_POST['amount'];
    $status = $_POST['status'];
    $desc = $_POST['description'] ?? '';

    // NEW FIELDS
    $pay_mode = $_POST['payment_mode'];
    $invoice = $_POST['invoice_no'];
    $due = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;
    $auth = $_POST['authorized_by'];

    $bill_name = $_POST['existing_bill'];
    if (isset($_FILES['bill_file']) && $_FILES['bill_file']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['bill_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'png', 'pdf'])) {
            $bill_name = "Bill_" . time() . "." . $ext;
            move_uploaded_file($_FILES['bill_file']['tmp_name'], "bills/" . $bill_name);
        }
    }

    if ($vendor_id) {
        if (!empty($id)) {
            $stmt = $conn->prepare("UPDATE factory_expenses SET date=?, category=?, vendor_id=?, amount=?, status=?, description=?, bill_file=?, payment_mode=?, invoice_no=?, due_date=?, authorized_by=? WHERE id=?");
            $stmt->bind_param("ssidsssssssi", $date, $category, $vendor_id, $amount, $status, $desc, $bill_name, $pay_mode, $invoice, $due, $auth, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO factory_expenses (date, category, vendor_id, amount, status, description, bill_file, payment_mode, invoice_no, due_date, authorized_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssidsssssss", $date, $category, $vendor_id, $amount, $status, $desc, $bill_name, $pay_mode, $invoice, $due, $auth);
        }

        if ($stmt->execute()) {
            header("Location: expenses.php?msg=saved");
            exit;
        }
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


// 6. FETCH LIST (UPDATED: Show ONLY Service Providers)

$vendors_res = $conn->query("SELECT id, name FROM sellers  ORDER BY name ASC");

$search = $_GET['search'] ?? '';
// Note: Changed alias 'v' to refer to 'sellers' table
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses | Trishe Agro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* UNIVERSAL VARIABLES (Match admin_products.php) */
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --secondary: #3b82f6;
            --bg-body: #f3f4f6;
            --surface: #ffffff;
            --text-main: #0c0d0e;
            --text-light: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            --danger: #ef4444;
            --warning: #f59e0b;
            --radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            color: var(--text-main);
            font-size: 14px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
        }

        .btn {
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-outline {
            background: white;
            border: 1px solid var(--border);
            color: var(--text-main);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--surface);
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .stat-icon {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            opacity: 0.2;
        }

        .stat-card.customers .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            opacity: 1;
        }

        .stat-card.income .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            opacity: 1;
        }

        .stat-card.expense .stat-icon {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            opacity: 1;
        }

        .stat-card.profit .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            opacity: 1;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
            margin-top: 10px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }

        /* Content Card */
        .content-card {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .filter-bar {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 10px;
            background: #f9fafb;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 200px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            outline: none;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            text-align: left;
            padding: 14px 20px;
            background: #f9fafb;
            color: var(--text-light);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
            vertical-align: middle;
            font-size: 14px;
        }

        tr:hover {
            background: #f9fafb;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Overdue */

        .pay-mode {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: var(--text-light);
            border: 1px solid var(--border);
            padding: 2px 8px;
            border-radius: 6px;
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 50;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }

        .modal-box {
            background: white;
            width: 95%;
            max-width: 700px;
            border-radius: 12px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            animation: slideDown 0.3s;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            background: #f9fafb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        @media(max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 6px;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            outline: none;
            background: white;
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">

        <div class="page-header">
            <h1 class="page-title">Expense Manager</h1>
            <div style="display:flex; gap:10px;">
                <button class="btn btn-outline" onclick="openVendorModal()">+ Add Vendor (Seller)</button>
                <button class="btn btn-primary" onclick="openExpenseModal()">+ Add Expense</button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card customers">
                <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-value"><?= formatCurrency($s_today) ?></div>
                <div class="stat-label">Today's Expense</div>
            </div>
            <div class="stat-card income">
                <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-value"><?= formatCurrency($s_month) ?></div>
                <div class="stat-label">Monthly Expense</div>
            </div>
            <div class="stat-card expense">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-value"><?= formatCurrency($s_pending) ?></div>
                <div class="stat-label">Pending Payments</div>
                <div class="stat-subtext" style="color:var(--danger)">Unpaid Bills</div>
            </div>
            <div class="stat-card profit">
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                <div class="stat-value"><?= formatCurrency($s_total) ?></div>
                <div class="stat-label">Lifetime Total</div>
            </div>
        </div>

        <div class="content-card">

            <form method="GET" class="filter-bar">
                <input type="text" name="search" class="search-input" placeholder="Search by Vendor, Invoice No, Category..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($search): ?><a href="expenses.php" class="btn btn-outline">Reset</a><?php endif; ?>
            </form>

            <div class="table-responsive">
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
                                        <div style="font-weight:600"><?= date('d M Y', strtotime($row['date'])) ?></div>
                                        <div style="font-size:12px; color:var(--text-light)">
                                            <?= $row['invoice_no'] ? '#' . $row['invoice_no'] : 'No Invoice' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600"><?= htmlspecialchars($row['vendor_name'] ?? 'Unknown Vendor') ?></div>
                                        <div style="font-size:12px; color:var(--text-light)"><?= htmlspecialchars($row['category']) ?></div>
                                        <?php if ($row['description']): ?>
                                            <div style="font-size:11px; color:#888; margin-top:2px;">"<?= htmlspecialchars($row['description']) ?>"</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight:700"><?= formatCurrency($row['amount']) ?></td>
                                    <td>
                                        <span class="badge <?= $row['status'] == 'Paid' ? 'badge-success' : ($is_overdue ? 'badge-danger' : 'badge-warning') ?>">
                                            <?= $row['status'] ?>
                                        </span>
                                        <?php if ($row['status'] == 'Pending' && $row['due_date']): ?>
                                            <div style="font-size:11px; margin-top:4px; color:<?= $is_overdue ? 'var(--danger)' : 'var(--text-light)' ?>">
                                                Due: <?= date('d M', strtotime($row['due_date'])) ?>
                                                <?= $is_overdue ? '(Overdue)' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="pay-mode">
                                            <i class="fas fa-<?php
                                                                echo match ($row['payment_mode']) {
                                                                    'Cash' => 'money-bill-wave',
                                                                    'UPI' => 'mobile-alt',
                                                                    'Bank Transfer' => 'university',
                                                                    'Cheque' => 'money-check',
                                                                    default => 'wallet'
                                                                };
                                                                ?>"></i>
                                            <?= $row['payment_mode'] ?>
                                        </div>
                                    </td>
                                    <td style="font-size:13px;"><?= htmlspecialchars($row['authorized_by'] ?? '-') ?></td>
                                    <td style="text-align:right">
                                        <?php if ($row['bill_file']): ?>
                                            <a href="bills/<?= $row['bill_file'] ?>" target="_blank" class="btn btn-outline" style="padding:5px 8px; font-size:12px;"><i class="fas fa-paperclip"></i></a>
                                        <?php endif; ?>
                                        <button onclick='editExpense(<?= json_encode($row) ?>)' class="btn btn-outline" style="padding:5px 8px;"><i class="fas fa-edit"></i></button>
                                        <a href="?delete=<?= $row['id'] ?>" class="btn btn-outline" style="padding:5px 8px; color:var(--danger); border-color:var(--danger)" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                            <?php endwhile;
                        else: ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:30px; color:var(--text-light)">No expenses found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="expenseModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Expense</h3>
                <span onclick="closeModal('expenseModal')" style="cursor:pointer; font-size:24px">&times;</span>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="expense_id" id="exp_id">
                    <input type="hidden" name="existing_bill" id="exp_bill">

                    <h4 style="font-size:12px; text-transform:uppercase; color:var(--primary); margin-bottom:10px;">Basic Info</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Expense Date</label>
                            <input type="date" name="date" id="exp_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" id="exp_cat" class="form-control">
                                <optgroup label="Production">
                                    <option>Raw Material</option>
                                    <option>Packing Material</option>
                                    <option>Labor</option>
                                    <option>Fuel (Diesel/Wood)</option>
                                </optgroup>
                                <optgroup label="Factory Overheads">
                                    <option>Electricity Bill</option>
                                    <option>Factory Rent</option>
                                    <option>Water Bill</option>
                                    <option>Maintenance & Repair</option>
                                </optgroup>
                                <optgroup label="Others">
                                    <option>Transport</option>
                                    <option>Salary (Staff)</option>
                                    <option>Tea/Snacks (Office)</option>
                                    <option>Other</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Vendor (Seller)</label>
                            <select name="vendor_id" id="exp_vendor" class="form-control" required>
                                <option value="">Select Vendor</option>
                                <?php $vendors_res->data_seek(0);
                                while ($v = $vendors_res->fetch_assoc()) echo "<option value='{$v['id']}'>{$v['name']}</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Authorized By</label>
                            <input type="text" name="authorized_by" id="exp_auth" class="form-control" placeholder="Manager Name">
                        </div>
                    </div>

                    <h4 style="font-size:12px; text-transform:uppercase; color:var(--primary); margin:15px 0 10px;">Payment Details</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Amount (₹)</label>
                            <input type="number" step="0.01" name="amount" id="exp_amount" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Invoice / Bill No</label>
                            <input type="text" name="invoice_no" id="exp_invoice" class="form-control" placeholder="INV-001">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="exp_status" class="form-control">
                                <option value="Pending">Pending</option>
                                <option value="Paid">Paid</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Due Date (If Pending)</label>
                            <input type="date" name="due_date" id="exp_due" class="form-control">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode" id="exp_pay_mode" class="form-control">
                                <option>Cash</option>
                                <option>UPI</option>
                                <option>Bank Transfer</option>
                                <option>Cheque</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Bill File (Img/PDF)</label>
                            <input type="file" name="bill_file" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description / Note</label>
                        <input type="text" name="description" id="exp_desc" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('expenseModal')">Cancel</button>
                    <button type="submit" name="save_expense" class="btn btn-primary">Save Expense</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="vendorModal">
        <div class="modal-box" style="max-width: 400px;">
            <div class="modal-header">
                <h3>Add New Vendor (Seller)</h3>
                <span onclick="closeModal('vendorModal')" style="cursor:pointer; font-size:24px">&times;</span>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Vendor Name</label>
                        <input type="text" name="new_vendor_name" class="form-control" required placeholder="e.g. Ramesh Traders">
                    </div>
                    <div class="form-group" style="margin-top:10px;">
                        <label>Phone No (Optional)</label>
                        <input type="text" name="new_vendor_phone" class="form-control" placeholder="9876543210">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="save_vendor" class="btn btn-primary">Save Vendor</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openExpenseModal() {
            document.getElementById('expenseModal').style.display = 'flex';
            document.getElementById('modalTitle').innerText = 'Add New Expense';
            document.getElementById('exp_id').value = '';
            document.getElementById('exp_amount').value = '';
            // Reset other fields as needed
        }

        function openVendorModal() {
            document.getElementById('vendorModal').style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function editExpense(data) {
            openExpenseModal();
            document.getElementById('modalTitle').innerText = 'Edit Expense';
            document.getElementById('exp_id').value = data.id;
            document.getElementById('exp_date').value = data.date;
            document.getElementById('exp_cat').value = data.category;
            document.getElementById('exp_vendor').value = data.vendor_id;
            document.getElementById('exp_amount').value = data.amount;
            document.getElementById('exp_status').value = data.status;
            document.getElementById('exp_desc').value = data.description;
            document.getElementById('exp_bill').value = data.bill_file;
            // New Fields
            document.getElementById('exp_pay_mode').value = data.payment_mode;
            document.getElementById('exp_invoice').value = data.invoice_no;
            document.getElementById('exp_due').value = data.due_date;
            document.getElementById('exp_auth').value = data.authorized_by;
        }

        <?php if ($edit_data): ?> editExpense(<?= json_encode($edit_data) ?>);
        <?php endif; ?>

        window.onclick = function(e) {
            if (e.target.classList.contains('modal-overlay')) e.target.style.display = 'none';
        }
    </script>
</body>

</html>