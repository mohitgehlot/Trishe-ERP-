<?php
include 'config.php';
$message = [];

if (isset($_POST['submit'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $cpassword = $_POST['cpassword'];

    // बेसिक वैलिडेशन
    if (empty($name) || empty($email) || empty($password)) {
        $message[] = 'Please fill all fields.';
    } elseif ($password != $cpassword) {
        $message[] = 'Confirm password does not match!';
    } else {
        // चेक करें कि ईमेल पहले से तो नहीं है
        $stmt = $conn->prepare("SELECT id FROM `users` WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $message[] = 'User already exists!';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("INSERT INTO `users` (name, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $name, $email, $hashed_password);

            if ($stmt->execute()) {
                header('location: login.php');
                exit();
            } else {
                $message[] = 'Registration failed!';
            }
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Trishe</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Exact same CSS as login.php */
        body {
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            background: linear-gradient(120deg, #caf0f8 0%, #fff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .message-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
        }

        .message {
            background: #fff;
            color: #b91c1c;
            border-left: 5px solid #ef4444;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
            min-width: 300px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .message i {
            cursor: pointer;
            margin-left: auto;
            color: #666;
        }

        .form-container {
            background: rgba(255, 255, 255, 0.85);
            border-radius: 26px;
            padding: 40px 30px;
            box-shadow: 0 20px 40px rgba(46, 136, 255, .1);
            backdrop-filter: blur(12px);
            width: 100%;
            max-width: 380px;
            text-align: center;
        }

        .form-container h3 {
            font-size: 28px;
            color: #1e293b;
            margin-bottom: 25px;
        }

        .box {
            width: 100%;
            padding: 15px;
            margin-bottom: 18px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 16px;
            box-sizing: border-box;
        }

        .box:focus {
            outline: none;
            border-color: #3b82f6;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .btn {
            width: 100%;
            padding: 15px;
            border-radius: 12px;
            background: linear-gradient(90deg, #23b9e7, #337cff);
            color: white;
            font-weight: bold;
            font-size: 18px;
            cursor: pointer;
            border: none;
            transition: 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(35, 185, 231, 0.3);
        }

        .form-container p {
            margin-top: 20px;
            color: #64748b;
            font-size: 14px;
        }

        .form-container a {
            color: #3b82f6;
            font-weight: 600;
            text-decoration: none;
        }
    </style>
</head>

<body>

    <div class="message-container">
        <?php foreach ($message as $msg): ?>
            <div class="message">
                <span><?= $msg ?></span>
                <i class="fas fa-times" onclick="this.parentElement.remove();"></i>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="form-container">
        <form action="" method="post">
            <h3>Register Now</h3>
            <input type="text" name="name" placeholder="Enter name" required class="box">
            <input type="email" name="email" placeholder="Enter email" required class="box">
            <input type="password" name="password" placeholder="Enter password" required class="box">
            <input type="password" name="cpassword" placeholder="Confirm password" required class="box">
            <input type="submit" name="submit" value="Register Now" class="btn">
            <p>Already have an account? <a href="login.php">Login Now</a></p>
        </form>
    </div>

</body>

</html>