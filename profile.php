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
            $msg = "<div class='alert' style='background:#fee2e2; color:#b91c1c; border-color:#fca5a5;'><i class='fas fa-exclamation-triangle'></i> Sirf JPG, JPEG, ya PNG format hi allowed hai.</div>";
        }
    }

    if (empty($msg)) {
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
            $msg = "<div class='alert' style='background:#dcfce7; color:#166534; border-color:#bbf7d0;'><i class='fas fa-check-circle'></i> Company Details & Bank Info successfully update ho gayi!</div>";
        } else {
            $msg = "<div class='alert' style='background:#fee2e2; color:#b91c1c; border-color:#fca5a5;'><i class='fas fa-exclamation-circle'></i> Error: " . $conn->error . "</div>";
        }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            overflow-x: hidden;
        }

        .page-header-box {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
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
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            flex-shrink: 0;
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
            font-size: 1.1rem;
            font-weight: 700;
        }

        textarea.form-input {
            resize: vertical;
            min-height: 80px;
        }

        @media (max-width: 768px) {
            body {
                padding-left: 0;
            }

            .container {
                padding: 15px;
            }

            .page-header-box {
                text-align: center;
                justify-content: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .logo-preview-box {
                flex-direction: column;
                align-items: flex-start;
            }

            .submit-btn-wrap {
                width: 100%;
                display: flex;
            }

            .submit-btn-wrap .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-building text-primary"></i> Company Settings</h1>
        </div>

        <?= $msg ?>

        <div class="card" style="padding:30px;">
            <form method="POST" enctype="multipart/form-data">

                <div class="logo-preview-box">
                    <div class="logo-preview">
                        <?php if (!empty($settings['logo'])): ?>
                            <img src="uploads/logo/<?= $settings['logo'] ?>" alt="Logo">
                        <?php else: ?>
                            <div style="color:var(--text-muted); font-size:0.8rem; text-align:center;">
                                <i class="fas fa-image fa-2x" style="opacity:0.5; margin-bottom:5px;"></i><br>No Logo
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group" style="flex:1; margin:0;">
                        <label class="form-label" style="font-size:1.05rem;">Brand Logo</label>
                        <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:10px; font-weight:500;">Ye logo aapke Invoices aur Bills par print hoga. (Max size 2MB, JPG/PNG)</p>
                        <input type="file" name="logo" class="form-input" accept="image/*" style="background:white; padding:10px;">
                    </div>
                </div>

                <h3 class="section-title"><i class="fas fa-info-circle text-primary" style="margin-right:8px;"></i> Business Details</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Brand / Company Name *</label>
                        <input type="text" name="brand_name" class="form-input" value="<?= htmlspecialchars($settings['brand_name'] ?? 'Trishe Agro') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">GST Number</label>
                        <input type="text" name="gst_no" class="form-input" value="<?= htmlspecialchars($settings['gst_no'] ?? '') ?>" placeholder="e.g. 08AAAAA0000A1Z5" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mobile Number</label>
                        <input type="text" name="mobile_no" class="form-input" value="<?= htmlspecialchars($settings['mobile_no'] ?? '') ?>" placeholder="For Bills & Invoices">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($settings['email'] ?? '') ?>" placeholder="info@company.com">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Full Business Address</label>
                    <textarea name="address" class="form-input" placeholder="Office / Factory Address..."><?= htmlspecialchars($settings['address'] ?? '') ?></textarea>
                </div>

                <h3 class="section-title" style="margin-top: 40px;"><i class="fas fa-university text-info" style="margin-right:8px;"></i> Payment & Bank Details</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Bank Name</label>
                        <input type="text" name="bank_name" class="form-input" value="<?= htmlspecialchars($settings['bank_name'] ?? '') ?>" placeholder="e.g. State Bank of India">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Holder Name</label>
                        <input type="text" name="account_name" class="form-input" value="<?= htmlspecialchars($settings['account_name'] ?? '') ?>" placeholder="e.g. Trishe Agro">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Number</label>
                        <input type="text" name="account_no" class="form-input" value="<?= htmlspecialchars($settings['account_no'] ?? '') ?>" placeholder="e.g. 30000123456789">
                    </div>
                    <div class="form-group">
                        <label class="form-label">IFSC Code</label>
                        <input type="text" name="ifsc_code" class="form-input" value="<?= htmlspecialchars($settings['ifsc_code'] ?? '') ?>" placeholder="e.g. SBIN0001234" style="text-transform: uppercase;">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">UPI ID (Optional)</label>
                    <input type="text" name="upi_id" class="form-input" value="<?= htmlspecialchars($settings['upi_id'] ?? '') ?>" placeholder="e.g. trisheagro@sbi">
                </div>

                <hr style="border:0; border-top:1px solid var(--border); margin:30px 0 20px;">

                <div class="submit-btn-wrap" style="text-align: right;">
                    <button type="submit" name="save_settings" class="btn btn-primary" style="padding:12px 25px; font-size:1.05rem;">
                        <i class="fas fa-save" style="margin-right:8px;"></i> Save All Details
                    </button>
                </div>
            </form>
        </div>
    </div>

</body>

</html>