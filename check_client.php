<?php
$conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (isset($_POST['ClientID'])) {
    $ClientID = (int) $_POST['ClientID'];
    
    $stmt = $conn->prepare("SELECT ClientID FROM client WHERE ClientID = ?");
    $stmt->bind_param("i", $ClientID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        echo "exists";
    } else {
        echo "not_exists";
    }

    $stmt->close();
    $conn->close();
} else {
    echo "error_no_id";
}
?>