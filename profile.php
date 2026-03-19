<?php
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit();
}

$msg = "";

// 1. FOLDER CHECK
if (!file_exists('uploads/logo')) {
    mkdir('uploads/logo', 0777, true);
}

// 2. HANDLE FORM SUBMISSION
if (isset($_POST['save_settings'])) {
    $brand_name = $conn->real_escape_string(trim($_POST['brand_name']));
    $gst_no = $conn->real_escape_string(trim($_POST['gst_no']));
    $mobile_no = $conn->real_escape_string(trim($_POST['mobile_no']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $address = $conn->real_escape_string(trim($_POST['address']));

    // Naye Bank Fields
    $bank_name = $conn->real_escape_string(trim($_POST['bank_name']));
    $account_name = $conn->real_escape_string(trim($_POST['account_name']));
    $account_no = $conn->real_escape_string(trim($_POST['account_no']));
    $ifsc_code = $conn->real_escape_string(trim($_POST['ifsc_code']));
    $upi_id = $conn->real_escape_string(trim($_POST['upi_id']));

    $logo_query = "";
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $logo_name = "logo_" . time() . "." . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], "uploads/logo/" . $logo_name)) {
                $logo_query = ", logo = '$logo_name'";
            }
        } else {
            $msg = "<div class='alert alert-danger'>Sirf JPG, JPEG, ya PNG format hi allowed hai.</div>";
        }
    }

    $sql = "UPDATE company_settings SET 
            brand_name = '$brand_name', 
            gst_no = '$gst_no', 
            mobile_no = '$mobile_no', 
            email = '$email', 
            address = '$address', 
            bank_name = '$bank_name', 
            account_name = '$account_name', 
            account_no = '$account_no', 
            ifsc_code = '$ifsc_code', 
            upi_id = '$upi_id'
            $logo_query 
            WHERE id = 1";

    if ($conn->query($sql)) {
        $msg = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> Company Details & Bank Info successfully update ho gayi!</div>";
    } else {
        $msg = "<div class='alert alert-danger'>Error: " . $conn->error . "</div>";
    }
}

// 3. FETCH CURRENT DATA
$settings = $conn->query("SELECT * FROM company_settings WHERE id = 1")->fetch_assoc();
if (!$settings) {
    $settings = []; // blank array if no data
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Company Settings | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --bg-body: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --success: #10b981;
            --radius: 12px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            padding-bottom: 80px;
            padding-left: 260px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        @media (max-width: 1024px) {
            body {
                padding-left: 0;
            }

            .container {
                padding: 15px;
            }
        }

        .page-header {
            display: flex;
            align-items: center;
            margin-bottom: 24px;
            gap: 12px;
        }

        .page-title {
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0;
            color: var(--text-main);
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            padding: 25px;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            font-size: 1rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #f8fafc;
            outline: none;
            transition: 0.2s;
            color: var(--text-main);
        }

        .form-control:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            justify-content: center;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .logo-preview-box {
            display: flex;
            gap: 20px;
            align-items: center;
            margin-bottom: 30px;
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            border: 1px dashed var(--border);
        }

        .logo-preview {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: white;
            border: 1px solid var(--border);
        }

        .logo-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .section-title {
            margin-top: 0;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
            margin-bottom: 20px;
            color: var(--text-main);
            font-size: 1.2rem;
        }

        @media (max-width: 576px) {
            .logo-preview-box {
                flex-direction: column;
                align-items: flex-start;
            }

            .btn-primary {
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header">
            <i class="fas fa-building fa-2x" style="color:var(--primary);"></i>
            <h1 class="page-title">Company Settings</h1>
        </div>

        <?= $msg ?>

        <div class="card">
            <form method="POST" enctype="multipart/form-data">

                <div class="logo-preview-box">
                    <div class="logo-preview">
                        <?php if (!empty($settings['logo'])): ?>
                            <img src="uploads/logo/<?= $settings['logo'] ?>" alt="Logo">
                        <?php else: ?>
                            <div style="color:var(--text-secondary); font-size:0.8rem; text-align:center;">
                                <i class="fas fa-image fa-2x" style="opacity:0.5; margin-bottom:5px;"></i><br>No Logo
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group" style="flex:1; margin:0;">
                        <label style="color:var(--text-main); font-size:1rem;">Brand Logo</label>
                        <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:10px;">Ye logo aapke Invoices aur Bills par print hoga. (Max size 2MB, JPG/PNG)</p>
                        <input type="file" name="logo" class="form-control" accept="image/*" style="background:white;">
                    </div>
                </div>

                <h3 class="section-title"><i class="fas fa-info-circle text-secondary"></i> Business Details</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Brand / Company Name *</label>
                        <input type="text" name="brand_name" class="form-control" value="<?= htmlspecialchars($settings['brand_name'] ?? 'Trishe Agro') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>GST Number</label>
                        <input type="text" name="gst_no" class="form-control" value="<?= htmlspecialchars($settings['gst_no'] ?? '') ?>" placeholder="e.g. 08AAAAA0000A1Z5" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Mobile Number</label>
                        <input type="text" name="mobile_no" class="form-control" value="<?= htmlspecialchars($settings['mobile_no'] ?? '') ?>" placeholder="For Bills & Invoices">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($settings['email'] ?? '') ?>" placeholder="info@company.com">
                    </div>
                </div>
                <div class="form-group">
                    <label>Full Business Address</label>
                    <textarea name="address" class="form-control" placeholder="Office / Factory Address..."><?= htmlspecialchars($settings['address'] ?? '') ?></textarea>
                </div>

                <h3 class="section-title" style="margin-top: 30px;"><i class="fas fa-university text-secondary"></i> Payment & Bank Details</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($settings['bank_name'] ?? '') ?>" placeholder="e.g. State Bank of India">
                    </div>
                    <div class="form-group">
                        <label>Account Holder Name</label>
                        <input type="text" name="account_name" class="form-control" value="<?= htmlspecialchars($settings['account_name'] ?? '') ?>" placeholder="e.g. Trishe Agro">
                    </div>
                    <div class="form-group">
                        <label>Account Number</label>
                        <input type="text" name="account_no" class="form-control" value="<?= htmlspecialchars($settings['account_no'] ?? '') ?>" placeholder="e.g. 30000123456789">
                    </div>
                    <div class="form-group">
                        <label>IFSC Code</label>
                        <input type="text" name="ifsc_code" class="form-control" value="<?= htmlspecialchars($settings['ifsc_code'] ?? '') ?>" placeholder="e.g. SBIN0001234" style="text-transform: uppercase;">
                    </div>
                </div>
                <div class="form-group">
                    <label>UPI ID (Optional)</label>
                    <input type="text" name="upi_id" class="form-control" value="<?= htmlspecialchars($settings['upi_id'] ?? '') ?>" placeholder="e.g. trisheagro@sbi">
                </div>

                <hr style="border:0; border-top:1px solid var(--border); margin:30px 0 20px;">

                <div style="text-align: right;">
                    <button type="submit" name="save_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save All Details
                    </button>
                </div>
            </form>
        </div>
    </div>

</body>

</html>