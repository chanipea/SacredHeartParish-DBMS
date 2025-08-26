<?php

// Prevent caching of the page after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$pageTitle = "Marriage Records Management";
require_once 'session_timeout.php'; 

// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Log_In\login_system.php");
    exit();
}

// --- START: Filter Logic for GET requests (Marriage Records by Wedding Date) ---
$whereClauses_marriage = [];
// Default order for marriage records
$orderByClause_marriage = "ORDER BY mr.MarriageID DESC";
$filter_params_marriage = []; // Parameters for prepared statement
$filter_param_types_marriage = ""; // Types for prepared statement

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (isset($_GET['filter_type_marriage']) && !empty($_GET['filter_type_marriage'])) {
        $filter_type = $_GET['filter_type_marriage'];

        switch ($filter_type) {
            case 'year':
                if (isset($_GET['filter_year_value_marriage']) && !empty($_GET['filter_year_value_marriage'])) {
                    $year = filter_var($_GET['filter_year_value_marriage'], FILTER_VALIDATE_INT);
                    if ($year && strlen((string)$year) == 4) {
                        $whereClauses_marriage[] = "mr.WeddingYear = ?";
                        $filter_params_marriage[] = $year;
                        $filter_param_types_marriage .= "i";
                    }
                }
                break;
            case 'month':
                if (isset($_GET['filter_month_value_marriage']) && !empty($_GET['filter_month_value_marriage'])) {
                    $monthName = $_GET['filter_month_value_marriage'];
                    $validMonths = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
                    if (in_array($monthName, $validMonths)) {
                        // This assumes WeddingMonthDay stores "Month Day" e.g., "January 1"
                        $whereClauses_marriage[] = "SUBSTRING_INDEX(mr.WeddingMonthDay, ' ', 1) = ?";
                        $filter_params_marriage[] = $monthName;
                        $filter_param_types_marriage .= "s";

                        if (isset($_GET['filter_year_for_month_value_marriage']) && !empty($_GET['filter_year_for_month_value_marriage'])) {
                            $year_for_month = filter_var($_GET['filter_year_for_month_value_marriage'], FILTER_VALIDATE_INT);
                            if ($year_for_month && strlen((string)$year_for_month) == 4) {
                                $whereClauses_marriage[] = "mr.WeddingYear = ?";
                                $filter_params_marriage[] = $year_for_month;
                                $filter_param_types_marriage .= "i";
                            }
                        }
                    }
                }
                break;
            case 'specific_date':
                if (isset($_GET['filter_date_value_marriage']) && !empty($_GET['filter_date_value_marriage'])) {
                    $date_str = $_GET['filter_date_value_marriage']; // Expects DD/MM/YYYY
                    $date_parts = explode('/', $date_str);
                    // Basic validation for DD/MM/YYYY format
                    if (count($date_parts) === 3 && ctype_digit($date_parts[0]) && ctype_digit($date_parts[1]) && ctype_digit($date_parts[2]) && checkdate((int)$date_parts[1], (int)$date_parts[0], (int)$date_parts[2])) {
                        $day_input = (int)$date_parts[0];
                        $month_num = (int)$date_parts[1];
                        $year_val = (int)$date_parts[2];

                        $dateObj = DateTime::createFromFormat('!m', $month_num); // Create date object from month number
                        $monthNameForQuery = $dateObj->format('F'); // Get full month name like 'January'

                        // Handle potential variations in day storage (e.g., "1" vs "01")
                        $weddingMonthDay_format1 = $monthNameForQuery . " " . $day_input; // e.g., "January 1"
                        $weddingMonthDay_format2 = $monthNameForQuery . " " . str_pad($day_input, 2, '0', STR_PAD_LEFT); // e.g., "January 01"


                        $whereClauses_marriage[] = "mr.WeddingYear = ?";
                        $filter_params_marriage[] = $year_val;
                        $filter_param_types_marriage .= "i";

                        $whereClauses_marriage[] = "(mr.WeddingMonthDay = ? OR mr.WeddingMonthDay = ?)";
                        $filter_params_marriage[] = $weddingMonthDay_format1;
                        $filter_params_marriage[] = $weddingMonthDay_format2;
                        $filter_param_types_marriage .= "ss";
                    }
                }
                break;
            case 'oldest_to_latest':
                // Order by year, then by month (using FIELD for correct month order), then by day (casted to number)
                $orderByClause_marriage = "ORDER BY mr.WeddingYear ASC, 
                                       FIELD(SUBSTRING_INDEX(mr.WeddingMonthDay, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') ASC, 
                                       CAST(SUBSTRING_INDEX(mr.WeddingMonthDay, ' ', -1) AS UNSIGNED) ASC,
                                       mr.MarriageID ASC"; // Tie-breaker
                break;
            case 'latest_to_oldest':
                $orderByClause_marriage = "ORDER BY mr.WeddingYear DESC, 
                                        FIELD(SUBSTRING_INDEX(mr.WeddingMonthDay, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') DESC, 
                                        CAST(SUBSTRING_INDEX(mr.WeddingMonthDay, ' ', -1) AS UNSIGNED) DESC,
                                        mr.MarriageID DESC"; // Tie-breaker
                break;
        }
    }
    // Handle standalone sort_order parameter
    if (isset($_GET['sort_order_marriage'])) {
        if ($_GET['sort_order_marriage'] === 'asc' && $filter_type !== 'oldest_to_latest') {
            $orderByClause_marriage = "ORDER BY mr.WeddingYear ASC, FIELD(SUBSTRING_INDEX(mr.WeddingMonthDay, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') ASC, CAST(SUBSTRING_INDEX(mr.WeddingMonthDay, ' ', -1) AS UNSIGNED) ASC, mr.MarriageID ASC";
        } elseif ($_GET['sort_order_marriage'] === 'desc' && $filter_type !== 'latest_to_oldest') {
            $orderByClause_marriage = "ORDER BY mr.WeddingYear DESC, FIELD(SUBSTRING_INDEX(mr.WeddingMonthDay, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') DESC, CAST(SUBSTRING_INDEX(mr.WeddingMonthDay, ' ', -1) AS UNSIGNED) DESC, mr.MarriageID DESC";
        }
    }
}
// --- END: Filter Logic (Marriage Records) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // VITAL DEBUGGING: Log raw POST data
    error_log("---- RAW POST DATA ----");
    error_log(print_r($_POST, true));
    error_log("-----------------------");

    $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

    if ($conn->connect_error) {
        error_log("Database Connection Failed: " . $conn->connect_error);
        echo "<script>alert('Error: Could not connect to the database.'); window.history.back();</script>";
        exit();
    }
    $conn->set_charset("utf8mb4"); // Ensure consistent UTF-8 encoding

    // --- Collect and Trim POST data ---
    $WeddingYear = trim($_POST['WeddingYear'] ?? '');
    $WeddingMonthDay = trim($_POST['WeddingMonthDay'] ?? '');
    $GroomClientID = trim($_POST['GroomClientID'] ?? '');
    $BrideClientID = trim($_POST['BrideClientID'] ?? '');
    $GroomStatus = trim($_POST['GroomStatus'] ?? '');
    $BrideStatus = trim($_POST['BrideStatus'] ?? '');
    $GroomAge = trim($_POST['GroomAge'] ?? '');
    $BrideAge = trim($_POST['BrideAge'] ?? '');
    $GroomCity = trim($_POST['GroomCity'] ?? '');
    $BrideCity = trim($_POST['BrideCity'] ?? '');
    $GroomAddress = trim($_POST['GroomAddress'] ?? '');
    $BrideAddress = trim($_POST['BrideAddress'] ?? '');
    $GroomParentsName = trim($_POST['GroomParentsName'] ?? '');
    $BrideParentsName = trim($_POST['BrideParentsName'] ?? '');
    $GroomWitnessOneName = trim($_POST['GroomWitnessOneName'] ?? '');
    $GroomWitnessOneAddress = trim($_POST['GroomWitnessOneAddress'] ?? '');
    $BrideWitnessOneName = trim($_POST['BrideWitnessOneName'] ?? '');
    $BrideWitnessOneAddress = trim($_POST['BrideWitnessOneAddress'] ?? '');
    $PriestID = trim($_POST['PriestID'] ?? '');
    $StipendRaw = trim($_POST['Stipend'] ?? '');
    $Observando = trim($_POST['Observando'] ?? '');
    if ($Observando === '') {
        $Observando = null;
    }

    $Stipend = ($StipendRaw !== "" && is_numeric($StipendRaw)) ? (float)$StipendRaw : null;


    // --- SERVER-SIDE VALIDATION ---
    $errors = [];
    $currentYear = date("Y");

    // Standard name pattern (no ampersand) - Hyphen at the end or escaped
    $namePattern = '/^[\p{L}\s.\'\-]{2,100}$/u';

    // Parents' name pattern (with ampersand & dot) - Hyphen at the end or escaped
    $namePatternWithAmpersandAndDot = '/^[\p{L}\s.\'&:\-]{2,100}$/u'; // Added colon just in case it was mistyped for dot previously

    $addressPattern = '/^[\p{L}\p{N}\s,.\'#\/\-]{2,255}$/u';
    $monthDayPattern = '/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+([1-9]|[12][0-9]|3[01])$/i';
    $validStatuses = ['Single', 'Married', 'Divorced', 'Separated', 'Widowed'];

    // DEBUGGING SPECIFIC FIELDS (Parents' Names)
    error_log("---- PHP VALIDATION INPUTS (Parents' Names) ----");
    error_log("GroomParentsName (trimmed): '" . $GroomParentsName . "' | preg_match ($namePatternWithAmpersandAndDot) result: " . (preg_match($namePatternWithAmpersandAndDot, $GroomParentsName) ? '1' : '0'));
    error_log("BrideParentsName (trimmed): '" . $BrideParentsName . "' | preg_match ($namePatternWithAmpersandAndDot) result: " . (preg_match($namePatternWithAmpersandAndDot, $BrideParentsName) ? '1' : '0'));
    error_log("----------------------------------------------");


    // WeddingYear
    if (empty($WeddingYear)) { $errors[] = "Wedding Year is required."; } // Server-side only check
    elseif (!preg_match('/^\d{4}$/', $WeddingYear) || (int)$WeddingYear < 1900 || (int)$WeddingYear > ($currentYear + 10)) {
        $errors[] = "Invalid Wedding Year (4 digits, 1900-" . ($currentYear + 10) . ").";
    }
    // WeddingMonthDay
    if (empty($WeddingMonthDay)) { $errors[] = "Wedding Month & Day are required."; } // Server-side only check
    elseif (!preg_match($monthDayPattern, $WeddingMonthDay)) {
        $errors[] = "Invalid Wedding Month & Day (e.g., January 1).";
    }
    // Client IDs
    if (empty($GroomClientID)) {
        $errors[] = "Groom's Client ID is required.";
    } elseif (!ctype_digit($GroomClientID) || (int)$GroomClientID <= 0) {
        $errors[] = "Invalid Groom's Client ID (must be a positive number).";
    } else {
        // Check if GroomClientID exists in client table
        $stmt_check_groom = $conn->prepare("SELECT ClientID FROM client WHERE ClientID = ?");
        if ($stmt_check_groom) {
            $stmt_check_groom->bind_param("i", $GroomClientID);
            $stmt_check_groom->execute();
            $stmt_check_groom->store_result();
            if ($stmt_check_groom->num_rows == 0) {
                $errors[] = "Groom's Client ID (" . htmlspecialchars($GroomClientID) . ") does not exist in client records. Please ensure the client is registered first.";
            }
            $stmt_check_groom->close();
        } else {
            $errors[] = "Database error checking Groom's Client ID."; // Should not happen if DB connection is fine
            error_log("Failed to prepare statement for Groom ClientID check: " . $conn->error);
        }
    }

    if (empty($BrideClientID)) {
        $errors[] = "Bride's Client ID is required.";
    } elseif (!ctype_digit($BrideClientID) || (int)$BrideClientID <= 0) {
        $errors[] = "Invalid Bride's Client ID (must be a positive number).";
    } else {
        // Check if BrideClientID exists in client table
        $stmt_check_bride = $conn->prepare("SELECT ClientID FROM client WHERE ClientID = ?");
        if ($stmt_check_bride) {
            $stmt_check_bride->bind_param("i", $BrideClientID);
            $stmt_check_bride->execute();
            $stmt_check_bride->store_result();
            if ($stmt_check_bride->num_rows == 0) {
                $errors[] = "Bride's Client ID (" . htmlspecialchars($BrideClientID) . ") does not exist in client records. Please ensure the client is registered first.";
            }
            $stmt_check_bride->close();
        } else {
            $errors[] = "Database error checking Bride's Client ID.";
            error_log("Failed to prepare statement for Bride ClientID check: " . $conn->error);
        }
    }

    // Updated Parents' Names Validation
    if (empty($GroomParentsName)) { $errors[] = "Groom's Parents' Name is required."; } // Server-side only check
    elseif (!preg_match($namePatternWithAmpersandAndDot, $GroomParentsName)) { $errors[] = "Invalid Groom's Parents' Name (min 2 chars, allow letters, space, ., ', &, -)."; }
    if (empty($BrideParentsName)) { $errors[] = "Bride's Parents' Name is required."; } // Server-side only check
    elseif (!preg_match($namePatternWithAmpersandAndDot, $BrideParentsName)) { $errors[] = "Invalid Bride's Parents' Name (min 2 chars, allow letters, space, ., ', &, -)."; }

    if (empty($GroomWitnessOneName)) { $errors[] = "Groom's Witness Name is required."; } // Server-side only check
    elseif (!preg_match($namePattern, $GroomWitnessOneName)) { $errors[] = "Invalid Groom's Witness Name (min 2 chars, letters, spaces, ., ', -)."; }
    if (empty($BrideWitnessOneName)) { $errors[] = "Bride's Witness Name is required."; } // Server-side only check
    elseif (!preg_match($namePattern, $BrideWitnessOneName)) { $errors[] = "Invalid Bride's Witness Name (min 2 chars, letters, spaces, ., ', -)."; }
    // Status
    if (empty($GroomStatus)) { $errors[] = "Groom's Status is required."; } // Server-side only check
    elseif (!in_array($GroomStatus, $validStatuses)) { $errors[] = "Invalid Groom's Status."; }
    if (empty($BrideStatus)) { $errors[] = "Bride's Status is required."; } // Server-side only check
    elseif (!in_array($BrideStatus, $validStatuses)) { $errors[] = "Invalid Bride's Status."; }
    // Age
    if (empty($GroomAge)) { $errors[] = "Groom's Age is required."; } // Server-side only check
    elseif (!ctype_digit($GroomAge) || (int)$GroomAge < 18 || (int)$GroomAge > 120) { $errors[] = "Groom's Age must be a number between 18-120."; }
    if (empty($BrideAge)) { $errors[] = "Bride's Age is required."; } // Server-side only check
    elseif (!ctype_digit($BrideAge) || (int)$BrideAge < 18 || (int)$BrideAge > 120) { $errors[] = "Bride's Age must be a number between 18-120."; }
    // City & Address
    if (empty($GroomCity)) { $errors[] = "Groom's City is required."; } // Server-side only check
    elseif (!preg_match($addressPattern, $GroomCity)) { $errors[] = "Invalid Groom's City (min 2 chars, alphanumeric, spaces, ,.'#/-)."; }
    if (empty($BrideCity)) { $errors[] = "Bride's City is required."; } // Server-side only check
    elseif (!preg_match($addressPattern, $BrideCity)) { $errors[] = "Invalid Bride's City (min 2 chars, alphanumeric, spaces, ,.'#/-)."; }
    if (empty($GroomAddress)) { $errors[] = "Groom's Address is required."; } // Server-side only check
    elseif (!preg_match($addressPattern, $GroomAddress)) { $errors[] = "Invalid Groom's Address (min 2 chars, alphanumeric, spaces, ,.'#/-)."; }
    if (empty($BrideAddress)) { $errors[] = "Bride's Address is required."; } // Server-side only check
    elseif (!preg_match($addressPattern, $BrideAddress)) { $errors[] = "Invalid Bride's Address (min 2 chars, alphanumeric, spaces, ,.'#/-)."; }
    if (empty($GroomWitnessOneAddress)) { $errors[] = "Groom's Witness Address is required."; } // Server-side only check
    elseif (!preg_match($addressPattern, $GroomWitnessOneAddress)) { $errors[] = "Invalid Groom's Witness Address (min 2 chars, alphanumeric, spaces, ,.'#/-)."; }
    if (empty($BrideWitnessOneAddress)) { $errors[] = "Bride's Witness Address is required."; } // Server-side only check
    elseif (!preg_match($addressPattern, $BrideWitnessOneAddress)) { $errors[] = "Invalid Bride's Witness Address (min 2 chars, alphanumeric, spaces, ,.'#/-)."; }
    // Stipend (optional)
    if ($Stipend !== null && !is_numeric($StipendRaw)) { $errors[] = "Stipend must be a number if provided."; }
    elseif ($Stipend !== null && $Stipend < 0) { $errors[] = "Stipend cannot be negative."; }
    // PriestID
    if (empty($PriestID)) { $errors[] = "Priest selection is required."; } // Server-side only check
    elseif (!ctype_digit($PriestID) || (int)$PriestID <= 0) { $errors[] = "Invalid Priest selection."; }
    // Observando (was required by HTML form, now optional)
    if ($Observando !== null) { // Check if $Observando has a value
        if (strlen($Observando) > 500) { $errors[] = "Observando too long (max 500 chars)."; }
        elseif (preg_match('/[<>]/', $Observando)) { $errors[] = "Observando should not contain HTML tags."; }
    }


    if (!empty($errors)) {
        error_log("---- PHP ERRORS ARRAY BEFORE ALERT ----"); // DEBUGGING
        error_log(print_r($errors, true));                  // DEBUGGING
        error_log("-----------------------------------");    // DEBUGGING

        $decodedErrors = array_map(function($err) {
            return htmlspecialchars_decode($err, ENT_QUOTES | ENT_HTML5);
        }, $errors);
        $jsSafeErrors = array_map('addslashes', $decodedErrors);
        $errorStringForAlert = implode("\\n", $jsSafeErrors);

        echo "<script>alert('Validation Errors:\\n" . $errorStringForAlert . "'); window.history.back();</script>";
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



    if (isset($_POST['save_marriage'])) {
        $sql = "INSERT INTO Marriage_Records
            (GroomClientID, BrideClientID, WeddingYear, WeddingMonthDay, GroomStatus, BrideStatus,
             GroomAge, BrideAge, GroomCity, BrideCity, GroomAddress, BrideAddress, GroomParentsName, BrideParentsName,
             GroomWitnessOneName, GroomWitnessOneAddress, BrideWitnessOneName, BrideWitnessOneAddress,
             Stipend, PriestID, Observando, ParishStaffID)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; // 23 placeholders
        $stmt = $conn->prepare($sql);


        $types = "iissssiissssssssssdisi"; // Total 22 characters

        // Log for debugging bind_param
        error_log("INSERT bind_param types: " . $types . " (length: " . strlen($types) . ")");
        error_log("INSERT Number of variables: 22");


        $stmt->bind_param(
            $types,
            $GroomClientID, $BrideClientID, $WeddingYear, $WeddingMonthDay, $GroomStatus, $BrideStatus,
            $GroomAge, $BrideAge, $GroomCity, $BrideCity, $GroomAddress, $BrideAddress, $GroomParentsName, $BrideParentsName,
            $GroomWitnessOneName, $GroomWitnessOneAddress, $BrideWitnessOneName, $BrideWitnessOneAddress,
            $Stipend, $PriestID, $Observando, $parishStaffID);

        if ($stmt->execute()) {
            echo "<script>alert('Record inserted successfully!'); window.location.href = window.location.href;</script>";
        } else {
            error_log("Insert Error: " . $stmt->error . " | SQL: " . $sql);
            echo "<script>alert('Error inserting record: " . htmlspecialchars($stmt->error) . "'); window.history.back();</script>";
        }
        $stmt->close();
        exit();

    }

    // UPDATE RECORD
    elseif (isset($_POST['updateRecord'])) {
        // MarriageID for WHERE clause comes from the hidden/readonly field named 'WeddingID' in the update form
        $MarriageID_to_update = $_POST['WeddingID'] ?? '';
        $adminPassword = $_POST['adminPassword'] ?? ''; // From hidden input in update form
        $isValid = false;

        // Check admin password
        $sqlAdmin = "SELECT PasswordHash FROM admin_users";
        $resultAdmin = $conn->query($sqlAdmin);
        if ($resultAdmin && $resultAdmin->num_rows > 0) {
            while ($rowAdmin = $resultAdmin->fetch_assoc()) {
                if (password_verify($adminPassword, $rowAdmin['PasswordHash'])) {
                    $isValid = true;
                    break;
                }
            }
        }

        if (!$isValid) {
            echo "<script>alert('Incorrect admin password. Update denied.');</script>";
        } else {
            if (empty($MarriageID_to_update)) {
                echo "<script>alert('Error: Record ID for update is missing.');</script>";
            } else {
                $sql = "UPDATE Marriage_Records SET
                GroomClientID=?, BrideClientID=?, WeddingYear=?, WeddingMonthDay=?,
                GroomStatus=?, BrideStatus=?, GroomAge=?, BrideAge=?,
                GroomCity=?, BrideCity=?, GroomAddress=?, BrideAddress=?,
                GroomParentsName=?, BrideParentsName=?,
                GroomWitnessOneName=?, GroomWitnessOneAddress=?,
                BrideWitnessOneName=?, BrideWitnessOneAddress=?,
                Stipend=?, PriestID=?, Observando=?
                WHERE MarriageID=?";

                $stmt = $conn->prepare($sql);

                // New type string: 21 values for SET + 1 for WHERE = 22 total
                $stmt->bind_param(
                    'iissssiissssssssssdisi',
                    $GroomClientID,
                    $BrideClientID,
                    $WeddingYear,
                    $WeddingMonthDay,
                    $GroomStatus,
                    $BrideStatus,
                    $GroomAge,
                    $BrideAge,
                    $GroomCity,
                    $BrideCity,
                    $GroomAddress,
                    $BrideAddress,
                    $GroomParentsName,
                    $BrideParentsName,
                    $GroomWitnessOneName,
                    $GroomWitnessOneAddress,
                    $BrideWitnessOneName,
                    $BrideWitnessOneAddress,
                    $Stipend,
                    $PriestID,
                    $Observando, $MarriageID_to_update);


                if ($stmt->execute()) {
                    echo "<script>alert('Record updated successfully!'); window.location.href = window.location.href;</script>";
                    exit();
                } else {
                    echo "<script>alert('Update error: " . htmlspecialchars($stmt->error) . " (Error No: " . htmlspecialchars($stmt->errno) . ")');</script>";
                }
                $stmt->close();
            }
        }
    }

    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- ADD THIS -->
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" href="imagess/sacred.png">
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="stylesheet" href="marriagestyle.css?v=27">
    <link rel="stylesheet" href="responsive.css?v=13"> <!-- ADD THIS (increment v as needed) -->
</head>
<body>
<button id="sidebarToggleBtn" class="sidebar-toggle-button">
    <img src="icons/Menu.png" alt="Menu">
</button>
<!-- ... (HTML structure - ensure all fields have correct IDs for JS validation) ... -->
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
        <div class="section-title">Marriage Records</div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search..." style="flex-grow: 1;">

            <!-- START: Category Filter for Marriage -->
            <div class="filter-container">
                <select id="categoryFilterMarriage" name="category_filter_marriage" title="Category Filter">
                    <option value="">-- Filter By Wedding Date --</option>
                    <option value="year">Year</option>
                    <option value="month">Month</option>
                    <option value="specific_date">Specific Date</option>
                    <option value="oldest_to_latest">Oldest to Latest</option>
                    <option value="latest_to_oldest">Latest to Oldest</option>
                </select>

                <div id="filterYearInputContainerMarriage" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearValueMarriage" name="filter_year_value_marriage" placeholder="YYYY">
                </div>
                <div id="filterMonthInputContainerMarriage" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearForMonthValueMarriage" name="filter_year_for_month_value_marriage" placeholder="YYYY (Opt.)">
                    <select id="filterMonthValueMarriage" name="filter_month_value_marriage">
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
                <div id="filterDateInputContainerMarriage" class="filter-input-group" style="display:none;">
                    <input type="text" id="filterDateValueMarriage" name="filter_date_value_marriage" placeholder="DD/MM/YYYY">
                </div>
                <button id="applyFilterBtnMarriage" class="filter-btn">Apply</button>
                <button id="clearFilterBtnMarriage" class="filter-btn">Clear</button>
            </div>
            <!-- END: Category Filter for Marriage -->

            <div class="record-buttons" style="margin-left: auto;">
                <button id="updateRecordBtn">✎ Update Record</button>
                <button id="addRecordBtn">+ Add Record</button>
            </div>
        </div>

        <div class="table-scroll">
            <table id="recordsTable">
                <thead>
                <tr class="header-row-1">
                    <th>Marriage ID</th>
                    <th colspan="2">Matrimonii Contracti</th>
                    <th >Client Name</th>
                    <!-- <th>Sponsorum</th> -->
                    <th colspan="2">Sponsorum</th>
                    <th colspan="2">Locus</th>
                    <th>Parentum</th>
                    <th colspan="2">Testium</th>
                    <th>Stipend</th>
                    <th>Nomen Ministri</th>
                    <th>Observando</th>
                    <th>Created By</th>
                </tr>
                <tr class="header-row-2">
                    <th></th>
                    <th>Year</th>
                    <th>Month and Day</th>
                    <th></th>
                    <!-- <th>Name and Surname</th> -->
                    <th>Status</th>
                    <th>Age</th>
                    <th>Originated</th>
                    <th>Habitationis</th>
                    <th>Name and Surname</th>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Amount</th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
                </thead>
                <?php
                $connView = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");
                if (!$connView->connect_error) {
                    $connView->set_charset("utf8mb4");
                    // start modify for filter

                    $baseSqlMarriage = "SELECT mr.*, 
                            p.FullName AS PriestName,
                            cg.FullName AS GroomClientName,
                            cb.FullName AS BrideClientName,
                            COALESCE(au.username, su.username, 'Unknown') AS CreatedBy
                        FROM Marriage_Records mr
                        LEFT JOIN Priest p ON mr.PriestID = p.PriestID
                        LEFT JOIN parishstaff ps ON mr.ParishStaffID = ps.ParishStaffID
                        LEFT JOIN admin_users au ON ps.AdminUserID = au.ID
                        LEFT JOIN staff_users su ON ps.StaffUserID = su.id
                        LEFT JOIN client cg ON mr.GroomClientID = cg.ClientID
                        LEFT JOIN client cb ON mr.BrideClientID = cb.ClientID";

                    $finalSqlMarriage = $baseSqlMarriage;

                    if (!empty($whereClauses_marriage)) {
                        $finalSqlMarriage .= " WHERE " . implode(" AND ", $whereClauses_marriage);
                    }
                    $finalSqlMarriage .= " " . $orderByClause_marriage;

                    $resultMarriage = null; // Initialize

                    if (!empty($filter_params_marriage)) {
                        $stmtMarriage = $connView->prepare($finalSqlMarriage);
                        if ($stmtMarriage === false) {
                            error_log("SQL Prepare Error (Filter Marriage): " . $connView->error . " | SQL: " . $finalSqlMarriage);
                            echo "<tr><td colspan='15'>Error preparing marriage data.</td></tr>"; // Adjusted colspan
                        } else {
                            $stmtMarriage->bind_param($filter_param_types_marriage, ...$filter_params_marriage);
                            $stmtMarriage->execute();
                            $resultMarriage = $stmtMarriage->get_result();
                            if ($resultMarriage === false) {
                                error_log("SQL Get Result Error (Filter Marriage): " . $stmtMarriage->error);
                                echo "<tr><td colspan='15'>Error retrieving filtered marriage data.</td></tr>";
                            }
                        }
                    } else {
                        $resultMarriage = $connView->query($finalSqlMarriage);
                        if ($resultMarriage === false) {
                            error_log("SQL Query Error (Marriage): " . $connView->error . " | SQL: " . $finalSqlMarriage);
                            echo "<tr><td colspan='15'>Error fetching marriage data.</td></tr>";
                        }
                    }

                    if ($resultMarriage && $resultMarriage->num_rows > 0) {
                        while ($row = $resultMarriage->fetch_assoc()) {
                            // Using htmlspecialchars for all echoed data
                            echo "<tr data-marriage-id='" . htmlspecialchars($row["MarriageID"] ?? '') . "' data-priest-id='" . htmlspecialchars($row["PriestID"] ?? '') . "'>";
                            echo "<td rowspan='2'>" . htmlspecialchars($row["MarriageID"] ?? '-') . "</td>";
                            echo "<td rowspan='2'>" . htmlspecialchars($row["WeddingYear"] ?? '-') . "</td>";
                            echo "<td rowspan='2'>" . htmlspecialchars($row["WeddingMonthDay"] ?? '-') . "</td>";

                            echo "<td>" . htmlspecialchars($row["GroomClientName"] ?? ($row["GroomClientID"] ?? '-')) . "</td>"; // Fallback to ID if name is null
                            echo "<td>" . htmlspecialchars($row["GroomStatus"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["GroomAge"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["GroomCity"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["GroomAddress"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["GroomParentsName"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["GroomWitnessOneName"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["GroomWitnessOneAddress"] ?? '-') . "</td>";

                            echo "<td rowspan='2'>" . htmlspecialchars($row["Stipend"] ?? '-') . "</td>";
                            echo "<td rowspan='2'>" . htmlspecialchars($row["PriestName"] ?? ($row["PriestID"] ?? '-')) . "</td>";
                            echo "<td rowspan='2'>" . htmlspecialchars($row["Observando"] ?? '-') . "</td>";
                            echo "<td rowspan='2'>" . htmlspecialchars($row["CreatedBy"] ?? '-') . "</td>";
                            echo "</tr>";

                            echo "<tr>"; // Bride's row
                            echo "<td>" . htmlspecialchars($row["BrideClientName"] ?? ($row["BrideClientID"] ?? '-')) . "</td>"; // Fallback to ID
                            echo "<td>" . htmlspecialchars($row["BrideStatus"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["BrideAge"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["BrideCity"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["BrideAddress"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["BrideParentsName"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["BrideWitnessOneName"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["BrideWitnessOneAddress"] ?? '-') . "</td>";
                            echo "</tr>";
                        }
                    } else if ($resultMarriage) {
                        echo "<tr><td colspan='15'>No marriage records found matching your criteria.</td></tr>"; // Adjusted colspan
                    }
                    $connView->close();
                } else {
                    echo "<tr><td colspan='15'>Database connection error for viewing records.</td></tr>";
                }
                ?> /* end modify filter */
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Modal [start] -->
    <div class="modal" id="recordModal">
        <form class="modal-content" id="addMarriageForm" method="POST" action="marriage.php" style="width: 1000px; height: 650px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>
            <div class="modal-header" style="background: #2c3e50; color: white; text-align: center; border-radius: 0; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Marriage Details</h3>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">
                <div style="flex: 1 1 45%;">
                    <label for="addWeddingYear" style="margin-left: 30px;">Wedding Year:</label><br>
                    <input type="text" name="WeddingYear" id="addWeddingYear" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addWeddingYearError" class="error-message hidden" style="margin-left: 30px;"></small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="addWeddingMonthDay" style="margin-left: 55px;">Wedding Month & Day:</label><br>
                    <input type="text" name="WeddingMonthDay" id="addWeddingMonthDay" required style="width: 80%; padding: 5px; margin-left: 55px;" placeholder="Example: January 1">
                    <small id="addWeddingMonthDayError" class="error-message hidden" style="margin-left: 55px;">Error</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="addGroomClientID" style="margin-left: 30px;">Groom's Client ID:</label><br>
                    <input type="text" name="GroomClientID" id="addGroomClientID" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addGroomClientIDError" class="error-message hidden" style="margin-left: 30px;">Error</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="addBrideClientID" style="margin-left: 55px;">Bride's Client ID:</label><br>
                    <input type="text" name="BrideClientID" id="addBrideClientID" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addBrideClientIDError" class="error-message hidden" style="margin-left: 55px;">Error</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="addGroomStatus" style="margin-left: 30px;">Groom's Status:</label><br>
                    <select name="GroomStatus" id="addGroomStatus" required style="width: 80%; padding: 5px; margin-left: 30px;">
                        <option value="">-- Select Civil Status --</option><option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Divorced">Divorced</option>
                        <option value="Separated">Separated</option>
                        <option value="Widowed">Widowed</option>
                    </select>
                    <small id="addGroomStatusError" class="error-message hidden" style="margin-left: 30px;">Error</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="addBrideStatus" style="margin-left: 55px;">Bride's Status:</label><br>
                    <select name="BrideStatus" id="addBrideStatus" required style="width: 80%; padding: 5px; margin-left: 55px;">
                        <option value="">-- Select Civil Status --</option><option value="Single">Single</option>
                        <option value="Married">Married</option><option value="Divorced">Divorced</option>
                        <option value="Separated">Separated</option><option value="Widowed">Widowed</option>
                    </select>
                    <small id="addBrideStatusError" class="error-message hidden" style="margin-left: 55px;">Error</small>
                </div>

                <div style="flex: 1 1 45%;"><label for="addGroomAge" style="margin-left: 30px;">Groom's Age:</label><br><input type="number" name="GroomAge" id="addGroomAge" required style="width: 80%; padding: 5px; margin-left: 30px;"><small id="addGroomAgeError" class="error-message hidden" style="margin-left: 30px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addBrideAge" style="margin-left: 55px;">Bride's Age:</label><br><input type="number" name="BrideAge" id="addBrideAge" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="addBrideAgeError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addGroomCity" style="margin-left: 30px;">Groom's City:</label><br><input type="text" name="GroomCity" id="addGroomCity" required style="width: 80%; padding: 5px; margin-left: 30px;"><small id="addGroomCityError" class="error-message hidden" style="margin-left: 30px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addBrideCity" style="margin-left: 55px;">Bride's City:</label><br><input type="text" name="BrideCity" id="addBrideCity" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="addBrideCityError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addGroomAddress" style="margin-left: 30px;">Groom's Address:</label><br><input type="text" name="GroomAddress" id="addGroomAddress" required style="width: 80%; padding: 5px; margin-left: 30px;"><small id="addGroomAddressError" class="error-message hidden" style="margin-left: 30px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addBrideAddress" style="margin-left: 55px;">Bride's Address:</label><br><input type="text" name="BrideAddress" id="addBrideAddress" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="addBrideAddressError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addGroomParentsName" style="margin-left: 30px;">Groom's Parents' Name:</label><br><input type="text" name="GroomParentsName" id="addGroomParentsName" required style="width: 80%; padding: 5px; margin-left: 30px;"><small id="addGroomParentsNameError" class="error-message hidden" style="margin-left: 30px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addBrideParentsName" style="margin-left: 55px;">Bride's Parents' Name:</label><br><input type="text" name="BrideParentsName" id="addBrideParentsName" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="addBrideParentsNameError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addGroomWitnessOneName" style="margin-left: 30px;">Groom's Witness Name:</label><br><input type="text" name="GroomWitnessOneName" id="addGroomWitnessOneName" required style="width: 80%; padding: 5px; margin-left: 30px;"><small id="addGroomWitnessOneNameError" class="error-message hidden" style="margin-left: 30px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addBrideWitnessOneName" style="margin-left: 55px;">Bride's Witness Name:</label><br><input type="text" name="BrideWitnessOneName" id="addBrideWitnessOneName" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="addBrideWitnessOneNameError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addGroomWitnessOneAddress" style="margin-left: 30px;">Groom's Witness Address:</label><br><input type="text" name="GroomWitnessOneAddress" id="addGroomWitnessOneAddress" required style="width: 80%; padding: 5px; margin-left: 30px;"><small id="addGroomWitnessOneAddressError" class="error-message hidden" style="margin-left: 30px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addBrideWitnessOneAddress" style="margin-left: 55px;">Bride's Witness Address:</label><br><input type="text" name="BrideWitnessOneAddress" id="addBrideWitnessOneAddress" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="addBrideWitnessOneAddressError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addPriestID" style="margin-left: 30px;">Select Priest:</label><br><select name="PriestID" id="addPriestID" required style="width: 80%; padding: 5px; margin-left: 30px;"><option value="">-- Select Priest --</option><?php $connPriestAdd = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS"); if (!$connPriestAdd->connect_error) {$connPriestAdd->set_charset("utf8mb4"); $priestSql = "SELECT PriestID, FullName, ContactInfo, Status FROM Priest ORDER BY FullName"; $priestResult = $connPriestAdd->query($priestSql); if ($priestResult->num_rows > 0) {while($priest = $priestResult->fetch_assoc()) {$status = $priest["Status"] ?? "Active"; $contact = $priest["ContactInfo"] ?? "No contact info"; echo "<option value='" . htmlspecialchars($priest["PriestID"]) . "'>" . htmlspecialchars($priest["FullName"]) . " | " . htmlspecialchars($contact) . " | " . htmlspecialchars($status) . "</option>";}} $connPriestAdd->close();} ?></select><small id="addPriestIDError" class="error-message hidden" style="margin-left: 30px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="addStipend" style="margin-left: 55px;">Stipend (Optional):</label><br><input type="number" name="Stipend" id="addStipend" style="width: 80%; padding: 5px; margin-left: 55px;"><small id="addStipendError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>

                <div style="flex: 1 1 96%;">
                    <label for="addObservando" style="margin-left: 30px;">Observando:</label><br>
                    <textarea name="Observando" id="addObservando" style="width: 93%; min-height: 60px; padding: 5px; margin-left: 30px; resize: none;" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px';"></textarea>
                    <small id="addObservandoError" class="error-message hidden" style="margin-left: 30px;">Error</small>
                </div>
            </div>
            <div class="modal-footer" style="text-align: center; margin-top: 30px;"><button type="submit" name="save_marriage" id="addMarriageSubmitButton" style="background-color: #28a745; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">+ Add Record</button></div>
        </form>
    </div>
    <!-- Add Modal [end] -->

    <!-- Update Modal [start] -->
    <div class="modal" id="updateModal">
        <form class="modal-content" id="updateMarriageForm" method="POST" action="marriage.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeUpdateModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>
            <div class="modal-header" style="background: #F39C12; color: white; text-align: center; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Update Marriage Record</h3>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">
                <div style="flex: 1 1 45%;"><label for="updateWeddingID" style="margin-left: 55px;">Wedding ID (Record ID):</label><br><input type="text" id="updateWeddingID" name="WeddingID" readonly style="width: 80%; padding: 5px; margin-left: 55px; background-color: #e9e9e9;"></div>
                <div style="flex: 1 1 45%;"><label for="updateWeddingYear" style="margin-left: 55px;">Wedding Year:</label><br><input type="text" id="updateWeddingYear" name="WeddingYear" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateWeddingYearError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateWeddingMonthDay" style="margin-left: 55px;">Wedding Month & Day:</label><br><input type="text" id="updateWeddingMonthDay" name="WeddingMonthDay" required style="width: 80%; padding: 5px; margin-left: 55px;" placeholder="Example: August 1"><small id="updateWeddingMonthDayError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateGroomClientID" style="margin-left: 55px;">Groom's Client ID:</label><br><input type="text" id="updateGroomClientID" name="GroomClientID" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateGroomClientIDError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateBrideClientID" style="margin-left: 55px;">Bride's Client ID:</label><br><input type="text" id="updateBrideClientID" name="BrideClientID" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateBrideClientIDError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <!-- <div style="flex: 1 1 45%;"><label for="updateGroomFullName" style="margin-left: 55px;">Groom's Full Name:</label><br><input type="text" id="updateGroomFullName" name="GroomFullName" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateGroomFullNameError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateBrideFullName" style="margin-left: 55px;">Bride's Full Name:</label><br><input type="text" id="updateBrideFullName" name="BrideFullName" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateBrideFullNameError" class="error-message hidden" style="margin-left: 55px;">Error</small></div> -->
                <div style="flex: 1 1 45%;"><label for="updateGroomStatus" style="margin-left: 55px;">Groom's Status:</label><br><select id="updateGroomStatus" name="GroomStatus" required style="width: 80%; padding: 5px; margin-left: 55px;"><option value="">-- Select Civil Status --</option><option value="Single">Single</option><option value="Married">Married</option><option value="Divorced">Divorced</option><option value="Separated">Separated</option><option value="Widowed">Widowed</option></select><small id="updateGroomStatusError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateBrideStatus" style="margin-left: 55px;">Bride's Status:</label><br><select id="updateBrideStatus" name="BrideStatus" required style="width: 80%; padding: 5px; margin-left: 55px;"><option value="">-- Select Civil Status --</option><option value="Single">Single</option><option value="Married">Married</option><option value="Divorced">Divorced</option><option value="Separated">Separated</option><option value="Widowed">Widowed</option></select><small id="updateBrideStatusError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateGroomAge" style="margin-left: 55px;">Groom's Age:</label><br><input type="number" id="updateGroomAge" name="GroomAge" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateGroomAgeError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateBrideAge" style="margin-left: 55px;">Bride's Age:</label><br><input type="number" id="updateBrideAge" name="BrideAge" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateBrideAgeError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateGroomCity" style="margin-left: 55px;">Groom's City:</label><br><input type="text" id="updateGroomCity" name="GroomCity" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateGroomCityError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateBrideCity" style="margin-left: 55px;">Bride's City:</label><br><input type="text" id="updateBrideCity" name="BrideCity" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateBrideCityError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateGroomAddress" style="margin-left: 55px;">Groom's Address:</label><br><input type="text" id="updateGroomAddress" name="GroomAddress" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateGroomAddressError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateBrideAddress" style="margin-left: 55px;">Bride's Address:</label><br><input type="text" id="updateBrideAddress" name="BrideAddress" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateBrideAddressError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateGroomParentsName" style="margin-left: 55px;">Groom's Parents' Name:</label><br><input type="text" id="updateGroomParentsName" name="GroomParentsName" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateGroomParentsNameError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateBrideParentsName" style="margin-left: 55px;">Bride's Parents' Name:</label><br><input type="text" id="updateBrideParentsName" name="BrideParentsName" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateBrideParentsNameError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateGroomWitnessOneName" style="margin-left: 55px;">Groom's Witness Name:</label><br><input type="text" id="updateGroomWitnessOneName" name="GroomWitnessOneName" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateGroomWitnessOneNameError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateBrideWitnessOneName" style="margin-left: 55px;">Bride's Witness Name:</label><br><input type="text" id="updateBrideWitnessOneName" name="BrideWitnessOneName" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateBrideWitnessOneNameError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateGroomWitnessOneAddress" style="margin-left: 55px;">Groom's Witness Address:</label><br><input type="text" id="updateGroomWitnessOneAddress" name="GroomWitnessOneAddress" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateGroomWitnessOneAddressError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateBrideWitnessOneAddress" style="margin-left: 55px;">Bride's Witness Address:</label><br><input type="text" id="updateBrideWitnessOneAddress" name="BrideWitnessOneAddress" required style="width: 80%; padding: 5px; margin-left: 55px;"><small id="updateBrideWitnessOneAddressError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updatePriestID" style="margin-left: 55px;">Select Priest:</label><br><select name="PriestID" id="updatePriestID" required style="width: 80%; padding: 5px; margin-left: 55px;"><option value="">-- Select Priest --</option><?php $connPriestUpdate = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS"); if (!$connPriestUpdate->connect_error) {$connPriestUpdate->set_charset("utf8mb4"); $priestSql = "SELECT PriestID, FullName, ContactInfo, Status FROM Priest ORDER BY FullName"; $priestResult = $connPriestUpdate->query($priestSql); if ($priestResult->num_rows > 0) { while($priest = $priestResult->fetch_assoc()) { $status = $priest["Status"] ?? "Active"; $contact = $priest["ContactInfo"] ?? "No contact info"; echo "<option value='" . htmlspecialchars($priest["PriestID"]) . "'>" . htmlspecialchars($priest["FullName"]) . " | " . htmlspecialchars($contact) . " | " . htmlspecialchars($status) . "</option>"; }} else { echo "<option disabled>No priests found</option>"; } $connPriestUpdate->close(); } ?></select><small id="updatePriestIDError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex: 1 1 45%;"><label for="updateStipend" style="margin-left: 55px;">Stipend (Optional):</label><br><input type="number" id="updateStipend" name="Stipend" style="width: 40%; padding: 5px; margin-left: 55px;"><small id="updateStipendError" class="error-message hidden" style="margin-left: 55px;">Error</small></div>
                <div style="flex-basis: 100%;"><label for="updateObservando" style="margin-left: 55px;">Observando:</label><br><textarea id="updateObservando" name="Observando" required style="width: 90%; min-height: 60px; padding: 5px; margin-left: 55px; resize: none; box-sizing: border-box;" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px';"></textarea>
                    <small id="updateObservandoError" class="error-message hidden" style="margin-left: 55px;">Error</small>
                </div>
            </div>
            <div class="modal-footer" style="text-align: center; margin-top: 30px; margin-bottom: 20px;"><button type="submit" name="updateRecord" id="updateMarriageSubmitButton" style="background-color: #F39C12; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">✎ Update Record</button></div>
            <input type="hidden" name="adminPassword" id="hiddenAdminPassword">
        </form>
    </div>
    <!-- Update Modal [end] -->

    <!-- Admin Modal, Message Modal, Certificate Modal (Same as previous response) -->
    <div class="modal" id="adminModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:9999;">
        <form class="modal-content" style="position: relative; width: 400px; padding: 20px; background: #fff; border-radius: 8px;" onsubmit="return validateAdmin(event)">
            <span onclick="closeAdminModal()" style="position: absolute; top: 10px; right: 15px; font-size: 30px; cursor: pointer; color: #B9B9B9;">×</span>
            <h2 style="text-align: center; margin-bottom: 10px; color: #F39C12; margin-bottom: 30px;">Admin</h2>
            <p style="text-align: left; margin-bottom: 5px; font-weight: lighter;">Enter Admin Password:</p>
            <input type="password" id="adminPasswordInput" placeholder="Enter Admin Password" required style="width: 100%; padding: 10px; border: 1px solid rgba(102, 102, 102, 0.35); border-radius: 3px;">
            <div style="text-align: center; margin-top: 20px;"><button type="submit" style="padding: 10px 20px; background-color: #F39C12; color: white; border: none; border-radius: 5px; width: 100%;">Submit</button></div>
        </form>
    </div>
    <div id="messageModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:9999;">
        <div style="background:white; padding:20px; border-radius:10px; max-width:400px; text-align:center;">
            <p id="messageModalText" style="color:black; font-size:16px; padding:10px;">Message here</p>
            <button onclick="document.getElementById('messageModal').style.display='none'" style="background-color:#F39C12; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">OK</button>
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

<script>
    /*----- GLOBAL CONSTANTS & REGEX -----*/
    const marriageRegexPatterns = {
        positiveInteger: /^\d+$/,
        year: /^\d{4}$/,
        monthDay: /^(January|February|March|April|May|June|July|August|September|October|November|December)\s+([1-9]|[12][0-9]|3[01])$/i,
        name: /^[\p{L}\s.'\-]{2,100}$/u, // Hyphen at the end or escaped
        nameWithAmpersandAndDot: /^[\p{L}\s.'&\-]{2,100}$/u, // Explicitly includes dot, ampersand, apostrophe, hyphen
        address: /^[\p{L}\p{N}\s,.'#/\-]{2,255}$/u,
        stipend: /^\d*(\.\d{1,2})?$/,
        noHtmlTags: /^[^<>]*$/
    };
    const currentYearJS = new Date().getFullYear();

    /*----- MARRIAGE CLIENT-SIDE VALIDATION -----*/
    const addMarriageForm = document.getElementById('addMarriageForm');
    const addMarriageSubmitButton = document.getElementById('addMarriageSubmitButton');
    const addMarriageFields = {
        WeddingYear: document.getElementById('addWeddingYear'), WeddingMonthDay: document.getElementById('addWeddingMonthDay'),
        GroomClientID: document.getElementById('addGroomClientID'), BrideClientID: document.getElementById('addBrideClientID'),
        // GroomFullName: document.getElementById('addGroomFullName'), BrideFullName: document.getElementById('addBrideFullName'),
        GroomStatus: document.getElementById('addGroomStatus'), BrideStatus: document.getElementById('addBrideStatus'),
        GroomAge: document.getElementById('addGroomAge'), BrideAge: document.getElementById('addBrideAge'),
        GroomCity: document.getElementById('addGroomCity'), BrideCity: document.getElementById('addBrideCity'),
        GroomAddress: document.getElementById('addGroomAddress'), BrideAddress: document.getElementById('addBrideAddress'),
        GroomParentsName: document.getElementById('addGroomParentsName'), BrideParentsName: document.getElementById('addBrideParentsName'),
        GroomWitnessOneName: document.getElementById('addGroomWitnessOneName'), GroomWitnessOneAddress: document.getElementById('addGroomWitnessOneAddress'),
        BrideWitnessOneName: document.getElementById('addBrideWitnessOneName'), BrideWitnessOneAddress: document.getElementById('addBrideWitnessOneAddress'),
        PriestID: document.getElementById('addPriestID'), Stipend: document.getElementById('addStipend'),
        Observando: document.getElementById('addObservando')
    };
    const addMarriageFormState = {};

    const updateMarriageForm = document.getElementById('updateMarriageForm');
    const updateMarriageSubmitButton = document.getElementById('updateMarriageSubmitButton');
    const updateMarriageFields = {
        WeddingID: document.getElementById('updateWeddingID'),
        WeddingYear: document.getElementById('updateWeddingYear'), WeddingMonthDay: document.getElementById('updateWeddingMonthDay'),
        GroomClientID: document.getElementById('updateGroomClientID'), BrideClientID: document.getElementById('updateBrideClientID'),
        // GroomFullName: document.getElementById('updateGroomFullName'), BrideFullName: document.getElementById('updateBrideFullName'),
        GroomStatus: document.getElementById('updateGroomStatus'), BrideStatus: document.getElementById('updateBrideStatus'),
        GroomAge: document.getElementById('updateGroomAge'), BrideAge: document.getElementById('updateBrideAge'),
        GroomCity: document.getElementById('updateGroomCity'), BrideCity: document.getElementById('updateBrideCity'),
        GroomAddress: document.getElementById('updateGroomAddress'), BrideAddress: document.getElementById('updateBrideAddress'),
        GroomParentsName: document.getElementById('updateGroomParentsName'), BrideParentsName: document.getElementById('updateBrideParentsName'),
        GroomWitnessOneName: document.getElementById('updateGroomWitnessOneName'), GroomWitnessOneAddress: document.getElementById('updateGroomWitnessOneAddress'),
        BrideWitnessOneName: document.getElementById('updateBrideWitnessOneName'), BrideWitnessOneAddress: document.getElementById('updateBrideWitnessOneAddress'),
        PriestID: document.getElementById('updatePriestID'), Stipend: document.getElementById('updateStipend'),
        Observando: document.getElementById('updateObservando')
    };
    const updateMarriageFormState = {};

    function validateMarriageField(fieldName, value, fieldElement, formTypePrefix) {
        let isValid = true;
        const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
        const currentFormState = (formTypePrefix === 'add') ? addMarriageFormState : updateMarriageFormState;
        value = String(value).trim();
        let specificErrorMsg = '';

        if (!fieldElement) {
            console.error("Field element not found for", fieldName);
            currentFormState[fieldName] = false;
            checkMarriageFormOverallValidity(formTypePrefix);
            return;
        }
        const isOptional = ['Stipend', 'Observando'].includes(fieldName);

        if (!isOptional && value === '') {
            isValid = false;
            switch(fieldName) {
                case 'WeddingYear': specificErrorMsg = "Wedding Year is required."; break;
                case 'WeddingMonthDay': specificErrorMsg = "Wedding Month & Day are required."; break;
                case 'GroomClientID': specificErrorMsg = "Groom's Client ID is required."; break;
                case 'BrideClientID': specificErrorMsg = "Bride's Client ID is required."; break;
                // case 'GroomFullName': specificErrorMsg = "Groom's Full Name is required."; break;
                // case 'BrideFullName': specificErrorMsg = "Bride's Full Name is required."; break;
                case 'GroomStatus': specificErrorMsg = "Groom's Status is required."; break;
                case 'BrideStatus': specificErrorMsg = "Bride's Status is required."; break;
                case 'GroomAge': specificErrorMsg = "Groom's Age is required."; break;
                case 'BrideAge': specificErrorMsg = "Bride's Age is required."; break;
                case 'GroomCity': specificErrorMsg = "Groom's City is required."; break;
                case 'BrideCity': specificErrorMsg = "Bride's City is required."; break;
                case 'GroomAddress': specificErrorMsg = "Groom's Address is required."; break;
                case 'BrideAddress': specificErrorMsg = "Bride's Address is required."; break;
                case 'GroomParentsName': specificErrorMsg = "Groom's Parents' Name is required."; break;
                case 'BrideParentsName': specificErrorMsg = "Bride's Parents' Name is required."; break;
                case 'GroomWitnessOneName': specificErrorMsg = "Groom's Witness Name is required."; break;
                case 'GroomWitnessOneAddress': specificErrorMsg = "Groom's Witness Address is required."; break;
                case 'BrideWitnessOneName': specificErrorMsg = "Bride's Witness Name is required."; break;
                case 'BrideWitnessOneAddress': specificErrorMsg = "Bride's Witness Address is required."; break;
                case 'PriestID': specificErrorMsg = "Priest selection is required."; break;
                // case 'Observando': specificErrorMsg = "Observando is required."; break;
                default: specificErrorMsg = "This field is required.";
            }
        } else {
            switch(fieldName) {
                case 'WeddingYear':
                    if (!marriageRegexPatterns.year.test(value)) { isValid = false; specificErrorMsg = `Invalid Wedding Year (must be 4 digits).`; }
                    else { const yearVal = parseInt(value); if (yearVal < 1900 || yearVal > (currentYearJS + 10)) { isValid = false; specificErrorMsg = `Wedding Year must be between 1900 and ${currentYearJS + 10}.`; }}
                    break;
                case 'WeddingMonthDay':
                    if (!marriageRegexPatterns.monthDay.test(value)) { isValid = false; specificErrorMsg = `Invalid Wedding Month & Day (e.g., January 1).`; }
                    break;
                case 'GroomClientID': case 'BrideClientID':
                    if (!marriageRegexPatterns.positiveInteger.test(value) || parseInt(value) <= 0) { isValid = false; specificErrorMsg = `Client ID must be a positive number.`; }
                    break;
                case 'GroomWitnessOneName': case 'BrideWitnessOneName':
                    if (!marriageRegexPatterns.name.test(value)) { isValid = false; specificErrorMsg = `Invalid name (2-100 chars, letters, spaces, ., ', -).`; }
                    break;
                case 'GroomParentsName': case 'BrideParentsName':
                    if (!marriageRegexPatterns.nameWithAmpersandAndDot.test(value)) { isValid = false; specificErrorMsg = `Invalid parents' name (2-100 chars, letters, spaces, ., ', &, -).`; }
                    break;
                case 'GroomStatus': case 'BrideStatus':
                    if (value === '') {isValid = false; specificErrorMsg = "Please select a status.";}
                    break;
                case 'GroomAge': case 'BrideAge':
                    if (!marriageRegexPatterns.positiveInteger.test(value) || parseInt(value) < 18 || parseInt(value) > 120) { isValid = false; specificErrorMsg = `Age must be a number between 18 and 120.`; }
                    break;
                case 'GroomCity': case 'BrideCity': case 'GroomAddress': case 'BrideAddress':
                case 'GroomWitnessOneAddress': case 'BrideWitnessOneAddress':
                    if (!marriageRegexPatterns.address.test(value)) { isValid = false; specificErrorMsg = `Invalid format (2-255 chars, alphanumeric, spaces, ,.'#/-).`; }
                    break;
                case 'PriestID':
                    if (value === '') {isValid = false; specificErrorMsg = "Please select a Priest.";}
                    break;
                case 'Stipend':
                    if (value !== '' && (!marriageRegexPatterns.stipend.test(value) || parseFloat(value) < 0)) { isValid = false; specificErrorMsg = 'Stipend must be a non-negative number (e.g., 100 or 100.50).';}
                    break;
                case 'Observando':
                    if (value !== '') { // Only apply these validations if Observando is NOT empty
                        if (value.length > 500) {
                            isValid = false;
                            specificErrorMsg = 'Observando is too long (max 500 characters).';
                        } else if (!marriageRegexPatterns.noHtmlTags.test(value)) {
                            isValid = false;
                            specificErrorMsg = 'Observando should not contain HTML tags.';
                        }
                    }
                    // If value IS empty, 'isValid' remains true, and 'specificErrorMsg' remains empty.
                    break;
            }
        }
        currentFormState[fieldName] = isValid;
        if (errorElement) {
            if (isValid) { fieldElement.classList.remove('invalid'); fieldElement.classList.add('valid'); errorElement.classList.add('hidden'); errorElement.textContent = ''; }
            else { fieldElement.classList.remove('valid'); fieldElement.classList.add('invalid'); errorElement.classList.remove('hidden'); errorElement.textContent = specificErrorMsg; }
        }
        checkMarriageFormOverallValidity(formTypePrefix);
    }

    function checkMarriageFormOverallValidity(formTypePrefix) {
        const currentFormState = (formTypePrefix === 'add') ? addMarriageFormState : updateMarriageFormState;
        const currentFields = (formTypePrefix === 'add') ? addMarriageFields : updateMarriageFields;
        const submitBtn = (formTypePrefix === 'add') ? addMarriageSubmitButton : updateMarriageSubmitButton;
        if (!submitBtn) { console.error("Submit button not found for", formTypePrefix); return; }
        let allValid = true;
        for (const fieldName in currentFields) {
            if (currentFields.hasOwnProperty(fieldName) && fieldName !== 'WeddingID') {
                if (currentFormState[fieldName] !== true) { allValid = false; break; }
            }
        }
        submitBtn.disabled = !allValid;
    }

    function initializeMarriageValidation(formTypePrefix) {
        const form = (formTypePrefix === 'add') ? addMarriageForm : updateMarriageForm;
        const fields = (formTypePrefix === 'add') ? addMarriageFields : updateMarriageFields;
        const formState = (formTypePrefix === 'add') ? addMarriageFormState : updateMarriageFormState;
        const submitButton = (formTypePrefix === 'add') ? addMarriageSubmitButton : updateMarriageSubmitButton;

        if (!form || !submitButton) {
            console.error("Form or submit button not found for", formTypePrefix);
            return;
        }

        // Initially disable the submit button based on the initial (mostly empty for 'add') form state
        // This will be re-evaluated by checkMarriageFormOverallValidity at the end

        for (const fieldName in fields) {
            if (fields.hasOwnProperty(fieldName)) {
                const fieldElement = fields[fieldName];
                if (fieldElement) {
                    // For 'update' form, WeddingID is readonly and pre-filled, consider it valid.
                    if (fieldName === 'WeddingID' && formTypePrefix === 'update') {
                        formState[fieldName] = true; // Assumed valid as it's read-only
                        fieldElement.classList.add('valid');
                        continue; // Skip event listeners for readonly
                    }

                    const isOptional = ['Stipend', 'Observando'].includes(fieldName);

                    // Set initial logical state:
                    // - Optional fields are true (valid) if empty.
                    // - Required fields are false (invalid) if empty.
                    formState[fieldName] = (isOptional && fieldElement.value.trim() === '');
                    if (!isOptional && fieldElement.value.trim() === '') {
                        formState[fieldName] = false;
                    } else if (!isOptional && fieldElement.value.trim() !== '') {
                        // If a required field has a value (e.g., on update form load),
                        // it's initially considered valid for the state,
                        // but will be properly validated by the blur/input if changed.
                        // Or, better, call validateMarriageField for update form here.
                        if (formTypePrefix === 'update') {
                            // Validate pre-filled fields on update form load to show their status
                            validateMarriageField(fieldName, fieldElement.value, fieldElement, formTypePrefix);
                        } else {
                            formState[fieldName] = true; // if add form somehow has value and not optional
                        }
                    }


                    // --- CRITICAL: Do NOT show errors on initial load for 'add' form ---
                    // Clear any pre-existing validation UI (borders, messages)
                    // This is especially important for the 'add' form when it's re-opened.
                    fieldElement.classList.remove('valid', 'invalid');
                    const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
                    if (errorElement) {
                        errorElement.classList.add('hidden');
                        errorElement.textContent = '';
                    }
                    // --- End Critical Section ---

                    const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'number' || ['Stipend', 'Observando'].includes(fieldName)) ? 'change' : 'input';

                    fieldElement.addEventListener(eventType, function() {
                        validateMarriageField(fieldName, this.value, this, formTypePrefix);
                    });
                    fieldElement.addEventListener('blur', function() {
                        validateMarriageField(fieldName, this.value, this, formTypePrefix);
                    });

                    // If it's an update form and the field has a value, validate it to show initial status
                    if (formTypePrefix === 'update' && fieldElement.value.trim() !== '') {
                        validateMarriageField(fieldName, fieldElement.value, fieldElement, formTypePrefix);
                    } else if (isOptional && fieldElement.value.trim() === '') {
                        // Optional empty fields are valid
                        fieldElement.classList.add('valid');
                        formState[fieldName] = true;
                    }


                } else {
                    console.warn("Field element for " + fieldName + " not found in " + formTypePrefix + " form.");
                    formState[fieldName] = false;
                }
            }
        }
        checkMarriageFormOverallValidity(formTypePrefix); // Check button state after setup

        form.addEventListener('submit', function(event) {
            let formIsValid = true;
            console.log("---- Client-Side Form Data Before Submission (" + formTypePrefix + ") ----");
            const formDataForLog = {};
            for (const fieldName in fields) {
                if (fields.hasOwnProperty(fieldName) && fields[fieldName]) {
                    formDataForLog[fieldName] = fields[fieldName].value;
                    if (fieldName === 'WeddingID' && formTypePrefix === 'update') continue;
                    validateMarriageField(fieldName, fields[fieldName].value, fields[fieldName], formTypePrefix);
                    if (formState[fieldName] === false) {
                        formIsValid = false;
                    }
                }
            }
            console.log(formDataForLog);
            console.log("-------------------------------------------------------");
            if (formTypePrefix === 'update' && document.getElementById('hiddenAdminPassword').value === '') {
                showMessageModal("Admin password missing for update. Please re-authenticate.");
                formIsValid = false;
            }
            if (!formIsValid) {
                event.preventDefault();
                alert('Please correct the errors highlighted in the form before submitting.');
            }
        });
    }

    function resetMarriageForm(formTypePrefix) {
        const form = (formTypePrefix === 'add') ? addMarriageForm : updateMarriageForm;
        const fields = (formTypePrefix === 'add') ? addMarriageFields : updateMarriageFields;
        const formState = (formTypePrefix === 'add') ? addMarriageFormState : updateMarriageFormState;
        // const submitButton = (formTypePrefix === 'add') ? addMarriageSubmitButton : updateMarriageSubmitButton; // Already handled by checkMarriageFormOverallValidity

        if (!form) {
            console.error("Form not found for reset:", formTypePrefix);
            return;
        }

        if (formTypePrefix === 'add') {
            form.reset(); // Resets to initial HTML state for "add" form
        } else { // For "update" form, clear fields manually
            for (const fieldName in fields) {
                if (fields.hasOwnProperty(fieldName) && fields[fieldName]) {
                    if (fieldName !== 'WeddingID') fields[fieldName].value = '';
                }
            }
            if(fields.WeddingID) fields.WeddingID.value = ''; // Clear WeddingID too on general reset
        }


        for (const fieldName in fields) {
            if (fields.hasOwnProperty(fieldName) && fields[fieldName]) {
                fields[fieldName].classList.remove('valid', 'invalid');
                const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
                if (errorElement) {
                    errorElement.classList.add('hidden');
                    errorElement.textContent = '';
                }

                if (fieldName === 'WeddingID' && formTypePrefix === 'update') {
                    formState[fieldName] = true; // WeddingID is always "valid" as it's readonly or prefilled
                    fields[fieldName].classList.add('valid'); // Explicitly mark as valid
                    continue;
                }
                const isOptional = ['Stipend', 'Observando'].includes(fieldName);
                // For 'add' form, required fields start as 'false' (invalid for logic, but no UI error yet)
                // Optional fields start as 'true'
                formState[fieldName] = isOptional;
                if (isOptional) {
                    fields[fieldName].classList.add('valid'); // Optional fields are initially valid if empty
                }
            }
        }

        if (formTypePrefix === 'update' && document.getElementById('hiddenAdminPassword')) {
            document.getElementById('hiddenAdminPassword').value = '';
        }
        checkMarriageFormOverallValidity(formTypePrefix); // This will disable the submit button
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
    function openAdminModal() { document.getElementById("adminPasswordInput").value = ""; document.getElementById("adminModal").style.display = "flex"; document.getElementById("adminPasswordInput").focus(); }
    function closeAdminModal() { document.getElementById("adminModal").style.display = "none"; adminAuthenticated = false; document.getElementById("adminPasswordInput").value = ''; if(document.getElementById("hiddenAdminPassword")) document.getElementById("hiddenAdminPassword").value = ''; disableRowClickEdit(); }
    function validateAdmin(event) {
        event.preventDefault(); const inputPassword = document.getElementById("adminPasswordInput").value;
        fetch("update_validation.php", { method: "POST", headers: {"Content-Type": "application/x-www-form-urlencoded"}, body: "password=" + encodeURIComponent(inputPassword)})
            .then(response => response.json()).then(data => {
            if (data.success) { adminAuthenticated = true; document.getElementById("adminModal").style.display = "none"; showMessageModal("Access granted. Please click on a record to edit."); if(document.getElementById("hiddenAdminPassword")) document.getElementById("hiddenAdminPassword").value = inputPassword; enableRowClickEdit(); }
            else { adminAuthenticated = false; showMessageModal("Incorrect password. Please try again."); disableRowClickEdit(); }
            document.getElementById("adminPasswordInput").value = '';
        }).catch(error => { console.error("Error validating admin:", error); showMessageModal("An error occurred during validation. Try again."); adminAuthenticated = false; disableRowClickEdit(); });
        return false;
    }

    function showMessageModal(message) { const modal = document.getElementById("messageModal"); const messageText = document.getElementById("messageModalText"); messageText.textContent = message; modal.style.display = "flex"; }
    document.getElementById("addRecordBtn").onclick = function () { resetMarriageForm('add'); document.getElementById("recordModal").style.display = "flex"; };
    function closeModal() { document.getElementById("recordModal").style.display = "none"; resetMarriageForm('add'); }
    document.getElementById("updateRecordBtn").onclick = function () { adminAuthenticated = false; resetMarriageForm('update'); openAdminModal(); disableRowClickEdit(); };
    function enableRowClickEdit() { const rows = document.querySelectorAll("#recordsTable tbody tr:nth-child(odd)"); rows.forEach(row => { row.style.cursor = "pointer"; row.removeEventListener('click', handleRowClick); row.addEventListener('click', handleRowClick); }); }

    function handleRowClick() {


        if (!adminAuthenticated) { showMessageModal("Admin authentication required to edit. Please click 'Update Record' again."); return; }

        const row = this; const cellsRow1 = row.querySelectorAll("td");
        const nextRow = row.nextElementSibling;
        const cellsRow2 = nextRow ? nextRow.querySelectorAll("td") : [];
        const marriageId = row.dataset.marriageId; const priestId = row.dataset.priestId;

        if (cellsRow1.length >= 14 && cellsRow2.length >= 8 && marriageId) {

            updateMarriageFields.WeddingID.value = marriageId;
            updateMarriageFields.WeddingYear.value = cellsRow1[1].innerText.trim();
            updateMarriageFields.WeddingMonthDay.value = cellsRow1[2].innerText.trim();
            updateMarriageFields.GroomClientID.value = cellsRow1[3].innerText.trim();
            // updateMarriageFields.GroomFullName.value = cellsRow1[4].innerText.trim();
            updateMarriageFields.GroomStatus.value = cellsRow1[4].innerText.trim();
            updateMarriageFields.GroomAge.value = cellsRow1[5].innerText.trim();
            updateMarriageFields.GroomCity.value = cellsRow1[6].innerText.trim();
            updateMarriageFields.GroomAddress.value = cellsRow1[7].innerText.trim();
            updateMarriageFields.GroomParentsName.value = cellsRow1[8].innerText.trim();
            updateMarriageFields.GroomWitnessOneName.value = cellsRow1[9].innerText.trim();
            updateMarriageFields.GroomWitnessOneAddress.value = cellsRow1[10].innerText.trim();
            updateMarriageFields.Stipend.value = cellsRow1[11].innerText.trim();
            updateMarriageFields.Observando.value = cellsRow1[13].innerText.trim();
            updateMarriageFields.PriestID.value = priestId;


            updateMarriageFields.BrideClientID.value = cellsRow2[0].innerText.trim();
            // updateMarriageFields.BrideFullName.value = cellsRow2[1].innerText.trim();
            updateMarriageFields.BrideStatus.value = cellsRow2[1].innerText.trim();
            updateMarriageFields.BrideAge.value = cellsRow2[2].innerText.trim();
            updateMarriageFields.BrideCity.value = cellsRow2[3].innerText.trim();
            updateMarriageFields.BrideAddress.value = cellsRow2[4].innerText.trim();
            updateMarriageFields.BrideParentsName.value = cellsRow2[5].innerText.trim();
            updateMarriageFields.BrideWitnessOneName.value = cellsRow2[6].innerText.trim();
            updateMarriageFields.BrideWitnessOneAddress.value = cellsRow2[7].innerText.trim();



            for (const fieldName in updateMarriageFields) { if (updateMarriageFields.hasOwnProperty(fieldName) && updateMarriageFields[fieldName]) { if (fieldName === 'WeddingID') continue; validateMarriageField(fieldName, updateMarriageFields[fieldName].value, updateMarriageFields[fieldName], 'update'); }}
            checkMarriageFormOverallValidity('update'); document.getElementById("updateModal").style.display = "flex";
        }
        else { console.error("Could not parse row data. Groom cells:", cellsRow1.length, "Bride cells:", cellsRow2.length, "MarriageID:", marriageId); showMessageModal("Error: Could not load complete record data. Please check console."); }
    }

    function disableRowClickEdit() {
        const rows = document.querySelectorAll("#recordsTable tbody tr");
        rows.forEach(row => {
            row.removeEventListener('click', handleRowClick);
            row.style.cursor = "default";
        });
    }

    function closeUpdateModal() {
        document.getElementById("updateModal").style.display = "none";
        adminAuthenticated = false;
        disableRowClickEdit();
        resetMarriageForm('update');
        if (document.getElementById("hiddenAdminPassword"))
            document.getElementById("hiddenAdminPassword").value = '';
    }

    window.onclick = function (event) {
        const modalsToClose = [
            { modal: document.getElementById("recordModal"), closeFn: closeModal },
            { modal: document.getElementById("updateModal"), closeFn: closeUpdateModal },
            { modal: document.getElementById("adminModal"), closeFn: closeAdminModal },
            {
                modal: document.getElementById("messageModal"),
                closeFn: () => {
                    if (document.getElementById("messageModal"))
                        document.getElementById("messageModal").style.display = "none";
                }
            },
            { modal: document.getElementById("certificateModal"), closeFn: closeCertModal }
        ];

        modalsToClose.forEach(item => {
            if (item.modal && event.target === item.modal) {
                item.closeFn();
            }
        });
    };

    function toggleSidebar() {
        document.querySelector(".sidebar").classList.toggle("active");
    }

    function toggleDropdown() {
        let dropdown = document.getElementById("certificateDropdown");
        let certificatesItem = document.getElementById("certificates");

        dropdown.classList.toggle("dropdown-active");
        certificatesItem.classList.toggle("open");

    }

    document.getElementById("addRecordBtn").onclick = function () {
        document.getElementById("recordModal").style.display = "flex";
    };

    function closeModal() {
        document.getElementById("recordModal").style.display = "none";
    }

    window.onclick = function (event) {
        const modal = document.getElementById("recordModal");
        if (event.target === modal) {
            closeModal();
        }
    };

    /* ========= CHOOSING CERT [start] ============*/
    function toggleCertType() {
        document.getElementById("certTypeDropdown")?.classList.toggle("dropdown-active");
        document.getElementById("certDropdownIcon")?.classList.toggle("rotated");
    }

    function selectCertType(type) {
        const typeInput = document.getElementById("certTypeInput");
        const chooseButton = document.querySelector(".certChoose-btn");
        const dropdown = document.getElementById("certTypeDropdown");
        const icon = document.getElementById("certDropdownIcon");

        if(typeInput) typeInput.value = type;
        if(chooseButton && icon) {
            chooseButton.innerHTML = (type === "baptismal" ? 'Baptismal Certificate' : 'Confirmation Certificate') + ` <span id="certDropdownIcon">${icon.textContent}</span>`; // Keep current icon
            icon.classList.remove("rotated"); // Ensure icon is not rotated when closed
        }
        if(dropdown) dropdown.classList.remove("dropdown-active");
    }

    function openCertModal() {
        const certModal = document.getElementById("certificateModal");
        if(certModal) certModal.style.display = "block";
    }

    function closeCertModal() {
        const certModal = document.getElementById("certificateModal");
        const certForm = document.getElementById("certificateForm");
        const chooseButton = document.querySelector(".certChoose-btn");
        const icon = document.getElementById("certDropdownIcon");

        if(certModal) certModal.style.display = "none";
        if(certForm) certForm.reset();
        if(chooseButton && icon) {
            chooseButton.innerHTML = 'Choose Certificate <span id="certDropdownIcon">▶</span>';
            icon.classList.remove("rotated");
        }
        if(document.getElementById("certTypeInput")) document.getElementById("certTypeInput").value = '';
    }
    /* ========= CHOOSING CERT [end] ============*/

    const pageNavigations = {
        dashboardButton: "dashboard.php",
        priestButton: "priestrecords.php",
        eventsButton: "event.php",
        massButton: "massSchedule.php",
        baptismalButton: "baptismal.php",
        MarriageButton: "marriage.php",
        burialButton: "burial.php",
        confirmationButton: "confirmation.php",
        clientButton: "client.php"
    };

    for (const btnId in pageNavigations) {
        const btnElement = document.getElementById(btnId);
        if (btnElement) {
            btnElement.addEventListener("click", function () {
                window.location.href = pageNavigations[btnId];
            });
        }
    }

    document.getElementById("searchInput").addEventListener("keyup", function () {
        const filter = this.value.toLowerCase();
        const table = document.getElementById("recordsTable");
        const trGroups = Array.from(table.querySelectorAll("tbody tr:nth-child(odd)"));

        trGroups.forEach(groomRow => {
            const brideRow = groomRow.nextElementSibling;
            const groomText = groomRow.textContent.toLowerCase();
            const brideText = brideRow ? brideRow.textContent.toLowerCase() : "";
            const displayStyle = (groomText.includes(filter) || (brideRow && brideText.includes(filter))) ? "" : "none";

            groomRow.style.display = displayStyle;
            if (brideRow) brideRow.style.display = displayStyle;
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        initializeMarriageValidation('add');
        initializeMarriageValidation('update');
    });

    document.addEventListener('DOMContentLoaded', function () {
        initializeMarriageValidation('add');
        initializeMarriageValidation('update');

        // Sidebar Toggle Functionality (from baptismal example)
        const sidebarToggle = document.getElementById('sidebarToggleBtn');
        const sidebarElement = document.querySelector('.sidebar');
        const mainPageOverlay = document.createElement('div');

        if (sidebarToggle && sidebarElement) {
            mainPageOverlay.style.position = 'fixed';
            mainPageOverlay.style.top = '0';
            mainPageOverlay.style.left = '0';
            mainPageOverlay.style.width = '100%';
            mainPageOverlay.style.height = '100%';
            mainPageOverlay.style.backgroundColor = 'rgba(0,0,0,0.4)';
            mainPageOverlay.style.zIndex = '1099'; // Below sidebar (1100), above content
            mainPageOverlay.style.display = 'none';
            document.body.appendChild(mainPageOverlay);

            sidebarToggle.addEventListener('click', () => {
                sidebarElement.classList.toggle('active');
                if (sidebarElement.classList.contains('active')) {
                    mainPageOverlay.style.display = 'block';
                    // Optional: Adjust table container margin when sidebar is active
                    // document.querySelector('.table-container').style.marginLeft = sidebarElement.offsetWidth + 'px';
                } else {
                    mainPageOverlay.style.display = 'none';
                    // Optional: Reset table container margin
                    // document.querySelector('.table-container').style.marginLeft = '10px'; // or your default mobile margin
                }
            });

            mainPageOverlay.addEventListener('click', () => {
                if (sidebarElement.classList.contains('active')) {
                    sidebarElement.classList.remove('active');
                    mainPageOverlay.style.display = 'none';
                    // Optional: Reset table container margin
                    // document.querySelector('.table-container').style.marginLeft = '10px';
                }
            });
        }
    });

    /*--- ClientID Error [start] ---*/
    // Function to check if a client ID exists via AJAX
function checkClientID(ClientID, type, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'check_client.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

    xhr.onload = function() {
        if (xhr.status === 200) {
            callback(xhr.responseText.trim() === "exists");
        } else {
            callback(false);
        }
    };

    xhr.onerror = function() {
        callback(false);
    };

    xhr.send('ClientID=' + encodeURIComponent(ClientID));
}

function validateBothClientIDs(groomID, brideID, callback) {
    let groomValid = false;
    let brideValid = false;
    
    checkClientID(groomID, "Groom", function(isGroomValid) {
        groomValid = isGroomValid;
        checkClientID(brideID, "Bride", function(isBrideValid) {
            brideValid = isBrideValid;
            
            if (!groomValid && !brideValid) {
                alert(`Error: Both Client IDs (Groom: ${groomID}, Bride: ${brideID}) do not exist.`);
                callback(false);
            } else if (!groomValid) {
                alert(`Error: Groom's Client ID (${groomID}) does not exist.`);
                callback(false);
            } else if (!brideValid) {
                alert(`Error: Bride's Client ID (${brideID}) does not exist.`);
                callback(false);
            } else {
                callback(true);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const addForm = document.getElementById('addMarriageForm');
    const updateForm = document.getElementById('updateMarriageForm');

    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const GroomClientID = document.getElementById('addGroomClientID').value;
            const BrideClientID = document.getElementById('addBrideClientID').value;

            if (!this.checkValidity()) {
                return false;
            }

            validateBothClientIDs(GroomClientID, BrideClientID, function(isValid) {
                if (isValid) {
                    const formData = new FormData(addForm);
                    formData.append('save_marriage', 'true');

                    fetch('marriage.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(data => {
                        if (data.includes('Record inserted successfully')) {
                            alert('Record inserted successfully!');
                            window.location.reload();
                        } else if (data === 'error_both_clients_not_found') {
                            alert(`Error: Both Client IDs (Groom: ${GroomClientID}, Bride: ${BrideClientID}) do not exist.`);
                        } else if (data === 'error_groom_not_found') {
                            alert(`Error: Groom's Client ID (${GroomClientID}) does not exist.`);
                        } else if (data === 'error_bride_not_found') {
                            alert(`Error: Bride's Client ID (${BrideClientID}) does not exist.`);
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

            const GroomClientID = document.getElementById('updateGroomClientID').value;
            const BrideClientID = document.getElementById('updateBrideClientID').value;

            if (!this.checkValidity()) {
                return false;
            }

            validateBothClientIDs(GroomClientID, BrideClientID, function(isValid) {
                if (isValid) {
                    const formData = new FormData(updateForm);
                    formData.append('updateRecord', 'true');

                    fetch('marriage.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(data => {
                        if (data.includes('Record updated successfully')) {
                            alert('Record updated successfully!');
                            window.location.reload();
                        } else if (data === 'error_both_clients_not_found') {
                            alert(`Error: Both Client IDs (Groom: ${GroomClientID}, Bride: ${BrideClientID}) do not exist.`);
                        } else if (data === 'error_groom_not_found') {
                            alert(`Error: Groom's Client ID (${GroomClientID}) does not exist.`);
                        } else if (data === 'error_bride_not_found') {
                            alert(`Error: Bride's Client ID (${BrideClientID}) does not exist.`);
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

    // --- START: New Filter JavaScript for marriage.php ---
    const categoryFilter_marriage = document.getElementById('categoryFilterMarriage');
    const yearInputContainer_marriage = document.getElementById('filterYearInputContainerMarriage');
    const monthInputContainer_marriage = document.getElementById('filterMonthInputContainerMarriage');
    const dateInputContainer_marriage = document.getElementById('filterDateInputContainerMarriage');

    const yearValueInput_marriage = document.getElementById('filterYearValueMarriage');
    const yearForMonthValueInput_marriage = document.getElementById('filterYearForMonthValueMarriage');
    const monthValueSelect_marriage = document.getElementById('filterMonthValueMarriage');
    const dateValueInput_marriage = document.getElementById('filterDateValueMarriage'); // Text input for DD/MM/YYYY

    const applyFilterButton_marriage = document.getElementById('applyFilterBtnMarriage');
    const clearFilterButton_marriage = document.getElementById('clearFilterBtnMarriage');
    const searchInput_marriage = document.getElementById('searchInput');

    function toggleFilterInputs_marriage() {
        if (!categoryFilter_marriage) return;
        const selectedFilter = categoryFilter_marriage.value;

        if(yearInputContainer_marriage) yearInputContainer_marriage.style.display = 'none';
        if(monthInputContainer_marriage) monthInputContainer_marriage.style.display = 'none';
        if(dateInputContainer_marriage) dateInputContainer_marriage.style.display = 'none';

        if (selectedFilter === 'year' && yearInputContainer_marriage) {
            yearInputContainer_marriage.style.display = 'inline-block';
        } else if (selectedFilter === 'month' && monthInputContainer_marriage) {
            monthInputContainer_marriage.style.display = 'inline-block';
        } else if (selectedFilter === 'specific_date' && dateInputContainer_marriage) {
            dateInputContainer_marriage.style.display = 'inline-block';
        }
    }

    if (categoryFilter_marriage) {
        categoryFilter_marriage.addEventListener('change', toggleFilterInputs_marriage);
    }

    if (applyFilterButton_marriage) {
        applyFilterButton_marriage.addEventListener('click', function() {
            if (!categoryFilter_marriage) return;
            const filterType = categoryFilter_marriage.value;
            if (!filterType) return;

            let queryParams = new URLSearchParams();
            queryParams.set('filter_type_marriage', filterType);

            if (filterType === 'year') {
                if (!yearValueInput_marriage || !yearValueInput_marriage.value || !/^\d{4}$/.test(yearValueInput_marriage.value)) {
                    alert('Please enter a valid 4-digit year.'); return;
                }
                queryParams.set('filter_year_value_marriage', yearValueInput_marriage.value);
            } else if (filterType === 'month') {
                if (!monthValueSelect_marriage || !monthValueSelect_marriage.value) {
                    alert('Please select a month.'); return;
                }
                queryParams.set('filter_month_value_marriage', monthValueSelect_marriage.value);
                if (yearForMonthValueInput_marriage && yearForMonthValueInput_marriage.value) {
                    if (!/^\d{4}$/.test(yearForMonthValueInput_marriage.value)) {
                        alert('If providing a year for the month, please enter a valid 4-digit year.'); return;
                    }
                    queryParams.set('filter_year_for_month_value_marriage', yearForMonthValueInput_marriage.value);
                }
            } else if (filterType === 'specific_date') {
                // Basic DD/MM/YYYY validation
                if (!dateValueInput_marriage || !dateValueInput_marriage.value || !/^\d{2}\/\d{2}\/\d{4}$/.test(dateValueInput_marriage.value)) {
                    alert('Please enter a date in DD/MM/YYYY format.'); return;
                }
                queryParams.set('filter_date_value_marriage', dateValueInput_marriage.value);
            } else if (filterType === 'oldest_to_latest') {
                queryParams.set('sort_order_marriage', 'asc');
            } else if (filterType === 'latest_to_oldest') {
                queryParams.set('sort_order_marriage', 'desc');
            }
            window.location.search = queryParams.toString();
        });
    }

    if (clearFilterButton_marriage) {
        clearFilterButton_marriage.addEventListener('click', function(event) {
            event.preventDefault();
            if (searchInput_marriage) {
                searchInput_marriage.value = '';
            }
            window.location.href = window.location.pathname;
        });
    }

    function setFiltersFromUrl_marriage() {
        if (!categoryFilter_marriage) return;
        const urlParams = new URLSearchParams(window.location.search);
        const filterTypeFromUrl = urlParams.get('filter_type_marriage');

        categoryFilter_marriage.value = "";
        if(yearValueInput_marriage) yearValueInput_marriage.value = "";
        if(yearForMonthValueInput_marriage) yearForMonthValueInput_marriage.value = "";
        if(monthValueSelect_marriage) monthValueSelect_marriage.value = "";
        if(dateValueInput_marriage) dateValueInput_marriage.value = ""; // For DD/MM/YYYY text input
        toggleFilterInputs_marriage();

        if (filterTypeFromUrl) {
            categoryFilter_marriage.value = filterTypeFromUrl;
            toggleFilterInputs_marriage();

            if (filterTypeFromUrl === 'year' && urlParams.has('filter_year_value_marriage') && yearValueInput_marriage) {
                yearValueInput_marriage.value = urlParams.get('filter_year_value_marriage');
            } else if (filterTypeFromUrl === 'month') {
                if (urlParams.has('filter_month_value_marriage') && monthValueSelect_marriage) {
                    monthValueSelect_marriage.value = urlParams.get('filter_month_value_marriage');
                }
                if (urlParams.has('filter_year_for_month_value_marriage') && yearForMonthValueInput_marriage) {
                    yearForMonthValueInput_marriage.value = urlParams.get('filter_year_for_month_value_marriage');
                }
            } else if (filterTypeFromUrl === 'specific_date' && urlParams.has('filter_date_value_marriage') && dateValueInput_marriage) {
                dateValueInput_marriage.value = urlParams.get('filter_date_value_marriage');
            }
        } else if (urlParams.has('sort_order_marriage')) {
            const sortOrder = urlParams.get('sort_order_marriage');
            if (sortOrder === 'asc') categoryFilter_marriage.value = 'oldest_to_latest';
            if (sortOrder === 'desc') categoryFilter_marriage.value = 'latest_to_oldest';
        }
    }

    setFiltersFromUrl_marriage(); // Call on page load
    // --- END: New Filter JavaScript for marriage.php ---
});
    /*--- ClientID Error [end] ---*/

</script>
</body>
</html>