<?php
// Prevent caching of the page after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$pageTitle = "Baptismal Records Management";
require_once 'session_timeout.php'; 

// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Log_In/login_system.php");
    exit();
}

// --- START: Filter Logic for GET requests ---
$whereClauses = [];
$orderByClause = "ORDER BY br.BaptismID DESC"; // Default order: newest first by ID
$filter_params = []; // Parameters for prepared statement
$filter_param_types = ""; // Types for prepared statement

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (isset($_GET['filter_type']) && !empty($_GET['filter_type'])) {
        $filter_type = $_GET['filter_type'];

        switch ($filter_type) {
            case 'year':
                if (isset($_GET['filter_year_value']) && !empty($_GET['filter_year_value'])) {
                    $year = filter_var($_GET['filter_year_value'], FILTER_VALIDATE_INT);
                    if ($year && strlen((string)$year) == 4) {
                        $whereClauses[] = "br.BaptismYear = ?";
                        $filter_params[] = $year;
                        $filter_param_types .= "i";
                    }
                }
                break;
            case 'month':
                if (isset($_GET['filter_month_value']) && !empty($_GET['filter_month_value'])) {
                    $month = $_GET['filter_month_value'];
                    $validMonths = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
                    if (in_array($month, $validMonths)) {
                        $whereClauses[] = "br.BaptismMonthDay LIKE ?";
                        $filter_params[] = $month . " %";
                        $filter_param_types .= "s";
                    }
                }
                break;
            case 'specific_date':
                if (isset($_GET['filter_date_value']) && !empty($_GET['filter_date_value'])) {
                    $date_str = $_GET['filter_date_value']; // Expects DD/MM/YYYY
                    $date_parts = explode('/', $date_str);
                    if (count($date_parts) === 3 && checkdate((int)$date_parts[1], (int)$date_parts[0], (int)$date_parts[2])) {
                        $day_input = $date_parts[0];
                        $month_num = (int)$date_parts[1];
                        $year_val = (int)$date_parts[2];

                        $dateObj = DateTime::createFromFormat('!m', $month_num);
                        $monthName = $dateObj->format('F');

                        // Handle potential variations in day storage (e.g., "1" vs "01")
                        $day_as_int = intval($day_input);
                        $baptismMonthDay_format1 = $monthName . " " . $day_as_int; // e.g., "January 1"
                        $baptismMonthDay_format2 = $monthName . " " . str_pad($day_as_int, 2, '0', STR_PAD_LEFT); // e.g., "January 01"

                        $whereClauses[] = "br.BaptismYear = ?";
                        $filter_params[] = $year_val;
                        $filter_param_types .= "i";

                        $whereClauses[] = "(br.BaptismMonthDay = ? OR br.BaptismMonthDay = ?)";
                        $filter_params[] = $baptismMonthDay_format1;
                        $filter_params[] = $baptismMonthDay_format2;
                        $filter_param_types .= "ss";
                    }
                }
                break;
            case 'oldest_to_latest':
                $orderByClause = "ORDER BY br.BaptismYear ASC, 
                                  FIELD(SUBSTRING_INDEX(br.BaptismMonthDay, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') ASC, 
                                  CAST(SUBSTRING_INDEX(br.BaptismMonthDay, ' ', -1) AS UNSIGNED) ASC";
                break;
            case 'latest_to_oldest':
                $orderByClause = "ORDER BY br.BaptismYear DESC, 
                                   FIELD(SUBSTRING_INDEX(br.BaptismMonthDay, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') DESC, 
                                   CAST(SUBSTRING_INDEX(br.BaptismMonthDay, ' ', -1) AS UNSIGNED) DESC";
                break;
        }
    }
    // If filter_type is not set but sort_order is, honor it (e.g., after clearing a filter but wanting a sort)
    // This part is mostly covered by the above, but good for explicit sort_order param if used alone.
    if (isset($_GET['sort_order'])) {
        if ($_GET['sort_order'] === 'asc' && $filter_type !== 'oldest_to_latest') { // Avoid redundant setting
            $orderByClause = "ORDER BY br.BaptismYear ASC, FIELD(SUBSTRING_INDEX(br.BaptismMonthDay, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') ASC, CAST(SUBSTRING_INDEX(br.BaptismMonthDay, ' ', -1) AS UNSIGNED) ASC";
        } elseif ($_GET['sort_order'] === 'desc' && $filter_type !== 'latest_to_oldest') {
            $orderByClause = "ORDER BY br.BaptismYear DESC, FIELD(SUBSTRING_INDEX(br.BaptismMonthDay, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') DESC, CAST(SUBSTRING_INDEX(br.BaptismMonthDay, ' ', -1) AS UNSIGNED) DESC";
        }
    }
}
// --- END: Filter Logic ---


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

    if ($conn->connect_error) {
        error_log("Database Connection Failed: " . $conn->connect_error);
        echo "<script>alert('Error: Could not connect to the database. Please try again later.'); window.history.back();</script>";
        exit();
    }

    // --- Collect and trim form inputs ---
    $BaptismID_for_update = trim($_POST['BaptismID'] ?? ''); // For update only
    $PriestID = trim($_POST['PriestID'] ?? '');
    $BaptismYear = trim($_POST['BaptismYear'] ?? '');
    $BaptismMonthDay = trim($_POST['BaptismMonthDay'] ?? '');
    $ClientID = trim($_POST['ClientID'] ?? '');
    $BdayYear = trim($_POST['BdayYear'] ?? '');
    $BdayMonthDay = trim($_POST['BdayMonthDay'] ?? '');
    $Legitimacy = trim($_POST['Legitimacy'] ?? ''); // This will now be from the select
    $FatherName = trim($_POST['FatherName'] ?? '');
    $FatherPlaceOfOrigin = trim($_POST['FatherPlaceOfOrigin'] ?? '');
    $FatherPlaceOfResidence = trim($_POST['FatherPlaceOfResidence'] ?? '');
    $MotherName = trim($_POST['MotherName'] ?? '');
    $MotherPlaceOfOrigin = trim($_POST['MotherPlaceOfOrigin'] ?? '');
    $MotherPlaceOfResidence = trim($_POST['MotherPlaceOfResidence'] ?? '');
    $OneSponsorName = trim($_POST['OneSponsorName'] ?? '');
    $OneSponsorPlace = trim($_POST['OneSponsorPlace'] ?? '');
    $TwoSponsorName = trim($_POST['TwoSponsorName'] ?? '');
    $TwoSponsorPlace = trim($_POST['TwoSponsorPlace'] ?? '');
    $StipendPesosRaw = trim($_POST['StipendPesos'] ?? '');
    $StipendCtsRaw = trim($_POST['StipendCts'] ?? '');
    $RemarkRaw = trim($_POST['Remark'] ?? '');

    $StipendPesos = ($StipendPesosRaw !== "") ? floatval($StipendPesosRaw) : null;
    $StipendCts = ($StipendCtsRaw !== "") ? floatval($StipendCtsRaw) : null;
    $Remark = ($RemarkRaw !== "") ? $RemarkRaw : null;


    // --- SERVER-SIDE VALIDATION ---
    $errors = [];
    $currentYear = date('Y');
    $namePattern = '/^[\p{L}\s.\'-]+$/u';
    $addressPattern = '/^[\p{L}\p{N}\s,.\'#\/\-]+$/u';
    $monthDayPattern = '/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+([0-9]{1,2})$/i';
    $yearPattern = '/^\d{4}$/';
    // $generalTextPattern = '/^[\p{L}\p{N}\s.,;:\'\"()\/\-]+$/u'; // No longer needed for Legitimacy if it's a select


    // BaptismID (only for update)
    if (isset($_POST['updateRecord'])) {
        if (empty($BaptismID_for_update)) {
            $errors[] = "Baptism ID is required for an update.";
        } elseif (!ctype_digit($BaptismID_for_update) || intval($BaptismID_for_update) <= 0) {
            $errors[] = "Invalid Baptism ID format for update.";
        }
    }

    // PriestID
    if (empty($PriestID)) {
        $errors[] = "Priest selection is required.";
    } elseif (!ctype_digit($PriestID) || intval($PriestID) <= 0) {
        $errors[] = "Invalid Priest ID selected.";
    } else {
        $checkPriestStmt = $conn->prepare("SELECT PriestID FROM priest WHERE PriestID = ?");
        if ($checkPriestStmt) {
            $checkPriestStmt->bind_param("i", $PriestID);
            $checkPriestStmt->execute();
            $checkPriestResult = $checkPriestStmt->get_result();
            if ($checkPriestResult->num_rows === 0) $errors[] = "Selected Priest does not exist.";
            $checkPriestStmt->close();
        } else { $errors[] = "Error verifying priest."; }
    }

    // BaptismYear
    if (empty($BaptismYear)) {
        $errors[] = "Baptism Year is required.";
    } elseif (!preg_match($yearPattern, $BaptismYear)) {
        $errors[] = "Baptism Year must be a 4-digit year.";
    } elseif (intval($BaptismYear) < 1800 || intval($BaptismYear) > ($currentYear + 5)) {
        $errors[] = "Baptism Year must be between 1800 and " . ($currentYear + 5) . ".";
    }

    // BaptismMonthDay
    $baptismDateObj = null;
    if (empty($BaptismMonthDay)) {
        $errors[] = "Baptism Month and Day are required.";
    } elseif (!preg_match($monthDayPattern, $BaptismMonthDay, $matchesBaptismDate)) {
        $errors[] = "Baptism Month and Day format is invalid (e.g., 'January 01').";
    } else {
        $monthNameBaptism = $matchesBaptismDate[1];
        $dayBaptism = intval($matchesBaptismDate[2]);
        if ($dayBaptism < 1 || $dayBaptism > 31) $errors[] = "Day of Baptism must be between 1 and 31.";
        if (empty($errors) && preg_match($yearPattern, $BaptismYear)) {
            $baptismDateTimeStr = $monthNameBaptism . " " . $dayBaptism . " " . $BaptismYear;
            $baptismDateObj = DateTime::createFromFormat('F j Y', $baptismDateTimeStr);
            if ($baptismDateObj === false || strtolower($baptismDateObj->format('F')) !== strtolower($monthNameBaptism) || (int)$baptismDateObj->format('j') !== $dayBaptism) {
                $errors[] = "The Baptism Month, Day, and Year do not form a valid date.";
            }
        }
    }

    // ClientID
    if (empty($ClientID)) {
        $errors[] = "Client ID is required.";
    } elseif (!ctype_digit($ClientID) || intval($ClientID) <= 0) {
        $errors[] = "Client ID must be a positive number.";
    }

    // BdayYear
    if (empty($BdayYear)) {
        $errors[] = "Birth Year is required.";
    } elseif (!preg_match($yearPattern, $BdayYear)) {
        $errors[] = "Birth Year must be a 4-digit year.";
    } elseif (intval($BdayYear) < 1800 || intval($BdayYear) > $currentYear) {
        $errors[] = "Birth Year must be between 1800 and the current year.";
    }

    // BdayMonthDay
    $bdayDateObj = null;
    if (empty($BdayMonthDay)) {
        $errors[] = "Birth Month and Day are required.";
    } elseif (!preg_match($monthDayPattern, $BdayMonthDay, $matchesBdayDate)) {
        $errors[] = "Birth Month and Day format is invalid (e.g., 'January 01').";
    } else {
        $monthNameBday = $matchesBdayDate[1];
        $dayBday = intval($matchesBdayDate[2]);
        if ($dayBday < 1 || $dayBday > 31) $errors[] = "Day of Birth must be between 1 and 31.";
        if (empty($errors) && preg_match($yearPattern, $BdayYear)) {
            $bdayDateTimeStr = $monthNameBday . " " . $dayBday . " " . $BdayYear;
            $bdayDateObj = DateTime::createFromFormat('F j Y', $bdayDateTimeStr);
            if ($bdayDateObj === false || strtolower($bdayDateObj->format('F')) !== strtolower($monthNameBday) || (int)$bdayDateObj->format('j') !== $dayBday) {
                $errors[] = "The Birth Month, Day, and Year do not form a valid date.";
            }
        }
    }

    if (empty($errors) && $baptismDateObj && $bdayDateObj) {
        if ($baptismDateObj < $bdayDateObj) {
            $errors[] = "Baptism date cannot be before the birth date.";
        }
    }

    // Legitimacy (Updated for select)
    $validLegitimacyOptions = ['Legitimate', 'Illegitimate', 'Adopted', 'Unknown'];
    if (empty($Legitimacy)) {
        $errors[] = "Legitimacy status is required.";
    } elseif (!in_array($Legitimacy, $validLegitimacyOptions, true)) {
        $errors[] = "Invalid Legitimacy status selected.";
    }

    $namesToValidate = [
        'FatherName' => $FatherName, 'MotherName' => $MotherName,
        'OneSponsorName' => $OneSponsorName, 'TwoSponsorName' => $TwoSponsorName
    ];
    foreach ($namesToValidate as $key => $value) {
        if (empty($value)) {
            $errors[] = str_replace(array('One', 'Two'), array('First', 'Second'), preg_replace('/(?<!^)([A-Z])/', ' $1', $key)) . " is required.";
        } elseif (!preg_match($namePattern, $value) || strlen($value) > 100) {
            $errors[] = str_replace(array('One', 'Two'), array('First', 'Second'), preg_replace('/(?<!^)([A-Z])/', ' $1', $key)) . ": Invalid characters or too long (max 100).";
        }
    }

    $placesToValidate = [
        'FatherPlaceOfOrigin' => $FatherPlaceOfOrigin, 'FatherPlaceOfResidence' => $FatherPlaceOfResidence,
        'MotherPlaceOfOrigin' => $MotherPlaceOfOrigin, 'MotherPlaceOfResidence' => $MotherPlaceOfResidence,
        'OneSponsorPlace' => $OneSponsorPlace, 'TwoSponsorPlace' => $TwoSponsorPlace
    ];
    foreach ($placesToValidate as $key => $value) {
        if (empty($value)) {
            $errors[] = str_replace(array('One', 'Two'), array('First', 'Second'), preg_replace('/(?<!^)([A-Z])/', ' $1', $key)) . " is required.";
        } elseif (!preg_match($addressPattern, $value) || strlen($value) > 255) {
            $errors[] = str_replace(array('One', 'Two'), array('First', 'Second'), preg_replace('/(?<!^)([A-Z])/', ' $1', $key)) . ": Invalid characters or too long (max 255).";
        }
    }

    if ($StipendPesos !== null) {
        if (!is_numeric($StipendPesosRaw) || floatval($StipendPesosRaw) < 0) {
            $errors[] = "Stipend Pesos must be a non-negative number.";
        }
    }
    if ($StipendCts !== null) {
        if (!is_numeric($StipendCtsRaw) || floatval($StipendCtsRaw) < 0 || floatval($StipendCtsRaw) >= 100) {
            $errors[] = "Stipend Cents must be a non-negative number less than 100.";
        }
    }

    if ($Remark !== null) {
        if (preg_match('/[<>]/', $Remark)) {
            $errors[] = "Remark should not contain HTML tags like < or >.";
        } elseif (strlen($Remark) > 500) {
            $errors[] = "Remark is too long (max 500 characters).";
        }
    }

    if (!empty($errors)) {
        $errorString = implode("\\n", array_map('htmlspecialchars', $errors));
        echo "<script>alert('Validation Errors:\\n" . $errorString . "'); window.history.back();</script>";
        $conn->close();
        exit();
    }

    // Get ParishStaffID based on logged-in user
    $parishStaffID = null;
    if (isset($_SESSION['user_role']) && isset($_SESSION['user_id'])) {
        $userRole = $_SESSION['user_role']; // expected to be 'admin' or 'staff'
        $userId = $_SESSION['user_id'];

        if ($userRole === 'admin') {
            // Look for ParishStaff where UserType = 'admin' and AdminUserID matches
            $stmtStaff = $conn->prepare("SELECT ParishStaffID FROM parishstaff WHERE UserType = 'admin' AND AdminUserID = ?");
            $stmtStaff->bind_param("i", $userId);
        } elseif ($userRole === 'staff') {
            // Look for ParishStaff where UserType = 'staff' and StaffUserID matches
            $stmtStaff = $conn->prepare("SELECT ParishStaffID FROM parishstaff WHERE UserType = 'staff' AND StaffUserID = ?");
            $stmtStaff->bind_param("i", $userId);
        } else {
            echo "<script>alert('Error: Invalid user role.'); window.history.back();</script>";
            $conn->close();
            exit();
        }

        $stmtStaff->execute();
        $stmtStaff->bind_result($psID);
        if ($stmtStaff->fetch()) {
            $parishStaffID = $psID;
        }
        $stmtStaff->close();

        if (!$parishStaffID) {
            echo "<script>alert('Error: Your user is not linked in ParishStaff table. Contact admin.'); window.history.back();</script>";
            $conn->close();
            exit();
        }
    } else {
        echo "<script>alert('Error: User session invalid.'); window.history.back();</script>";
        $conn->close();
        exit();
    }


                        
    // INSERT RECORD
    if (isset($_POST['submitRecord'])) {
        /*--- ClientID Error [start] ---*/
        $checkClientSql = "SELECT ClientID FROM client WHERE ClientID = '$ClientID'";
        $checkResult = $conn->query($checkClientSql);

        if (!$checkResult || $checkResult->num_rows === 0) {
            error_log("Client ID validation failed on server side for: " . $ClientID);
            $conn->close();
            exit();
        }
        /*--- ClientID Error [end] ---*/

        /*--- Duplicate Check [start] ---*/
        $check_baptism_sql = "SELECT BaptismID FROM Baptismal_Records  
                            WHERE ClientID = ? 
                            AND BaptismYear = ? 
                            AND BaptismMonthDay = ?";
        $check_stmt = $conn->prepare($check_baptism_sql);
        $check_stmt->bind_param("iss", $ClientID, $BaptismYear, $BaptismMonthDay);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows > 0) {
            echo "<script>alert('Error: A baptismal record already exists for this person on this date.'); window.history.back();</script>";
            exit();
        }
        $check_stmt->close();
        /*--- Duplicate Check [end] ---*/

        $sql = "INSERT INTO Baptismal_Records (
            PriestID, BaptismYear, BaptismMonthDay, ClientID,
            BdayYear, BdayMonthDay, Legitimacy, FatherName,
            FatherPlaceOfOrigin, FatherPlaceOfResidence, MotherName,
            MotherPlaceOfOrigin, MotherPlaceOfResidence, OneSponsorName,
            OneSponsorPlace, TwoSponsorName, TwoSponsorPlace,
            StipendPesos, StipendCts, Remark, ParishStaffID
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ississsssssssssssddsi",
            $PriestID, $BaptismYear, $BaptismMonthDay, $ClientID,
            $BdayYear, $BdayMonthDay, $Legitimacy, $FatherName,
            $FatherPlaceOfOrigin, $FatherPlaceOfResidence, $MotherName,
            $MotherPlaceOfOrigin, $MotherPlaceOfResidence, $OneSponsorName,
            $OneSponsorPlace, $TwoSponsorName, $TwoSponsorPlace,
            $StipendPesos, $StipendCts, $Remark, $parishStaffID
        );

        if ($stmt->execute()) {
            echo "<script>alert('Record inserted successfully!'); window.location.href = window.location.href;</script>";
            exit();
        } else {
            error_log("SQL Execute Error (Insert Baptismal): " . $stmt->error);
            echo "<script>alert('Error inserting record: " . htmlspecialchars($stmt->error, ENT_QUOTES) . "');</script>";
        }
        $stmt->close();
    }

        // UPDATE RECORD
        elseif (isset($_POST['updateRecord'])) {
            $adminPassword = $_POST['adminPassword'] ?? '';
            $isValid = false;
            $sqlPass = "SELECT PasswordHash FROM admin_users";
            $resultPass = $conn->query($sqlPass);
            if ($resultPass && $resultPass->num_rows > 0) {
                while ($row = $resultPass->fetch_assoc()) {
                    if (password_verify($adminPassword, $row['PasswordHash'])) {
                        $isValid = true;
                        break;
                    }
                }
            }

            if (!$isValid) {
                echo "<script>alert('Incorrect admin password. Update denied.'); window.history.back();</script>";
            } else {
                /*--- ClientID Error [start] ---*/
                $checkClientSql = "SELECT ClientID FROM client WHERE ClientID = '$ClientID'";
                $checkResult = $conn->query($checkClientSql);

                if (!$checkResult || $checkResult->num_rows === 0) {
                    error_log("Client ID validation failed on server side for: " . $ClientID);
                    $conn->close();
                    exit();
                }
                /*--- ClientID Error [end] ---*/

                $sql = "UPDATE Baptismal_Records SET
                        PriestID=?, BaptismYear=?, BaptismMonthDay=?, ClientID=?,
                        BdayYear=?, BdayMonthDay=?, Legitimacy=?, FatherName=?,
                        FatherPlaceOfOrigin=?, FatherPlaceOfResidence=?, MotherName=?,
                        MotherPlaceOfOrigin=?, MotherPlaceOfResidence=?, OneSponsorName=?,
                        OneSponsorPlace=?, TwoSponsorName=?, TwoSponsorPlace=?,
                        StipendPesos=?, StipendCts=?, Remark=?
                        WHERE BaptismID=?";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "ississsssssssssssddsi",
                    $PriestID, $BaptismYear, $BaptismMonthDay, $ClientID,
                    $BdayYear, $BdayMonthDay, $Legitimacy, $FatherName,
                    $FatherPlaceOfOrigin, $FatherPlaceOfResidence, $MotherName,
                    $MotherPlaceOfOrigin, $MotherPlaceOfResidence, $OneSponsorName,
                    $OneSponsorPlace, $TwoSponsorName, $TwoSponsorPlace,
                    $StipendPesos, $StipendCts, $Remark, $BaptismID_for_update
                );

                if ($stmt->execute()) {
                    echo "<script>alert('Record updated successfully!'); window.location.href = window.location.href;</script>";
                    exit();
                } else {
                    error_log("SQL Execute Error (Update Baptismal): " . $stmt->error);
                    echo "<script>alert('Update error: " . htmlspecialchars($stmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
                }
                $stmt->close();
            }
        }

        $conn->close();
    }
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" href="imagess/sacred.png">
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="stylesheet" href="baptismalStyle.css?v=13">
    <!-- Add these two lines for responsiveness -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="responsive.css?v=9">
</head>
<body>
<button id="sidebarToggleBtn" class="sidebar-toggle-button">
    <img src="icons/Menu.png" alt="Menu">
</button>
<div class="container">
    <div class="header">
        <div class="icon"></div>
        <div class="title">Sacred Heart Database Management System</div>
    </div>

    <aside class="sidebar">
        <div class="logo">Menus</div>
        <nav class="menu">
            <ul>
                <!-- <li id="dashboardButton">Dashboard</li> -->
                <li id="dashboardButton">
                    <img src="icons\dashboard.png" alt="Dashboard Icon">
                    Dashboard
                </li>
                <!-- <li id="priestButton">Priest Records</li> -->

                <li id="priestButton">
                    <img src="icons\priest.png" alt="Priest Icon">
                    Priest Records
                </li>
                
                <!-- <li id="eventsButton">Event Records</li> -->

                <li id="eventsButton">
                    <img src="icons\event.png" alt="Event Icon">
                    Event Records
                </li>

                <!-- <li id="massButton">Mass Schedules</li> -->

                <li id="massButton">
                    <img src="icons\mass.png" alt="Mass Icon">
                    Mass Schedules
                </li>

                <!-- <li onclick="toggleDropdown()" id="certificates">
                        Records <span id="dropdownIcon">▶</span>
                </li>    
                </li> -->

                <li onclick="toggleDropdown()" id="certificates">
                    <img src="icons\records.png" alt="Records Icon" class="Records icon">
                    Records <span id="dropdownIcon">▶</span>
                </li>


                <ul class="dropdown dropdown-active" id="certificateDropdown">
                    <li id="baptismalButton">Baptismal Records</li>
                    <li id="MarriageButton">Marriage Records</li>
                    <li id="burialButton">Burial Records</li>
                    <li id="confirmationButton">Confirmation Records</li>
                </ul>

                <!-- <li id="clientButton">Clients</li> -->

                <li id="clientButton">
                    <img src="icons\clients.png" alt="Clients Icon">
                    Clients
                </li>

                <!-- cert & logout [start] -->
                    <div class = "button-stack">
                        <div id="generateCertificateButton" class="bottom-left">
                            <button id="generateCertBtn" class="certOpen-btn" onclick="openCertModal()">Generate Certificate</button>
                        </div>

                        <!-- Logout Button (placed right below) -->
                        <div class="sidebar-logout">
                            <a href="Logout/logout.php">
                                <button class="logout-btn">Log Out</button>
                            </a>
                        </div>
                    </div>
                    <!-- cert & logout [end] -->
            </ul>
        </nav>
    </aside>

    <div class="table-container">
        <div class="section-title">Baptismal Records</div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search..." style="flex-grow: 1;"> <!-- Allow search to take space -->

            <!-- START: Category Filter -->
            <div class="filter-container">
                <select id="categoryFilter" name="category_filter" title="Category Filter">
                    <option value="">-- Filter By --</option>
                    <option value="year">Year</option>
                    <option value="month">Month</option>
                    <option value="specific_date">Specific Date</option>
                    <option value="oldest_to_latest">Oldest to Latest</option>
                    <option value="latest_to_oldest">Latest to Oldest</option>
                </select>

                <div id="filterYearInputContainer" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearValue" name="filter_year_value" placeholder="YYYY">
                </div>
                <div id="filterMonthInputContainer" class="filter-input-group" style="display:none;">
                    <select id="filterMonthValue" name="filter_month_value">
                        <option value="">-- Select Month --</option>
                        <option value="January">January</option>
                        <option value="February">February</option>
                        <option value="March">March</option>
                        <option value="April">April</option>
                        <option value="May">May</option>
                        <option value="June">June</option>
                        <option value="July">July</option>
                        <option value="August">August</option>
                        <option value="September">September</option>
                        <option value="October">October</option>
                        <option value="November">November</option>
                        <option value="December">December</option>
                    </select>
                </div>
                <div id="filterDateInputContainer" class="filter-input-group" style="display:none;">
                    <input type="text" id="filterDateValue" name="filter_date_value" placeholder="DD/MM/YYYY">
                </div>
                <button id="applyFilterBtn" class="filter-btn">Apply</button>
                <button id="clearFilterBtn" class="filter-btn">Clear</button>
            </div>
            <!-- END: Category Filter -->

            <div class="record-buttons" style="margin-left: auto;"> <!-- Push buttons to the far right -->
                <button id="updateRecordBtn">✎ Update Record</button>
                <button id="addRecordBtn">+ Add Record</button>
            </div>
        </div>

         <div class="table-scroll">
            <table id="recordsTable">
                <thead>
                <tr class="header-row-1">
                    <th>Baptismal ID</th>
                    <th colspan="2">Baptismi</th>
                    <th>ClientID</th>
                    <!-- <th>Baptizatorum</th> -->
                    <th colspan="3">Nativitatis</th>
                    <th colspan="3">Parentum</th>
                    <th colspan="2">Patrinorum</th>
                    <th colspan="2">Stipend</th>
                    <th>Name of Priest</th>
                    <th>Observanda</th>
                    <th>Created By</th>
                </tr>
                <tr class="header-row-2">
                    <th></th>
                    <th>Year</th>
                    <th>Month and Day</th>
                    <th></th>
                    <!-- <th>Name and Surname</th> -->
                    <th>Annus</th>
                    <th>Mensis et Dies</th>
                    <th>Legitimitas</th>
                    <th>Name and Surname</th>
                    <th>Originis locus</th>
                    <th>Habitationis locus</th>
                    <th>Name and Surname</th>
                    <th>Habitationis locus</th>
                    <th>Pesos</th>
                    <th>Cents</th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php
                $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error);
                }

                // $sql = "SELECT * FROM Baptismal_Records";
                // START MODIFY FOR FILTER

                $baseSql = "SELECT br.*, p.FullName AS PriestFullName,
                                    c.FullName AS ClientName,
                                    COALESCE(au.username, su.username, 'Unknown') AS CreatedBy
                                    FROM Baptismal_Records br
                                    LEFT JOIN priest p ON br.PriestID = p.PriestID
                                    LEFT JOIN parishstaff ps ON br.ParishStaffID = ps.ParishStaffID
                                    LEFT JOIN admin_users au ON ps.AdminUserID = au.ID
                                    LEFT JOIN staff_users su ON ps.StaffUserID = su.id
                                    LEFT JOIN client c ON br.ClientID = c.ClientID";

                $finalSql = $baseSql;

                if (!empty($whereClauses)) {
                    $finalSql .= " WHERE " . implode(" AND ", $whereClauses);
                }
                $finalSql .= " " . $orderByClause;

                // Prepare and execute the statement
                if (!empty($filter_params)) {
                    $stmt = $conn->prepare($finalSql);
                    if ($stmt === false) {
                        // Handle error, e.g., log it or display a generic message
                        error_log("SQL Prepare Error: " . $conn->error . " | SQL: " . $finalSql);
                        echo "<tr><td colspan='19'>Error preparing data.</td></tr>";
                        $result = null; // Ensure result is null
                    } else {
                        $stmt->bind_param($filter_param_types, ...$filter_params);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    }
                } else {
                    $result = $conn->query($finalSql);
                    if ($result === false) {
                        error_log("SQL Query Error: " . $conn->error . " | SQL: " . $finalSql);
                        echo "<tr><td colspan='19'>Error fetching data.</td></tr>";
                    }
                }

                if ($result && $result->num_rows > 0) { // Check if $result is not nu
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td rowspan='2'>" . ($row["BaptismID"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["BaptismYear"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["BaptismMonthDay"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["ClientName"] ?? '-') . "</td>";

                        echo "<td rowspan='2'>" . ($row["BdayYear"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["BdayMonthDay"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["Legitimacy"] ?? '-') . "</td>";

                        // Father's Name in first row
                        echo "<td>" . ($row["FatherName"] ?? '-') . "</td>";
                        echo "<td>" . ($row["FatherPlaceOfOrigin"] ?? '-') . "</td>";
                        echo "<td>" . ($row["FatherPlaceOfResidence"] ?? '-') . "</td>";
                        echo "<td>" . ($row["OneSponsorName"] ?? '-') . "</td>";
                        echo "<td>" . ($row["OneSponsorPlace"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["StipendPesos"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["StipendCts"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["PriestFullName"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["Remark"] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . ($row["CreatedBy"] ?? '-') . "</td>";
                        echo "</tr>";

                        // Second row for Mother Name
                        echo "<tr>";
                        echo "<td>" . ($row["MotherName"] ?? '-') . "</td>";
                        echo "<td>" . ($row["MotherPlaceOfOrigin"] ?? '-') . "</td>";
                        echo "<td>" . ($row["MotherPlaceOfResidence"] ?? '-') . "</td>";
                        echo "<td>" . ($row["TwoSponsorName"] ?? '-') . "</td>";
                        echo "<td>" . ($row["TwoSponsorPlace"] ?? '-') . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='19'>No baptismal records found.</td></tr>";
                }
                $conn->close(); // END FILTER
                ?>
                </tbody>

            </table>
        </div>
    </div>

    <div class="modal" id="recordModal">
        <form class="modal-content" id="addBaptismalForm" method="POST" action="baptismal.php" style="width: 1000px; height: 650px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>

            <div class="modal-header" style="background: #2c3e50; color: white; text-align: center; border-radius: 0; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Baptismal Details</h3>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">


                <div style="flex: 1 1 45%;">
                    <label for="BaptismYear" style="margin-left: 55px;">Baptism Year:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text"  id="addBaptismYear" name="BaptismYear" required style="width: 80%; padding: 5px;">
                    <small id="addBaptismYearError" class="error-message hidden" style="margin-left: 10000px;">Baptism year is required (YYYY).</small>
                    </div>
                </div>

               <div style="flex: 1 1 45%;">
                    <label for="addMotherName" style="margin-left: 30px;">Mother's Name:</label><br>
                    <div style="margin-left: 30px;">
                        <input type="text" id="addMotherName" name="MotherName" required style="width: 80%; padding: 5px;">
                        <small id="addMotherNameError" class="error-message hidden">Mother's name is required.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="BaptismMonthDay" style="margin-left: 55px;">Baptism Month & Day:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="addBaptismMonthDay" name="BaptismMonthDay" required style="width: 80%; padding: 5px;">
                    <small id="addBaptismMonthDayError" class="error-message hidden" style="margin-left: 55px;">Baptism month & day required (e.g., January 01).</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="MotherPlaceOfOrigin" style="margin-left: 30px;">Mother's Place Of Origin:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text"  id="addMotherPlaceOfOrigin" name="MotherPlaceOfOrigin" required style="width: 80%; padding: 5px;">
                    <small id="addMotherPlaceOfOriginError" class="error-message hidden" style="margin-left: 30px;">Mother's place of origin required.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="ClientID" style="margin-left: 55px;">Client ID:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="number" id="addClientID" name="ClientID" required style="width: 80%; padding: 5px;">
                    <small id="addClientIDError" class="error-message hidden" style="margin-left: 55px;">Client ID must be a positive number.</small>
                    </div>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="MotherPlaceOfResidence" style="margin-left: 30px;">Mother's Place Of Residence:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="addMotherPlaceOfResidence" name="MotherPlaceOfResidence" required style="width: 80%; padding: 5px;">
                    <small id="addMotherPlaceOfResidenceError" class="error-message hidden" style="margin-left: 30px;">Mother's place of residence required.</small>
                    </div>
                </div>

                 <div style="flex: 1 1 45%;">
                    <label for="BdayYear" style="margin-left: 55px;">Birthday Year:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="addBdayYear" name="BdayYear" required style="width: 80%; padding: 5px;">
                    <small id="addBdayYearError" class="error-message hidden" style="margin-left: 55px;">Birth year is required (YYYY).</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="OneSponsorName" style="margin-left: 30px;">One Sponsor Name:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="addOneSponsorName" name="OneSponsorName" required style="width: 80%; padding: 5px;">
                    <small id="addOneSponsorNameError" class="error-message hidden" style="margin-left: 30px;">First sponsor's name required.</small>
                    </div>
                </div>

                 <div style="flex: 1 1 45%;">
                    <label for="BdayMonthDay" style="margin-left: 55px;">Birthday Month & Day:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text"  id="addBdayMonthDay" name="BdayMonthDay" required style="width: 80%; padding: 5px;">
                    <small id="addBdayMonthDayError" class="error-message hidden" style="margin-left: 55px;">Birth month & day required (e.g., January 01).</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="OneSponsorPlace" style="margin-left: 30px;">One Sponsor Place:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="addOneSponsorPlace" name="OneSponsorPlace" required style="width: 80%; padding: 5px;">
                    <small id="addOneSponsorPlaceError" class="error-message hidden" style="margin-left: 30px;">First sponsor's place required.</small>
                    </div>
                </div>

                 <div style="flex: 1 1 45%;">
                    <label for="Legitimacy" style="margin-left: 55px;">Legitimacy:</label><br>
                    <div style="margin-left: 55px;">
                    <select id="addLegitimacy" name="Legitimacy" required style="width: 80%; padding: 5px;">
                        <option value="">-- Select Legitimacy --</option>
                        <option value="Legitimate">Legitimate</option>
                        <option value="Illegitimate">Illegitimate</option>
                        <option value="Adopted">Adopted</option>
                        <option value="Unknown">Unknown</option>
                    </select>
                    <small id="addLegitimacyError" class="error-message hidden" style="margin-left: 55px;">Please select a legitimacy status.</small>
                    </div>
                </div>
        
                <div style="flex: 1 1 45%;">
                    <label for="TwoSponsorName" style="margin-left: 30px;">Two Sponsor Name:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="addTwoSponsorName" name="TwoSponsorName" required style="width: 80%; padding: 5px;">
                    <small id="addTwoSponsorNameError" class="error-message hidden" style="margin-left: 30px;">Second sponsor's name required.</small>
                    </div>
                </div>
                
                

                <div style="flex: 1 1 45%;">
                    <label for="FatherName" style="margin-left: 55px;">Father's Name:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="addFatherName" name="FatherName" required style="width: 80%; padding: 5px;">
                    <small id="addFatherNameError" class="error-message hidden" style="margin-left: 55px;">Father's name is required.</small>
                    </div>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="TwoSponsorPlace" style="margin-left: 30px;">Two Sponsor Place:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text"  id="addTwoSponsorPlace" name="TwoSponsorPlace" required style="width: 80%; padding: 5px;">
                    <small id="addTwoSponsorPlaceError" class="error-message hidden" style="margin-left: 30px;">Second sponsor's place required.</small>
                    </div>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="FatherPlaceOfOrigin" style="margin-left: 55px;">Father's Place Of Origin:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="addFatherPlaceOfOrigin" name="FatherPlaceOfOrigin" required style="width: 80%; padding: 5px;">
                    <small id="addFatherPlaceOfOriginError" class="error-message hidden" style="margin-left: 55px;">Father's place of origin required.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="StipendCts" style="margin-left: 30px;">Stipend Cents:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="number" id="addStipendCts" name="StipendCts" style="width: 80%; padding: 5px;">
                    <small id="addStipendCtsError" class="error-message hidden" style="margin-left: 30px;">Must be a whole number (0-99).</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="FatherPlaceOfResidence" style="margin-left: 55px;">Father's Place Of Residence:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text"  id="addFatherPlaceOfResidence"  name="FatherPlaceOfResidence" required style="width: 80%; padding: 5px;">
                    <small id="addFatherPlaceOfResidenceError" class="error-message hidden" style="margin-left: 55px;">Father's place of residence required.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="StipendPesos" style="margin-left: 30px;">Stipend Pesos:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="number" id="addStipendPesos" name="StipendPesos" style="width: 80%; padding: 5px;">
                    <small id="addStipendPesosError" class="error-message hidden" style="margin-left: 30px;">Must be a valid number (e.g., 100.50).</small>
                    </div>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="PriestID" style="margin-left: 55px;">Select Priest:</label><br>
                    <div style="margin-left: 55px;">
                    <select  id="addPriestID" name="PriestID" required style="width: 80%; padding: 5px;">
                        <option value="">-- Select Priest --</option>
                        <?php
                        $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");
                        if ($conn->connect_error) {
                            die("Connection failed: " . $conn->connect_error);
                        }

                        $priestSql = "SELECT PriestID, FullName, ContactInfo, Status FROM Priest ORDER BY FullName";
                        $priestResult = $conn->query($priestSql);

                        if ($priestResult->num_rows > 0) {
                            while($priest = $priestResult->fetch_assoc()) {
                                $status = $priest["Status"] ?? "Active";
                                $contact = $priest["ContactInfo"] ?? "No contact info";
                                echo "<option value='" . $priest["PriestID"] . "'>" .
                                    $priest["FullName"] . " | " .
                                    $contact . " | " .
                                    $status . "</option>";
                            }
                        }
                        $conn->close();
                        ?>
                    </select>
                    <small id="addPriestIDError" class="error-message hidden" style="margin-left: 30px;">Please select a priest.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="Remark" style="margin-left: 30px;">Remark:</label><br>
                    <div style="margin-left: 30px;">
                    <textarea id="addRemark" name="Remark" required
                              style="width: 87.9%; min-height: 60px; padding: 5px; resize: none;"
                              oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px';"></textarea>
                    <small id="addRemarkError" class="error-message hidden" style="margin-left: 30px;">Remark should not contain HTML tags.</small>
                    </div>
                </div>


            </div>

            <div class="modal-footer" style="text-align: center; margin-top: 30px;">
                <button type="submit" id="addBaptismalSubmitButton" name="submitRecord" style="background-color: #28a745; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">+ Add Record</button>
            </div>
        </form>
    </div>


    <!-- Admin Modal -->
    <div class="modal" id="adminModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
        background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:9999;">

        <form class="modal-content"
              style="position: relative; width: 400px; padding: 20px; background: #fff; border-radius: 8px;"
              onsubmit="return validateAdmin(event)">

            <!-- Close button -->
            <span onclick="closeAdminModal()"
                  style="position: absolute; top: 10px; right: 15px; font-size: 30px; cursor: pointer; color: #B9B9B9;">&times;</span>

            <h2 style="text-align: center; margin-bottom: 10px; color: #F39C12; margin-bottom: 30px;">Admin</h2>
            <p style="text-align: left; margin-bottom: 5px; font-weight: lighter;">Enter Admin Password:</p>

            <input type="password"
                   id="adminPassword"
                   placeholder="Enter Admin Password"
                   required
                   style="width: 100%;
                                padding: 10px;
                                border: 1px solid rgba(102, 102, 102, 0.35);
                                border-radius: 3px;">

            <div style="text-align: center; margin-top: 20px;">
                <button type="submit"
                        style="padding: 10px 20px; background-color: #F39C12; color: white; border: none; border-radius: 5px; width: 100%;">
                    Submit
                </button>
            </div>
        </form>
    </div>

    <!-- Update Modal -->
    <div class="modal" id="updateModal">
        <form class="modal-content" id="updateBaptismalForm" method="POST" action="baptismal.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeUpdateModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>

            <div class="modal-header" style="background: #F39C12; color: white; text-align: center; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Update Baptismal Record</h3>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">

                <div style="flex: 1 1 45%;">
                    <label for="updateBaptismID" style="margin-left: 55px;">Baptism ID:</label><br>
                    <input type="text" id="updateBaptismID" name="BaptismID" readonly style="width: 80%; padding: 5px; margin-left: 55px; background-color: #e9e9e9;">
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateMotherName" style="margin-left: 30px;">Mother's Name:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="updateMotherName" name="MotherName" required style="width: 80%; padding: 5px;">
                    <small id="updateMotherNameError" class="error-message hidden" style="margin-left: 30px;">Mother's name is required.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateBaptismYear" style="margin-left: 55px;">Baptism Year:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="updateBaptismYear" name="BaptismYear" required style="width: 80%; padding: 5px;">
                    <small id="updateBaptismYearError" class="error-message hidden" style="margin-left: 55px;">Baptism year is required (YYYY).</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateMotherPlaceOfOrigin" style="margin-left: 30px;">Mother's Place Of Origin:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="updateMotherPlaceOfOrigin" name="MotherPlaceOfOrigin" required style="width: 80%; padding: 5px;">
                    <small id="updateMotherPlaceOfOriginError" class="error-message hidden" style="margin-left: 30px;">Mother's place of origin required.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateBaptismMonthDay" style="margin-left: 55px;">Baptism Month & Day:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="updateBaptismMonthDay" name="BaptismMonthDay" required style="width: 80%; padding: 5px;">
                    <small id="updateBaptismMonthDayError" class="error-message hidden" style="margin-left: 55px;">Baptism month & day required (e.g., January 01).</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateMotherPlaceOfResidence" style="margin-left: 30px;">Mother's Place Of Residence:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="updateMotherPlaceOfResidence" name="MotherPlaceOfResidence" required style="width: 80%; padding: 5px;">
                    <small id="updateMotherPlaceOfResidenceError" class="error-message hidden" style="margin-left: 30px;">Mother's place of residence required.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateClientID" style="margin-left: 55px;">Client ID:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="number" id="updateClientID" name="ClientID" required style="width: 80%; padding: 5px;">
                    <small id="updateClientIDError" class="error-message hidden" style="margin-left: 55px;">Client ID must be a positive number.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateOneSponsorName" style="margin-left: 30px;">One Sponsor Name:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="updateOneSponsorName" name="OneSponsorName" required style="width: 80%; padding: 5px;">
                    <small id="updateOneSponsorNameError" class="error-message hidden" style="margin-left: 30px;">First sponsor's name required.</small>
                    </div>
                </div>

                 <div style="flex: 1 1 45%;">
                    <label for="updateBdayYear" style="margin-left: 55px;">Birthday Year:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="updateBdayYear" name="BdayYear" required style="width: 80%; padding: 5px;">
                    <small id="updateBdayYearError" class="error-message hidden" style="margin-left: 55px;">Birthday year is required (YYYY).</small>
                    </div>
                </div>

                

                <div style="flex: 1 1 45%;">
                    <label for="updateOneSponsorPlace" style="margin-left: 30px;">One Sponsor Place:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="updateOneSponsorPlace" name="OneSponsorPlace" required style="width: 80%; padding: 5px;">
                    <small id="updateOneSponsorPlaceError" class="error-message hidden" style="margin-left: 30px;">First sponsor's place required.</small>
                    </div>
                </div>

               <div style="flex: 1 1 45%;">
                    <label for="updateBdayMonthDay" style="margin-left: 55px;">Birthday Month & Day:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="updateBdayMonthDay" name="BdayMonthDay" required style="width: 80%; padding: 5px;">
                    <small id="updateBdayMonthDayError" class="error-message hidden" style="margin-left: 55px;">Birthday month & day required (e.g., January 01).</small>
                    </div>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updateTwoSponsorName" style="margin-left: 30px;">Two Sponsor Name:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="updateTwoSponsorName" name="TwoSponsorName" required style="width: 80%; padding: 5px;">
                    <small id="updateTwoSponsorNameError" class="error-message hidden" style="margin-left: 30px;">Second sponsor's name required.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateLegitimacy" style="margin-left: 55px;">Legitimacy:</label><br>
                    <div style="margin-left: 55px;">
                    <select id="updateLegitimacy" name="Legitimacy" required style="width: 80%; padding: 5px;">
                        <option value="">-- Select Legitimacy --</option>
                        <option value="Legitimate">Legitimate</option>
                        <option value="Illegitimate">Illegitimate</option>
                        <option value="Adopted">Adopted</option>
                        <option value="Unknown">Unknown</option>
                    </select>
                    <small id="updateLegitimacyError" class="error-message hidden" style="margin-left: 55px;">Please select a legitimacy status.</small>
                    </div>
                </div>

                
                <div style="flex: 1 1 45%;">
                    <label for="updateTwoSponsorPlace" style="margin-left: 30px;">Two Sponsor Place:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="text" id="updateTwoSponsorPlace" name="TwoSponsorPlace" required style="width: 80%; padding: 5px;">
                    <small id="updateTwoSponsorPlaceError" class="error-message hidden" style="margin-left: 30px;">Second sponsor's place required.</small>
                    </div>
                </div>

                 <div style="flex: 1 1 45%;">
                    <label for="updateFatherName" style="margin-left: 55px;">Father's Name:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="updateFatherName" name="FatherName" required style="width: 80%; padding: 5px;">
                    <small id="updateFatherNameError" class="error-message hidden" style="margin-left: 55px;">Father's name is required.</small>
                    </div>
                </div>

                
                <div style="flex: 1 1 45%;">
                    <label for="updateStipendPesos" style="margin-left: 30px;">Stipend Pesos:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="number" id="updateStipendPesos" name="StipendPesos" style="width: 80%; padding: 5px;">
                    <small id="updateStipendPesosError" class="error-message hidden" style="margin-left: 30px;">Must be a valid number (e.g., 100.50).</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateFatherPlaceOfOrigin" style="margin-left: 55px;">Father's Place Of Origin:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="updateFatherPlaceOfOrigin" name="FatherPlaceOfOrigin" required style="width: 80%; padding: 5px;">
                    <small id="updateFatherPlaceOfOriginError" class="error-message hidden" style="margin-left: 55px;">Father's place of origin required.</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateStipendCts" style="margin-left: 30px;">Stipend Cents:</label><br>
                    <div style="margin-left: 30px;">
                    <input type="number" id="updateStipendCts" name="StipendCts" style="width: 80%; padding: 5px;">
                    <small id="updateStipendCtsError" class="error-message hidden" style="margin-left: 30px;">Must be a whole number (0-99).</small>
                    </div>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateFatherPlaceOfResidence" style="margin-left: 55px;">Father's Place Of Residence:</label><br>
                    <div style="margin-left: 55px;">
                    <input type="text" id="updateFatherPlaceOfResidence" name="FatherPlaceOfResidence" required style="width: 80%; padding: 5px;">
                    <small id="updateFatherPlaceOfResidenceError" class="error-message hidden" style="margin-left: 55px;">Father's place of residence required.</small>
                    </div>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updatePriestID" style="margin-left: 30px;">Select Priest:</label><br>
                    <div style="margin-left: 30px;">
                    <select name="PriestID" id="updatePriestID" required style="width: 80%; padding: 5px;">
                        <option value="">-- Select Priest --</option>
                        <?php
                        $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");
                        if ($conn->connect_error) {
                            die("Connection failed: " . $conn->connect_error);
                        }

                        $priestSql = "SELECT PriestID, FullName, ContactInfo, Status FROM Priest ORDER BY FullName";
                        $priestResult = $conn->query($priestSql);

                        if ($priestResult->num_rows > 0) {
                            while($priest = $priestResult->fetch_assoc()) {
                                $status = $priest["Status"] ?? "Active";
                                $contact = $priest["ContactInfo"] ?? "No contact info";
                                echo "<option value='" . $priest["PriestID"] . "'>" .
                                    $priest["FullName"] . " | " .
                                    $contact . " | " .
                                    $status . "</option>";
                            }
                        }
                        $conn->close();
                        ?>
                    </select>
                    <small id="updatePriestIDError" class="error-message hidden" style="margin-left: 30px;">Please select a priest.</small>
                    </div>
                </div>

                <div style="flex: 1 1 96%;"> <label for="updateRemark" style="margin-left: 55px;">Remark:</label><br>
                    <div style="margin-left: 55px;">
                    <textarea name="Remark" id="updateRemark" required
                              style="width: 90%; min-height: 60px; padding: 5px; resize: none;"
                              oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px';"></textarea>
                    <small id="updateRemarkError" class="error-message hidden" style="margin-left: 55px;">Remark should not contain HTML tags.</small>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" id="updateBaptismalSubmitButton" name="updateRecord" style="background-color: #F39C12; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">✎ Update Record</button>
            </div>

            <input type="hidden" name="adminPassword" id="hiddenAdminPassword">
        </form>
    </div>
    <!-- Update Modal [end] -->


    <!-- message modal when admin password is correct/incorrect -->
    <div id="messageModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
        background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:9999;">
        <div style="background:white; padding:20px; border-radius:10px; max-width:400px; text-align:center;">
            <p id="messageModalText" style="color:black; font-size:16px; padding:10px;">
                Message here
            </p>
            <button
                    onclick="document.getElementById('messageModal').style.display='none'"
                    style="background-color:#F39C12; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">
                OK
            </button>
        </div>
    </div>


    <!-- Modal for Client ID input WITH CHOOSING CERT [start] -->
    <div id="certificateModal" class="modal">
        <div class="modal-contentCert">
            <span class="close-btn" onclick="closeCertModal()">&times;</span>
            <h2>Generate Certificate</h2>
            <form action="generate_certificate.php" method="GET" id="certificateForm">
                <label for="client_id">Enter Baptismal/Confirmation ID:</label><br>
                <input type="text" id="client_id" name="client_id" placeholder="Enter Baptismal/Confirmation ID" required><br><br>

                <!-- Certificate Type Dropdown -->
                <div class="dropdown-up-container">
                    <div class="certChoose-btn-wrapper">
                        <button type="button" class="certChoose-btn" onclick="toggleCertType()">
                            Choose Certificate <span id="certDropdownIcon">▶</span>
                        </button>
                    </div>
                    <ul id="certTypeDropdown" class="cert-dropdown hidden">
                        <li onclick="selectCertType('baptismal')">Baptismal Certificate</li>
                        <li onclick="selectCertType('confirmation')">Confirmation Certificate</li>
                    </ul>
                </div>
                <input type="hidden" name="type" id="certTypeInput" required>
                <br>
                <button id="generateCertBtn" class="certAdd-btn" type="submit">Generate Certificate</button>
            </form>
        </div>
    </div>
    <!-- Modal for Client ID input WITH CHOOSING CERT [start] -->


    <!------- JAVASCRIPT ------->

    <script>
        /*----- GLOBAL CONSTANTS & REGEX -----*/
        const baptismalRegexPatterns = {
            positiveInteger: /^\d+$/,
            year: /^\d{4}$/,
            monthDay: /^(January|February|March|April|May|June|July|August|September|October|November|December)\s+([1-9]|[12][0-9]|3[01])$/i,
            name: /^[\p{L}\s.'-]+$/u,
            address: /^[\p{L}\p{N}\s,.'#/\-]+$/u,
            // generalText: /^[\p{L}\p{N}\s.,;:'"()/\-]+$/u, // Not used for Legitimacy anymore
            stipend: /^\d*(\.\d{1,2})?$/,
            noHtmlTags: /^[^<>]*$/
        };
        const currentYearJS = new Date().getFullYear();

        /*----- BAPTISMAL CLIENT-SIDE VALIDATION -----*/
        const addBaptismalForm = document.getElementById('addBaptismalForm');
        const addBaptismalSubmitButton = document.getElementById('addBaptismalSubmitButton');
        const addBaptismalFields = {
            PriestID: document.getElementById('addPriestID'), BaptismYear: document.getElementById('addBaptismYear'),
            BaptismMonthDay: document.getElementById('addBaptismMonthDay'), ClientID: document.getElementById('addClientID'),
            BdayYear: document.getElementById('addBdayYear'), BdayMonthDay: document.getElementById('addBdayMonthDay'),
            Legitimacy: document.getElementById('addLegitimacy'),
            FatherName: document.getElementById('addFatherName'), FatherPlaceOfOrigin: document.getElementById('addFatherPlaceOfOrigin'),
            FatherPlaceOfResidence: document.getElementById('addFatherPlaceOfResidence'), MotherName: document.getElementById('addMotherName'),
            MotherPlaceOfOrigin: document.getElementById('addMotherPlaceOfOrigin'), MotherPlaceOfResidence: document.getElementById('addMotherPlaceOfResidence'),
            OneSponsorName: document.getElementById('addOneSponsorName'), OneSponsorPlace: document.getElementById('addOneSponsorPlace'),
            TwoSponsorName: document.getElementById('addTwoSponsorName'), TwoSponsorPlace: document.getElementById('addTwoSponsorPlace'),
            StipendPesos: document.getElementById('addStipendPesos'), StipendCts: document.getElementById('addStipendCts'),
            Remark: document.getElementById('addRemark')
        };
        const addBaptismalFormState = {};

        const updateBaptismalForm = document.getElementById('updateBaptismalForm');
        const updateBaptismalSubmitButton = document.getElementById('updateBaptismalSubmitButton');
        const updateBaptismalFields = {
            PriestID: document.getElementById('updatePriestID'), BaptismYear: document.getElementById('updateBaptismYear'),
            BaptismMonthDay: document.getElementById('updateBaptismMonthDay'), ClientID: document.getElementById('updateClientID'),
            BdayYear: document.getElementById('updateBdayYear'), BdayMonthDay: document.getElementById('updateBdayMonthDay'),
            Legitimacy: document.getElementById('updateLegitimacy'),
            FatherName: document.getElementById('updateFatherName'), FatherPlaceOfOrigin: document.getElementById('updateFatherPlaceOfOrigin'),
            FatherPlaceOfResidence: document.getElementById('updateFatherPlaceOfResidence'), MotherName: document.getElementById('updateMotherName'),
            MotherPlaceOfOrigin: document.getElementById('updateMotherPlaceOfOrigin'), MotherPlaceOfResidence: document.getElementById('updateMotherPlaceOfResidence'),
            OneSponsorName: document.getElementById('updateOneSponsorName'), OneSponsorPlace: document.getElementById('updateOneSponsorPlace'),
            TwoSponsorName: document.getElementById('updateTwoSponsorName'), TwoSponsorPlace: document.getElementById('updateTwoSponsorPlace'),
            StipendPesos: document.getElementById('updateStipendPesos'), StipendCts: document.getElementById('updateStipendCts'),
            Remark: document.getElementById('updateRemark')
        };
        const updateBaptismalFormState = {};

        function validateBaptismalField(fieldName, value, fieldElement, formTypePrefix) {
            let isValid = false;
            const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
            const currentFormState = (formTypePrefix === 'add') ? addBaptismalFormState : updateBaptismalFormState;

            value = String(value).trim();
            let specificErrorMsg = '';

            if (!fieldElement) { currentFormState[fieldName] = true; checkBaptismalFormOverallValidity(formTypePrefix); return; }

            const isOptional = ['StipendPesos', 'StipendCts', 'Remark'].includes(fieldName);

            if (isOptional && value === '') {
                isValid = true;
            } else if (!isOptional && value === '') {
                isValid = false;
                specificErrorMsg = fieldName.replace(/([A-Z0-9])/g, ' $1').trim().replace('One ', 'First ').replace('Two ', 'Second ') + ' is required.';
                if (fieldName === 'Legitimacy' || fieldName === 'PriestID') { // For select elements
                    specificErrorMsg = `Please select a ${fieldName === 'Legitimacy' ? 'legitimacy status' : 'priest'}.`;
                }
            } else {
                switch(fieldName) {
                    case 'PriestID': isValid = value !== ''; specificErrorMsg = 'Please select a Priest.'; break;
                    case 'BaptismYear': case 'BdayYear':
                        isValid = baptismalRegexPatterns.year.test(value);
                        if (isValid) {
                            const yearVal = parseInt(value);
                            const maxYear = (fieldName === 'BaptismYear') ? (currentYearJS + 5) : currentYearJS;
                            if (yearVal < 1800 || yearVal > maxYear) {
                                isValid = false; specificErrorMsg = `${fieldName.includes('Bday') ? 'Birth' : 'Baptism'} Year must be between 1800 and ${maxYear}.`;
                            }
                        } else { specificErrorMsg = `${fieldName.includes('Bday') ? 'Birth' : 'Baptism'} Year must be a 4-digit year.`; }
                        break;
                    case 'BaptismMonthDay': case 'BdayMonthDay':
                        isValid = baptismalRegexPatterns.monthDay.test(value);
                        specificErrorMsg = `Invalid format. Use 'Month Day' (e.g., January 01).`;
                        break;
                    case 'ClientID':
                        isValid = baptismalRegexPatterns.positiveInteger.test(value) && parseInt(value) > 0;
                        specificErrorMsg = 'Client ID must be a positive number.';
                        break;
                    case 'FatherName': case 'MotherName': case 'OneSponsorName': case 'TwoSponsorName':
                        isValid = baptismalRegexPatterns.name.test(value) && value.length <= 100;
                        if (!baptismalRegexPatterns.name.test(value)) specificErrorMsg = 'Name contains invalid characters (letters, spaces, .\'- allowed).';
                        else if (value.length > 100) specificErrorMsg = 'Name is too long (max 100 characters).';
                        // else specificErrorMsg = 'Invalid name format.'; // Fallback can be removed if above are sufficient
                        break;
                    case 'Legitimacy': // This is now a select
                        isValid = value !== '';
                        specificErrorMsg = 'Please select a legitimacy status.';
                        break;
                    case 'FatherPlaceOfOrigin': case 'FatherPlaceOfResidence': case 'MotherPlaceOfOrigin':
                    case 'MotherPlaceOfResidence': case 'OneSponsorPlace': case 'TwoSponsorPlace':
                        isValid = baptismalRegexPatterns.address.test(value) && value.length <= 255;
                        if (!baptismalRegexPatterns.address.test(value)) specificErrorMsg = 'Place/Address contains invalid characters.';
                        else if (value.length > 255) specificErrorMsg = 'Place/Address is too long (max 255 characters).';
                        break;
                    case 'StipendPesos':
                        isValid = (value === '') ? true : (baptismalRegexPatterns.stipend.test(value) && parseFloat(value) >= 0);
                        if (!isValid && value !== '') specificErrorMsg = 'Stipend Pesos must be a non-negative number (e.g., 100 or 100.50).';
                        break;
                    case 'StipendCts':
                        isValid = (value === '') ? true : (baptismalRegexPatterns.positiveInteger.test(value) && parseInt(value) >= 0 && parseInt(value) < 100);
                        if (!isValid && value !== '') specificErrorMsg = 'Stipend Cents must be a whole number between 0 and 99.';
                        break;
                    case 'Remark':
                        isValid = (value === '') ? true : (baptismalRegexPatterns.noHtmlTags.test(value) && value.length <= 500);
                        if (!isValid && value !== '') {
                            if (!baptismalRegexPatterns.noHtmlTags.test(value)) specificErrorMsg = 'Remark should not contain HTML tags like < or >.';
                            else if (value.length > 500) specificErrorMsg = 'Remark is too long (max 500 characters).';
                        }
                        break;
                    default: isValid = true;
                }
            }

            currentFormState[fieldName] = isValid;
            if (errorElement) {
                if (isValid) {
                    fieldElement.classList.remove('invalid'); fieldElement.classList.add('valid');
                    errorElement.classList.add('hidden'); errorElement.textContent = '';
                } else {
                    fieldElement.classList.remove('valid'); fieldElement.classList.add('invalid');
                    errorElement.classList.remove('hidden'); errorElement.textContent = specificErrorMsg;
                }
            }
            checkBaptismalFormOverallValidity(formTypePrefix);
        }

        function checkBaptismalFormOverallValidity(formTypePrefix) {
            const currentFormState = (formTypePrefix === 'add') ? addBaptismalFormState : updateBaptismalFormState;
            const currentFields = (formTypePrefix === 'add') ? addBaptismalFields : updateBaptismalFields;
            const submitBtn = (formTypePrefix === 'add') ? addBaptismalSubmitButton : updateBaptismalSubmitButton;

            if (!submitBtn) return;
            let allValid = true;
            for (const fieldName in currentFields) {
                if (currentFields.hasOwnProperty(fieldName) && currentFormState[fieldName] !== true) {
                    allValid = false; break;
                }
            }
            submitBtn.disabled = !allValid;
            submitBtn.style.backgroundColor = allValid ? ((formTypePrefix === 'add') ? '#28a745' : '#F39C12') : '#cccccc';
            submitBtn.style.cursor = allValid ? 'pointer' : 'not-allowed';
        }

        function initializeBaptismalValidation(formTypePrefix) {
            const form = (formTypePrefix === 'add') ? addBaptismalForm : updateBaptismalForm;
            const fields = (formTypePrefix === 'add') ? addBaptismalFields : updateBaptismalFields;
            const formState = (formTypePrefix === 'add') ? addBaptismalFormState : updateBaptismalFormState;
            const submitButton = (formTypePrefix === 'add') ? addBaptismalSubmitButton : updateBaptismalSubmitButton;

            if (!form || !submitButton) return;
            submitButton.disabled = true; submitButton.style.backgroundColor = '#cccccc'; submitButton.style.cursor = 'not-allowed';

            for (const fieldName in fields) {
                if (fields.hasOwnProperty(fieldName)) {
                    const fieldElement = fields[fieldName];
                    if (fieldElement) {
                        const isOptional = ['StipendPesos', 'StipendCts', 'Remark'].includes(fieldName);
                        formState[fieldName] = (isOptional && fieldElement.value.trim() === '') ? true : false;

                        const eventType = (fieldElement.tagName === 'SELECT' || fieldName.includes('Stipend')) ? 'change' : 'input';
                        fieldElement.addEventListener(eventType, function() { validateBaptismalField(fieldName, this.value, this, formTypePrefix); });
                        fieldElement.addEventListener('blur', function() { validateBaptismalField(fieldName, this.value, this, formTypePrefix); });
                        if (fieldElement.value.trim() !== '' || isOptional) {
                            validateBaptismalField(fieldName, fieldElement.value, fieldElement, formTypePrefix);
                        }
                    }
                }
            }

            form.addEventListener('submit', function(event) {
                console.log("Form submit event fired! Current formState:", JSON.stringify(formState));
                let formIsValid = true;
                for (const fieldName in fields) {
                    if (fields.hasOwnProperty(fieldName) && fields[fieldName]) {
                        validateBaptismalField(fieldName, fields[fieldName].value, fields[fieldName], formTypePrefix);
                        if (formState[fieldName] === false) formIsValid = false;
                    }
                }
                if (formTypePrefix === 'update' && document.getElementById('hiddenAdminPassword').value === '') {
                    showMessageModal("Admin password missing for update. Please re-authenticate."); formIsValid = false;
                }
                if (!formIsValid) {
                    event.preventDefault();
                    alert('Please correct the errors highlighted in the form before submitting.');
                }
            });
        }

        function resetBaptismalForm(formTypePrefix) {
            const form = (formTypePrefix === 'add') ? addBaptismalForm : updateBaptismalForm;
            const fields = (formTypePrefix === 'add') ? addBaptismalFields : updateBaptismalFields;
            const formState = (formTypePrefix === 'add') ? addBaptismalFormState : updateBaptismalFormState;
            const submitButton = (formTypePrefix === 'add') ? addBaptismalSubmitButton : updateBaptismalSubmitButton;

            if (formTypePrefix === 'add' && form) form.reset();
            else if (formTypePrefix === 'update') {
                for (const fieldName in fields) {
                    if (fields.hasOwnProperty(fieldName) && fields[fieldName]) fields[fieldName].value = '';
                }
                if(document.getElementById('updateBaptismID')) document.getElementById('updateBaptismID').value = '';
            }

            for (const fieldName in fields) {
                if (fields.hasOwnProperty(fieldName) && fields[fieldName]) {
                    fields[fieldName].classList.remove('valid', 'invalid');
                    const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
                    if (errorElement) { errorElement.classList.add('hidden'); errorElement.textContent = ''; }
                    const isOptional = ['StipendPesos', 'StipendCts', 'Remark'].includes(fieldName);
                    formState[fieldName] = isOptional ? true : false;
                }
            }
            if (submitButton) { submitButton.disabled = true; submitButton.style.backgroundColor = '#cccccc'; submitButton.style.cursor = 'not-allowed'; }
            if (formTypePrefix === 'update') document.getElementById('hiddenAdminPassword').value = '';
        }

        let adminAuthenticated = false;
        document.addEventListener('keydown', function (event) {
            if (event.key === "Escape") {
                if (document.getElementById("adminModal").style.display === "flex") closeAdminModal();
                if (document.getElementById("messageModal").style.display === "flex") document.getElementById('messageModal').style.display = 'none';
                if (document.getElementById("recordModal").style.display === "flex") closeModal();
                if (document.getElementById("updateModal").style.display === "flex") closeUpdateModal();
                if (document.getElementById("certificateModal").style.display === "block") closeCertModal();
            }
        });
        function openAdminModal() { document.getElementById("adminPassword").value = ""; document.getElementById("adminModal").style.display = "flex"; }
        function closeAdminModal() {
            document.getElementById("adminModal").style.display = "none"; adminAuthenticated = false;
            document.getElementById("adminPassword").value = ''; document.getElementById("hiddenAdminPassword").value = '';
            disableRowClickEdit();
        }
        function validateAdmin(event) {
            event.preventDefault(); const inputPassword = document.getElementById("adminPassword").value;
            fetch("update_validation.php", { method: "POST", headers: {"Content-Type": "application/x-www-form-urlencoded"}, body: "password=" + encodeURIComponent(inputPassword)})
                .then(response => response.json()).then(data => {
                if (data.success) {
                    adminAuthenticated = true; document.getElementById("adminModal").style.display = "none";
                    showMessageModal("Access granted. Please click on a record to edit.");
                    document.getElementById("hiddenAdminPassword").value = inputPassword; enableRowClickEdit();
                } else { adminAuthenticated = false; showMessageModal("Incorrect password."); disableRowClickEdit(); }
                document.getElementById("adminPassword").value = '';
            }).catch(error => { showMessageModal("An error occurred. Try again."); adminAuthenticated = false; disableRowClickEdit(); });
            return false;
        }
        function showMessageModal(message) {
            document.getElementById("messageModalText").textContent = message;
            document.getElementById("messageModal").style.display = "flex";
        }

        document.getElementById("addRecordBtn").onclick = function () { resetBaptismalForm('add'); document.getElementById("recordModal").style.display = "flex"; };
        function closeModal() { document.getElementById("recordModal").style.display = "none"; resetBaptismalForm('add'); }

        document.getElementById("updateRecordBtn").onclick = function () { adminAuthenticated = false; resetBaptismalForm('update'); openAdminModal(); };

        function enableRowClickEdit() {
            const rows = document.querySelectorAll("#recordsTable tbody tr");
            rows.forEach(row => {
                if (row.querySelectorAll("td[rowspan='2']").length > 0) {
                    row.style.cursor = "pointer";
                    row.onclick = function () {
                        if (!adminAuthenticated) { showMessageModal("Admin authentication required."); return; }
                        const cellsRow1 = this.querySelectorAll("td");
                        const nextRow = this.nextElementSibling;
                        const cellsRow2 = nextRow ? nextRow.querySelectorAll("td") : [];

                        if (cellsRow1.length >= 17 && cellsRow2.length >= 5) {
                            document.getElementById("updateBaptismID").value = cellsRow1[0].innerText.trim();
                            document.getElementById("updateBaptismYear").value = cellsRow1[1].innerText.trim();
                            document.getElementById("updateBaptismMonthDay").value = cellsRow1[2].innerText.trim();
                            document.getElementById("updateClientID").value = cellsRow1[3].innerText.trim();
                            document.getElementById("updateBdayYear").value = cellsRow1[4].innerText.trim();
                            document.getElementById("updateBdayMonthDay").value = cellsRow1[5].innerText.trim();
                            document.getElementById("updateLegitimacy").value = cellsRow1[6].innerText.trim(); // This will set the select value
                            document.getElementById("updateFatherName").value = cellsRow1[7].innerText.trim();
                            document.getElementById("updateFatherPlaceOfOrigin").value = cellsRow1[8].innerText.trim();
                            document.getElementById("updateFatherPlaceOfResidence").value = cellsRow1[9].innerText.trim();
                            document.getElementById("updateOneSponsorName").value = cellsRow1[10].innerText.trim();
                            document.getElementById("updateOneSponsorPlace").value = cellsRow1[11].innerText.trim();
                            document.getElementById("updateStipendPesos").value = cellsRow1[12].innerText.trim();
                            document.getElementById("updateStipendCts").value = cellsRow1[13].innerText.trim();

                            const priestNameInTable = cellsRow1[14].innerText.trim();
                            const priestSelect = document.getElementById("updatePriestID"); let matchedPriestID = '';
                            if (priestNameInTable !== '-') {
                                for(let i = 0; i < priestSelect.options.length; i++) {
                                    const optionTextParts = priestSelect.options[i].text.split('|');
                                    if (optionTextParts.length > 0 && optionTextParts[0].trim() === priestNameInTable) {
                                        matchedPriestID = priestSelect.options[i].value; break;
                                    }
                                }
                            }
                            priestSelect.value = matchedPriestID;
                            document.getElementById("updateRemark").value = cellsRow1[15].innerText.trim();

                            document.getElementById("updateMotherName").value = cellsRow2[0].innerText.trim();
                            document.getElementById("updateMotherPlaceOfOrigin").value = cellsRow2[1].innerText.trim();
                            document.getElementById("updateMotherPlaceOfResidence").value = cellsRow2[2].innerText.trim();
                            document.getElementById("updateTwoSponsorName").value = cellsRow2[3].innerText.trim();
                            document.getElementById("updateTwoSponsorPlace").value = cellsRow2[4].innerText.trim();

                            for (const fieldName in updateBaptismalFields) {
                                if (updateBaptismalFields.hasOwnProperty(fieldName) && updateBaptismalFields[fieldName]) {
                                    validateBaptismalField(fieldName, updateBaptismalFields[fieldName].value, updateBaptismalFields[fieldName], 'update');
                                }
                            }
                            document.getElementById("updateModal").style.display = "flex";
                        } else { showMessageModal("Error: Could not load all record data."); }
                    };
                } else { row.style.cursor = "default"; row.onclick = null; }
            });
        }
        function disableRowClickEdit() { document.querySelectorAll("#recordsTable tbody tr").forEach(r => {r.onclick = null; r.style.cursor = "default";}); }
        function closeUpdateModal() { document.getElementById("updateModal").style.display = "none"; adminAuthenticated = false; disableRowClickEdit(); resetBaptismalForm('update'); }

        window.onclick = function (event) {
            const modals = [
                { id: "recordModal", closeFn: closeModal }, { id: "updateModal", closeFn: closeUpdateModal },
                { id: "adminModal", closeFn: closeAdminModal }, { id: "messageModal", closeFn: () => document.getElementById("messageModal").style.display = "none" },
                { id: "certificateModal", closeFn: closeCertModal }
            ];
            modals.forEach(m => { if (event.target === document.getElementById(m.id)) m.closeFn(); });
        };

        function toggleSidebar() { document.querySelector(".sidebar").classList.toggle("active"); }
        function toggleDropdown() { document.getElementById("certificateDropdown").classList.toggle("dropdown-active"); document.getElementById("certificates").classList.toggle("open"); }
        function openCertModal() { document.getElementById("certificateModal").style.display = "block"; }
        function closeCertModal() { document.getElementById("certificateModal").style.display = "none"; }
        function toggleCertType() { document.getElementById("certTypeDropdown").classList.toggle("dropdown-active"); document.getElementById("certDropdownIcon").classList.toggle("rotated"); }
        function selectCertType(type) {
            document.getElementById("certTypeInput").value = type;
            const btn = document.querySelector("#certificateModal .certChoose-btn");
            btn.innerHTML = (type === 'baptismal' ? 'Baptismal' : 'Confirmation') + ' Certificate <span id="certDropdownIcon" class="rotated">▶</span>';
            document.getElementById("certTypeDropdown").classList.remove("dropdown-active");
        }
        const pageNav = {
            dashboardButton: "dashboard.php", priestButton: "priestrecords.php", eventsButton: "event.php",
            massButton: "massSchedule.php", baptismalButton: "baptismal.php", MarriageButton: "marriage.php",
            burialButton: "burial.php", confirmationButton: "confirmation.php", clientButton: "client.php"
        };
        for (const btnId in pageNav) { if (document.getElementById(btnId)) document.getElementById(btnId).addEventListener("click", () => window.location.href = pageNav[btnId]); }

        document.getElementById("searchInput").addEventListener("keyup", function () {
            const filter = this.value.toLowerCase(); const rows = document.querySelectorAll("#recordsTable tbody tr");
            let firstRowOfRecordVisible = false;
            rows.forEach(row => {
                if (row.querySelectorAll("td[rowspan='2']").length > 0) {
                    const text = row.textContent.toLowerCase() + (row.nextElementSibling ? row.nextElementSibling.textContent.toLowerCase() : "");
                    firstRowOfRecordVisible = text.includes(filter); row.style.display = firstRowOfRecordVisible ? "" : "none";
                } else { row.style.display = firstRowOfRecordVisible ? "" : "none"; }
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            if (addBaptismalForm) initializeBaptismalValidation('add');
            if (updateBaptismalForm) initializeBaptismalValidation('update');
        });

        // Add this to your existing JavaScript block at the end of baptismal.php
        const sidebarToggle = document.getElementById('sidebarToggleBtn');
        const sidebarElement = document.querySelector('.sidebar');
        const mainPageOverlay = document.createElement('div'); // For overlay effect

        if (sidebarToggle && sidebarElement) {
            mainPageOverlay.style.position = 'fixed';
            mainPageOverlay.style.top = '0';
            mainPageOverlay.style.left = '0';
            mainPageOverlay.style.width = '100%';
            mainPageOverlay.style.height = '100%';
            mainPageOverlay.style.backgroundColor = 'rgba(0,0,0,0.4)';
            mainPageOverlay.style.zIndex = '999'; // Below sidebar (1000), above content
            mainPageOverlay.style.display = 'none';
            document.body.appendChild(mainPageOverlay);

            sidebarToggle.addEventListener('click', () => {
                sidebarElement.classList.toggle('active');
                if (sidebarElement.classList.contains('active')) {
                    mainPageOverlay.style.display = 'block';
                } else {
                    mainPageOverlay.style.display = 'none';
                }
            });

            mainPageOverlay.addEventListener('click', () => { // Close sidebar if overlay is clicked
                if (sidebarElement.classList.contains('active')) {
                    sidebarElement.classList.remove('active');
                    mainPageOverlay.style.display = 'none';
                }
            });
        }

        /*--- ClientID Error [start] ---*/
        // Function to check if a client ID exists via AJAX
        function checkClientID(ClientID, callback) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'check_client.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onload = function() {
                if (xhr.status === 200) {
                    if (xhr.responseText.trim() === "not_exists") {
                        alert('Error: Client ID does not exist. Please check the Client ID and try again.');
                        callback(false);
                    } else {
                        callback(true);
                    }
                } else {
                    alert('Error validating Client ID. Please try again.');
                    callback(false);
                }
            };

            xhr.onerror = function() {
                alert('Network error occurred while validating Client ID.');
                callback(false);
            };

            xhr.send('ClientID=' + encodeURIComponent(ClientID));
        }

        document.addEventListener('DOMContentLoaded', function () {
            const addForm = document.getElementById('addBaptismalForm');
            const updateForm = document.getElementById('updateBaptismalForm');

            // --- START: New Filter JavaScript ---
            const categoryFilterSelect = document.getElementById('categoryFilter');
            const yearInputContainer = document.getElementById('filterYearInputContainer');
            const monthInputContainer = document.getElementById('filterMonthInputContainer');
            const dateInputContainer = document.getElementById('filterDateInputContainer');
            const yearValueInput = document.getElementById('filterYearValue');
            const monthValueSelect = document.getElementById('filterMonthValue');
            const dateValueInput = document.getElementById('filterDateValue');
            const applyFilterButton = document.getElementById('applyFilterBtn');
            const clearFilterButton = document.getElementById('clearFilterBtn');

            function toggleFilterInputs() {
                const selectedFilter = categoryFilterSelect.value;
                yearInputContainer.style.display = 'none';
                monthInputContainer.style.display = 'none';
                dateInputContainer.style.display = 'none';

                if (selectedFilter === 'year') {
                    yearInputContainer.style.display = 'inline-block';
                } else if (selectedFilter === 'month') {
                    monthInputContainer.style.display = 'inline-block';
                } else if (selectedFilter === 'specific_date') {
                    dateInputContainer.style.display = 'inline-block';
                }
            }

            if (categoryFilterSelect) {
                categoryFilterSelect.addEventListener('change', toggleFilterInputs);
            }

            if (applyFilterButton) {
                applyFilterButton.addEventListener('click', function() {
                    const filterType = categoryFilterSelect.value;
                    if (!filterType) { // No filter selected
                        // Optionally clear existing filters if "Apply" is clicked with no selection
                        // window.location.search = ''; // This would clear all query params
                        return;
                    }

                    let queryParams = new URLSearchParams(); // Start fresh for filter params
                    queryParams.set('filter_type', filterType);

                    if (filterType === 'year') {
                        if (!yearValueInput.value || !/^\d{4}$/.test(yearValueInput.value)) {
                            alert('Please enter a valid 4-digit year.');
                            return;
                        }
                        queryParams.set('filter_year_value', yearValueInput.value);
                    } else if (filterType === 'month') {
                        if (!monthValueSelect.value) {
                            alert('Please select a month.');
                            return;
                        }
                        queryParams.set('filter_month_value', monthValueSelect.value);
                    } else if (filterType === 'specific_date') {
                        if (!dateValueInput.value || !/^\d{2}\/\d{2}\/\d{4}$/.test(dateValueInput.value)) {
                            alert('Please enter a date in DD/MM/YYYY format.');
                            return;
                        }
                        queryParams.set('filter_date_value', dateValueInput.value);
                    } else if (filterType === 'oldest_to_latest') {
                        queryParams.set('sort_order', 'asc');
                    } else if (filterType === 'latest_to_oldest') {
                        queryParams.set('sort_order', 'desc');
                    }
                    window.location.search = queryParams.toString();
                });
            }

            if (clearFilterButton) {
                clearFilterButton.addEventListener('click', function() {
                    // Construct current URL without any filter parameters
                    const baseUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                    window.location.href = baseUrl; // Go to base URL, clearing all query params
                });
            }

            // Function to set filter UI based on URL parameters on page load
            function setFiltersFromUrl() {
                const urlParams = new URLSearchParams(window.location.search);
                const filterType = urlParams.get('filter_type');

                if (filterType) {
                    categoryFilterSelect.value = filterType;
                    toggleFilterInputs(); // Show the correct input fields

                    if (filterType === 'year' && urlParams.has('filter_year_value')) {
                        yearValueInput.value = urlParams.get('filter_year_value');
                    } else if (filterType === 'month' && urlParams.has('filter_month_value')) {
                        monthValueSelect.value = urlParams.get('filter_month_value');
                    } else if (filterType === 'specific_date' && urlParams.has('filter_date_value')) {
                        dateValueInput.value = urlParams.get('filter_date_value');
                    }
                } else if (urlParams.has('sort_order')) { // If only sorting is applied
                    const sortOrder = urlParams.get('sort_order');
                    if (sortOrder === 'asc') categoryFilterSelect.value = 'oldest_to_latest';
                    if (sortOrder === 'desc') categoryFilterSelect.value = 'latest_to_oldest';
                }
            }

            setFiltersFromUrl(); // Call on page load
            // --- END: New Filter JavaScript ---

            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const ClientID = document.getElementById('addClientID').value;

                    if (!this.checkValidity()) {
                        return false;
                    }

                    checkClientID(ClientID, function(isValid) {
                        if (isValid) {
                            const formData = new FormData(addForm);
                            formData.append('submitRecord', 'true');

                            fetch('baptismal.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.text())
                            .then(data => {
                                if (data.includes('Record inserted successfully')) {
                                    alert('Record inserted successfully!');
                                    window.location.reload();
                                } else {
                                    alert('Error inserting record. Record may already exist.');
                                }
                            })
                            .catch(() => {
                                alert('Error submitting form.');
                            });
                        }
                    });
                });
            }

            if (updateForm) {
                updateForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const ClientID = document.getElementById('updateClientID').value;

                    if (!this.checkValidity()) {
                        return false;
                    }

                    checkClientID(ClientID, function(isValid) {
                        if (isValid) {
                            const formData = new FormData(updateForm);
                            formData.append('updateRecord', 'true');

                            fetch('baptismal.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.text())
                            .then(data => {
                                if (data.includes('Record updated successfully')) {
                                    alert('Record updated successfully!');
                                    window.location.reload();
                                } else {
                                    alert('Error updating record.');
                                }
                            })
                            .catch(() => {
                                alert('Error updating form.');
                            });
                        }
                    });
                });
            }
        });
        /*--- ClientID Error [end] ---*/

    </script>
</body>
</html>