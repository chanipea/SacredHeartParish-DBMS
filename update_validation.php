<?php
session_start();
$response = array('success' => false);

if (isset($_POST['password'])) {
    $inputPassword = $_POST['password'];

    // connect to database
    $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

    if ($conn->connect_error) {
        $response['error'] = "Connection failed: " . $conn->connect_error;
        echo json_encode($response);
        exit;
    }

    // fetch all password hashes from admin_users
    $sql = "SELECT PasswordHash FROM admin_users";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if (password_verify($inputPassword, $row['PasswordHash'])) {
                $response['success'] = true;
                break;
            }
        }
        if (!$response['success']) {
            $response['error'] = 'Invalid password';
        }
    } else {
        $response['error'] = 'No admin users found';
    }

    $conn->close();
} else {
    $response['error'] = 'Password not provided';
}

echo json_encode($response);
?>
