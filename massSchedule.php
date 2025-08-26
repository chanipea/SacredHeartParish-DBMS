<?php
// Prevent caching of the page after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$pageTitle = "Mass Schedule Management";
require_once 'session_timeout.php'; 

// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Log_In/login_system.php");
    exit();
}

// --- START: Filter Logic for GET requests (Mass Schedules by Date) ---
$whereClauses_mass = [];
// Default order for mass schedules
$orderByClause_mass = "ORDER BY ms.Date DESC, ms.Time DESC"; // Default: newest first
$filter_params_mass = []; // Parameters for prepared statement
$filter_param_types_mass = ""; // Types for prepared statement

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (isset($_GET['filter_type_mass']) && !empty($_GET['filter_type_mass'])) {
        $filter_type = $_GET['filter_type_mass'];

        switch ($filter_type) {
            case 'year':
                if (isset($_GET['filter_year_value_mass']) && !empty($_GET['filter_year_value_mass'])) {
                    $year = filter_var($_GET['filter_year_value_mass'], FILTER_VALIDATE_INT);
                    if ($year && strlen((string)$year) == 4) {
                        $whereClauses_mass[] = "YEAR(ms.Date) = ?";
                        $filter_params_mass[] = $year;
                        $filter_param_types_mass .= "i";
                    }
                }
                break;
            case 'month':
                if (isset($_GET['filter_month_value_mass']) && !empty($_GET['filter_month_value_mass'])) {
                    $month = filter_var($_GET['filter_month_value_mass'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 12]]);
                    if ($month) {
                        $whereClauses_mass[] = "MONTH(ms.Date) = ?";
                        $filter_params_mass[] = $month;
                        $filter_param_types_mass .= "i";

                        if (isset($_GET['filter_year_for_month_value_mass']) && !empty($_GET['filter_year_for_month_value_mass'])) {
                            $year_for_month = filter_var($_GET['filter_year_for_month_value_mass'], FILTER_VALIDATE_INT);
                            if ($year_for_month && strlen((string)$year_for_month) == 4) {
                                $whereClauses_mass[] = "YEAR(ms.Date) = ?";
                                $filter_params_mass[] = $year_for_month;
                                $filter_param_types_mass .= "i";
                            }
                        }
                    }
                }
                break;
            case 'specific_date':
                if (isset($_GET['filter_date_value_mass']) && !empty($_GET['filter_date_value_mass'])) {
                    $date_str = $_GET['filter_date_value_mass']; // Expected YYYY-MM-DD
                    $d = DateTime::createFromFormat('Y-m-d', $date_str);
                    if ($d && $d->format('Y-m-d') === $date_str) {
                        $whereClauses_mass[] = "ms.Date = ?";
                        $filter_params_mass[] = $date_str;
                        $filter_param_types_mass .= "s";
                    }
                }
                break;
            case 'oldest_to_latest':
                $orderByClause_mass = "ORDER BY ms.Date ASC, ms.Time ASC";
                break;
            case 'latest_to_oldest':
                $orderByClause_mass = "ORDER BY ms.Date DESC, ms.Time DESC";
                break;
        }
    }
    // Handle standalone sort_order parameter
    if (isset($_GET['sort_order_mass'])) {
        if ($_GET['sort_order_mass'] === 'asc' && $filter_type !== 'oldest_to_latest') {
            $orderByClause_mass = "ORDER BY ms.Date ASC, ms.Time ASC";
        } elseif ($_GET['sort_order_mass'] === 'desc' && $filter_type !== 'latest_to_oldest') {
            $orderByClause_mass = "ORDER BY ms.Date DESC, ms.Time DESC";
        }
    }
}
// --- END: Filter Logic (Mass Schedules) ---

// Define the correct table name
const MASS_SCHEDULE_TABLE = 'massschedule'; // Use this constant

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

    if ($conn->connect_error) {
        error_log("DB Connection Failed (Mass Schedule POST): " . $conn->connect_error);
        echo "<script>alert('Error: Could not connect to the database for POST operation.'); window.history.back();</script>";
        exit();
    }

    // Retrieve and trim POST data
    $priestID   = trim($_POST['PriestID'] ?? '');
    $date       = trim($_POST['Date'] ?? ''); // Expected YYYY-MM-DD
    $time       = trim($_POST['Time'] ?? ''); // Expected HH:MM or HH:MM:SS
    $location   = trim($_POST['Location'] ?? '');
    $scheduleIDFromPost = null; // For updates

    // --- SERVER-SIDE INPUT VALIDATION ---
    $errors = [];
    $today = new DateTime();
    $today->setTime(0,0,0); // For date comparisons

    if (isset($_POST['updateRecord'])) {
        $scheduleIDFromPost = trim($_POST['ScheduleID'] ?? '');
        if (empty($scheduleIDFromPost)) {
            $errors[] = "Schedule ID is required for an update.";
        }
        // Add more specific validation for $scheduleIDFromPost format if needed, e.g., regex
        // For now, we assume it's the "MASS-..." string if it's an update.
    }

    if (empty($priestID)) {
        $errors[] = "Priest selection is required.";
    } elseif (!ctype_digit($priestID)) {
        $errors[] = "Invalid Priest ID selected.";
    } else {
        $checkPriestStmt = $conn->prepare("SELECT PriestID FROM priest WHERE PriestID = ?");
        if ($checkPriestStmt) {
            $checkPriestStmt->bind_param("i", $priestID);
            $checkPriestStmt->execute();
            $checkPriestResult = $checkPriestStmt->get_result();
            if ($checkPriestResult->num_rows === 0) {
                $errors[] = "Selected Priest does not exist.";
            }
            $checkPriestStmt->close();
        } else {
            $errors[] = "Error preparing priest check: " . $conn->error;
        }
    }

    if (empty($date)) {
        $errors[] = "Date is required.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = "Invalid Date format (YYYY-MM-DD).";
    } else {
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
            $errors[] = "Invalid Date value (e.g., day/month out of range).";
        } elseif ($dateObj < $today && !isset($_POST['updateRecord'])) {
            $errors[] = "Date must be today or in the future for new schedules.";
        }
    }

    if (empty($time)) {
        $errors[] = "Time is required.";
    } elseif (!preg_match('/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/', $time) && // HH:MM
        !preg_match('/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $time)) { // HH:MM:SS
        $errors[] = "Invalid Time format (HH:MM or HH:MM:SS).";
    }

    if (empty($location)) {
        $errors[] = "Location is required.";
    } elseif (!preg_match('/^[a-zA-Z0-9\s.,\'()&-]{3,150}$/', $location)) {
        $errors[] = "Invalid Location format (3-150 alphanumeric and common punctuation).";
    }

    if (!empty($errors)) {
        $errorString = implode("\\n", $errors);
        echo "<script>alert('Input Validation Errors:\\n" . htmlspecialchars($errorString, ENT_QUOTES) . "'); window.history.back();</script>";
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

    // FOR ADDING RECORDS
    if (isset($_POST['submitRecord'])) {
        // --- Conflict Checks (same as before) ---
        $exactDuplicateCheck = $conn->prepare("SELECT ScheduleID FROM " . MASS_SCHEDULE_TABLE . " WHERE Date = ? AND Time = ? AND Location = ?");
        if ($exactDuplicateCheck) { /* ... execute and check ... */ $exactDuplicateCheck->bind_param("sss", $date, $time, $location, ); $exactDuplicateCheck->execute(); if ($exactDuplicateCheck->get_result()->num_rows > 0) { echo "<script>alert('Conflict: A mass is already scheduled at this exact time and location.'); window.history.back();</script>"; $conn->close(); exit(); } $exactDuplicateCheck->close(); } else { /* ... error handling ... */ error_log("SQL Prepare Error (Exact Duplicate Check): " . $conn->error); echo "<script>alert('Error preparing exact duplicate check.'); window.history.back();</script>"; $conn->close(); exit(); }

        $priestConflictCheck = $conn->prepare("SELECT ms.ScheduleID FROM " . MASS_SCHEDULE_TABLE . " ms WHERE ms.PriestID = ? AND ms.Date = ? AND TIME_TO_SEC(ms.Time) BETWEEN (TIME_TO_SEC(?) - 3599) AND (TIME_TO_SEC(?) + 3599)");
        if ($priestConflictCheck) { /* ... execute and check ... */ $priestConflictCheck->bind_param("isss", $priestID, $date, $time, $time); $priestConflictCheck->execute(); if ($priestConflictCheck->get_result()->num_rows > 0) { /* ... alert priest conflict ... */ echo "<script>alert('Priest Conflict: This priest already has a mass scheduled near this time on this date.'); window.history.back();</script>"; $conn->close(); exit(); } $priestConflictCheck->close(); } else { /* ... error handling ... */ error_log("SQL Prepare Error (Priest Conflict Check): " . $conn->error); echo "<script>alert('Error preparing priest conflict check.'); window.history.back();</script>"; $conn->close(); exit(); }

        $locationConflictCheck = $conn->prepare("SELECT ms.ScheduleID FROM " . MASS_SCHEDULE_TABLE . " ms WHERE ms.Location = ? AND ms.Date = ? AND TIME_TO_SEC(ms.Time) BETWEEN (TIME_TO_SEC(?) - 1799) AND (TIME_TO_SEC(?) + 1799)");
        if ($locationConflictCheck) { /* ... execute and check ... */ $locationConflictCheck->bind_param("ssss", $location, $date, $time, $time); $locationConflictCheck->execute(); if ($locationConflictCheck->get_result()->num_rows > 0) { /* ... alert location conflict ... */ echo "<script>alert('Location Conflict: This location is already booked near this time on this date.'); window.history.back();</script>"; $conn->close(); exit(); } $locationConflictCheck->close(); } else { /* ... error handling ... */ error_log("SQL Prepare Error (Location Conflict Check): " . $conn->error); echo "<script>alert('Error preparing location conflict check.'); window.history.back();</script>"; $conn->close(); exit(); }
        // --- End Conflict Checks ---

        // --- GENERATE ScheduleID ---
        $generatedScheduleID = "";
        $maxRetries = 5; // To prevent infinite loop if something is wrong with sequence generation
        $retryCount = 0;

        do {
            $stmtSeq = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(ScheduleID, '-', -1) AS UNSIGNED)) as last_seq FROM " . MASS_SCHEDULE_TABLE . " WHERE ScheduleID LIKE ?");
            if (!$stmtSeq) {
                error_log("SQL Prepare Error (Generate ID - Seq): " . $conn->error);
                echo "<script>alert('Error preparing to generate Schedule ID. Please contact admin.'); window.history.back();</script>";
                $conn->close(); exit();
            }
            $likePattern = "MASS-" . $date . "-%"; // $date is YYYY-MM-DD from form
            $stmtSeq->bind_param("s", $likePattern);
            $stmtSeq->execute();
            $resultSeqRow = $stmtSeq->get_result()->fetch_assoc();
            $stmtSeq->close();

            $nextSeqNum = ($resultSeqRow && $resultSeqRow['last_seq'] !== null) ? (int)$resultSeqRow['last_seq'] + 1 : 1;
            $generatedScheduleID = "MASS-" . $date . "-" . str_pad($nextSeqNum, 3, '0', STR_PAD_LEFT);

            // Check if this generated ID already exists (rare, but possible in race conditions or if sequence logic isn't perfect)
            $checkIDStmt = $conn->prepare("SELECT ScheduleID FROM " . MASS_SCHEDULE_TABLE . " WHERE ScheduleID = ?");
            if (!$checkIDStmt) {
                error_log("SQL Prepare Error (Generate ID - Check): " . $conn->error);
                echo "<script>alert('Error preparing to verify generated Schedule ID. Please contact admin.'); window.history.back();</script>";
                $conn->close(); exit();
            }
            $checkIDStmt->bind_param("s", $generatedScheduleID);
            $checkIDStmt->execute();
            $idExists = $checkIDStmt->get_result()->num_rows > 0;
            $checkIDStmt->close();

            $retryCount++;
            if ($retryCount > $maxRetries && $idExists) {
                error_log("Failed to generate unique ScheduleID after $maxRetries retries for date $date.");
                echo "<script>alert('Could not generate a unique Schedule ID. Please try submitting again or contact support.'); window.history.back();</script>";
                $conn->close(); exit();
            }
        } while ($idExists); // Loop if the generated ID somehow already exists
        // --- End Generate ScheduleID ---


        $sql_insert = "INSERT INTO " . MASS_SCHEDULE_TABLE . " (ScheduleID, PriestID, Date, Time, Location, ParishStaffID) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql_insert);

        if ($stmt === false) {
            error_log("SQL Prepare Error (Insert Mass): " . $conn->error);
            echo "<script>alert('Error preparing the record for insertion.'); window.history.back();</script>";
        } else {
            $stmt->bind_param("sisssi", $generatedScheduleID, $priestID, $date, $time, $location, $parishStaffID); // 's' for $generatedScheduleID
            if ($stmt->execute()) {
                echo "<script>alert('Mass Schedule inserted successfully! ID: " . htmlspecialchars($generatedScheduleID) . "'); window.location.href = window.location.href.split('?')[0];</script>";
            } else {
                error_log("SQL Execute Error (Insert Mass): " . $stmt->error . " | Query: " . $sql_insert . " | ID: " . $generatedScheduleID);
                echo "<script>alert('Error inserting record: " . htmlspecialchars($stmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
            }
            $stmt->close();
        }
        $conn->close();
        exit();
    }

    // FOR UPDATING RECORDS
    elseif (isset($_POST['updateRecord'])) {
        // $scheduleIDFromPost was retrieved and validated (partially)
        // Use $scheduleIDFromPost for the WHERE clause in update and for excluding in conflict checks

        $priestConflictUpdateCheck = $conn->prepare
            ("SELECT ms.ScheduleID FROM " . MASS_SCHEDULE_TABLE . " 
                ms WHERE ms.PriestID = ? AND 
                ms.Date = ? AND 
                TIME_TO_SEC(ms.Time) BETWEEN (TIME_TO_SEC(?) - 3599) AND 
                (TIME_TO_SEC(?) + 3599) AND 
                ms.ScheduleID != ?");
                
        if ($priestConflictUpdateCheck) { 
            $priestConflictUpdateCheck->bind_param
            ("issss", $priestID, $date, $time, $time, $scheduleIDFromPost); 
            $priestConflictUpdateCheck->execute(); 
                
            if ($priestConflictUpdateCheck->get_result()->num_rows > 0) { 
                echo "<script>alert('Priest conflict for update.'); window.history.back();</script>"; 
                
            $conn->close(); exit(); } 
        
            $priestConflictUpdateCheck->close(); 
        } 
        
        else { 
            error_log("SQL Prepare Error (Priest Conflict Update): " . $conn->error); 
            echo "<script>alert('Error preparing priest conflict check for update.'); window.history.back();</script>"; 
            $conn->close(); exit(); 
        }

        $locationConflictUpdateCheck = $conn->prepare
            ("SELECT ms.ScheduleID FROM " . MASS_SCHEDULE_TABLE . " 
                ms WHERE ms.Location = ? AND 
                ms.Date = ? AND 
                TIME_TO_SEC(ms.Time) BETWEEN (TIME_TO_SEC(?) - 1799) AND 
                (TIME_TO_SEC(?) + 1799) AND 
                ms.ScheduleID != ?");
            
        if ($locationConflictUpdateCheck) { 
            $locationConflictUpdateCheck->bind_param
            ("sssss", $location, $date, $time, $time, $scheduleIDFromPost); 
            $locationConflictUpdateCheck->execute(); 
            
            if ($locationConflictUpdateCheck->get_result()->num_rows > 0) { 
                echo "<script>alert('Location conflict for update.'); window.history.back();</script>"; 
                
            $conn->close(); exit(); } $locationConflictUpdateCheck->close(); 
        } 
        
        else 
        { 
            error_log("SQL Prepare Error (Location Conflict Update): " . $conn->error); 
                echo "<script>alert('Error preparing location conflict check for update.'); window.history.back();</script>"; 
                
                $conn->close(); exit(); 
        }

            $sql_update = "UPDATE " . MASS_SCHEDULE_TABLE . " 
                SET PriestID=?, 
                Date=?, 
                Time=?, 
                Location=? 
                WHERE ScheduleID=?";

            $updateStmt = $conn->prepare($sql_update);
            if ($updateStmt === false) {
                error_log("SQL Prepare Error (Update Mass): " . $conn->error); 
                echo "<script>alert('Error preparing the update statement.'); window.history.back();</script>";
            } 
            
            else {
                $updateStmt->bind_param("issss", $priestID, $date, $time, $location, $scheduleIDFromPost); // 's' for $scheduleIDFromPost
                if ($updateStmt->execute()) {
                    if ($updateStmt->affected_rows > 0) {
                        echo "<script>alert('Record updated successfully!'); window.location.href = window.location.href.split('?')[0];</script>";
                    } else {
                        echo "<script>alert('Update executed, but no changes were made. Data might be the same or Schedule ID not found.'); window.location.href = window.location.href.split('?')[0];</script>";
                    }
                } else {
                    error_log("SQL Execute Error (Update Mass): " . $updateStmt->error . " | Query: " . $sql_update);
                    echo "<script>alert('Update error: " . htmlspecialchars($updateStmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
                }
                $updateStmt->close();
            }
            
            $conn->close();
            exit();
    }
} // End of if ($_SERVER["REQUEST_METHOD"] == "POST")
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" href="/imagess/sacred.png">
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="stylesheet" href="massStyle.css?v=12">
        <!-- Add these two lines for responsiveness -->
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="massResponsive.css?v=12">
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


                <ul class="dropdown" id="certificateDropdown">
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
        <div class="section-title">Mass Schedule</div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search..." style="flex-grow: 1;">

            <!-- START: Category Filter for Mass Schedules -->
            <div class="filter-container">
                <select id="categoryFilterMass" name="category_filter_mass" title="Category Filter">
                    <option value="">-- Filter By Date --</option>
                    <option value="year">Year</option>
                    <option value="month">Month</option>
                    <option value="specific_date">Specific Date</option>
                    <option value="oldest_to_latest">Oldest to Latest</option>
                    <option value="latest_to_oldest">Latest to Oldest</option>
                </select>

                <div id="filterYearInputContainerMass" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearValueMass" name="filter_year_value_mass" placeholder="YYYY">
                </div>
                <div id="filterMonthInputContainerMass" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearForMonthValueMass" name="filter_year_for_month_value_mass" placeholder="YYYY (Opt.)">
                    <select id="filterMonthValueMass" name="filter_month_value_mass">
                        <option value="">-- Select Month --</option>
                        <option value="1">January</option>
                        <option value="2">February</option>
                        <option value="3">March</option>
                        <option value="4">April</option>
                        <option value="5">May</option>
                        <option value="6">June</option>
                        <option value="7">July</option>
                        <option value="8">August</option>
                        <option value="9">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                </div>
                <div id="filterDateInputContainerMass" class="filter-input-group" style="display:none;">
                    <input type="date" id="filterDateValueMass" name="filter_date_value_mass"> <!-- Using type="date" -->
                </div>
                <button id="applyFilterBtnMass" class="filter-btn">Apply</button>
                <button id="clearFilterBtnMass" class="filter-btn">Clear</button>
            </div>
            <!-- END: Category Filter for Mass Schedules -->

            <div class="record-buttons" style="margin-left: auto;">
                <button id="updateRecordBtn">✎ Update Record</button>
                <button id="addRecordBtn">+ Add Record</button>
            </div>
        </div>

        <div class="table-scroll">
            <table id="recordsTable">
                <thead>
                <tr>
                    <th>Schedule ID</th>
                    <th>Priest Name</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Location</th>
                    <th>Created By</th>
                </tr>
                </thead>
                <tbody>
                <?php
                // Database connection for display
                $conn_display_table = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS"); // Use a distinct variable
                if ($conn_display_table->connect_error) {
                    echo "<tr><td colspan='5' style='color:red; font-weight:bold;'>Connection failed: " . htmlspecialchars($conn_display_table->connect_error) . "</td></tr>";
                } else {
                    // Using MASS_SCHEDULE_TABLE constant

                    // start modify for filter
                    $baseSqlMass = "SELECT 
                    ms.*,
                    p.FullName AS PriestName,
                    p.PriestID AS ActualPriestID, /* Ensure you fetch the actual PriestID for data attributes */
                    COALESCE(au.username, su.username, 'Unknown') AS CreatedBy
                FROM " . MASS_SCHEDULE_TABLE . " ms /* Use defined constant */
                LEFT JOIN priest p ON ms.PriestID = p.PriestID
                LEFT JOIN parishstaff ps ON ms.ParishStaffID = ps.ParishStaffID
                LEFT JOIN admin_users au ON ps.AdminUserID = au.ID
                LEFT JOIN staff_users su ON ps.StaffUserID = su.id";

                    $finalSqlMass = $baseSqlMass;

                    if (!empty($whereClauses_mass)) {
                        $finalSqlMass .= " WHERE " . implode(" AND ", $whereClauses_mass);
                    }
                    $finalSqlMass .= " " . $orderByClause_mass;

                    $result_table = null; // Initialize

                    if (!empty($filter_params_mass)) {
                        $stmtMass = $conn_display_table->prepare($finalSqlMass);
                        if ($stmtMass === false) {
                            error_log("SQL Prepare Error (Filter Mass): " . $conn_display_table->error . " | SQL: " . $finalSqlMass);
                            echo "<tr><td colspan='6'>Error preparing mass schedule data.</td></tr>";
                        } else {
                            $stmtMass->bind_param($filter_param_types_mass, ...$filter_params_mass);
                            $stmtMass->execute();
                            $result_table = $stmtMass->get_result();
                            if ($result_table === false) {
                                error_log("SQL Get Result Error (Filter Mass): " . $stmtMass->error);
                                echo "<tr><td colspan='6'>Error retrieving filtered mass schedule data.</td></tr>";
                            }
                        }
                    } else {
                        $result_table = $conn_display_table->query($finalSqlMass);
                        if ($result_table === false) {
                            error_log("SQL Query Error (Mass): " . $conn_display_table->error . " | SQL: " . $finalSqlMass);
                            echo "<tr><td colspan='6'>Error fetching mass schedule data.</td></tr>";
                        }
                    }

                    if ($result_table && $result_table->num_rows > 0) {
                        while ($row_table = $result_table->fetch_assoc()) {
                            // Using htmlspecialchars for all echoed data
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($row_table["ScheduleID"] ?? '-') . "</td>";
                            // IMPORTANT: Add data-priestid to the Priest Name cell
                            echo "<td data-priestid='" . htmlspecialchars($row_table["ActualPriestID"] ?? ($row_table["PriestID"] ?? '')) . "'>" . htmlspecialchars($row_table["PriestName"] ?? 'N/A - Priest not found') . "</td>";
                            echo "<td>" . htmlspecialchars($row_table["Date"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars(isset($row_table["Time"]) ? date("h:i A", strtotime($row_table["Time"])) : '-') . "</td>"; // Format to HH:MM AM/PM
                            echo "<td>" . htmlspecialchars($row_table["Location"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row_table["CreatedBy"] ?? '-') . "</td>";
                            echo "</tr>";
                        }
                    } else if ($result_table) {
                        echo "<tr><td colspan='6'>No Mass Schedules found matching your criteria.</td></tr>";
                    }
                    $conn_display_table->close();
                }
                ?> // end for modify filter
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Modal [start] -->
    <div class="modal" id="recordModal">
        <form class="modal-content" id="addMassForm" method="POST" action="massSchedule.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>
            <div class="modal-header" style="background: #2c3e50; color: white; text-align: center; border-radius: 0; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Mass Schedule Details</h3>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">
                <div style="flex: 1 1 45%;">
                    <label for="addPriestID" style="margin-left: 30px;">Select Priest:</label><br>
                    <select id="addPriestID" name="PriestID" style="width: 80%; padding: 5px; margin-left: 30px;">
                        <option value="">-- Select Priest --</option>
                        <?php
                        $conn_priests_add = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");
                        if (!$conn_priests_add->connect_error) {
                            $priestSql_add = "SELECT PriestID, FullName FROM Priest WHERE Status = 'Active' ORDER BY FullName"; // Only Active priests
                            $priestResult_add = $conn_priests_add->query($priestSql_add);
                            if ($priestResult_add->num_rows > 0) {
                                while($priest_add = $priestResult_add->fetch_assoc()) {
                                    echo "<option value='" . htmlspecialchars($priest_add["PriestID"]) . "'>" . htmlspecialchars($priest_add["FullName"]) . "</option>";
                                }
                            }
                            $conn_priests_add->close();
                        }
                        ?>
                    </select>
                    <small id="addPriestIDError" class="error-message hidden" style="margin-left: 30px;">Priest selection is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="addDate" style="margin-left: 55px;">Date:</label><br>
                    <input type="date" id="addDate" name="Date" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addDateError" class="error-message hidden" style="margin-left: 55px;">Date is required and must be today or in the future.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="addTime" style="margin-left: 30px;">Time:</label><br>
                    <input type="time" id="addTime" name="Time" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addTimeError" class="error-message hidden" style="margin-left: 30px;">Time is required.</small>
                </div>
                <div style="flex: 1 2 45%;">
                    <label for="addLocation" style="margin-left: 55px;">Location:</label><br>
                    <input type="text" id="addLocation" name="Location" placeholder="Enter mass location" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addLocationError" class="error-message hidden" style="margin-left: 55px;">Location is required.</small>
                </div>
            </div>
            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" name="submitRecord" id="addMassSubmitButton" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">+ Add Record</button>
            </div>
        </form>
    </div>
    <!-- Add Modal [end] -->

    <!-- Update Modal [start] -->
    <div class="modal" id="updateModal">
        <form class="modal-content" id="updateMassForm" method="POST" action="massSchedule.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeUpdateModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>
            <div class="modal-header" style="background: #F39C12; color: white; text-align: center; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Update Mass Schedule Details</h3>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">
                <div style="flex: 1 1 45%;">
                    <label for="updateScheduleID" style="margin-left: 55px;">Schedule ID:</label><br>
                    <input type="text" id="updateScheduleID" name="ScheduleID" readonly style="width: 80%; padding: 5px; margin-left: 55px; background-color: #e9e9e9;">
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updatePriestID" style="margin-left: 30px;">Select Priest:</label><br>
                    <select id="updatePriestID" name="PriestID" style="width: 80%; padding: 5px; margin-left: 30px;">
                        <option value="">-- Select Priest --</option>
                        <?php
                        $conn_priests_update = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");
                        if (!$conn_priests_update->connect_error) {
                            $priestSql_update = "SELECT PriestID, FullName FROM Priest WHERE Status = 'Active' ORDER BY FullName"; // Only Active priests
                            $priestResult_update = $conn_priests_update->query($priestSql_update);
                            if ($priestResult_update->num_rows > 0) {
                                while($priest_update = $priestResult_update->fetch_assoc()) {
                                    echo "<option value='" . htmlspecialchars($priest_update["PriestID"]) . "'>" . htmlspecialchars($priest_update["FullName"]) . "</option>";
                                }
                            }
                            $conn_priests_update->close();
                        }
                        ?>
                    </select>
                    <small id="updatePriestIDError" class="error-message hidden" style="margin-left: 30px;">Priest selection is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateDate" style="margin-left: 55px;">Date:</label><br>
                    <input type="date" id="updateDate" name="Date" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateDateError" class="error-message hidden" style="margin-left: 55px;">Date is required and must be today or in the future.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateTime" style="margin-left: 30px;">Time:</label><br>
                    <input type="time" id="updateTime" name="Time" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateTimeError" class="error-message hidden" style="margin-left: 30px;">Time is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateLocation" style="margin-left: 55px;">Location:</label><br>
                    <input type="text" id="updateLocation" name="Location" placeholder="Enter mass location" style="width: 39.9%; padding: 5px; margin-left: 55px;">
                    <small id="updateLocationError" class="error-message hidden" style="margin-left: 55px;">Location is required.</small>
                </div>
            </div>
            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" name="updateRecord" id="updateMassSubmitButton" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">✎ Update Record</button>
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

            /*----- REGEX PATTERNS (GLOBAL) -----*/
            const massRegexPatterns = {
            dateFormat: /^\d{4}-\d{2}-\d{2}$/,
            timeFormat: /^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/, // HH:MM or HH:MM:SS
            locationFormat: /^[a-zA-Z0-9\s.,'()&-]{3,150}$/,
            idFormat: /^\d+$/ // For PriestID, ScheduleID (simple digit check)
        };

        /*----- ADMIN PERMISSION [start]-----*/


        // Close modal on ESC key
        document.addEventListener('keydown', function (event) {
            if (event.key === "Escape") {
                closeAdminModal();
            }
        });

        function openAdminModal() {
            document.getElementById("adminPassword").value = ""; // Clear previous password
            document.getElementById("adminModal").style.display = "block";
        }

        function closeAdminModal() {
            document.getElementById("adminModal").style.display = "none";
            resetRecordFields(); // Optional
            adminAuthenticated = false;  // Reset auth
            document.getElementById("adminPassword").value = ''; // Clear password input
            document.getElementById("hiddenAdminPassword").value = ''; // Clear hidden field
        }
        /*----- ADMIN PERMISSION [end]-----*/



        /*----- ADD RECORDS [start]-----*/
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
        /*----- ADD RECORDS [end]-----*/



        /*----- UPDATE RECORDS [start]-----*/
        /*----- admin check [start]-----*/
        let adminAuthenticated = false;

        // Trigger modal when update button is clicked
        document.getElementById("updateRecordBtn").onclick = function () {
            // Reset authentication and fields on each update attempt
            adminAuthenticated = false;
            document.getElementById("adminModal").style.display = "flex";
            resetRecordFields(); // Clear any previously selected record fields
        };

        // Validate admin password
        function validateAdmin(event) {
            event.preventDefault();
            const inputPassword = document.getElementById("adminPassword").value;
            console.log("Entered Password:", inputPassword); // Log to check the input value

            fetch("update_validation.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: "password=" + encodeURIComponent(inputPassword)
            })
                .then(response => response.json())
                .then(data => {
                    console.log(data); // Log the response from PHP for further debugging
                    if (data.success) {
                        adminAuthenticated = true;
                        document.getElementById("adminModal").style.display = "none";
                        showMessageModal("Access granted. Please click on a record to edit.");
                        document.getElementById("hiddenAdminPassword").value = inputPassword;
                        enableRowClickEdit();
                    } else {
                        adminAuthenticated = false;
                        showMessageModal("Incorrect password.");
                    }

                    // Always clear input
                    document.getElementById("adminPassword").value = '';
                })
                .catch(error => {
                    console.error("Error validating admin:", error);
                    showMessageModal("An error occurred. Try again.");
                });
        }

        // Function to close message modal
        function closeMessageModal() {
            document.getElementById('messageModal').style.display = 'none';
        }

        // Show a message in a modal popup
        function showMessageModal(message) {
            const modal = document.getElementById("messageModal");
            const messageText = document.getElementById("messageModalText");
            messageText.textContent = message;
            modal.style.display = "flex";
        }
        /*----- admin check [end]-----*/

        /*----- choosing row [start]-----*/
        // Reset the record fields when modal is closed
        function resetRecordFields() {
            document.getElementById("ScheduleID").value = '';
            document.getElementById("PriestID").value = '';
            document.getElementById("Date").value = '';
            document.getElementById("Time").value = '';
            document.getElementById("Location").value = '';
        }

        // Enable row click for editing
        function enableRowClickEdit() {
            const rows = document.querySelectorAll("#recordsTable tbody tr");

            rows.forEach(row => {
                row.style.cursor = "pointer";
                row.onclick = function () {
                    if (!adminAuthenticated) return;

                    const cells = row.querySelectorAll("td");
                    document.getElementById("updateScheduleID").value = cells[0].innerText.trim();
                    document.getElementById("updatePriestID").value = cells[1].dataset.priestid;
                    document.getElementById("updateDate").value = cells[2].innerText.trim();
                    document.getElementById("updateTime").value = cells[3].innerText.trim();
                    document.getElementById("updateLocation").value = cells[4].innerText.trim();

                    document.getElementById("updateModal").style.display = "flex";
                };
            });
        }

        function disableRowClickEdit() {
            const rows = document.querySelectorAll("#recordsTable tbody tr");
            rows.forEach(row => {
                row.onclick = null; // remove the click handler
                row.style.cursor = "default"; // optionally remove pointer cursor
            });
        }

        function closeUpdateModal() {
            document.getElementById("updateModal").style.display = "none";
            adminAuthenticated = false; // Revoke access again
            disableRowClickEdit();      // Remove event listeners for row clicks
        }
        /*----- choosing row [end]-----*/
        /*----- UPDATE RECORDS [end]-----*/


        /* ========= CHOOSING CERT [start] ============*/
        function toggleCertType() {
            const dropdown = document.getElementById("certTypeDropdown");
            const icon = document.getElementById("certDropdownIcon");

            dropdown.classList.toggle("dropdown-active");
            icon.classList.toggle("rotated");
        }

        function selectCertType(type) {
            document.getElementById("certTypeInput").value = type;
            const certChooseButton = document.querySelector(".certChoose-btn");

            if (type === "baptismal") {
                certChooseButton.innerHTML = 'Baptismal Certificate <span id="certDropdownIcon" class="rotated">▶</span>';
            } else if (type === "confirmation") {
                certChooseButton.innerHTML = 'Confirmation Certificate <span id="certDropdownIcon" class="rotated">▶</span>';
            }

            document.getElementById("certTypeDropdown").classList.remove("dropdown-active");
        }

        // open the modal for generating certificate
        function openCertModal() {
            document.getElementById("certificateModal").style.display = "block";
        }

        // close the modal
        function closeCertModal() {
            document.getElementById("certificateModal").style.display = "none";
        }
        /* ========= CHOOSING CERT [end] ============*/


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

        document.getElementById("dashboardButton").addEventListener("click", function() {
            window.location.href = "dashboard.php";
        });
        document.getElementById("priestButton").addEventListener("click", function() {
            window.location.href = "priestrecords.php";
        });
        document.getElementById("eventsButton").addEventListener("click", function() {
            window.location.href = "event.php";
        });
        document.getElementById("massButton").addEventListener("click", function() {
            window.location.href = "massSchedule.php";
        });
        document.getElementById("baptismalButton").addEventListener("click", function() {
            window.location.href = "baptismal.php";
        });
        document.getElementById("MarriageButton").addEventListener("click", function() {
            window.location.href = "marriage.php";
        });
        document.getElementById("burialButton").addEventListener("click", function() {
            window.location.href = "burial.php";
        });
        document.getElementById("confirmationButton").addEventListener("click", function() {
            window.location.href = "confirmation.php";
        });
        document.getElementById("clientButton").addEventListener("click", function() {
            window.location.href = "client.php";
        });

        document.getElementById("searchInput").addEventListener("keyup", function () {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll("#recordsTable tbody tr");

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? "" : "none";
            });
        });

            function openAdminModal() {
                document.getElementById("adminPassword").value = "";
                document.getElementById("adminModal").style.display = "flex";
            }
            function closeAdminModal() {
                document.getElementById("adminModal").style.display = "none";
                adminAuthenticated = false;
                document.getElementById("adminPassword").value = '';
                document.getElementById("hiddenAdminPassword").value = '';
            }
            function validateAdmin(event) {
                event.preventDefault();
                const inputPassword = document.getElementById("adminPassword").value;
                fetch("update_validation.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: "password=" + encodeURIComponent(inputPassword)
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            adminAuthenticated = true;
                            document.getElementById("adminModal").style.display = "none";
                            showMessageModal("Access granted. Please click on a record to edit.");
                            document.getElementById("hiddenAdminPassword").value = inputPassword;
                            enableMassRowClickEdit(); // Change to specific enable function
                        } else {
                            adminAuthenticated = false;
                            showMessageModal("Incorrect password.");
                        }
                        document.getElementById("adminPassword").value = '';
                    })
                    .catch(error => {
                        console.error("Error validating admin:", error);
                        showMessageModal("An error occurred during admin validation. Try again.");
                    });
                return false;
            }


            /*----- GENERAL MODAL/MESSAGE FUNCTIONS -----*/
            function showMessageModal(message) {
                const modal = document.getElementById("messageModal");
                if (modal) {
                    const messageText = document.getElementById("messageModalText");
                    if(messageText) messageText.textContent = message;
                    modal.style.display = "flex";
                }
            }

            /*----- ADD MASS SCHEDULE VALIDATION -----*/
            const addMassForm = document.getElementById('addMassForm');
            const addMassFields = {
                PriestID: document.getElementById('addPriestID'),
                Date: document.getElementById('addDate'),
                Time: document.getElementById('addTime'),
                Location: document.getElementById('addLocation')
            };
            const addMassFormState = {};
            const addMassSubmitButton = document.getElementById('addMassSubmitButton');

            function initializeAddMassValidation() {
                if (!addMassForm || !addMassSubmitButton) return;
                addMassSubmitButton.disabled = true;

                Object.keys(addMassFields).forEach(fieldName => {
                    const fieldElement = addMassFields[fieldName];
                    if (fieldElement) {
                        addMassFormState[fieldName] = false;
                        const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'date' || fieldElement.type === 'time') ? 'change' : 'input';
                        fieldElement.addEventListener(eventType, () => validateMassField(fieldName, fieldElement.value, fieldElement, 'add'));
                        fieldElement.addEventListener('blur', () => validateMassField(fieldName, fieldElement.value, fieldElement, 'add'));
                    }
                });
                addMassForm.addEventListener('submit', function(e) {
                    let formIsValid = true;
                    Object.keys(addMassFields).forEach(fieldName => {
                        validateMassField(fieldName, addMassFields[fieldName].value, addMassFields[fieldName], 'add');
                        if (!addMassFormState[fieldName]) formIsValid = false;
                    });
                    if (!formIsValid) {
                        e.preventDefault();
                        alert('Please correct all input errors before submitting.');
                    }
                });
            }

            function resetAddMassForm() {
                if (addMassForm) addMassForm.reset();
                Object.keys(addMassFields).forEach(fieldName => {
                    const fieldElement = addMassFields[fieldName];
                    const errorElement = document.getElementById('add' + fieldName + 'Error');
                    if (fieldElement) fieldElement.style.border = '';
                    if (errorElement) errorElement.classList.add('hidden');
                    addMassFormState[fieldName] = false;
                });
                if (addMassSubmitButton) {
                    addMassSubmitButton.disabled = true;
                    addMassSubmitButton.style.backgroundColor = '#cccccc';
                }
            }

            document.getElementById("addRecordBtn").onclick = function () {
                resetAddMassForm();
                document.getElementById("recordModal").style.display = "flex";
            };
            function closeModal() { // For Add Mass Modal
                document.getElementById("recordModal").style.display = "none";
                resetAddMassForm();
            }

            /*----- UPDATE MASS SCHEDULE VALIDATION -----*/
            const updateMassForm = document.getElementById('updateMassForm');
            const updateMassFields = {
                PriestID: document.getElementById('updatePriestID'),
                Date: document.getElementById('updateDate'),
                Time: document.getElementById('updateTime'),
                Location: document.getElementById('updateLocation')
            };
            const updateMassFormState = {};
            const updateMassSubmitButton = document.getElementById('updateMassSubmitButton');

            function initializeUpdateMassValidation() {
                if (!updateMassForm || !updateMassSubmitButton) return;
                updateMassSubmitButton.disabled = true;

                Object.keys(updateMassFields).forEach(fieldName => {
                    const fieldElement = updateMassFields[fieldName];
                    if (fieldElement) {
                        updateMassFormState[fieldName] = false;
                        const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'date' || fieldElement.type === 'time') ? 'change' : 'input';
                        fieldElement.addEventListener(eventType, () => validateMassField(fieldName, fieldElement.value, fieldElement, 'update'));
                        fieldElement.addEventListener('blur', () => validateMassField(fieldName, fieldElement.value, fieldElement, 'update'));
                    }
                });
                updateMassForm.addEventListener('submit', function(e) {
                    let formIsValid = true;
                    Object.keys(updateMassFields).forEach(fieldName => {
                        validateMassField(fieldName, updateMassFields[fieldName].value, updateMassFields[fieldName], 'update');
                        if (!updateMassFormState[fieldName]) formIsValid = false;
                    });
                    if (!formIsValid) {
                        e.preventDefault();
                        alert('Please correct all input errors before submitting the update.');
                    }
                });
            }

            function resetUpdateMassForm() {
                Object.keys(updateMassFields).forEach(fieldName => {
                    const fieldElement = updateMassFields[fieldName];
                    const errorElement = document.getElementById('update' + fieldName + 'Error');
                    if (fieldElement) {
                        fieldElement.value = '';
                        fieldElement.style.border = '';
                    }
                    if (errorElement) errorElement.classList.add('hidden');
                    updateMassFormState[fieldName] = false;
                });
                if (updateMassSubmitButton) {
                    updateMassSubmitButton.disabled = true;
                    updateMassSubmitButton.style.backgroundColor = '#cccccc';
                }
                // Don't reset updateScheduleID as it's readonly and key
            }

            document.getElementById("updateRecordBtn").onclick = function () {
                adminAuthenticated = false;
                openAdminModal(); // Admin auth first
            };

            function enableMassRowClickEdit() { // Specific for mass schedule
                const rows = document.querySelectorAll("#recordsTable tbody tr");
                rows.forEach(row => {
                    row.style.cursor = "pointer";
                    row.onclick = function () {
                        if (!adminAuthenticated) {
                            showMessageModal("Admin authentication required to edit.");
                            return;
                        }
                        const cells = row.querySelectorAll("td");
                        document.getElementById("updateScheduleID").value = cells[0].innerText.trim();

                        const priestCell = cells[1]; // Priest Name is the second cell (index 1)
                        const priestId = priestCell.dataset.priestid; // Get priestId from data-priestid

                        if(updateMassFields.PriestID && priestId !== undefined) {
                            updateMassFields.PriestID.value = priestId;
                        } else if(updateMassFields.PriestID) {
                            updateMassFields.PriestID.value = ""; // Fallback if priestId is not found
                            // console.warn("PriestID data attribute not found on cell:", priestCell);
                        }

                        if(updateMassFields.Date) updateMassFields.Date.value = cells[2].innerText.trim();
                        if(updateMassFields.Time) updateMassFields.Time.value = cells[3].innerText.trim();
                        if(updateMassFields.Location) updateMassFields.Location.value = cells[4].innerText.trim();

                        // Trigger validation for all fields after populating
                        Object.keys(updateMassFields).forEach(fieldName => {
                            if(updateMassFields[fieldName]) {
                                validateMassField(fieldName, updateMassFields[fieldName].value, updateMassFields[fieldName], 'update');
                            }
                        });
                        document.getElementById("updateModal").style.display = "flex";
                    };
                });
            }
            function disableMassRowClickEdit() {
                const rows = document.querySelectorAll("#recordsTable tbody tr");
                rows.forEach(row => {
                    row.onclick = null;
                    row.style.cursor = "default";
                });
            }
            function closeUpdateModal() {
                document.getElementById("updateModal").style.display = "none";
                adminAuthenticated = false;
                disableMassRowClickEdit();
                resetUpdateMassForm();
            }


            /*----- COMMON MASS FIELD VALIDATION FUNCTION -----*/
            function validateMassField(fieldName, value, fieldElement, formTypePrefix) {
                let isValid = false;
                const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
                const currentFormState = (formTypePrefix === 'add') ? addMassFormState : updateMassFormState;
                const submitBtn = (formTypePrefix === 'add') ? addMassSubmitButton : updateMassSubmitButton;

                if (!errorElement) {
                    // console.error("Error element not found for:", formTypePrefix + fieldName + 'Error');
                    // Silently return if error element is not defined, for fields like ScheduleID that don't have one.
                    return;
                }
                value = value.trim();
                let defaultErrorMsg = fieldName.replace(/([A-Z])/g, ' $1').trim() + ' is required.';
                let specificErrorMsg = '';

                switch(fieldName) {
                    case 'PriestID':
                        isValid = value !== "" && massRegexPatterns.idFormat.test(value);
                        specificErrorMsg = 'Invalid Priest selection.';
                        break;
                    case 'Date':
                        const isFormatValid = massRegexPatterns.dateFormat.test(value);
                        if (!isFormatValid) {
                            isValid = false;
                            specificErrorMsg = value === '' ? defaultErrorMsg : 'Date format must be YYYY-MM-DD.';
                        } else {
                            const selectedDate = new Date(value + "T00:00:00");
                            const today = new Date();
                            today.setHours(0,0,0,0);
                            isValid = selectedDate >= today; // Date must be today or in the future
                            specificErrorMsg = isValid ? '' : 'Date must be today or in the future.';
                        }
                        break;
                    case 'Time':
                        isValid = massRegexPatterns.timeFormat.test(value);
                        specificErrorMsg = 'Invalid Time format (HH:MM or HH:MM:SS).';
                        break;
                    case 'Location':
                        isValid = massRegexPatterns.locationFormat.test(value);
                        specificErrorMsg = 'Invalid Location format (3-150 chars).';
                        break;
                    default:
                        isValid = true;
                }

                currentFormState[fieldName] = isValid;
                if (fieldElement) {
                    if (isValid) {
                        fieldElement.style.border = '2px solid green';
                        errorElement.classList.add('hidden');
                    } else {
                        fieldElement.style.border = '2px solid red';
                        errorElement.classList.remove('hidden');
                        errorElement.textContent = (value === '') ? defaultErrorMsg : specificErrorMsg;
                    }
                }
                checkMassFormOverallValidity(formTypePrefix);
            }

            function checkMassFormOverallValidity(formTypePrefix) {
                const currentFormState = (formTypePrefix === 'add') ? addMassFormState : updateMassFormState;
                const currentFields = (formTypePrefix === 'add') ? addMassFields : updateMassFields;
                const submitBtn = (formTypePrefix === 'add') ? addMassSubmitButton : updateMassSubmitButton;
                if (!submitBtn) return;

                const allValid = Object.keys(currentFields).every(fieldName => currentFormState[fieldName] === true);
                if (allValid) {
                    submitBtn.disabled = false;
                    submitBtn.style.backgroundColor = (formTypePrefix === 'add') ? '#28a745' : '#F39C12';
                } else {
                    submitBtn.disabled = true;
                    submitBtn.style.backgroundColor = '#cccccc';
                }
            }

            /*----- GENERAL UI & NAVIGATION (Same as your priestrecords.php) -----*/
            // ... (copy your toggleSidebar, toggleDropdown, certificate modal functions, and navigation event listeners here) ...
            function toggleSidebar() { document.querySelector(".sidebar").classList.toggle("active"); }
            function toggleDropdown() {
                document.getElementById("certificateDropdown").classList.toggle("dropdown-active");
                document.getElementById("certificates").classList.toggle("open");
            }
            function toggleCertType() {
                document.getElementById("certTypeDropdown").classList.toggle("dropdown-active");
                document.getElementById("certDropdownIcon").classList.toggle("rotated");
            }
            function selectCertType(type) {
                document.getElementById("certTypeInput").value = type;
                const btn = document.querySelector(".certChoose-btn");
                btn.innerHTML = (type === "baptismal" ? 'Baptismal Certificate' : 'Confirmation Certificate') + ' <span id="certDropdownIcon" class="rotated">▶</span>';
                document.getElementById("certTypeDropdown").classList.remove("dropdown-active");
            }
            function openCertModal() { document.getElementById("certificateModal").style.display = "block"; }
            function closeCertModal() { document.getElementById("certificateModal").style.display = "none"; }

            window.onclick = function(event) {
                if (event.target == document.getElementById("certificateModal")) closeCertModal();
                if (event.target == document.getElementById("recordModal")) closeModal(); // For Add modal
                if (event.target == document.getElementById("updateModal")) closeUpdateModal(); // For Update modal
                if (event.target == document.getElementById("adminModal")) closeAdminModal(); // For Admin modal
            };

            document.getElementById("dashboardButton").addEventListener("click", () => window.location.href = "dashboard.php");
            document.getElementById("priestButton").addEventListener("click", () => window.location.href = "priestrecords.php");
            document.getElementById("eventsButton").addEventListener("click", () => window.location.href = "event.php");
            document.getElementById("massButton").addEventListener("click", () => window.location.href = "massSchedule.php");
            document.getElementById("baptismalButton").addEventListener("click", () => window.location.href = "baptismal.php");
            document.getElementById("MarriageButton").addEventListener("click", () => window.location.href = "marriage.php");
            document.getElementById("burialButton").addEventListener("click", () => window.location.href = "burial.php");
            document.getElementById("confirmationButton").addEventListener("click", () => window.location.href = "confirmation.php");
            document.getElementById("clientButton").addEventListener("click", () => window.location.href = "client.php");

            document.getElementById("searchInput").addEventListener("keyup", function () {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll("#recordsTable tbody tr");
                rows.forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(filter) ? "" : "none";
                });
            });


            /*----- INITIALIZE VALIDATIONS ON DOM LOADED -----*/
            document.addEventListener('DOMContentLoaded', function() {
                initializeAddMassValidation();
                initializeUpdateMassValidation();
                // Note: You might not have an update_validation.php for mass schedules if admin auth is different
                // Adjust admin validation flow if needed.

                // --- START: New Filter JavaScript for massSchedule.php ---
                const categoryFilter_mass = document.getElementById('categoryFilterMass');
                const yearInputContainer_mass = document.getElementById('filterYearInputContainerMass');
                const monthInputContainer_mass = document.getElementById('filterMonthInputContainerMass');
                const dateInputContainer_mass = document.getElementById('filterDateInputContainerMass');

                const yearValueInput_mass = document.getElementById('filterYearValueMass');
                const yearForMonthValueInput_mass = document.getElementById('filterYearForMonthValueMass');
                const monthValueSelect_mass = document.getElementById('filterMonthValueMass');
                const dateValueInput_mass = document.getElementById('filterDateValueMass'); // This is type="date"

                const applyFilterButton_mass = document.getElementById('applyFilterBtnMass');
                const clearFilterButton_mass = document.getElementById('clearFilterBtnMass');
                const searchInput_mass = document.getElementById('searchInput');

                function toggleFilterInputs_mass() {
                    if (!categoryFilter_mass) return;
                    const selectedFilter = categoryFilter_mass.value;

                    if(yearInputContainer_mass) yearInputContainer_mass.style.display = 'none';
                    if(monthInputContainer_mass) monthInputContainer_mass.style.display = 'none';
                    if(dateInputContainer_mass) dateInputContainer_mass.style.display = 'none';

                    if (selectedFilter === 'year' && yearInputContainer_mass) {
                        yearInputContainer_mass.style.display = 'inline-block';
                    } else if (selectedFilter === 'month' && monthInputContainer_mass) {
                        monthInputContainer_mass.style.display = 'inline-block';
                    } else if (selectedFilter === 'specific_date' && dateInputContainer_mass) {
                        dateInputContainer_mass.style.display = 'inline-block';
                    }
                }

                if (categoryFilter_mass) {
                    categoryFilter_mass.addEventListener('change', toggleFilterInputs_mass);
                }

                if (applyFilterButton_mass) {
                    applyFilterButton_mass.addEventListener('click', function() {
                        if (!categoryFilter_mass) return;
                        const filterType = categoryFilter_mass.value;
                        if (!filterType) return;

                        let queryParams = new URLSearchParams();
                        queryParams.set('filter_type_mass', filterType);

                        if (filterType === 'year') {
                            if (!yearValueInput_mass || !yearValueInput_mass.value || !/^\d{4}$/.test(yearValueInput_mass.value)) {
                                alert('Please enter a valid 4-digit year.'); return;
                            }
                            queryParams.set('filter_year_value_mass', yearValueInput_mass.value);
                        } else if (filterType === 'month') {
                            if (!monthValueSelect_mass || !monthValueSelect_mass.value) {
                                alert('Please select a month.'); return;
                            }
                            queryParams.set('filter_month_value_mass', monthValueSelect_mass.value);
                            if (yearForMonthValueInput_mass && yearForMonthValueInput_mass.value) {
                                if (!/^\d{4}$/.test(yearForMonthValueInput_mass.value)) {
                                    alert('If providing a year for the month, please enter a valid 4-digit year.'); return;
                                }
                                queryParams.set('filter_year_for_month_value_mass', yearForMonthValueInput_mass.value);
                            }
                        } else if (filterType === 'specific_date') {
                            if (!dateValueInput_mass || !dateValueInput_mass.value) { // type="date" gives YYYY-MM-DD
                                alert('Please select a date.'); return;
                            }
                            if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValueInput_mass.value)) {
                                alert('Invalid date format. Expected YYYY-MM-DD.'); return;
                            }
                            queryParams.set('filter_date_value_mass', dateValueInput_mass.value);
                        } else if (filterType === 'oldest_to_latest') {
                            queryParams.set('sort_order_mass', 'asc');
                        } else if (filterType === 'latest_to_oldest') {
                            queryParams.set('sort_order_mass', 'desc');
                        }
                        window.location.search = queryParams.toString();
                    });
                }

                if (clearFilterButton_mass) {
                    clearFilterButton_mass.addEventListener('click', function(event) {
                        event.preventDefault();
                        if (searchInput_mass) {
                            searchInput_mass.value = '';
                        }
                        window.location.href = window.location.pathname;
                    });
                }

                function setFiltersFromUrl_mass() {
                    if (!categoryFilter_mass) return;
                    const urlParams = new URLSearchParams(window.location.search);
                    const filterTypeFromUrl = urlParams.get('filter_type_mass');

                    categoryFilter_mass.value = "";
                    if(yearValueInput_mass) yearValueInput_mass.value = "";
                    if(yearForMonthValueInput_mass) yearForMonthValueInput_mass.value = "";
                    if(monthValueSelect_mass) monthValueSelect_mass.value = "";
                    if(dateValueInput_mass) dateValueInput_mass.value = ""; // type="date" handles empty
                    toggleFilterInputs_mass();

                    if (filterTypeFromUrl) {
                        categoryFilter_mass.value = filterTypeFromUrl;
                        toggleFilterInputs_mass();

                        if (filterTypeFromUrl === 'year' && urlParams.has('filter_year_value_mass') && yearValueInput_mass) {
                            yearValueInput_mass.value = urlParams.get('filter_year_value_mass');
                        } else if (filterTypeFromUrl === 'month') {
                            if (urlParams.has('filter_month_value_mass') && monthValueSelect_mass) {
                                monthValueSelect_mass.value = urlParams.get('filter_month_value_mass');
                            }
                            if (urlParams.has('filter_year_for_month_value_mass') && yearForMonthValueInput_mass) {
                                yearForMonthValueInput_mass.value = urlParams.get('filter_year_for_month_value_mass');
                            }
                        } else if (filterTypeFromUrl === 'specific_date' && urlParams.has('filter_date_value_mass') && dateValueInput_mass) {
                            dateValueInput_mass.value = urlParams.get('filter_date_value_mass');
                        }
                    } else if (urlParams.has('sort_order_mass')) {
                        const sortOrder = urlParams.get('sort_order_mass');
                        if (sortOrder === 'asc') categoryFilter_mass.value = 'oldest_to_latest';
                        if (sortOrder === 'desc') categoryFilter_mass.value = 'latest_to_oldest';
                    }
                }

                setFiltersFromUrl_mass(); // Call on page load
                // --- END: New Filter JavaScript for massSchedule.php ---
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

    </script>
</body>
</html>