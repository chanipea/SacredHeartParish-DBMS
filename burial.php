 <?php
// Prevent caching of the page after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$pageTitle = "Burial Records Management";
require_once 'session_timeout.php'; 

// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Log_In/login_system.php");
    exit();
}

// --- START: Filter Logic for GET requests (Burial Records by Burial Date) ---
$whereClauses_burial = [];
// Default order for burial records, can be overridden by filter
$orderByClause_burial = "ORDER BY br.BurialID DESC";
$filter_params_burial = []; // Parameters for prepared statement
$filter_param_types_burial = ""; // Types for prepared statement

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (isset($_GET['filter_type_burial']) && !empty($_GET['filter_type_burial'])) {
        $filter_type = $_GET['filter_type_burial'];

        switch ($filter_type) {
            case 'year':
                if (isset($_GET['filter_year_value_burial']) && !empty($_GET['filter_year_value_burial'])) {
                    $year = filter_var($_GET['filter_year_value_burial'], FILTER_VALIDATE_INT);
                    if ($year && strlen((string)$year) == 4) {
                        $whereClauses_burial[] = "br.YearOfBurial = ?";
                        $filter_params_burial[] = $year;
                        $filter_param_types_burial .= "i";
                    }
                }
                break;
            case 'month':
                if (isset($_GET['filter_month_value_burial']) && !empty($_GET['filter_month_value_burial'])) {
                    $monthName = $_GET['filter_month_value_burial'];
                    $validMonths = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
                    if (in_array($monthName, $validMonths)) {
                        $whereClauses_burial[] = "SUBSTRING_INDEX(br.MonthDayOfBurial, ' ', 1) = ?";
                        $filter_params_burial[] = $monthName;
                        $filter_param_types_burial .= "s";

                        if (isset($_GET['filter_year_for_month_value_burial']) && !empty($_GET['filter_year_for_month_value_burial'])) {
                            $year_for_month = filter_var($_GET['filter_year_for_month_value_burial'], FILTER_VALIDATE_INT);
                            if ($year_for_month && strlen((string)$year_for_month) == 4) {
                                $whereClauses_burial[] = "br.YearOfBurial = ?";
                                $filter_params_burial[] = $year_for_month;
                                $filter_param_types_burial .= "i";
                            }
                        }
                    }
                }
                break;
            case 'specific_date':
                if (isset($_GET['filter_date_value_burial']) && !empty($_GET['filter_date_value_burial'])) {
                    $date_str = $_GET['filter_date_value_burial']; // Expects DD/MM/YYYY
                    $date_parts = explode('/', $date_str);
                    if (count($date_parts) === 3 && checkdate((int)$date_parts[1], (int)$date_parts[0], (int)$date_parts[2])) {
                        $day_input = (int)$date_parts[0];
                        $month_num = (int)$date_parts[1];
                        $year_val = (int)$date_parts[2];

                        $dateObj = DateTime::createFromFormat('!m', $month_num);
                        $monthNameForQuery = $dateObj->format('F');

                        $monthDayForQuery_format1 = $monthNameForQuery . " " . $day_input; // e.g. "January 1"
                        $monthDayForQuery_format2 = $monthNameForQuery . " " . str_pad($day_input, 2, '0', STR_PAD_LEFT); // e.g. "January 01"

                        $whereClauses_burial[] = "br.YearOfBurial = ?";
                        $filter_params_burial[] = $year_val;
                        $filter_param_types_burial .= "i";

                        $whereClauses_burial[] = "(br.MonthDayOfBurial = ? OR br.MonthDayOfBurial = ?)";
                        $filter_params_burial[] = $monthDayForQuery_format1;
                        $filter_params_burial[] = $monthDayForQuery_format2;
                        $filter_param_types_burial .= "ss";
                    }
                }
                break;
            case 'oldest_to_latest':
                $orderByClause_burial = "ORDER BY br.YearOfBurial ASC, 
                                       FIELD(SUBSTRING_INDEX(br.MonthDayOfBurial, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') ASC, 
                                       CAST(SUBSTRING_INDEX(br.MonthDayOfBurial, ' ', -1) AS UNSIGNED) ASC,
                                       br.BurialID ASC"; // Tie-breaker
                break;
            case 'latest_to_oldest':
                $orderByClause_burial = "ORDER BY br.YearOfBurial DESC, 
                                        FIELD(SUBSTRING_INDEX(br.MonthDayOfBurial, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') DESC, 
                                        CAST(SUBSTRING_INDEX(br.MonthDayOfBurial, ' ', -1) AS UNSIGNED) DESC,
                                        br.BurialID DESC"; // Tie-breaker
                break;
        }
    }
    // Handle standalone sort_order parameter
    if (isset($_GET['sort_order_burial'])) {
        if ($_GET['sort_order_burial'] === 'asc' && $filter_type !== 'oldest_to_latest') {
            $orderByClause_burial = "ORDER BY br.YearOfBurial ASC, FIELD(SUBSTRING_INDEX(br.MonthDayOfBurial, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') ASC, CAST(SUBSTRING_INDEX(br.MonthDayOfBurial, ' ', -1) AS UNSIGNED) ASC, br.BurialID ASC";
        } elseif ($_GET['sort_order_burial'] === 'desc' && $filter_type !== 'latest_to_oldest') {
            $orderByClause_burial = "ORDER BY br.YearOfBurial DESC, FIELD(SUBSTRING_INDEX(br.MonthDayOfBurial, ' ', 1), 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') DESC, CAST(SUBSTRING_INDEX(br.MonthDayOfBurial, ' ', -1) AS UNSIGNED) DESC, br.BurialID DESC";
        }
    }
}
// --- END: Filter Logic (Burial Records) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

    if ($conn->connect_error) {
        error_log("Database Connection Failed: " . $conn->connect_error);
        echo "<script>alert('Error: Could not connect to the database. Please try again later.'); window.history.back();</script>";
        exit();
    }

    // --- Collect and trim form inputs ---
    $ClientID = trim($_POST['ClientID'] ?? '');
    $MonthDayOfDeath = trim($_POST['MonthDayOfDeath'] ?? '');
    $YearOfDeath = trim($_POST['YearOfDeath'] ?? '');
    $MonthDayOfBurial = trim($_POST['MonthDayOfBurial'] ?? '');
    $YearOfBurial = trim($_POST['YearOfBurial'] ?? '');
    $OneStatus = trim($_POST['OneStatus'] ?? ''); // Civil Status
    $TwoStatus = trim($_POST['TwoStatus'] ?? ''); // Age
    $OneImmediateFamily = trim($_POST['OneImmediateFamily'] ?? '');
    $TwoImmediateFamily = trim($_POST['TwoImmediateFamily'] ?? '');
    $OneResidence = trim($_POST['OneResidence'] ?? '');
    $TwoResidence = trim($_POST['TwoResidence'] ?? '');
    $Sacraments = trim($_POST['Sacraments'] ?? '');
    $CauseOFDeath = trim($_POST['CauseOFDeath'] ?? '');
    $PlaceOfBurial = trim($_POST['PlaceOfBurial'] ?? '');
    $PriestID = trim($_POST['PriestID'] ?? '');
    $RemarksRaw = trim($_POST['Remarks'] ?? '');
    $Remarks = ($RemarksRaw !== "") ? $RemarksRaw : null;
    $BurialID = null; // Initialize for update case

    // --- SERVER-SIDE VALIDATION ---
    $errors = [];
    $currentYear = date('Y');

    // Validate BurialID (only if it's an update action)
    if (isset($_POST['updateRecord'])) {
        $BurialID = trim($_POST['BurialID'] ?? ''); // Get BurialID for update
        if (empty($BurialID)) {
            $errors[] = "Burial ID is required for an update.";
        } elseif (!ctype_digit($BurialID) || intval($BurialID) <= 0) {
            $errors[] = "Invalid Burial ID format for update. Must be a positive number.";
        }
    }

    // ClientID
    if (empty($ClientID)) {
        $errors[] = "Client ID is required.";
    } elseif (!ctype_digit($ClientID) || intval($ClientID) <= 0) {
        $errors[] = "Client ID must be a positive number.";
    }
    // ... (rest of the PHP validation remains the same as in the previous correct answer) ...

    // Date validation patterns and helpers
    $monthDayPattern = '/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+([0-9]{1,2})$/i';
    $deathDateObj = null;
    $burialDateObj = null;

    // MonthDayOfDeath & YearOfDeath
    if (empty($MonthDayOfDeath)) {
        $errors[] = "Month and Day of Death are required.";
    } elseif (!preg_match($monthDayPattern, $MonthDayOfDeath, $matchesDeath)) {
        $errors[] = "Month and Day of Death format is invalid (e.g., 'January 1' or 'January 01').";
    } else {
        $monthNameDeath = $matchesDeath[1];
        $dayDeath = intval($matchesDeath[2]);
        if ($dayDeath < 1 || $dayDeath > 31) { // Basic day range check
            $errors[] = "Day of Death must be between 1 and 31.";
        }
    }

    if (empty($YearOfDeath)) {
        $errors[] = "Year of Death is required.";
    } elseif (!preg_match('/^\d{4}$/', $YearOfDeath)) {
        $errors[] = "Year of Death must be a 4-digit year.";
    } elseif (intval($YearOfDeath) < 1800 || intval($YearOfDeath) > $currentYear) {
        $errors[] = "Year of Death must be between 1800 and the current year ({$currentYear}).";
    }

    // Combine and validate full Death Date
    if (empty($errors) && isset($monthNameDeath) && isset($dayDeath) && !empty($YearOfDeath) && preg_match('/^\d{4}$/', $YearOfDeath)) {
        $deathDateTimeStr = $monthNameDeath . " " . $dayDeath . " " . $YearOfDeath;
        $deathDateObj = DateTime::createFromFormat('F j Y', $deathDateTimeStr);
        if ($deathDateObj === false) {
            $errors[] = "The Month, Day, and Year of Death do not form a valid date (e.g. February 30).";
        } elseif (strtolower($deathDateObj->format('F')) !== strtolower($monthNameDeath) || (int)$deathDateObj->format('j') !== $dayDeath) {
            // This check catches invalid day for month, e.g., "February 30"
            $errors[] = "The Day of Death ({$dayDeath}) is invalid for {$monthNameDeath} {$YearOfDeath}.";
        }
    }


    // MonthDayOfBurial & YearOfBurial
    if (empty($MonthDayOfBurial)) {
        $errors[] = "Month and Day of Burial are required.";
    } elseif (!preg_match($monthDayPattern, $MonthDayOfBurial, $matchesBurial)) {
        $errors[] = "Month and Day of Burial format is invalid (e.g., 'January 1' or 'January 01').";
    } else {
        $monthNameBurial = $matchesBurial[1];
        $dayBurial = intval($matchesBurial[2]);
        if ($dayBurial < 1 || $dayBurial > 31) { // Basic day range check
            $errors[] = "Day of Burial must be between 1 and 31.";
        }
    }

    if (empty($YearOfBurial)) {
        $errors[] = "Year of Burial is required.";
    } elseif (!preg_match('/^\d{4}$/', $YearOfBurial)) {
        $errors[] = "Year of Burial must be a 4-digit year.";
    } elseif (intval($YearOfBurial) > ($currentYear + 10)) { // Allow burial planning up to 10 years ahead
        $errors[] = "Year of Burial seems too far in the future (max " . ($currentYear + 10) . ").";
    }


    // Combine and validate full Burial Date
    if (empty($errors) && isset($monthNameBurial) && isset($dayBurial) && !empty($YearOfBurial) && preg_match('/^\d{4}$/', $YearOfBurial)) {
        $burialDateTimeStr = $monthNameBurial . " " . $dayBurial . " " . $YearOfBurial;
        $burialDateObj = DateTime::createFromFormat('F j Y', $burialDateTimeStr);
        if ($burialDateObj === false) {
            $errors[] = "The Month, Day, and Year of Burial do not form a valid date (e.g. February 30).";
        } elseif (strtolower($burialDateObj->format('F')) !== strtolower($monthNameBurial) || (int)$burialDateObj->format('j') !== $dayBurial) {
            // This check catches invalid day for month
            $errors[] = "The Day of Burial ({$dayBurial}) is invalid for {$monthNameBurial} {$YearOfBurial}.";
        }
    }


    // Date logical comparison: Burial date vs Death date
    if (empty($errors) && $deathDateObj && $burialDateObj) {
        if ($burialDateObj < $deathDateObj) {
            $errors[] = "Burial date cannot be before the date of death.";
        }
    }

    // OneStatus (Civil Status)
    $validStatuses = ['Single', 'Married', 'Divorced', 'Separated', 'Widowed'];
    if (empty($OneStatus)) {
        $errors[] = "Civil Status is required.";
    } elseif (!in_array($OneStatus, $validStatuses, true)) {
        $errors[] = "Invalid Civil Status selected.";
    }

    // TwoStatus (Age)
    if ($TwoStatus === '') { // Check for empty string specifically for 'required'
        $errors[] = "Age is required.";
    } elseif (!ctype_digit($TwoStatus)) {
        $errors[] = "Age must be a whole number.";
    } else {
        $age = intval($TwoStatus);
        if ($age < 0 || $age > 150) {
            $errors[] = "Age must be between 0 and 150.";
        }
    }

    // Immediate Family Names
    $namePattern = '/^[\p{L}\s.\'-]+$/u';
    if (empty($OneImmediateFamily)) {
        $errors[] = "First Immediate Family member's name is required.";
    } elseif (!preg_match($namePattern, $OneImmediateFamily)) {
        $errors[] = "First Immediate Family member's name contains invalid characters.";
    } elseif (strlen($OneImmediateFamily) > 100) {
        $errors[] = "First Immediate Family member's name is too long (max 100 characters).";
    }

    if (empty($TwoImmediateFamily)) {
        $errors[] = "Second Immediate Family member's name is required.";
    } elseif (!preg_match($namePattern, $TwoImmediateFamily)) {
        $errors[] = "Second Immediate Family member's name contains invalid characters.";
    } elseif (strlen($TwoImmediateFamily) > 100) {
        $errors[] = "Second Immediate Family member's name is too long (max 100 characters).";
    }

    // Residence fields
    $addressPattern = '/^[\p{L}\p{N}\s,.\'#\/\-]+$/u'; // Allows letters, numbers, space, comma, period, apostrophe, hash, slash, hyphen
    $maxLengthAddress = 255;
    if (empty($OneResidence)) {
        $errors[] = "First Immediate Family Residence is required.";
    } elseif (!preg_match($addressPattern, $OneResidence)) {
        $errors[] = "First Immediate Family Residence contains invalid characters.";
    } elseif (strlen($OneResidence) > $maxLengthAddress) {
        $errors[] = "First Immediate Family Residence is too long (max $maxLengthAddress characters).";
    }

    if (empty($TwoResidence)) {
        $errors[] = "Second Immediate Family Residence is required.";
    } elseif (!preg_match($addressPattern, $TwoResidence)) {
        $errors[] = "Second Immediate Family Residence contains invalid characters.";
    } elseif (strlen($TwoResidence) > $maxLengthAddress) {
        $errors[] = "Second Immediate Family Residence is too long (max $maxLengthAddress characters).";
    }

    if (empty($PlaceOfBurial)) {
        $errors[] = "Place of Burial is required.";
    } elseif (!preg_match($addressPattern, $PlaceOfBurial)) {
        $errors[] = "Place of Burial contains invalid characters.";
    } elseif (strlen($PlaceOfBurial) > $maxLengthAddress) {
        $errors[] = "Place of Burial is too long (max $maxLengthAddress characters).";
    }

    // Sacraments & CauseOFDeath
    $lettersAndSpacesPattern = '/^[\p{L}\s.\'-]+$/u'; // Allows letters (Unicode), spaces, periods, apostrophes, hyphens
    $maxLengthGeneral = 255;

    if (empty($Sacraments)) {
        $errors[] = "Sacraments information is required.";
    } elseif (!preg_match($lettersAndSpacesPattern, $Sacraments)) { // Use new pattern
        $errors[] = "Sacraments field contains invalid characters. Only letters, spaces, periods, apostrophes, and hyphens are allowed.";
    } elseif (strlen($Sacraments) > $maxLengthGeneral) {
        $errors[] = "Sacraments field is too long (max $maxLengthGeneral characters).";
    }

    if (empty($CauseOFDeath)) {
        $errors[] = "Cause of Death is required.";
    } elseif (!preg_match($lettersAndSpacesPattern, $CauseOFDeath)) { // Use new pattern
        $errors[] = "Cause of Death field contains invalid characters. Only letters, spaces, periods, apostrophes, and hyphens are allowed.";
    } elseif (strlen($CauseOFDeath) > $maxLengthGeneral) {
        $errors[] = "Cause of Death field is too long (max $maxLengthGeneral characters).";
    }

    // PriestID
    if (empty($PriestID)) {
        $errors[] = "Officiating Priest is required.";
    } elseif (!ctype_digit($PriestID) || intval($PriestID) <= 0) {
        $errors[] = "Invalid Priest ID selected.";
    } else {
        // Check if priest exists
        $checkPriestStmt = $conn->prepare("SELECT PriestID FROM priest WHERE PriestID = ?");
        if ($checkPriestStmt) {
            $checkPriestStmt->bind_param("i", $PriestID);
            $checkPriestStmt->execute();
            $checkPriestResult = $checkPriestStmt->get_result();
            if ($checkPriestResult->num_rows === 0) {
                $errors[] = "Selected Officiating Priest does not exist.";
            }
            $checkPriestStmt->close();
        } else {
            $errors[] = "Error verifying priest. Please try again."; // Database error
        }
    }

    // Remarks (optional)
    if ($Remarks !== null) { // Only validate if not null (i.e., was not empty string before trim)
        // Basic length check for remarks
        if (strlen($Remarks) > 500) { // Example max length
            $errors[] = "Remarks are too long (max 500 characters).";
        }
        // You might add more specific regex if remarks have expected patterns or restricted characters
        // For general text, often a length check and basic sanitization on output is enough.
        // A very basic check for script tags (can be bypassed, but better than nothing if no other sanitization)
        // if (preg_match('/<script|<style|<iframe|<embed|<object/i', $Remarks)) {
        //    $errors[] = "Remarks contain potentially unsafe content.";
        // }
    }


    // --- Handle Validation Errors ---
    if (!empty($errors)) {
        // Using htmlspecialchars for each error message to prevent XSS if an error message itself contained HTML/JS
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

    // --- Proceed with Database Operations if No Errors ---


    // INSERT RECORD
    if (isset($_POST['save_burial'])) {
        $sql = "INSERT INTO burial_records (
            ClientID, MonthDayOfDeath, YearOfDeath, 
            MonthDayOfBurial, YearOfBurial, OneStatus, TwoStatus, 
            OneImmediateFamily, TwoImmediateFamily, OneResidence, TwoResidence, 
            Sacraments, CauseOFDeath, PlaceOfBurial, PriestID, Remarks, ParishStaffID
        ) VALUES ( ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("SQL Prepare Error (Insert Burial): " . $conn->error);
            echo "<script>alert('Error preparing the record. Please try again.'); window.history.back();</script>";
            $conn->close();
            exit();
        }

        $stmt->bind_param(
            "isssssssssssssisi", // ClientID (i), TwoStatus (Age - now string), PriestID (i), rest (s)
            $ClientID, $MonthDayOfDeath, $YearOfDeath,
            $MonthDayOfBurial, $YearOfBurial, $OneStatus, $TwoStatus, // TwoStatus (Age) will be string
            $OneImmediateFamily, $TwoImmediateFamily, $OneResidence, $TwoResidence,
            $Sacraments, $CauseOFDeath, $PlaceOfBurial, $PriestID, $Remarks, $parishStaffID
        );

        if ($stmt->execute()) {
            echo "<script>alert('Record inserted successfully!'); window.location.href = window.location.href;</script>";
            exit();
        } else {
            error_log("SQL Execute Error (Insert Burial): " . $stmt->error);
            echo "<script>alert('Error inserting record: " . htmlspecialchars($stmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
        }
        $stmt->close();
    }

    // UPDATE RECORD
    elseif (isset($_POST['updateRecord'])) {
        // $BurialID is already retrieved and validated if this block is reached and no errors.
        $adminPassword = $_POST['adminPassword'] ?? '';
        $isValid = false;

        // Check admin password
        $sqlPass = "SELECT PasswordHash FROM admin_users"; // Renamed SQL variable
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
            $sql = "UPDATE burial_records SET
                    ClientID=?, MonthDayOfDeath=?, YearOfDeath=?,
                    MonthDayOfBurial=?, YearOfBurial=?, OneStatus=?, TwoStatus=?,
                    OneImmediateFamily=?, TwoImmediateFamily=?, OneResidence=?, TwoResidence=?,
                    Sacraments=?, CauseOFDeath=?, PlaceOfBurial=?, PriestID=?, Remarks=?
                    WHERE BurialID=?";

            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                error_log("SQL Prepare Error (Update Burial): " . $conn->error);
                echo "<script>alert('Error preparing the update. Please try again.'); window.history.back();</script>";
                $conn->close();
                exit();
            }

            $stmt->bind_param(
                "isssssssssssssisi", // ClientID (i), TwoStatus (Age - string), PriestID (i), BurialID (i), rest (s)
                $ClientID, $MonthDayOfDeath, $YearOfDeath,
                $MonthDayOfBurial, $YearOfBurial, $OneStatus, $TwoStatus, // TwoStatus (Age) will be string
                $OneImmediateFamily, $TwoImmediateFamily, $OneResidence, $TwoResidence,
                $Sacraments, $CauseOFDeath, $PlaceOfBurial, $PriestID, $Remarks, $BurialID
            );

            if ($stmt->execute()) {
                echo "<script>alert('Record updated successfully!'); window.location.href = window.location.href;</script>";
                exit();
            } else {
                error_log("SQL Execute Error (Update Burial): " . $stmt->error);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" href="/imagess/sacred.png">
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="stylesheet" href="burialStyle.css?v=11"> <!-- Make a CSS file for burial -->
    <link rel="stylesheet" href="responsive.css?v=12">
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
    <div class="section-title">Burial Records</div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search..." style="flex-grow: 1;">

            <!-- START: Category Filter for Burial -->
            <div class="filter-container">
                <select id="categoryFilterBurial" name="category_filter_burial" title="Category Filter">
                    <option value="">-- Filter By Burial Date --</option>
                    <option value="year">Year</option>
                    <option value="month">Month</option>
                    <option value="specific_date">Specific Date</option>
                    <option value="oldest_to_latest">Oldest to Latest</option>
                    <option value="latest_to_oldest">Latest to Oldest</option>
                </select>

                <div id="filterYearInputContainerBurial" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearValueBurial" name="filter_year_value_burial" placeholder="YYYY">
                </div>
                <div id="filterMonthInputContainerBurial" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearForMonthValueBurial" name="filter_year_for_month_value_burial" placeholder="YYYY (Opt.)">
                    <select id="filterMonthValueBurial" name="filter_month_value_burial">
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
                <div id="filterDateInputContainerBurial" class="filter-input-group" style="display:none;">
                    <input type="text" id="filterDateValueBurial" name="filter_date_value_burial" placeholder="DD/MM/YYYY">
                </div>
                <button id="applyFilterBtnBurial" class="filter-btn">Apply</button>
                <button id="clearFilterBtnBurial" class="filter-btn">Clear</button>
            </div>
            <!-- END: Category Filter for Burial -->

            <div class="record-buttons" style="margin-left: auto;">
                <button id="updateRecordBtn">✎ Update Record</button>
                <button id="addRecordBtn">+ Add Record</button>
            </div>
        </div>

        <div class="table-scroll">
            <table id="recordsTable">
                <thead>
                <tr class="header-row-1">
                    <th>Burial ID</th>
                    <th>Deceased</th>
                    <th colspan="2">Date Of Death</th>
                    <th colspan="2">Date of Burial</th>
                    <th colspan="2">Status and Age</th>
                    <th>Parents, Wife, or Husband</th>
                    <th>Residence</th>
                    <th>Sacraments</th>
                    <th>Cause of Death</th>
                    <th>Place of Burial</th>
                    <th>Name of Minister</th>
                    <th>Remarks</th>
                    <th>Created By</th>
                </tr>
                <tr class="header-row-2">
                    <th></th>
                    <th>Name and Family Name</th>
                    <th>Month and Day</th>
                    <th>Year</th>
                    <th>Month and Day</th>
                    <th>Year</th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
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

                // $sql = "SELECT * FROM burial_records";
                // START MODIFY FOR FILTER

                $baseSqlBurial = "SELECT br.*, p.FullName AS PriestName,
                    c.FullName AS ClientName,
                    COALESCE(au.username, su.username, 'Unknown') AS CreatedBy
                    FROM burial_records br
                    LEFT JOIN priest p ON br.PriestID = p.PriestID
                    LEFT JOIN parishstaff ps ON br.ParishStaffID = ps.ParishStaffID
                    LEFT JOIN admin_users au ON ps.AdminUserID = au.ID
                    LEFT JOIN staff_users su ON ps.StaffUserID = su.id
                    LEFT JOIN client c ON br.ClientID = c.ClientID";

                $finalSqlBurial = $baseSqlBurial;

                if (!empty($whereClauses_burial)) {
                    $finalSqlBurial .= " WHERE " . implode(" AND ", $whereClauses_burial);
                }
                $finalSqlBurial .= " " . $orderByClause_burial;

                $resultBurial = null; // Initialize

                if (!empty($filter_params_burial)) {
                    $stmtBurial = $conn->prepare($finalSqlBurial);
                    if ($stmtBurial === false) {
                        error_log("SQL Prepare Error (Filter Burial): " . $conn->error . " | SQL: " . $finalSqlBurial);
                        echo "<tr><td colspan='16'>Error preparing burial data.</td></tr>";
                    } else {
                        $stmtBurial->bind_param($filter_param_types_burial, ...$filter_params_burial);
                        $stmtBurial->execute();
                        $resultBurial = $stmtBurial->get_result();
                        if ($resultBurial === false) {
                            error_log("SQL Get Result Error (Filter Burial): " . $stmtBurial->error);
                            echo "<tr><td colspan='16'>Error retrieving filtered burial data.</td></tr>";
                        }
                    }
                } else {
                    $resultBurial = $conn->query($finalSqlBurial);
                    if ($resultBurial === false) {
                        error_log("SQL Query Error (Burial): " . $conn->error . " | SQL: " . $finalSqlBurial);
                        echo "<tr><td colspan='16'>Error fetching burial data.</td></tr>";
                    }
                }

                if ($resultBurial && $resultBurial->num_rows > 0) {
                    while ($row = $resultBurial->fetch_assoc()) {
                        // Using htmlspecialchars for all echoed data
                        echo "<tr>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['BurialID'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['ClientName'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['MonthDayOfDeath'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['YearOfDeath'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['MonthDayOfBurial'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['YearOfBurial'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['OneStatus'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['TwoStatus'] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row['OneImmediateFamily'] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row['OneResidence'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['Sacraments'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['CauseOFDeath'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['PlaceOfBurial'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['PriestName'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row['Remarks'] ?? '-') . "</td>";
                        echo "<td rowspan='2'>" . htmlspecialchars($row["CreatedBy"] ?? '-') . "</td>";
                        echo "</tr>";

                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['TwoImmediateFamily'] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row['TwoResidence'] ?? '-') . "</td>";
                        echo "</tr>";
                    }
                } else if ($resultBurial) {
                    echo "<tr><td colspan='16'>No burial records found matching your criteria.</td></tr>";
                }
                $conn->close();
                // END FILTER FOR burial
                ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- Add Modal [start] -->
    <div class="modal" id="recordModal">
        <form class="modal-content" id="addBurialForm" method="POST" action="burial.php" style="width: 1000px; height: 650px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>
            <div class="modal-header" style="background: #2c3e50; color: white; text-align: center; border-radius: 0; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Burial Details</h3>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">

                <!-- Burial Form Fields -->

                <div style="flex: 1 1 45%;">
                    <label for="ClientID" style="margin-left: 55px;">Client ID:</label><br>
                    <input type="text" id="addClientID" name="ClientID" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addClientIDError" class="error-message" style="margin-left: 55px;">Client ID must be a number.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="OneImmediateFamily" style="margin-left: 30px;">First Immediate Family:</label><br>
                    <input type="text" id="addOneImmediateFamily" name="OneImmediateFamily" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addOneImmediateFamilyError" class="error-message" style="margin-left: 30px;">Name is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="MonthDayOfDeath" style="margin-left: 55px;">Month & Day Of Death:</label><br>
                    <input type="text"  id="addMonthDayOfDeath" name="MonthDayOfDeath" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addMonthDayOfDeathError" class="error-message" style="margin-left: 55px;">Month and day of death is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="OneResidence" style="margin-left: 30px;">First Immediate Family Residence:</label><br>
                    <input type="text" id="addOneResidence" name="OneResidence" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addOneResidenceError" class="error-message" style="margin-left: 30px;">Residence is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="YearOfDeath" style="margin-left: 55px;">Year Of Death:</label><br>
                    <input type="text" id="addYearOfDeath" name="YearOfDeath" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addYearOfDeathError" class="error-message" style="margin-left: 55px;">Year of death is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="TwoImmediateFamily" style="margin-left: 30px;">Second Immediate Family:</label><br>
                    <input type="text" id="addTwoImmediateFamily" name="TwoImmediateFamily" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addTwoImmediateFamilyError" class="error-message" style="margin-left: 30px;">Name is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="MonthDayOfBurial" style="margin-left: 55px;">Month & Day Of Burial:</label><br>
                    <input type="text" id="addMonthDayOfBurial" name="MonthDayOfBurial" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addMonthDayOfBurialError" class="error-message" style="margin-left: 55px;">Month and day of burial is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="TwoResidence" style="margin-left: 30px;">Second Immediate Family Residence:</label><br>
                    <input type="text"  id="addTwoResidence" name="TwoResidence" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addTwoResidenceError" class="error-message" style="margin-left: 30px;">Residence is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="YearOfBurial" style="margin-left: 55px;">Year Of Burial:</label><br>
                    <input type="text" id="addYearOfBurial" name="YearOfBurial" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addYearOfBurialError" class="error-message" style="margin-left: 55px;">Year of burial is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="Sacraments" style="margin-left: 30px;">Sacraments:</label><br>
                    <input type="text" id="addSacraments" name="Sacraments" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addSacramentsError" class="error-message" style="margin-left: 30px;">Sacraments is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="OneStatus" style="margin-left: 55px;">Civil Status:</label><br>
                    <select id="addOneStatus" name="OneStatus" required style="width: 80%; padding: 5px; margin-left: 55px;">
                        <option value="">-- Select Civil Status --</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Divorced">Divorced</option>
                        <option value="Separated">Separated</option>
                        <option value="Widowed">Widowed</option>
                    </select>
                    <small id="addOneStatusError" class="error-message" style="margin-left: 55px;">Civil status is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="CauseOFDeath" style="margin-left: 30px;">Cause Of Death:</label><br>
                    <input type="text" id="addCauseOFDeath" name="CauseOFDeath" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addCauseOFDeathError" class="error-message" style="margin-left: 30px;">Cause of death is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="TwoStatus" style="margin-left: 55px;">Age:</label><br>
                    <input type="number" id="addTwoStatus" name="TwoStatus" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addTwoStatusError" class="error-message" style="margin-left: 55px;">Age is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="PlaceOfBurial" style="margin-left: 30px;">Place Of Burial:</label><br>
                    <input type="text" id="addPlaceOfBurial" name="PlaceOfBurial" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addPlaceOfBurialError" class="error-message" style="margin-left: 30px;">Place of burial is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="PriestID" style="margin-left: 55px;">Select Priest:</label><br>
                    <select id="addPriestID" name="PriestID" required style="width: 80%; padding: 5px; margin-left: 55px;">
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
                    <small id="addPriestIDError" class="error-message" style="margin-left: 55px;">Priest is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="Remarks" style="margin-left: 30px;">Remarks:</label><br>
                    <textarea id="addRemarks" name="Remarks" style="width: 88%; min-height: 90px; padding: 5px; margin-left: 30px; resize: none;"
                              oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px';"></textarea>
                    <small id="addRemarksError" class="error-message" style="margin-left: 30px;">Remarks is required.</small>
                </div>

            </div>

            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" id="addBurialSubmitButton" name="save_burial" style="background-color: #28a745; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">+ Add Record</button>
            </div>
        </form>
    </div>
    <!-- Add Modal [end] -->


    <!-- Update Modal [start] -->
    <div class="modal" id="updateModal">
        <form class="modal-content" id="updateBurialForm" method="POST" action="burial.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeUpdateModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>

            <div class="modal-header" style="background: #F39C12; color: white; text-align: center; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Update Burial Record</h3>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">

                <!-- Burial Form Fields -->
                <div style="flex: 1 1 45%;">
                    <label for="updateBurialID" style="margin-left: 55px;">Burial ID:</label><br>
                    <input type="text" id="updateBurialID" name="BurialID" readonly style="width: 80%; padding: 5px; margin-left: 55px; background-color: #e9e9e9;">
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updateOneImmediateFamily" style="margin-left: 30px;">First Immediate Family:</label><br>
                    <input type="text" id="updateOneImmediateFamily" name="OneImmediateFamily" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateOneImmediateFamilyError" class="error-message hidden" style="margin-left: 30px;">Name is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateClientID" style="margin-left: 55px;">Client ID:</label><br>
                    <input type="text" id="updateClientID" name="ClientID" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateClientIDError" class="error-message hidden" style="margin-left: 55px;">Client ID must be a number.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updateOneResidence" style="margin-left: 30px;">First Immediate Family Residence:</label><br>
                    <input type="text" id="updateOneResidence" name="OneResidence" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateOneResidenceError" class="error-message hidden" style="margin-left: 30px;">Residence is required..</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateMonthDayOfDeath" style="margin-left: 55px;">Month & Day Of Death:</label><br>
                    <input type="text" id="updateMonthDayOfDeath" name="MonthDayOfDeath" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateMonthDayOfDeathError" class="error-message hidden" style="margin-left: 55px;">Month and day of death is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateTwoImmediateFamily" style="margin-left: 30px;">Second Immediate Family:</label><br>
                    <input type="text" id="updateTwoImmediateFamily" name="TwoImmediateFamily" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateTwoImmediateFamilyError" class="error-message hidden" style="margin-left: 30px;">Name is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updateYearOfDeath" style="margin-left: 55px;">Year Of Death:</label><br>
                    <input type="text" id="updateYearOfDeath" name="YearOfDeath" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateYearOfDeathError" class="error-message hidden" style="margin-left: 55px;">Year of death is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateTwoResidence" style="margin-left: 30px;">Second Immediate Family Residence:</label><br>
                    <input type="text" id="updateTwoResidence" name="TwoResidence" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateTwoResidenceError" class="error-message hidden" style="margin-left: 30px;">Residence is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updateMonthDayOfBurial" style="margin-left: 55px;">Month & Day Of Burial:</label><br>
                    <input type="text" id="updateMonthDayOfBurial" name="MonthDayOfBurial" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateMonthDayOfBurialError" class="error-message hidden" style="margin-left: 55px;">Month and day of burial is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateSacraments" style="margin-left: 30px;">Sacraments:</label><br>
                    <input type="text" id="updateSacraments" name="Sacraments" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateSacramentsError" class="error-message hidden" style="margin-left: 30px;">Sacraments is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updateYearOfBurial" style="margin-left: 55px;">Year Of Burial:</label><br>
                    <input type="text" id ="updateYearOfBurial" name="YearOfBurial" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateYearOfBurialError" class="error-message hidden" style="margin-left: 55px;">Year of burial is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updateCauseOFDeath" style="margin-left: 30px;">Cause Of Death:</label><br>
                    <input type="text" id="updateCauseOFDeath" name="CauseOFDeath" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateCauseOFDeathError" class="error-message hidden" style="margin-left: 30px;">Cause of death is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateOneStatus" style="margin-left: 55px;">Civil Status:</label><br>
                    <select id="updateOneStatus" name="OneStatus" required style="width: 80%; padding: 5px; margin-left: 55px;">
                        <option value="">-- Select Civil Status --</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Divorced">Divorced</option>
                        <option value="Separated">Separated</option>
                        <option value="Widowed">Widowed</option>
                    </select>
                    <small id="updateOneStatusError" class="error-message hidden" style="margin-left: 55px;">Civil status is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updatePlaceOfBurial" style="margin-left: 30px;">Place Of Burial:</label><br>
                    <input type="text" id="updatePlaceOfBurial" name="PlaceOfBurial" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updatePlaceOfBurialError" class="error-message hidden" style="margin-left: 30px;">Place of burial is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updateTwoStatus" style="margin-left: 55px;">Age:</label><br>
                    <input type="number" id="updateTwoStatus" name="TwoStatus" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateTwoStatusError" class="error-message hidden" style="margin-left: 55px;">Age is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updatePriestID" style="margin-left: 30px;">Select Priest:</label><br>
                    <select name="PriestID" id="updatePriestID" required style="width: 80%; padding: 5px; margin-left: 30px;">
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
                    <small id="updatePriestIDError" class="error-message hidden" style="margin-left: 30px;">Priest is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateRemarks" style="margin-left: 55px;">Remarks:</label><br>
                    <textarea id="updateRemarks" name="Remarks" style="width: 88%; min-height: 90px; padding: 5px; margin-left: 55px; resize: none;"
                              oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px';"></textarea>
                    <small id="updateRemarksError" class="error-message hidden" style="margin-left: 55px;">Remarks error.</small>
                </div>

            </div>

            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" id="updateBurialSubmitButton" name="updateRecord" style="background-color: #F39C12; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">✎ Update Record</button>
            </div>

            <input type="hidden" name="adminPassword" id="hiddenAdminPassword">
        </form>
    </div>
    <!-- Update Modal [end] -->


    <!-- Admin Modal [start] -->
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
    <!-- Admin Modal [end] -->

    <!-- message modal when admin password is correct/incorrect [start] -->
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
    <!-- message modal when admin password is correct/incorrect [end] -->

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
        const burialRegexPatterns = {
            positiveInteger: /^\d+$/,
            name: /^[\p{L}\s.'-]+$/u,
            monthDay: /^(January|February|March|April|May|June|July|August|September|October|November|December)\s+([1-9]|[12][0-9]|3[01])$/i,
            year: /^\d{4}$/,
            address: /^[\p{L}\p{N}\s,.'#/\-]+$/u,
            lettersSpacesPunctuation: /^[\p{L}\s.'-]+$/u, // For Sacraments and Cause of Death
            noHtmlTags: /^[^<>]*$/
        };
        const currentYearJS = new Date().getFullYear();

        /*----- BURIAL CLIENT-SIDE VALIDATION -----*/
        const addBurialForm = document.getElementById('addBurialForm');
        const addBurialSubmitButton = document.getElementById('addBurialSubmitButton');
        const addBurialFields = {
            ClientID: document.getElementById('addClientID'),
            MonthDayOfDeath: document.getElementById('addMonthDayOfDeath'), YearOfDeath: document.getElementById('addYearOfDeath'),
            MonthDayOfBurial: document.getElementById('addMonthDayOfBurial'), YearOfBurial: document.getElementById('addYearOfBurial'),
            OneStatus: document.getElementById('addOneStatus'), TwoStatus: document.getElementById('addTwoStatus'),
            OneImmediateFamily: document.getElementById('addOneImmediateFamily'), TwoImmediateFamily: document.getElementById('addTwoImmediateFamily'),
            OneResidence: document.getElementById('addOneResidence'), TwoResidence: document.getElementById('addTwoResidence'),
            Sacraments: document.getElementById('addSacraments'), CauseOFDeath: document.getElementById('addCauseOFDeath'),
            PlaceOfBurial: document.getElementById('addPlaceOfBurial'), PriestID: document.getElementById('addPriestID'),
            Remarks: document.getElementById('addRemarks')
        };
        const addBurialFormState = {};

        const updateBurialForm = document.getElementById('updateBurialForm');
        const updateBurialSubmitButton = document.getElementById('updateBurialSubmitButton');
        const updateBurialFields = {
            ClientID: document.getElementById('updateClientID'),
            MonthDayOfDeath: document.getElementById('updateMonthDayOfDeath'), YearOfDeath: document.getElementById('updateYearOfDeath'),
            MonthDayOfBurial: document.getElementById('updateMonthDayOfBurial'), YearOfBurial: document.getElementById('updateYearOfBurial'),
            OneStatus: document.getElementById('updateOneStatus'), TwoStatus: document.getElementById('updateTwoStatus'),
            OneImmediateFamily: document.getElementById('updateOneImmediateFamily'), TwoImmediateFamily: document.getElementById('updateTwoImmediateFamily'),
            OneResidence: document.getElementById('updateOneResidence'), TwoResidence: document.getElementById('updateTwoResidence'),
            Sacraments: document.getElementById('updateSacraments'), CauseOFDeath: document.getElementById('updateCauseOFDeath'),
            PlaceOfBurial: document.getElementById('updatePlaceOfBurial'), PriestID: document.getElementById('updatePriestID'),
            Remarks: document.getElementById('updateRemarks')
        };
        const updateBurialFormState = {};


        function validateBurialField(fieldName, value, fieldElement, formTypePrefix) {
            let isValid = false;
            const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
            const currentFormState = (formTypePrefix === 'add') ? addBurialFormState : updateBurialFormState;

            value = String(value).trim();
            let specificErrorMsg = '';

            if (!fieldElement) {
                currentFormState[fieldName] = true;
                checkBurialFormOverallValidity(formTypePrefix);
                return;
            }
            if (fieldName === 'Remarks') {
                if (value === '') {
                    isValid = true;
                } else if (!burialRegexPatterns.noHtmlTags.test(value)) {
                    isValid = false; specificErrorMsg = "Remarks should not contain HTML tags like < or >.";
                } else if (value.length > 500) {
                    isValid = false; specificErrorMsg = 'Remarks too long (max 500 characters).';
                } else { isValid = true; }
            } else {
                if (value === '') {
                    isValid = false; specificErrorMsg = fieldName.replace(/([A-Z0-9])/g, ' $1').trim() + ' is required.';
                } else {
                    switch(fieldName) {
                        case 'ClientID':
                            isValid = burialRegexPatterns.positiveInteger.test(value) && parseInt(value) > 0;
                            specificErrorMsg = 'Client ID must be a positive number.';
                            break;
                        case 'OneImmediateFamily': case 'TwoImmediateFamily':
                            isValid = burialRegexPatterns.name.test(value) && value.length <= 100;
                            if (!burialRegexPatterns.name.test(value)) specificErrorMsg = 'Invalid characters in name.';
                            else if (value.length > 100) specificErrorMsg = 'Name is too long (max 100 chars).';
                            else specificErrorMsg = 'Invalid name format (letters, spaces, .\'- allowed, max 100 chars).';
                            break;
                        case 'MonthDayOfDeath': case 'MonthDayOfBurial':
                            isValid = burialRegexPatterns.monthDay.test(value);
                            specificErrorMsg = "Invalid format. Use 'Month Day' (e.g., January 01 or January 1).";
                            break;
                        case 'YearOfDeath':
                            isValid = burialRegexPatterns.year.test(value) && parseInt(value) >= 1800 && parseInt(value) <= currentYearJS;
                            specificErrorMsg = `Year must be a 4-digit year between 1800 and ${currentYearJS}.`;
                            break;
                        case 'YearOfBurial':
                            isValid = burialRegexPatterns.year.test(value) && parseInt(value) >= 1800 && parseInt(value) <= (currentYearJS + 10);
                            specificErrorMsg = `Year must be a 4-digit year between 1800 and ${currentYearJS + 10}.`;
                            break;
                        case 'OneStatus': case 'PriestID':
                            isValid = value !== '';
                            specificErrorMsg = `Please select a ${fieldName === 'OneStatus' ? 'Civil Status' : 'Priest'}.`;
                            break;
                        case 'TwoStatus':
                            isValid = burialRegexPatterns.positiveInteger.test(value) && parseInt(value) >= 0 && parseInt(value) <= 150;
                            specificErrorMsg = 'Age must be a whole number between 0 and 150.';
                            break;
                        case 'OneResidence': case 'TwoResidence': case 'PlaceOfBurial':
                            isValid = burialRegexPatterns.address.test(value) && value.length <= 255;
                            if (!burialRegexPatterns.address.test(value)) specificErrorMsg = 'Address contains invalid characters.';
                            else if (value.length > 255) specificErrorMsg = 'Address is too long (max 255 chars).';
                            else specificErrorMsg = 'Invalid address characters (max 255 chars).';
                            break;
                        case 'Sacraments': case 'CauseOFDeath':
                            isValid = burialRegexPatterns.lettersSpacesPunctuation.test(value) && value.length <= 255;
                            if (!burialRegexPatterns.lettersSpacesPunctuation.test(value)) specificErrorMsg = 'Field allows only letters, spaces, periods, apostrophes, hyphens.';
                            else if (value.length > 255) specificErrorMsg = 'Field is too long (max 255 chars).';
                            else specificErrorMsg = 'Invalid characters (max 255 chars).';
                            break;
                        default: isValid = true;
                    }
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
            checkBurialFormOverallValidity(formTypePrefix);
        }

        function checkBurialFormOverallValidity(formTypePrefix) {
            const currentFormState = (formTypePrefix === 'add') ? addBurialFormState : updateBurialFormState;
            const currentFields = (formTypePrefix === 'add') ? addBurialFields : updateBurialFields;
            const submitBtn = (formTypePrefix === 'add') ? addBurialSubmitButton : updateBurialSubmitButton;

            if (!submitBtn) return;
            let allValid = true;
            for (const fieldName in currentFields) {
                if (currentFields.hasOwnProperty(fieldName)) {
                    if (fieldName === 'Remarks') {
                        if (currentFormState[fieldName] === false) { allValid = false; break; }
                    } else if (currentFormState[fieldName] !== true) {
                        allValid = false; break;
                    }
                }
            }
            submitBtn.disabled = !allValid;
            submitBtn.style.backgroundColor = allValid ? ((formTypePrefix === 'add') ? '#28a745' : '#F39C12') : '#cccccc';
            submitBtn.style.cursor = allValid ? 'pointer' : 'not-allowed';
        }

        function initializeBurialValidation(formTypePrefix) {
            const form = (formTypePrefix === 'add') ? addBurialForm : updateBurialForm;
            const fields = (formTypePrefix === 'add') ? addBurialFields : updateBurialFields;
            const formState = (formTypePrefix === 'add') ? addBurialFormState : updateBurialFormState;
            const submitButton = (formTypePrefix === 'add') ? addBurialSubmitButton : updateBurialSubmitButton;

            if (!form || !submitButton) return;
            submitButton.disabled = true; submitButton.style.backgroundColor = '#cccccc'; submitButton.style.cursor = 'not-allowed';

            for (const fieldName in fields) {
                if (fields.hasOwnProperty(fieldName)) {
                    const fieldElement = fields[fieldName];
                    if (fieldElement) {
                        formState[fieldName] = (fieldName === 'Remarks' && fieldElement.value.trim() === '') ? true : false;
                        const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'number') ? 'change' : 'input';
                        fieldElement.addEventListener(eventType, function() { validateBurialField(fieldName, this.value, this, formTypePrefix); });
                        fieldElement.addEventListener('blur', function() { validateBurialField(fieldName, this.value, this, formTypePrefix); });
                        if (fieldElement.value.trim() !== '' || fieldName === 'Remarks') {
                            validateBurialField(fieldName, fieldElement.value, fieldElement, formTypePrefix);
                        }
                    }
                }
            }

            form.addEventListener('submit', function(event) {
                let formIsValid = true;
                for (const fieldName in fields) {
                    if (fields.hasOwnProperty(fieldName)) {
                        const fieldElement = fields[fieldName];
                        if (fieldElement) {
                            validateBurialField(fieldName, fieldElement.value, fieldElement, formTypePrefix);
                            if (formState[fieldName] === false) formIsValid = false;
                        }
                    }
                }
                if (formTypePrefix === 'update' && document.getElementById('hiddenAdminPassword').value === '') {
                    showMessageModal("Admin password seems to be missing. Please re-authenticate.");
                    formIsValid = false;
                }
                if (!formIsValid) {
                    event.preventDefault();
                    alert('Please correct the errors highlighted in the form before submitting.');
                }
            });
        }

        function resetBurialForm(formTypePrefix) {
            const form = (formTypePrefix === 'add') ? addBurialForm : updateBurialForm;
            const fields = (formTypePrefix === 'add') ? addBurialFields : updateBurialFields;
            const formState = (formTypePrefix === 'add') ? addBurialFormState : updateBurialFormState;
            const submitButton = (formTypePrefix === 'add') ? addBurialSubmitButton : updateBurialSubmitButton;

            if (formTypePrefix === 'add' && form) form.reset();
            else if (formTypePrefix === 'update') {
                for (const fieldName in fields) {
                    if (fields.hasOwnProperty(fieldName) && fields[fieldName]) fields[fieldName].value = '';
                }
            }

            for (const fieldName in fields) {
                if (fields.hasOwnProperty(fieldName)) {
                    const fieldElement = fields[fieldName];
                    const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
                    if (fieldElement) fieldElement.classList.remove('valid', 'invalid');
                    if (errorElement) { errorElement.classList.add('hidden'); errorElement.textContent = ''; }
                    formState[fieldName] = (fieldName === 'Remarks') ? true : false;
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

        document.getElementById("addRecordBtn").onclick = function () { resetBurialForm('add'); document.getElementById("recordModal").style.display = "flex"; };
        function closeModal() { document.getElementById("recordModal").style.display = "none"; resetBurialForm('add'); }

        document.getElementById("updateRecordBtn").onclick = function () { adminAuthenticated = false; resetBurialForm('update'); openAdminModal(); };

        function enableRowClickEdit() {
            const rows = document.querySelectorAll("#recordsTable tbody tr");
            rows.forEach(row => {
                if (row.querySelectorAll("td[rowspan='2']").length > 0) {
                    row.style.cursor = "pointer";
                    row.onclick = function () {
                        if (!adminAuthenticated) { showMessageModal("Admin authentication required. Click '✎ Update Record' first."); return; }
                        const cellsRow1 = this.querySelectorAll("td"); const nextRow = this.nextElementSibling;
                        const cellsRow2 = nextRow ? nextRow.querySelectorAll("td") : [];
                        if (cellsRow1.length >= 16 && cellsRow2.length >= 2) {
                            document.getElementById("updateBurialID").value = cellsRow1[0].innerText.trim();
                            document.getElementById("updateClientID").value = cellsRow1[1].innerText.trim();
                            document.getElementById("updateMonthDayOfDeath").value = cellsRow1[2].innerText.trim();
                            document.getElementById("updateYearOfDeath").value = cellsRow1[3].innerText.trim();
                            document.getElementById("updateMonthDayOfBurial").value = cellsRow1[4].innerText.trim();
                            document.getElementById("updateYearOfBurial").value = cellsRow1[5].innerText.trim();
                            document.getElementById("updateOneStatus").value = cellsRow1[6].innerText.trim();
                            document.getElementById("updateTwoStatus").value = cellsRow1[7].innerText.trim();
                            document.getElementById("updateOneImmediateFamily").value = cellsRow1[8].innerText.trim();
                            document.getElementById("updateOneResidence").value = cellsRow1[9].innerText.trim();
                            document.getElementById("updateSacraments").value = cellsRow1[10].innerText.trim();
                            document.getElementById("updateCauseOFDeath").value = cellsRow1[11].innerText.trim();
                            document.getElementById("updatePlaceOfBurial").value = cellsRow1[12].innerText.trim();
                            const priestNameInTable = cellsRow1[13].innerText.trim();
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
                            document.getElementById("updateRemarks").value = cellsRow1[14].innerText.trim();
                            document.getElementById("updateTwoImmediateFamily").value = cellsRow2[0].innerText.trim();
                            document.getElementById("updateTwoResidence").value = cellsRow2[1].innerText.trim();

                            for (const fieldName in updateBurialFields) {
                                if (updateBurialFields.hasOwnProperty(fieldName) && updateBurialFields[fieldName]) {
                                    validateBurialField(fieldName, updateBurialFields[fieldName].value, updateBurialFields[fieldName], 'update');
                                }
                            }
                            document.getElementById("updateModal").style.display = "flex";
                        } else { showMessageModal("Error: Could not load record data."); }
                    };
                } else { row.style.cursor = "default"; row.onclick = null; }
            });
        }

        function disableRowClickEdit() {
            document.querySelectorAll("#recordsTable tbody tr").forEach(row => { row.onclick = null; row.style.cursor = "default"; });
        }
        function closeUpdateModal() {
            document.getElementById("updateModal").style.display = "none"; adminAuthenticated = false;
            disableRowClickEdit(); resetBurialForm('update');
        }

        window.onclick = function (event) {
            const modals = [
                { id: "recordModal", closeFn: closeModal },
                { id: "updateModal", closeFn: closeUpdateModal },
                { id: "adminModal", closeFn: closeAdminModal },
                { id: "messageModal", closeFn: () => document.getElementById("messageModal").style.display = "none" },
                { id: "certificateModal", closeFn: closeCertModal }
            ];
            modals.forEach(m => { if (event.target === document.getElementById(m.id)) m.closeFn(); });
        };

        function toggleSidebar() { document.querySelector(".sidebar").classList.toggle("active"); }
        function toggleDropdown() {
            document.getElementById("certificateDropdown").classList.toggle("dropdown-active");
            document.getElementById("certificates").classList.toggle("open");
        }
        function openCertModal() { document.getElementById("certificateModal").style.display = "block"; }
        function closeCertModal() { document.getElementById("certificateModal").style.display = "none"; }
        function toggleCertType() {
            document.getElementById("certTypeDropdown").classList.toggle("dropdown-active");
            document.getElementById("certDropdownIcon").classList.toggle("rotated");
        }
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
        for (const btnId in pageNav) {
            const btn = document.getElementById(btnId);
            if (btn) btn.addEventListener("click", () => window.location.href = pageNav[btnId]);
        }

        document.getElementById("searchInput").addEventListener("keyup", function () {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll("#recordsTable tbody tr");
            let firstRowOfRecordVisible = false;
            rows.forEach(row => {
                if (row.querySelectorAll("td[rowspan='2']").length > 0) {
                    const text = row.textContent.toLowerCase() + (row.nextElementSibling ? row.nextElementSibling.textContent.toLowerCase() : "");
                    firstRowOfRecordVisible = text.includes(filter);
                    row.style.display = firstRowOfRecordVisible ? "" : "none";
                } else {
                    row.style.display = firstRowOfRecordVisible ? "" : "none";
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            if (addBurialForm) initializeBurialValidation('add');
            if (updateBurialForm) initializeBurialValidation('update');
        });

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
            const addForm = document.getElementById('addBurialForm');
            const updateForm = document.getElementById('updateBurialForm');

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
                            formData.append('save_burial', 'true');

                            fetch('burial.php', {
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

                            fetch('burial.php', {
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

            // --- START: New Filter JavaScript for burial.php ---
            const categoryFilter_burial = document.getElementById('categoryFilterBurial');
            const yearInputContainer_burial = document.getElementById('filterYearInputContainerBurial');
            const monthInputContainer_burial = document.getElementById('filterMonthInputContainerBurial');
            const dateInputContainer_burial = document.getElementById('filterDateInputContainerBurial');

            const yearValueInput_burial = document.getElementById('filterYearValueBurial');
            const yearForMonthValueInput_burial = document.getElementById('filterYearForMonthValueBurial');
            const monthValueSelect_burial = document.getElementById('filterMonthValueBurial');
            const dateValueInput_burial = document.getElementById('filterDateValueBurial'); // Text input for DD/MM/YYYY

            const applyFilterButton_burial = document.getElementById('applyFilterBtnBurial');
            const clearFilterButton_burial = document.getElementById('clearFilterBtnBurial');
            const searchInput_burial = document.getElementById('searchInput');

            function toggleFilterInputs_burial() {
                if (!categoryFilter_burial) return;
                const selectedFilter = categoryFilter_burial.value;

                if(yearInputContainer_burial) yearInputContainer_burial.style.display = 'none';
                if(monthInputContainer_burial) monthInputContainer_burial.style.display = 'none';
                if(dateInputContainer_burial) dateInputContainer_burial.style.display = 'none';

                if (selectedFilter === 'year' && yearInputContainer_burial) {
                    yearInputContainer_burial.style.display = 'inline-block';
                } else if (selectedFilter === 'month' && monthInputContainer_burial) {
                    monthInputContainer_burial.style.display = 'inline-block';
                } else if (selectedFilter === 'specific_date' && dateInputContainer_burial) {
                    dateInputContainer_burial.style.display = 'inline-block';
                }
            }

            if (categoryFilter_burial) {
                categoryFilter_burial.addEventListener('change', toggleFilterInputs_burial);
            }

            if (applyFilterButton_burial) {
                applyFilterButton_burial.addEventListener('click', function() {
                    if (!categoryFilter_burial) return;
                    const filterType = categoryFilter_burial.value;
                    if (!filterType) return;

                    let queryParams = new URLSearchParams();
                    queryParams.set('filter_type_burial', filterType);

                    if (filterType === 'year') {
                        if (!yearValueInput_burial || !yearValueInput_burial.value || !/^\d{4}$/.test(yearValueInput_burial.value)) {
                            alert('Please enter a valid 4-digit year.'); return;
                        }
                        queryParams.set('filter_year_value_burial', yearValueInput_burial.value);
                    } else if (filterType === 'month') {
                        if (!monthValueSelect_burial || !monthValueSelect_burial.value) {
                            alert('Please select a month.'); return;
                        }
                        queryParams.set('filter_month_value_burial', monthValueSelect_burial.value);
                        if (yearForMonthValueInput_burial && yearForMonthValueInput_burial.value) {
                            if (!/^\d{4}$/.test(yearForMonthValueInput_burial.value)) {
                                alert('If providing a year for the month, please enter a valid 4-digit year.'); return;
                            }
                            queryParams.set('filter_year_for_month_value_burial', yearForMonthValueInput_burial.value);
                        }
                    } else if (filterType === 'specific_date') {
                        if (!dateValueInput_burial || !dateValueInput_burial.value || !/^\d{2}\/\d{2}\/\d{4}$/.test(dateValueInput_burial.value)) {
                            alert('Please enter a date in DD/MM/YYYY format.'); return;
                        }
                        queryParams.set('filter_date_value_burial', dateValueInput_burial.value);
                    } else if (filterType === 'oldest_to_latest') {
                        queryParams.set('sort_order_burial', 'asc');
                    } else if (filterType === 'latest_to_oldest') {
                        queryParams.set('sort_order_burial', 'desc');
                    }
                    window.location.search = queryParams.toString();
                });
            }

            if (clearFilterButton_burial) {
                clearFilterButton_burial.addEventListener('click', function(event) {
                    event.preventDefault();
                    if (searchInput_burial) {
                        searchInput_burial.value = '';
                    }
                    window.location.href = window.location.pathname;
                });
            }

            function setFiltersFromUrl_burial() {
                if (!categoryFilter_burial) return;
                const urlParams = new URLSearchParams(window.location.search);
                const filterTypeFromUrl = urlParams.get('filter_type_burial');

                categoryFilter_burial.value = "";
                if(yearValueInput_burial) yearValueInput_burial.value = "";
                if(yearForMonthValueInput_burial) yearForMonthValueInput_burial.value = "";
                if(monthValueSelect_burial) monthValueSelect_burial.value = "";
                if(dateValueInput_burial) dateValueInput_burial.value = "";
                toggleFilterInputs_burial();

                if (filterTypeFromUrl) {
                    categoryFilter_burial.value = filterTypeFromUrl;
                    toggleFilterInputs_burial();

                    if (filterTypeFromUrl === 'year' && urlParams.has('filter_year_value_burial') && yearValueInput_burial) {
                        yearValueInput_burial.value = urlParams.get('filter_year_value_burial');
                    } else if (filterTypeFromUrl === 'month') {
                        if (urlParams.has('filter_month_value_burial') && monthValueSelect_burial) {
                            monthValueSelect_burial.value = urlParams.get('filter_month_value_burial');
                        }
                        if (urlParams.has('filter_year_for_month_value_burial') && yearForMonthValueInput_burial) {
                            yearForMonthValueInput_burial.value = urlParams.get('filter_year_for_month_value_burial');
                        }
                    } else if (filterTypeFromUrl === 'specific_date' && urlParams.has('filter_date_value_burial') && dateValueInput_burial) {
                        dateValueInput_burial.value = urlParams.get('filter_date_value_burial');
                    }
                } else if (urlParams.has('sort_order_burial')) {
                    const sortOrder = urlParams.get('sort_order_burial');
                    if (sortOrder === 'asc') categoryFilter_burial.value = 'oldest_to_latest';
                    if (sortOrder === 'desc') categoryFilter_burial.value = 'latest_to_oldest';
                }
            }

            setFiltersFromUrl_burial(); // Call on page load
            // --- END: New Filter JavaScript for burial.php ---

        });

        /*--- ClientID Error [end] ---*/

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

    </script>
</body>
</html>