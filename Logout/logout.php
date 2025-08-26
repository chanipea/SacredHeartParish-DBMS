<?php
session_start();
unset($_SESSION['came_from_dashboard']);
session_unset(); // Clear session variables
session_destroy(); // Destroy session data
// Clear the dashboard trail flag on logout or session end
header("Location: ../Log_In/login_system.php"); // Redirect to login page
exit();
