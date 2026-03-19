<?php
// add_seed.php - Add New Raw Material (Aligned with Database Image)
include 'config.php';
session_start();

// Security Check
if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

// Handle Form Submission
$message = "";
$msg_type = ""; // success or error

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $category = trim($_POST['category']);
    // Database accepts decimal(10,3), so we allow float input
    $stock = !empty($_POST['current_stock']) ? floatval($_POST['current_stock']) : 0.000;

    if (empty($name) || empty($category)) {
        $message = "Please fill all required fields.";
        $msg_type = "error";
    } else {
        // 1. Check if Seed Name already exists
        $checkStmt = $conn->prepare("SELECT id FROM seeds_master WHERE name = ?");
        $checkStmt->bind_param("s", $name);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $message = "Error: A seed with this name already exists!";
            $msg_type = "error";
        } else {
            // 2. Insert New Seed
            // Note: 'created_at' is automatic (current_timestamp) in your DB structure, so we don't need to pass it.
            $stmt = $conn->prepare("INSERT INTO seeds_master (name, category, current_stock) VALUES (?, ?, ?)");
            $stmt->bind_param("ssd", $name, $category, $stock);

            if ($stmt->execute()) {
                $message = "New Seed Added Successfully!";
                $msg_type = "success";
            } else {
                $message = "Database Error: " . $stmt->error;
                $msg_type = "error";
            }
            $stmt->close();
        }
        $checkStmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New Seed | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* --- MODERN CSS VARIABLES (Consistent with your theme) --- */
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 10px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding-bottom: 60px;
        }

        .container {
            max-width: 550px;
            margin: 50px auto;
            padding: 20px;
        }

        /* CARD STYLE */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .card-header {
            padding: 25px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            text-align: center;
        }

        .card-header h2 {
            margin: 0;
            font-size: 1.4rem;
            color: var(--text-main);
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .card-header p {
            margin: 5px 0 0;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .card-body { padding: 30px; }

        /* FORM ELEMENTS */
        .form-group { margin-bottom: 20px; }

        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            font-size: 0.95rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: #fff;
            box-sizing: border-box;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* BUTTONS */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover { background-color: var(--primary-hover); }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 25px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .back-link:hover { color: var(--primary); text-decoration: underline; }

        /* ALERT MESSAGES */
        .alert {
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    </style>
</head>
<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        
        <?php if(!empty($message)): ?>
            <div class="alert alert-<?= $msg_type ?>">
                <?php if($msg_type == 'success'): ?>
                    <i class="fas fa-check-circle"></i>
                <?php else: ?>
                    <i class="fas fa-exclamation-triangle"></i>
                <?php endif; ?>
                <?= $message ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-seedling" style="color:var(--primary);"></i> Add New Material</h2>
                <p>Register a new item in Seed Master</p>
            </div>
            
            <div class="card-body">
                <form action="" method="POST" autocomplete="off">
                    
                    <div class="form-group">
                        <label class="form-label">Item Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Mustard Seeds (Sarson)" required autofocus>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category (Type) *</label>
                        <select name="category" class="form-control" required>
                            <option value="" disabled selected>-- Select Type --</option>
                            <option value="oilseed">Oilseed (For crushing)</option>
                            <option value="cereal">Cereal (Grain)</option>
                            <option value="pulse">Pulse (Dal)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Opening Stock (Optional)</label>
                        <div style="position: relative;">
                            <input type="number" name="current_stock" class="form-control" placeholder="0.000" step="0.001" min="0">
                            <span style="position: absolute; right: 15px; top: 12px; color: #999; font-size: 0.9rem;">Kg</span>
                        </div>
                        <small style="color:var(--text-muted); font-size:0.8rem; margin-top:5px; display:block;">
                            Leave empty if starting with 0 stock.
                        </small>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save to Database
                    </button>

                </form>
            </div>
        </div>

        <a href="admin_inventory.php" class="back-link">&larr; Back to Inventory Dashboard</a>

    </div>

    <script>
        // Auto-hide success message after 3 seconds
        setTimeout(function() {
            const alertBox = document.querySelector('.alert-success');
            if(alertBox) {
                alertBox.style.transition = "opacity 0.5s ease";
                alertBox.style.opacity = "0";
                setTimeout(() => alertBox.remove(), 500);
            }
        }, 3000);
    </script>

</body>
</html>