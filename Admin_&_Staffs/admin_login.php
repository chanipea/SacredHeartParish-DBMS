<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
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
            <h2>Admin Log In</h2>
            <form action="../Admin_&_Staffs/admin_login_check.php" method="post">
                <label for="username">Admin Username</label>
                <input type="text" id="username" name="username" placeholder="Enter Admin Username" required>

                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter Password" required>

                <div class="options">
                    <label>
                        <input type="checkbox" name="remember"> Remember me for 30 days
                    </label>
                    <a href="#" class="forgot-password">Forgot password?</a>
                </div>

                <button type="submit">Log In</button>
            </form>
            <?php if (isset($_GET['error'])): ?>
                <div class="error">
                    <?php
                        if ($_GET['error'] == 'admin_not_found') {
                            echo "❌ Admin doesn't exist.";
                        } elseif ($_GET['error'] == 'invalid_credentials') {
                            echo "❌ Invalid username or password.";
                        }
                    ?>
                </div>
            <?php endif; ?>

            <form action="../Log_In/login_system.php" method="get">
                <button type="submit" style="margin-top: 10px;">Back to Log in</button>
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
            <p>❌ Invalid credentials or not an admin.</p>
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
