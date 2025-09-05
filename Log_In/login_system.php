<?php
session_start();

// If already logged in, redirect to intended page or dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $redirectTo = $_SESSION['redirect_to'] ?? '../dashboard.php';
    unset($_SESSION['redirect_to']);
    header("Location: $redirectTo");
    exit();
}

// Connect to the database
$conn = new mysqli("localhost", "root", "", "sacredheartparish_dbms");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST["username"];
    $password = $_POST["password"];

    // Attempt login for staff_users first
    $stmt = $conn->prepare("SELECT id, passwordHash FROM staff_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // User is staff
        $stmt->bind_result($id, $stored_password_hash);
        $stmt->fetch();

        if (password_verify($password, $stored_password_hash)) {
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $id;
            $_SESSION['username'] = $username;
            $_SESSION['user_role'] = 'staff';

            // Redirect to intended page or default dashboard
            $redirectTo = $_SESSION['redirect_to'] ?? '../dashboard.php';
            unset($_SESSION['redirect_to']);
            header("Location: $redirectTo");
            exit();
        }
    } else {
        // If not found in staff_users, try admin_users
        $stmt->close(); // Close previous statement
        $stmt = $conn->prepare("SELECT id, passwordHash FROM admin_users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            // User is admin
            $stmt->bind_result($id, $stored_password_hash);
            $stmt->fetch();

            if (password_verify($password, $stored_password_hash)) {
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = $id;
                $_SESSION['username'] = $username;
                $_SESSION['admin_username'] = $username;
                $_SESSION['user_role'] = 'admin';

                // Redirect to intended page or default dashboard
                $redirectTo = $_SESSION['redirect_to'] ?? '../dashboard.php';
                unset($_SESSION['redirect_to']);
                header("Location: $redirectTo");
                exit();
            }
        }
    }

    // If login failed
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('errorModal');
            modal.style.display = 'block';
        });
    </script>";

    $stmt->close();
    $conn->close();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="icon" type="image/x-icon" href="../tabLOGO.png">
    <link rel="stylesheet" href="../Log_In/style.css?v=2">
</head>
<body>
    <div class="container">
        <div class="login-section">
            <div class="logo">
                <img src="../sacredLOGO.png" alt="Logo">
            </div>
            <h2>Log in</h2>
            <form action="../Log_In/login_system.php" method="post">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Enter username" required>

                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter password" required>

                <!-- <div class="options">
                    <label>
                        <input type="checkbox" name="remember"> Remember for 30 days
                    </label>
                    <a href="#" class="forgot-password">Forgot password</a>
                </div> -->

                <button type="submit">Log in</button>
            </form>
            <!-- Admin Button (below the login form) -->
            <form action="../Admin_&_Staffs/admin_login.php" method="get">
                <button type="submit" style="margin-top: 10px;">Admin</button>
            </form>
        </div>
        <div class="image-section">
            <img src="../loginRIGHT.png" alt="Logo">
        </div>
    </div>

    <!-- Error Modal -->
    <div id="errorModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <p>❌ Invalid username or password. </p>
        </div>
    </div>

    <script>
        // Close modal when the close button is clicked
        const modal = document.getElementById("errorModal");
        const closeBtn = document.querySelector(".close");

        closeBtn.onclick = function() {
            modal.style.display = "none";
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target === modal) {
                modal.style.display = "none";
            }
        }
    </script>
</body>
</html>
