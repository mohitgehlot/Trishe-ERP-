<?php
session_start();
include 'config.php';


// Check if the form is submitted
if (isset($_POST['submit'])) {
    $user_id = $_SESSION['user_id']; // Assuming you store the user ID in the session
    $target_dir = "uploads/"; // Directory where the image will be saved
    $target_file = $target_dir . basename($_FILES["profile_picture"]["name"]);
    $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    // Check if the file is an actual image
    $check = getimagesize($_FILES["profile_picture"]["tmp_name"]);
    if ($check === false) {
        $_SESSION['message'] = "File is not an image.";
        header("Location: admin_page.php"); // Redirect back to the admin page
        exit();
    }

    // Check file size (5MB max)
    if ($_FILES["profile_picture"]["size"] > 5000000) {
        $_SESSION['message'] = "Sorry, your file is too large.";
        header("Location: admin_page.php");
        exit();
    }

    // Allow only certain file formats
    $allowed_formats = ["jpg", "jpeg", "png", "gif"];
    if (!in_array($imageFileType, $allowed_formats)) {
        $_SESSION['message'] = "Sorry, only JPG, JPEG, PNG, and GIF files are allowed.";
        header("Location: admin_page.php");
        exit();
    }

    // Generate a unique file name to avoid overwriting existing files
    $new_file_name = uniqid() . "." . $imageFileType;
    $target_file = $target_dir . $new_file_name;

    // Move the uploaded file to the target directory
    if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
        // Update the profile picture path in the database
        $stmt = $db->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
        $stmt->bind_param("si", $target_file, $user_id);

        if ($stmt->execute()) {
            $_SESSION['message'] = "Profile picture updated successfully.";
            $_SESSION['profile_picture'] = $target_file; // Update session with the new profile picture
        } else {
            $_SESSION['message'] = "Sorry, there was an error updating your profile picture.";
        }

        $stmt->close();
    } else {
        $_SESSION['message'] = "Sorry, there was an error uploading your file.";
    }

    // Redirect back to the admin page
    header("Location: admin_page.php");
    exit();
}

$db->close();
?>