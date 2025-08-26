<?php
session_start();

// Database connection
$conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

// Check for connection errors
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get the posted username and password
$username = $_POST["username"];
$password = $_POST["password"];

// Check if the admin username exists in the database
$query = $conn->prepare("SELECT * FROM admin_users WHERE Username = ?");
$query->bind_param("s", $username);
$query->execute();
$result = $query->get_result();

// If no result is found, redirect with an error message
if ($result->num_rows == 0) {
    header("Location: ../Admin_&_Staffs/admin_login.php?error=admin_not_found");
    exit();
}

// Fetch the user data
$user = $result->fetch_assoc();

// Verify the password
if (password_verify($password, $user['PasswordHash'])) {
    // Correct password
    $_SESSION['is_admin'] = true;
    $_SESSION['admin_username'] = $username;
    header("Location: ../Admin_&_Staffs/create_account.php"); // Redirect to admin dashboard
} else {
    // Incorrect password
    header("Location: ../Admin_&_Staffs/admin_login.php?error=invalid_credentials");
    exit();
}

$conn->close();
?>

