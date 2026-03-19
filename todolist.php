<?php
session_start();

// 1. Initialize Transactions in Session
if (!isset($_SESSION['finance_transactions'])) {
    $_SESSION['finance_transactions'] = [
        ['id' => 1, 'date' => date('Y-m-d'), 'description' => 'Sale of Finished Goods', 'type' => 'Income', 'amount' => 50000.00],
        ['id' => 2, 'date' => date('Y-m-d'), 'description' => 'Raw Material Purchase', 'type' => 'Expense', 'amount' => 15000.00],
        ['id' => 3, 'date' => date('Y-m-d'), 'description' => 'Working Capital Loan', 'type' => 'borrow', 'amount' => 10000.00],
    ];
}

$transactions = $_SESSION['finance_transactions'];

// 2. Handle Form Actions (Add, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        // Add Transaction
        if ($action === 'add' && !empty($_POST['description']) && !empty($_POST['amount'])) {
            $amount = floatval($_POST['amount']);
            // Ensure amount is positive and not zero
            if ($amount > 0) {
                $newTransaction = [
                    'id' => time(),
                    'date' => htmlspecialchars($_POST['date']),
                    'description' => htmlspecialchars($_POST['description']),
                    'type' => htmlspecialchars($_POST['type']),
                    'amount' => $amount
                ];
                // Add to array
                array_unshift($_SESSION['finance_transactions'], $newTransaction);
            }
        }

        // Delete Transaction
        if ($action === 'delete' && isset($_POST['id'])) {
            $_SESSION['finance_transactions'] = array_filter($_SESSION['finance_transactions'], function ($t) {
                return $t['id'] != $_POST['id'];
            });
        }
    }
    // Redirect to prevent form resubmission on refresh
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 3. Calculate Summary
$totalIncome = 0;
$totalExpense = 0;
$totalBorrow = 0; // New: To track borrowed funds separately

foreach ($transactions as $t) {
    if ($t['type'] === 'Income') {
        $totalIncome += $t['amount'];
    } elseif ($t['type'] === 'Expense') {
        $totalExpense += $t['amount'];
    } elseif ($t['type'] === 'borrow') {
        $totalBorrow += $t['amount'];
    }
}

// Net Balance: Income - Expense + Borrowing (as it adds to current cash)
$netBalance = $totalIncome - $totalExpense + $totalBorrow;

// Function to format currency
function formatCurrency($amount)
{
    return '₹ ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="hi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>वित्तीय रिकॉर्ड ट्रैकर (उधार सहित)</title>
    <!-- FontAwesome for Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        /* --- Pure CSS Styling --- */
        :root {
            --bg-body: #f9fafb;
            --bg-card: #ffffff;
            --text-main: #111827;
            --text-muted: #6b7280;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --danger: #ef4444;
            --success: #22c55e;
            --border: #e5e7eb;
            --income-color: #10b981;
            /* Green */
            --expense-color: #f87171;
            /* Red */
            --borrow-color: #f97316;
            /* Orange for Borrow/Liability */
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
            line-height: 1.5;
        }

        .container {
            max-width: 1100px;
            /* Increased max width for 4 summary cards */
            margin: 0 auto;
            padding: 20px 0;
        }

        /* Header */
        .header {
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border);
            padding-bottom: 1rem;
        }

        .header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2rem;
            color: var(--primary);
        }

        .header p {
            margin: 0;
            color: var(--text-muted);
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            /* Auto-fit for flexible 4 column layout, collapsing on small screens */
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .summary-card h3 {
            margin: 0 0 0.5rem 0;
            font-size: 0.9rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .summary-card .amount {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .income .amount {
            color: var(--income-color);
        }

        .expense .amount {
            color: var(--expense-color);
        }

        .borrow .amount {
            color: var(--borrow-color);
        }

        /* New borrow color */

        .balance-positive .amount {
            color: var(--success);
        }

        .balance-negative .amount {
            color: var(--danger);
        }

        .balance-zero .amount {
            color: var(--text-main);
        }


        /* Form */
        .form-section {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .form-section h2 {
            font-size: 1.25rem;
            margin-top: 0;
            margin-bottom: 1.25rem;
            color: var(--text-main);
        }

        .add-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 0.85rem;
            margin-bottom: 4px;
            color: var(--text-muted);
        }

        .form-group input,
        .form-group select {
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--primary);
        }

        .submit-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background 0.2s;
            grid-column: span 1;
            /* Ensure button occupies one column */
        }

        .submit-btn:hover {
            background: var(--primary-hover);
        }

        /* Transaction List */
        .list-section h2 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--text-main);
        }

        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-card);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .transaction-table th,
        .transaction-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .transaction-table th {
            background: #f3f4f6;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            text-transform: uppercase;
        }

        .transaction-table tr:last-child td {
            border-bottom: none;
        }

        .type-income {
            color: var(--income-color);
            font-weight: 600;
        }

        .type-expense {
            color: var(--expense-color);
            font-weight: 600;
        }

        .type-borrow {
            color: var(--borrow-color);
            font-weight: 600;
        }

        /* New borrow styling */

        .delete-btn {
            background: none;
            border: none;
            color: #d1d5db;
            cursor: pointer;
            font-size: 1rem;
            transition: color 0.2s;
        }

        .delete-btn:hover {
            color: var(--danger);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
                /* 2 columns on tablet/mobile */
            }

            .add-form {
                grid-template-columns: 1fr;
            }

            /* Hide date on very small screens for better description space */
            .transaction-table th:nth-child(2),
            .transaction-table td:nth-child(2) {
                display: none;
            }
        }
    </style>
</head>

<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">

        <!-- Header -->
        <div class="header">
            <h1>वित्तीय रिकॉर्ड ट्रैकर (Finance Tracker)</h1>
            <p>अपनी फैक्ट्री के सभी लेन-देन का रिकॉर्ड रखें। (Maintain records of all your factory transactions.)</p>
        </div>

        <!-- Summary Section -->
        <div class="summary-grid">

            <div class="summary-card income">
                <h3>कुल आमदनी (Total Income)</h3>
                <p class="amount"><?php echo formatCurrency($totalIncome); ?></p>
            </div>

            <div class="summary-card expense">
                <h3>कुल खर्च (Total Expense)</h3>
                <p class="amount"><?php echo formatCurrency($totalExpense); ?></p>
            </div>

            <div class="summary-card borrow">
                <h3>कुल उधार (Total Borrowing)</h3>
                <p class="amount"><?php echo formatCurrency($totalBorrow); ?></p>
            </div>

            <?php
            $balanceClass = 'balance-zero';
            if ($netBalance > 0) $balanceClass = 'balance-positive';
            if ($netBalance < 0) $balanceClass = 'balance-negative';
            ?>
            <div class="summary-card <?php echo $balanceClass; ?>">
                <h3>शुद्ध बैलेंस (Net Balance)</h3>
                <p class="amount"><?php echo formatCurrency($netBalance); ?></p>
            </div>
        </div>

        <!-- Add Transaction Form -->
        <div class="form-section">
            <h2>नया लेन-देन जोड़ें (Add New Transaction)</h2>
            <form method="POST" class="add-form">
                <input type="hidden" name="action" value="add">

                <div class="form-group">
                    <label for="date">तारीख (Date)</label>
                    <input type="date" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label for="description">विवरण (Description)</label>
                    <input type="text" id="description" name="description" placeholder="उदा. माल बेचा / किराया दिया" required>
                </div>

                <div class="form-group">
                    <label for="type">प्रकार (Type)</label>
                    <select id="type" name="type" required>
                        <option value="Income">आमदनी (Income)</option>
                        <option value="Expense">खर्च (Expense)</option>
                        <option value="borrow">उधार (Borrow)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount">राशि (Amount)</label>
                    <input type="number" id="amount" name="amount" placeholder="0.00" step="0.01" required min="0.01">
                </div>

                <button type="submit" class="submit-btn">रिकॉर्ड सेव करें (Save Record)</button>
            </form>
        </div>

        <!-- Transaction List -->
        <div class="list-section">
            <h2>सभी लेन-देन (All Transactions)</h2>

            <?php if (!empty($transactions)): ?>
                <table class="transaction-table">
                    <thead>
                        <tr>
                            <th>तारीख (Date)</th>
                            <th>विवरण (Description)</th>
                            <th>प्रकार (Type)</th>
                            <th>राशि (Amount)</th>
                            <th>हटाएँ (Action)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $t):
                            $typeClass = 'type-' . strtolower($t['type']);
                        ?>
                            <tr>
                                <td><?php echo date('d M, Y', strtotime($t['date'])); ?></td>
                                <td><?php echo htmlspecialchars($t['description']); ?></td>
                                <td class="<?php echo $typeClass; ?>"><?php echo $t['type']; ?></td>
                                <td class="<?php echo $typeClass; ?>"><?php echo formatCurrency($t['amount']); ?></td>
                                <td>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                        <button type="submit" class="delete-btn" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-muted); padding: 20px;">अभी तक कोई लेन-देन रिकॉर्ड नहीं किया गया है। (No transactions recorded yet.)</p>
            <?php endif; ?>
        </div>

    </div>
</body>

</html>