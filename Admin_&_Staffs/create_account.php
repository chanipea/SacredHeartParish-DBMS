<?php
session_start();

// Only allow admins to access this page
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header("Location: ../Admin_&_Staffs/admin_login.php");
    exit();
}

$successMsg = $errorMsg = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST["username"]);
    $password = $_POST["password"];

    if (empty($username) || empty($password)) {
        $errorMsg = "Both fields are required.";
    }  // Validate username
    elseif (!preg_match("/^[a-zA-Z0-9_]{5,20}$/", $username)) {
        $errorMsg = "Username must be 5-20 characters long and contain only letters, numbers, and underscores.";
    }
    // Validate password
    elseif (strlen($password) <8) {
        $errorMsg = "Password must be at least 8 characters long.";
    }
    elseif (!preg_match("/^[a-zA-Z0-9]+$/", $password)) {
        $errorMsg = "Password must contain only letters and numbers (no special characters).";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

        if ($conn->connect_error) {
            $errorMsg = "Connection failed: " . $conn->connect_error;
        } else {
            $username = $conn->real_escape_string($username);
            $check = $conn->prepare("SELECT 1 FROM staff_users WHERE username = ?");
            $check->bind_param("s", $username);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $errorMsg = "Username already exists.";
            } else {
                $stmt = $conn->prepare("INSERT INTO staff_users (username, passwordHash) VALUES (?, ?)");
                $stmt->bind_param("ss", $username, $hash);

                if ($stmt->execute()) {
                    $successMsg = "✅ New staff account created successfully.";
                } else {
                    $errorMsg = "Error: " . $stmt->error;
                }
                $stmt->close();
            }

            $check->close();
            $conn->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Staff Account</title>
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="icon" type="image/x-icon" href="../tabLOGO.png">
    <link rel="stylesheet" href="../Log_In/style.css">
</head>
<body>
<div class="container">
    <div class="login-section">
        <div class="top-bar"></div>
        <div class="logo">
            <img src="../sacredLOGO.png" alt="Logo">
        </div>
        <h2>Create Staff Account</h2>
        <form action="" method="post">
            <label for="username">New Username</label>
            <input type="text" id="username" name="username" placeholder="Enter username" required>

            <label for="password">New Password</label>
            <input type="password" id="password" name="password" placeholder="Enter password" required>

            <button type="submit">Create Account</button>
        </form>

        <?php if ($successMsg): ?>
            <div class="message" style="color: green; margin-top: 10px;">
                <?= htmlspecialchars($successMsg) ?>
            </div>
        <?php elseif ($errorMsg): ?>
            <div class="error" style="color: red; margin-top: 10px;">
                <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <form action="../Admin_&_Staffs/admin_users.php" method="get">
            <button type="submit" style="margin-top: 10px;">Add Admin Staff</button>
        </form>

        <form action="../Log_In/login_system.php" method="get">
            <button type="submit" style="margin-top: 10px;">Back to Log in</button>
        </form>
    </div>
    <div class="image-section">
        <img src="../loginRIGHT.png" alt="Logo">
    </div>
</div>
</body>
</html>