<?php

// Prevent caching of the page after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$pageTitle = "Priest Records Management";
require_once 'session_timeout.php'; 

// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: Log_In\login_system.php");
    exit();
}

// --- START: Filter Logic for GET requests (Priest Records by OrdinationDate) ---
$whereClauses_priest = [];
// Default order for priest records
$orderByClause_priest = "ORDER BY p.PriestID DESC"; // Default: newest first by ID
$filter_params_priest = []; // Parameters for prepared statement
$filter_param_types_priest = ""; // Types for prepared statement

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (isset($_GET['filter_type_priest']) && !empty($_GET['filter_type_priest'])) {
        $filter_type = $_GET['filter_type_priest'];

        switch ($filter_type) {
            case 'year':
                if (isset($_GET['filter_year_value_priest']) && !empty($_GET['filter_year_value_priest'])) {
                    $year = filter_var($_GET['filter_year_value_priest'], FILTER_VALIDATE_INT);
                    if ($year && strlen((string)$year) == 4) {
                        $whereClauses_priest[] = "YEAR(p.OrdinationDate) = ?";
                        $filter_params_priest[] = $year;
                        $filter_param_types_priest .= "i";
                    }
                }
                break;
            case 'month':
                if (isset($_GET['filter_month_value_priest']) && !empty($_GET['filter_month_value_priest'])) {
                    $month = filter_var($_GET['filter_month_value_priest'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 12]]);
                    if ($month) {
                        $whereClauses_priest[] = "MONTH(p.OrdinationDate) = ?";
                        $filter_params_priest[] = $month;
                        $filter_param_types_priest .= "i";

                        if (isset($_GET['filter_year_for_month_value_priest']) && !empty($_GET['filter_year_for_month_value_priest'])) {
                            $year_for_month = filter_var($_GET['filter_year_for_month_value_priest'], FILTER_VALIDATE_INT);
                            if ($year_for_month && strlen((string)$year_for_month) == 4) {
                                $whereClauses_priest[] = "YEAR(p.OrdinationDate) = ?";
                                $filter_params_priest[] = $year_for_month;
                                $filter_param_types_priest .= "i";
                            }
                        }
                    }
                }
                break;
            case 'specific_date':
                if (isset($_GET['filter_date_value_priest']) && !empty($_GET['filter_date_value_priest'])) {
                    $date_str = $_GET['filter_date_value_priest']; // Expected YYYY-MM-DD
                    $d = DateTime::createFromFormat('Y-m-d', $date_str);
                    if ($d && $d->format('Y-m-d') === $date_str) {
                        $whereClauses_priest[] = "p.OrdinationDate = ?";
                        $filter_params_priest[] = $date_str;
                        $filter_param_types_priest .= "s";
                    }
                }
                break;
            case 'oldest_to_latest':
                $orderByClause_priest = "ORDER BY p.OrdinationDate ASC, p.PriestID ASC";
                break;
            case 'latest_to_oldest':
                $orderByClause_priest = "ORDER BY p.OrdinationDate DESC, p.PriestID DESC";
                break;
        }
    }
    // Handle standalone sort_order parameter
    if (isset($_GET['sort_order_priest'])) {
        if ($_GET['sort_order_priest'] === 'asc' && $filter_type !== 'oldest_to_latest') {
            $orderByClause_priest = "ORDER BY p.OrdinationDate ASC, p.PriestID ASC";
        } elseif ($_GET['sort_order_priest'] === 'desc' && $filter_type !== 'latest_to_oldest') {
            $orderByClause_priest = "ORDER BY p.OrdinationDate DESC, p.PriestID DESC";
        }
    }
}
// --- END: Filter Logic (Priest Records) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

    if ($conn->connect_error) {
        error_log("Database Connection Failed: " . $conn->connect_error);
        echo "<script>alert('Error: Could not connect to the database. Please try again later.'); window.history.back();</script>";
        exit();
    }

    // Retrieve and trim POST data
    // $priestID will be specifically handled within the update block if 'updateRecord' is set
    $fullName           = trim($_POST['FullName'] ?? '');
    $dob                = trim($_POST['DOB'] ?? '');
    $contact            = trim($_POST['ContactInfo'] ?? '');
    $ordinationDate     = trim($_POST['OrdinationDate'] ?? '');
    $ordinationLocation = trim($_POST['OrdinationLoc'] ?? '');
    $bishop             = trim($_POST['OrdainingBishop'] ?? '');
    $seminary           = trim($_POST['SeminarySchool'] ?? '');
    $status             = trim($_POST['Status'] ?? '');
    $priestID_for_update = null; // Specific variable for update operation's PriestID

    // --- SERVER-SIDE VALIDATION ---
    $errors = [];
    $today = new DateTime();
    $today->setTime(0,0,0); // For date comparisons

    // PriestID validation (only if it's an update action)
    if (isset($_POST['updateRecord'])) {
        $priestID_for_update = trim($_POST['PriestID'] ?? ''); // Get PriestID specifically for update
        if (empty($priestID_for_update)) {
            $errors[] = "Priest ID is required for an update.";
        } elseif (!ctype_digit($priestID_for_update)) {
            $errors[] = "Invalid Priest ID format for update (must be a number).";
        }
    }

    // FullName
    if (empty($fullName)) {
        $errors[] = "Full Name is required.";
    } elseif (!preg_match('/^[a-zA-Z\s.\'-]{2,100}$/', $fullName)) {
        $errors[] = "Invalid Full Name format (letters, spaces, '.', ''', - allowed, 2-100 chars).";
    }

    // Date of Birth (DOB)
    $dobDateObj = null;
    if (empty($dob)) {
        $errors[] = "Date of Birth is required.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $errors[] = "Invalid Date of Birth format (YYYY-MM-DD).";
    } else {
        $dobDateObj = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dobDateObj || $dobDateObj->format('Y-m-d') !== $dob) {
            $errors[] = "Invalid Date of Birth (e.g., day/month out of range).";
            $dobDateObj = null;
        } elseif ($dobDateObj >= $today) {
            $errors[] = "Date of Birth must be in the past.";
            $dobDateObj = null;
        }
    }

    // ContactInfo - UPDATED FOR PH PHONE NUMBERS ONLY
    if (empty($contact)) {
        $errors[] = "Contact Info (Philippine Phone Number) is required.";
    } else {
        $phPhoneRegex = '/^(09|\+639|639)\d{2}[\s-]?\d{3}[\s-]?\d{4}$/';
        if (!preg_match($phPhoneRegex, $contact)) {
            $errors[] = "Invalid Philippine Phone Number format. Examples: 09171234567, +639171234567.";
        } else {
            $contact = preg_replace('/[\s-]+/', '', $contact); // Normalize
        }
    }

    // OrdinationDate
    $ordinationDateObj = null;
    if (empty($ordinationDate)) {
        $errors[] = "Ordination Date is required.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ordinationDate)) {
        $errors[] = "Invalid Ordination Date format (YYYY-MM-DD).";
    } else {
        $ordinationDateObj = DateTime::createFromFormat('Y-m-d', $ordinationDate);
        if (!$ordinationDateObj || $ordinationDateObj->format('Y-m-d') !== $ordinationDate) {
            $errors[] = "Invalid Ordination Date (e.g., day/month out of range).";
            $ordinationDateObj = null;
        } elseif ($ordinationDateObj >= $today) {
            $errors[] = "Ordination Date must be in the past.";
            $ordinationDateObj = null;
        } elseif ($dobDateObj && $ordinationDateObj) {
            $minOrdinationAge = clone $dobDateObj;
            $minOrdinationAge->modify('+18 years');
            if ($ordinationDateObj < $minOrdinationAge) {
                $errors[] = "Ordination Date must be at least 18 years after Date of Birth.";
            }
        }
    }

    // OrdinationLocation
    if (empty($ordinationLocation)) {
        $errors[] = "Ordination Location is required.";
    } elseif (!preg_match('/^[a-zA-Z0-9\s.,\'()-]{3,150}$/', $ordinationLocation)) {
        $errors[] = "Invalid Ordination Location format (3-150 chars).";
    }

    // OrdainingBishop
    if (empty($bishop)) {
        $errors[] = "Ordaining Bishop is required.";
    } elseif (!preg_match('/^[a-zA-Z\s.\'-]{2,100}$/', $bishop)) {
        $errors[] = "Invalid Ordaining Bishop name format (2-100 chars).";
    }

    // SeminarySchool
    if (empty($seminary)) {
        $errors[] = "Seminary School is required.";
    } elseif (!preg_match('/^[a-zA-Z0-9\s.,\'()-]{3,150}$/', $seminary)) {
        $errors[] = "Invalid Seminary School format (3-150 chars).";
    }

    // Status
    if (empty($status)) {
        $errors[] = "Status is required.";
    } elseif (!in_array($status, ['Active', 'Retired', 'On Leave'])) {
        $errors[] = "Invalid Status selected.";
    }

    // If errors, show them and exit
    if (!empty($errors)) {
        $errorString = implode("\\n", $errors);
        echo "<script>alert('Validation Errors:\\n" . htmlspecialchars($errorString, ENT_QUOTES) . "'); window.history.back();</script>";
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
        $sql = "INSERT INTO priest (FullName, DOB, ContactInfo, OrdinationDate, OrdinationLoc, OrdainingBishop, SeminarySchool, Status, ParishStaffID)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("SQL Prepare Error (Insert Priest): " . $conn->error);
            echo "<script>alert('Error preparing the record. Please try again.'); window.history.back();</script>";
            $conn->close();
            exit();
        }
    $stmt->bind_param("ssssssssi", $fullName, $dob, $contact, $ordinationDate, $ordinationLocation, $bishop, $seminary, $status, $parishStaffID);

        if ($stmt->execute()) {
            echo "<script>alert('Record inserted successfully!'); window.location.href = window.location.href;</script>";
        } else {
            error_log("SQL Execute Error (Insert Priest): " . $stmt->error);
            echo "<script>alert('Error inserting record: " . htmlspecialchars($stmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
        }
        $stmt->close();
        $conn->close();
        exit();

        // FOR UPDATING RECORDS
    } elseif (isset($_POST['updateRecord'])) {
        // ----- START DEBUGGING FOR UPDATE -----
        // echo "<pre>POST data for update: ";
        // var_dump($_POST);
        // echo "Priest ID specifically for update: ";
        // var_dump($priestID_for_update); // This is the ID validated earlier
        // echo "</pre>";
        // ----- END DEBUGGING FOR UPDATE (Comment out or remove after testing) -----

        // $priestID_for_update was validated earlier. If $errors was empty, it means $priestID_for_update is valid.

        $sql = "UPDATE priest
                SET FullName=?, DOB=?, ContactInfo=?, OrdinationDate=?, OrdinationLoc=?, OrdainingBishop=?, SeminarySchool=?, Status=?
                WHERE PriestID=?";
        $updateStmt = $conn->prepare($sql);
        if ($updateStmt === false) {
            error_log("SQL Prepare Error (Update Priest): " . $conn->error);
            echo "<script>alert('Error preparing the update. Please try again.'); window.history.back();</script>";
            $conn->close();
            exit();
        }
        // Use the $priestID_for_update variable that was specifically retrieved and validated for the update
        $updateStmt->bind_param("ssssssssi", $fullName, $dob, $contact, $ordinationDate, $ordinationLocation, $bishop, $seminary, $status, $priestID_for_update);

        if ($updateStmt->execute()) {
            // Check affected rows
            if ($updateStmt->affected_rows > 0) {
                echo "<script>alert('Record updated successfully! " . $updateStmt->affected_rows . " row(s) affected.'); window.location.href = window.location.href;</script>";
            } else if ($updateStmt->affected_rows === 0) {
                // This could mean the PriestID didn't match OR the submitted data was identical to existing data
                echo "<script>alert('Update query executed, but 0 rows were affected. This might mean the Priest ID was not found, or the data submitted was identical to the existing record.'); window.location.href = window.location.href;</script>";
            } else { // affected_rows can be -1 on error, though execute() should have returned false.
                echo "<script>alert('Record updated successfully (unknown affected rows)!'); window.location.href = window.location.href;</script>";
            }
        } else {
            error_log("SQL Execute Error (Update Priest): " . $updateStmt->error . " | PriestID: " . $priestID_for_update);
            echo "<script>alert('Update error: " . htmlspecialchars($updateStmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
        }
        $updateStmt->close();
        $conn->close();
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Priest Records Management</title>
    <link rel="icon" href="/imagess/sacred.png">
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="stylesheet" href="prieststyle.css?v=15">
     <!-- Add these two lines for responsiveness -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="priestResponsive.css?v=15">
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
        <div class="section-title">Priest Records</div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search..." style="flex-grow: 1;">

            <!-- START: Category Filter for Priest Records -->
            <div class="filter-container">
                <select id="categoryFilterPriest" name="category_filter_priest" title="Category Filter">
                    <option value="">-- Filter By Ordination Date --</option>
                    <option value="year">Year</option>
                    <option value="month">Month</option>
                    <option value="specific_date">Specific Date</option>
                    <option value="oldest_to_latest">Oldest to Latest</option>
                    <option value="latest_to_oldest">Latest to Oldest</option>
                </select>

                <div id="filterYearInputContainerPriest" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearValuePriest" name="filter_year_value_priest" placeholder="YYYY">
                </div>
                <div id="filterMonthInputContainerPriest" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearForMonthValuePriest" name="filter_year_for_month_value_priest" placeholder="YYYY (Opt.)">
                    <select id="filterMonthValuePriest" name="filter_month_value_priest">
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
                <div id="filterDateInputContainerPriest" class="filter-input-group" style="display:none;">
                    <input type="date" id="filterDateValuePriest" name="filter_date_value_priest"> <!-- Using type="date" -->
                </div>
                <button id="applyFilterBtnPriest" class="filter-btn">Apply</button>
                <button id="clearFilterBtnPriest" class="filter-btn">Clear</button>
            </div>
            <!-- END: Category Filter for Priest Records -->

            <div class="record-buttons" style="margin-left: auto;">
                <button id="updateRecordBtn">✎ Update Record</button>
                <button id="addRecordBtn">+ Add Record</button>
            </div>
        </div>

        <div class="table-scroll">
            <table id="recordsTable">
                <thead>
                <tr>
                    <th>Priest ID</th>
                    <th>Full Name</th>
                    <th>Date Of Birth</th>
                    <th>Contact Info</th>
                    <th>Ordination Date</th>
                    <th>Ordination Location</th>
                    <th>Ordaining Bishop</th>
                    <th>Seminary School</th>
                    <th>Status</th>
                    <th>Created By</th>
                </tr>
                </thead>
                <tbody>
                <?php
// Database connection FOR DISPLAY.
                // It's better to establish this connection ONCE at the top of the file if it's needed for both POST and GET (display).
                // However, if it's only for display here, this is fine.
                // For consistency, let's use $conn for all DB operations on this page.
                // If $conn was closed after POST, we need to reopen it.
                // The safest approach is to ensure $conn is open here.

                $conn_display_for_table = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS"); // Use a fresh connection variable for this block
                if ($conn_display_for_table->connect_error) {
                    echo "<tr><td colspan='10' style='color:red; font-weight:bold;'>Display Connection failed: " . htmlspecialchars($conn_display_for_table->connect_error) . "</td></tr>";
                } else {
                    $conn_display_for_table->set_charset("utf8mb4");

                    // start modify for filter

                    $baseSqlPriest = "SELECT 
                            p.*,
                            COALESCE(au.Username, su.username, 'Unknown') AS CreatedBy
                        FROM priest p
                        LEFT JOIN parishstaff ps ON p.ParishStaffID = ps.ParishStaffID
                        LEFT JOIN admin_users au ON ps.AdminUserID = au.ID
                        LEFT JOIN staff_users su ON ps.StaffUserID = su.id";

                    $finalSqlPriest = $baseSqlPriest;

                    if (!empty($whereClauses_priest)) {
                        $finalSqlPriest .= " WHERE " . implode(" AND ", $whereClauses_priest);
                    }
                    $finalSqlPriest .= " " . $orderByClause_priest;

                    $result_display = null; // Initialize

                    if (!empty($filter_params_priest)) {
                        $stmtPriest = $conn_display_for_table->prepare($finalSqlPriest); // Use $conn_display_for_table
                        if ($stmtPriest === false) {
                            error_log("SQL Prepare Error (Filter Priest): " . $conn_display_for_table->error . " | SQL: " . $finalSqlPriest);
                            echo "<tr><td colspan='10'>Error preparing priest data.</td></tr>";
                        } else {
                            $stmtPriest->bind_param($filter_param_types_priest, ...$filter_params_priest);
                            $stmtPriest->execute();
                            $result_display = $stmtPriest->get_result();
                            if ($result_display === false) {
                                error_log("SQL Get Result Error (Filter Priest): " . $stmtPriest->error);
                                echo "<tr><td colspan='10'>Error retrieving filtered priest data.</td></tr>";
                            }
                            $stmtPriest->close(); // Close statement
                        }
                    } else {
                        $result_display = $conn_display_for_table->query($finalSqlPriest); // Use $conn_display_for_table
                        if ($result_display === false) {
                            error_log("SQL Query Error (Priest): " . $conn_display_for_table->error . " | SQL: " . $finalSqlPriest);
                            echo "<tr><td colspan='10'>Error fetching priest data.</td></tr>";
                        }
                    }

                    if ($result_display && $result_display->num_rows > 0) {
                        while ($row = $result_display->fetch_assoc()) {
                            // Using htmlspecialchars for all echoed data
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($row["PriestID"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["FullName"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["DOB"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["ContactInfo"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["OrdinationDate"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["OrdinationLoc"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["OrdainingBishop"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["SeminarySchool"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["Status"] ?? '-') . "</td>";
                            echo "<td>" . htmlspecialchars($row["CreatedBy"] ?? '-') . "</td>";
                            echo "</tr>";
                        }
                    } else if ($result_display) { // Check if $result_display is not false
                        echo "<tr><td colspan='10'>No priest records found matching your criteria.</td></tr>";
                    }
                    $conn_display_for_table->close(); // Close this specific connection variable
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ADD RECORD MODAL -->
    <div class="modal" id="recordModal">
        <form class="modal-content" id="addPriestForm" method="POST" action="priestrecords.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>
            <div class="modal-header" style="background: #2c3e50; color: white; text-align: center; border-radius: 0; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Priest Details</h3>
            </div>

            <!-- <div style="flex: 1 1 45%;">
                <label for="PriestID" style="margin-left: 55px;">Priest ID:</label><br>
                <input type="text" name="PriestID" style="width: 80%; padding: 5px; margin-left: 55px;">
                <small id="formatExample" style="display: none; margin-left: 55px; margin-top: 5px; color: gray;">
                Format Example: PRT001
                </small>
            </div> -->

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">
                <div style="flex: 1 1 45%;">
                    <label for="addFullName" style="margin-left: 55px;">Full Name:</label><br>
                    <input type="text" id="addFullName" name="FullName" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addFullNameError" class="error-message hidden" style="margin-left: 55px;">Full Name is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="addDOB" style="margin-left: 30px;">Date of Birth:</label><br>
                    <input type="date" id="addDOB" name="DOB" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addDOBError" class="error-message hidden" style="margin-left: 30px;">Date of Birth is required and must be in the past.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="addContactInfo" style="margin-left: 55px;">Contact Info (PH Phone Number):</label><br>
                    <input type="text" id="addContactInfo" name="ContactInfo" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addContactInfoError" class="error-message hidden" style="margin-left: 55px;">Philippine Phone Number is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="addOrdinationDate" style="margin-left: 30px;">Ordination Date:</label><br>
                    <input type="date" id="addOrdinationDate" name="OrdinationDate" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addOrdinationDateError" class="error-message hidden" style="margin-left: 30px;">Ordination Date is required, must be in the past, and after DOB.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="addOrdinationLoc" style="margin-left: 55px;">Ordination Location:</label><br>
                    <input type="text" id="addOrdinationLoc" name="OrdinationLoc" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addOrdinationLocError" class="error-message hidden" style="margin-left: 55px;">Ordination Location is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="addOrdainingBishop" style="margin-left: 30px;">Ordaining Bishop:</label><br>
                    <input type="text" id="addOrdainingBishop" name="OrdainingBishop" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addOrdainingBishopError" class="error-message hidden" style="margin-left: 30px;">Ordaining Bishop's name is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="addSeminarySchool" style="margin-left: 55px;">Seminary School:</label><br>
                    <input type="text" id="addSeminarySchool" name="SeminarySchool" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addSeminarySchoolError" class="error-message hidden" style="margin-left: 55px;">Seminary School is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="addStatus" style="margin-left: 30px;">Status:</label><br>
                    <select id="addStatus" name="Status" required style="width: 80%; padding: 5px; margin-left: 30px;">
                        <option value="">-- Select Status --</option>
                        <option value="Active">Active</option>
                        <option value="Retired">Retired</option>
                        <option value="On Leave">On Leave</option>
                    </select>
                    <small id="addStatusError" class="error-message hidden" style="margin-left: 30px;">Status is required.</small>
                </div>
            </div>
            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" name="submitRecord" id="addSubmitButton" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">+ Add Record</button>
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


    <!-- UPDATE RECORD MODAL -->
    <div class="modal" id="updateModal">
        <form class="modal-content" id="updatePriestForm" method="POST" action="priestrecords.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeUpdateModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>
            <div class="modal-header" style="background: #F39C12; color: white; text-align: center; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Update Priest Record</h3>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">
                <div style="flex: 1 1 45%;">
                    <label for="updatePriestID" style="margin-left: 55px;">Priest ID:</label><br>
                    <input type="text" id="updatePriestID" name="PriestID" readonly style="width: 80%; padding: 5px; margin-left: 55px; background-color: #e9e9e9;">
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateFullName" style="margin-left: 30px;">Full Name:</label><br>
                    <input type="text" id="updateFullName" name="FullName" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateFullNameError" class="error-message hidden" style="margin-left: 30px;">Full Name is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateDOB" style="margin-left: 55px;">Date of Birth:</label><br>
                    <input type="date" id="updateDOB" name="DOB" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateDOBError" class="error-message hidden" style="margin-left: 55px;">Date of Birth is required and must be in the past.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateContactInfo" style="margin-left: 30px;">Contact Info (PH Phone Number):</label><br>
                    <input type="text" id="updateContactInfo" name="ContactInfo" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateContactInfoError" class="error-message hidden" style="margin-left: 30px;">Philippine Phone Number is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateOrdinationDate" style="margin-left: 55px;">Ordination Date:</label><br>
                    <input type="date" id="updateOrdinationDate" name="OrdinationDate" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateOrdinationDateError" class="error-message hidden" style="margin-left: 55px;">Ordination Date is required, must be in the past, and after DOB.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateOrdinationLoc" style="margin-left: 30px;">Ordination Location:</label><br>
                    <input type="text" id="updateOrdinationLoc" name="OrdinationLoc" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateOrdinationLocError" class="error-message hidden" style="margin-left: 30px;">Ordination Location is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateOrdainingBishop" style="margin-left: 55px;">Ordaining Bishop:</label><br>
                    <input type="text" id="updateOrdainingBishop" name="OrdainingBishop" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateOrdainingBishopError" class="error-message hidden" style="margin-left: 55px;">Ordaining Bishop's name is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateSeminarySchool" style="margin-left: 30px;">Seminary School:</label><br>
                    <input type="text" id="updateSeminarySchool" name="SeminarySchool" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateSeminarySchoolError" class="error-message hidden" style="margin-left: 30px;">Seminary School is required.</small>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="updateStatus" style="margin-left: 55px;">Status:</label><br>
                    <select id="updateStatus" name="Status" required style="width: 80%; padding: 5px; margin-left: 55px;">
                        <option value="">-- Select Status --</option>
                        <option value="Active">Active</option>
                        <option value="Retired">Retired</option>
                        <option value="On Leave">On Leave</option>
                    </select>
                    <small id="updateStatusError" class="error-message hidden" style="margin-left: 55px;">Status is required.</small>
                </div>
            </div>
            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" name="updateRecord" id="updateSubmitButton" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">✎ Update Record</button>
            </div>
            <input type="hidden" name="adminPassword" id="hiddenAdminPassword">
        </form>
    </div>



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


    <!-- Modal for Client ID input WITH CHOOSING CERT -->
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

    <script>
        /*----- REGEX PATTERNS (GLOBAL) -----*/
        const priestRegexPatterns = {
            name: /^[a-zA-Z\s.'-]{2,100}$/, // For FullName, OrdainingBishop
            generalText: /^[a-zA-Z0-9\s.,'()&-]{3,150}$/, // For OrdinationLoc, SeminarySchool
            contact: /^(09|\+639|639)\d{2}[\s-]?\d{3}[\s-]?\d{4}$/,
            dateFormat: /^\d{4}-\d{2}-\d{2}$/, // For DOB, OrdinationDate
            status: /^(Active|Retired|On Leave)$/ // For Status
        };

        /*----- ADMIN PERMISSION [start]-----*/
        let adminAuthenticated = false;

        document.addEventListener('keydown', function (event) {
            if (event.key === "Escape") {
                if (document.getElementById("adminModal").style.display === "block" || document.getElementById("adminModal").style.display === "flex") {
                    closeAdminModal();
                }
            }
        });

        function openAdminModal() {
            document.getElementById("adminPassword").value = "";
            document.getElementById("adminModal").style.display = "flex"; // Use flex for consistency
        }

        function closeAdminModal() {
            document.getElementById("adminModal").style.display = "none";
            adminAuthenticated = false;
            document.getElementById("adminPassword").value = '';
            document.getElementById("hiddenAdminPassword").value = '';
            // No need to call resetRecordFields here as it's for the Add modal
        }

        function validateAdmin(event) {
            event.preventDefault();
            const inputPassword = document.getElementById("adminPassword").value;
            fetch("update_validation.php", { // Ensure this PHP file exists and correctly validates passwords
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
                        document.getElementById("hiddenAdminPassword").value = inputPassword; // Store for form submission
                        enableRowClickEdit();
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
            return false; // Prevent default form submission from admin modal
        }
        /*----- ADMIN PERMISSION [end]-----*/


        /*----- ADD/UPDATE MODAL GENERAL FUNCTIONS -----*/
        function showMessageModal(message) {
            const modal = document.getElementById("messageModal");
            const messageText = document.getElementById("messageModalText");
            messageText.textContent = message;
            modal.style.display = "flex";
        }

        /*----- ADD PRIEST RECORD -----*/
        const addPriestForm = document.getElementById('addPriestForm');
        const addPriestFields = {
            FullName: document.getElementById('addFullName'),
            DOB: document.getElementById('addDOB'),
            ContactInfo: document.getElementById('addContactInfo'),
            OrdinationDate: document.getElementById('addOrdinationDate'),
            OrdinationLoc: document.getElementById('addOrdinationLoc'),
            OrdainingBishop: document.getElementById('addOrdainingBishop'),
            SeminarySchool: document.getElementById('addSeminarySchool'),
            Status: document.getElementById('addStatus')
        };
        const addPriestFormState = {};
        const addSubmitButton = document.getElementById('addSubmitButton');

        function initializeAddPriestValidation() {
            if (!addPriestForm || !addSubmitButton) return;
            addSubmitButton.disabled = true; // Initially disable

            Object.keys(addPriestFields).forEach(fieldName => {
                const fieldElement = addPriestFields[fieldName];
                if (fieldElement) {
                    addPriestFormState[fieldName] = false;
                    const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'date') ? 'change' : 'input';
                    fieldElement.addEventListener(eventType, function() {
                        validatePriestField(fieldName, this.value, this, 'add');
                    });
                    fieldElement.addEventListener('blur', function() { // Validate on blur as well
                        validatePriestField(fieldName, this.value, this, 'add');
                    });
                }
            });

            addPriestForm.addEventListener('submit', function(e) {
                let formIsValid = true;
                Object.keys(addPriestFields).forEach(fieldName => {
                    const fieldElement = addPriestFields[fieldName];
                    if (fieldElement) {
                        validatePriestField(fieldName, fieldElement.value, fieldElement, 'add');
                        if (!addPriestFormState[fieldName]) formIsValid = false;
                    }
                });
                if (!formIsValid) {
                    e.preventDefault();
                    alert('Please correct all errors before submitting.');
                }
            });
        }

        function resetAddPriestForm() {
            if (addPriestForm) addPriestForm.reset();
            Object.keys(addPriestFields).forEach(fieldName => {
                const fieldElement = addPriestFields[fieldName];
                const errorElement = document.getElementById('add' + fieldName + 'Error');
                if (fieldElement) fieldElement.style.border = '';
                if (errorElement) errorElement.classList.add('hidden');
                addPriestFormState[fieldName] = false;
            });
            if(addSubmitButton) {
                addSubmitButton.disabled = true;
                addSubmitButton.style.backgroundColor = '#cccccc';
            }
        }

        /*----- UPDATE PRIEST RECORD -----*/
        const updatePriestForm = document.getElementById('updatePriestForm');
        const updatePriestFields = {
            FullName: document.getElementById('updateFullName'),
            DOB: document.getElementById('updateDOB'),
            ContactInfo: document.getElementById('updateContactInfo'),
            OrdinationDate: document.getElementById('updateOrdinationDate'),
            OrdinationLoc: document.getElementById('updateOrdinationLoc'),
            OrdainingBishop: document.getElementById('updateOrdainingBishop'),
            SeminarySchool: document.getElementById('updateSeminarySchool'),
            Status: document.getElementById('updateStatus')
            // PriestID is read-only, no direct validation needed for its input but server validates it.
        };
        const updatePriestFormState = {};
        const updateSubmitButton = document.getElementById('updateSubmitButton');


        function initializeUpdatePriestValidation() {
            if (!updatePriestForm || !updateSubmitButton) return;
            updateSubmitButton.disabled = true; // Initially disable

            Object.keys(updatePriestFields).forEach(fieldName => {
                const fieldElement = updatePriestFields[fieldName];
                if (fieldElement) {
                    updatePriestFormState[fieldName] = false;
                    const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'date') ? 'change' : 'input';
                    fieldElement.addEventListener(eventType, function() {
                        validatePriestField(fieldName, this.value, this, 'update');
                    });
                    fieldElement.addEventListener('blur', function() {
                        validatePriestField(fieldName, this.value, this, 'update');
                    });
                }
            });
            updatePriestForm.addEventListener('submit', function(e) {
                let formIsValid = true;
                Object.keys(updatePriestFields).forEach(fieldName => {
                    const fieldElement = updatePriestFields[fieldName];
                    if (fieldElement) {
                        validatePriestField(fieldName, fieldElement.value, fieldElement, 'update');
                        if (!updatePriestFormState[fieldName]) formIsValid = false;
                    }
                });
                if (!formIsValid) {
                    e.preventDefault();
                    alert('Please correct all errors before submitting the update.');
                }
            });
        }

        function resetUpdatePriestForm() {
            // PriestID should not be reset as it's key for update
            // if (updatePriestForm) updatePriestForm.reset(); // This would clear PriestID too
            Object.keys(updatePriestFields).forEach(fieldName => {
                const fieldElement = updatePriestFields[fieldName];
                const errorElement = document.getElementById('update' + fieldName + 'Error');
                if (fieldElement) {
                    if (fieldElement.tagName === 'SELECT') fieldElement.value = ''; else fieldElement.value = ''; // Clear fields
                    fieldElement.style.border = '';
                }
                if (errorElement) errorElement.classList.add('hidden');
                updatePriestFormState[fieldName] = false;
            });
            if(updateSubmitButton) {
                updateSubmitButton.disabled = true;
                updateSubmitButton.style.backgroundColor = '#cccccc';
            }
        }

        document.getElementById("updateRecordBtn").onclick = function () {
            adminAuthenticated = false; // Require admin auth each time
            openAdminModal();
            // Don't reset fields or open update modal here; happens after admin auth
        };

        function enableRowClickEdit() {
            const rows = document.querySelectorAll("#recordsTable tbody tr");
            rows.forEach(row => {
                row.style.cursor = "pointer";
                row.onclick = function () {
                    if (!adminAuthenticated) {
                        showMessageModal("Admin authentication required to edit.");
                        return;
                    }
                    const cells = row.querySelectorAll("td");
                    document.getElementById("updatePriestID").value = cells[0].innerText.trim(); // Readonly

                    // Populate fields for update modal
                    if(updatePriestFields.FullName) updatePriestFields.FullName.value = cells[1].innerText.trim();
                    if(updatePriestFields.DOB) updatePriestFields.DOB.value = cells[2].innerText.trim();
                    if(updatePriestFields.ContactInfo) updatePriestFields.ContactInfo.value = cells[3].innerText.trim();
                    if(updatePriestFields.OrdinationDate) updatePriestFields.OrdinationDate.value = cells[4].innerText.trim();
                    if(updatePriestFields.OrdinationLoc) updatePriestFields.OrdinationLoc.value = cells[5].innerText.trim();
                    if(updatePriestFields.OrdainingBishop) updatePriestFields.OrdainingBishop.value = cells[6].innerText.trim();
                    if(updatePriestFields.SeminarySchool) updatePriestFields.SeminarySchool.value = cells[7].innerText.trim();
                    if(updatePriestFields.Status) updatePriestFields.Status.value = cells[8].innerText.trim();

                    // Trigger validation for populated fields
                    Object.keys(updatePriestFields).forEach(fieldName => {
                        const fieldElement = updatePriestFields[fieldName];
                        if(fieldElement) validatePriestField(fieldName, fieldElement.value, fieldElement, 'update');
                    });

                    document.getElementById("updateModal").style.display = "flex";
                };
            });
        }

        function disableRowClickEdit() {
            const rows = document.querySelectorAll("#recordsTable tbody tr");
            rows.forEach(row => {
                row.onclick = null;
                row.style.cursor = "default";
            });
        }

        function closeUpdateModal() {
            document.getElementById("updateModal").style.display = "none";
            adminAuthenticated = false; // Important: reset auth after modal closes
            disableRowClickEdit();
            resetUpdatePriestForm(); // Clear fields
        }

        /*----- COMMON PRIEST FIELD VALIDATION FUNCTION -----*/
        function validatePriestField(fieldName, value, fieldElement, formTypePrefix) { // formTypePrefix is 'add' or 'update'
            let isValid = false;
            const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
            const currentFormState = (formTypePrefix === 'add') ? addPriestFormState : updatePriestFormState;
            const currentFields = (formTypePrefix === 'add') ? addPriestFields : updatePriestFields;
            const submitBtn = (formTypePrefix === 'add') ? addSubmitButton : updateSubmitButton;

            if (!errorElement) {
                console.error("Error element not found for:", formTypePrefix + fieldName + 'Error');
                return;
            }
            value = value.trim();
            let defaultErrorMsg = fieldName.replace(/([A-Z])/g, ' $1').trim() + ' is required.';
            let specificErrorMsg = '';

            switch(fieldName) {
                case 'FullName':
                case 'OrdainingBishop':
                    isValid = priestRegexPatterns.name.test(value);
                    specificErrorMsg = 'Invalid name format. Use letters, spaces, periods, apostrophes, hyphens (2-100 chars).';
                    break;
                case 'OrdinationLoc':
                case 'SeminarySchool':
                    isValid = priestRegexPatterns.generalText.test(value);
                    specificErrorMsg = 'Invalid format. Use letters, numbers, and common punctuation (3-150 chars).';
                    break;
                case 'ContactInfo':
                    isValid = priestRegexPatterns.contact.test(value); // Uses the updated regex
                    specificErrorMsg = 'Invalid Philippine Phone Number. Examples: 09171234567, +639171234567.';
                    defaultErrorMsg = 'Contact Info (Philippine Phone Number) is required.'; // Update default message
                    break;
                case 'DOB':
                case 'OrdinationDate':
                    const isFormatValid = priestRegexPatterns.dateFormat.test(value);
                    if (!isFormatValid) {
                        isValid = false;
                        specificErrorMsg = value === '' ? defaultErrorMsg : 'Date format must be YYYY-MM-DD.';
                    } else {
                        const selectedDate = new Date(value + "T00:00:00"); // Ensure local date
                        const today = new Date();
                        today.setHours(0,0,0,0);

                        if (selectedDate >= today) {
                            isValid = false;
                            specificErrorMsg = 'Date must be in the past.';
                        } else {
                            if (fieldName === 'OrdinationDate') {
                                const dobField = currentFields.DOB; // Get DOB from the current form's fields
                                const dobValue = dobField ? dobField.value : null;
                                if (dobValue && priestRegexPatterns.dateFormat.test(dobValue)) {
                                    const dobDate = new Date(dobValue + "T00:00:00");
                                    const minOrdinationAge = new Date(dobDate);
                                    minOrdinationAge.setFullYear(dobDate.getFullYear() + 18); // Min 18 years
                                    if (selectedDate < minOrdinationAge) {
                                        isValid = false;
                                        specificErrorMsg = 'Ordination Date must be at least 18 years after Date of Birth.';
                                    } else {
                                        isValid = true;
                                    }
                                } else { // DOB not valid or not entered yet
                                    isValid = true; // Assume valid for now, will be re-validated if DOB changes
                                }
                            } else { // For DOB
                                isValid = true;
                                // If DOB changes, re-validate OrdinationDate
                                const ordinationDateField = currentFields.OrdinationDate;
                                if (ordinationDateField && ordinationDateField.value) {
                                    validatePriestField('OrdinationDate', ordinationDateField.value, ordinationDateField, formTypePrefix);
                                }
                            }
                        }
                    }
                    break;
                case 'Status':
                    isValid = priestRegexPatterns.status.test(value) && value !== "";
                    specificErrorMsg = 'Invalid status selected.';
                    break;
                default:
                    isValid = true; // Should not happen for defined fields
            }

            currentFormState[fieldName] = isValid;
            if (fieldElement) {
                if (isValid) {
                    fieldElement.style.border = '2px solid green';
                    errorElement.classList.add('hidden');
                } else {
                    fieldElement.style.border = '2px solid red';
                    errorElement.classList.remove('hidden');
                    // Use the updated defaultErrorMsg for the 'required' case
                    errorElement.textContent = (value === '') ? defaultErrorMsg : specificErrorMsg;
                }
            }
            checkPriestFormOverallValidity(formTypePrefix);
        }

        function checkPriestFormOverallValidity(formTypePrefix) {
            const currentFormState = (formTypePrefix === 'add') ? addPriestFormState : updatePriestFormState;
            const currentFields = (formTypePrefix === 'add') ? addPriestFields : updatePriestFields;
            const submitBtn = (formTypePrefix === 'add') ? addSubmitButton : updateSubmitButton;

            if (!submitBtn) return;

            const allValid = Object.keys(currentFields).every(fieldName => {
                // PriestID is not in updatePriestFields for direct validation so skip if not present
                return currentFields[fieldName] ? currentFormState[fieldName] === true : true;
            });

            if (allValid) {
                submitBtn.disabled = false;
                submitBtn.style.backgroundColor = (formTypePrefix === 'add') ? '#28a745' : '#F39C12';
            } else {
                submitBtn.disabled = true;
                submitBtn.style.backgroundColor = '#cccccc';
            }
        }


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
    resetAddPriestForm();  // Reset form fields and hide validation errors
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


    /*----- GENERAL UI & NAVIGATION -----*/
    // function toggleDropdown() { // For sidebar menu
    //     const dropdown = document.getElementById("certificateDropdown");
    //     const certificatesItem = document.getElementById("certificates");
    //     const icon = certificatesItem?.querySelector("#dropdownIcon");

    //     if (!dropdown || !certificatesItem || !icon) return;

    //     const isOpen = dropdown.classList.toggle("dropdown-active");
    //     certificatesItem.classList.toggle("open", isOpen);
    //     icon.classList.toggle("rotated", isOpen);
    //     icon.textContent = isOpen ? '▼' : '▶';
    // }

    // Navigation Event Listeners
    document.getElementById("dashboardButton")?.addEventListener("click", () => window.location.href = "dashboard.php");
    document.getElementById("priestButton")?.addEventListener("click", () => window.location.href = "priestrecords.php");
    document.getElementById("eventsButton")?.addEventListener("click", () => window.location.href = "event.php");
    document.getElementById("massButton")?.addEventListener("click", () => window.location.href = "massSchedule.php");
    document.getElementById("baptismalButton")?.addEventListener("click", () => window.location.href = "baptismal.php");
    document.getElementById("MarriageButton")?.addEventListener("click", () => window.location.href = "marriage.php");
    document.getElementById("burialButton")?.addEventListener("click", () => window.location.href = "burial.php");
    document.getElementById("confirmationButton")?.addEventListener("click", () => window.location.href = "confirmation.php");
    document.getElementById("clientButton")?.addEventListener("click", () => window.location.href = "client.php");

    // Search Functionality
    document.getElementById("searchInput")?.addEventListener("keyup", function () {
        const filter = this.value.toLowerCase();
        const rows = document.querySelectorAll("#recordsTable tbody tr");
        rows.forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(filter) ? "" : "none";
        });
    });


    /*----- INITIALIZE VALIDATIONS ON DOM LOADED -----*/
        document.addEventListener('DOMContentLoaded', function() {
            initializeAddPriestValidation();
            initializeUpdatePriestValidation();

            // --- START: New Filter JavaScript for priestrecords.php ---
            const categoryFilter_priest = document.getElementById('categoryFilterPriest');
            const yearInputContainer_priest = document.getElementById('filterYearInputContainerPriest');
            const monthInputContainer_priest = document.getElementById('filterMonthInputContainerPriest');
            const dateInputContainer_priest = document.getElementById('filterDateInputContainerPriest');

            const yearValueInput_priest = document.getElementById('filterYearValuePriest');
            const yearForMonthValueInput_priest = document.getElementById('filterYearForMonthValuePriest');
            const monthValueSelect_priest = document.getElementById('filterMonthValuePriest');
            const dateValueInput_priest = document.getElementById('filterDateValuePriest'); // This is type="date"

            const applyFilterButton_priest = document.getElementById('applyFilterBtnPriest');
            const clearFilterButton_priest = document.getElementById('clearFilterBtnPriest');
            const searchInput_priest = document.getElementById('searchInput');

            function toggleFilterInputs_priest() {
                if (!categoryFilter_priest) return;
                const selectedFilter = categoryFilter_priest.value;

                if(yearInputContainer_priest) yearInputContainer_priest.style.display = 'none';
                if(monthInputContainer_priest) monthInputContainer_priest.style.display = 'none';
                if(dateInputContainer_priest) dateInputContainer_priest.style.display = 'none';

                if (selectedFilter === 'year' && yearInputContainer_priest) {
                    yearInputContainer_priest.style.display = 'inline-block';
                } else if (selectedFilter === 'month' && monthInputContainer_priest) {
                    monthInputContainer_priest.style.display = 'inline-block';
                } else if (selectedFilter === 'specific_date' && dateInputContainer_priest) {
                    dateInputContainer_priest.style.display = 'inline-block';
                }
            }

            if (categoryFilter_priest) {
                categoryFilter_priest.addEventListener('change', toggleFilterInputs_priest);
            }

            if (applyFilterButton_priest) {
                applyFilterButton_priest.addEventListener('click', function() {
                    if (!categoryFilter_priest) return;
                    const filterType = categoryFilter_priest.value;
                    if (!filterType) return;

                    let queryParams = new URLSearchParams();
                    queryParams.set('filter_type_priest', filterType);

                    if (filterType === 'year') {
                        if (!yearValueInput_priest || !yearValueInput_priest.value || !/^\d{4}$/.test(yearValueInput_priest.value)) {
                            alert('Please enter a valid 4-digit year.'); return;
                        }
                        queryParams.set('filter_year_value_priest', yearValueInput_priest.value);
                    } else if (filterType === 'month') {
                        if (!monthValueSelect_priest || !monthValueSelect_priest.value) {
                            alert('Please select a month.'); return;
                        }
                        queryParams.set('filter_month_value_priest', monthValueSelect_priest.value);
                        if (yearForMonthValueInput_priest && yearForMonthValueInput_priest.value) {
                            if (!/^\d{4}$/.test(yearForMonthValueInput_priest.value)) {
                                alert('If providing a year for the month, please enter a valid 4-digit year.'); return;
                            }
                            queryParams.set('filter_year_for_month_value_priest', yearForMonthValueInput_priest.value);
                        }
                    } else if (filterType === 'specific_date') {
                        if (!dateValueInput_priest || !dateValueInput_priest.value) { // type="date" gives YYYY-MM-DD
                            alert('Please select a date.'); return;
                        }
                        if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValueInput_priest.value)) {
                            alert('Invalid date format. Expected YYYY-MM-DD.'); return;
                        }
                        queryParams.set('filter_date_value_priest', dateValueInput_priest.value);
                    } else if (filterType === 'oldest_to_latest') {
                        queryParams.set('sort_order_priest', 'asc');
                    } else if (filterType === 'latest_to_oldest') {
                        queryParams.set('sort_order_priest', 'desc');
                    }
                    window.location.search = queryParams.toString();
                });
            }

            if (clearFilterButton_priest) {
                clearFilterButton_priest.addEventListener('click', function(event) {
                    event.preventDefault();
                    if (searchInput_priest) {
                        searchInput_priest.value = '';
                    }
                    window.location.href = window.location.pathname;
                });
            }

            function setFiltersFromUrl_priest() {
                if (!categoryFilter_priest) return;
                const urlParams = new URLSearchParams(window.location.search);
                const filterTypeFromUrl = urlParams.get('filter_type_priest');

                categoryFilter_priest.value = "";
                if(yearValueInput_priest) yearValueInput_priest.value = "";
                if(yearForMonthValueInput_priest) yearForMonthValueInput_priest.value = "";
                if(monthValueSelect_priest) monthValueSelect_priest.value = "";
                if(dateValueInput_priest) dateValueInput_priest.value = ""; // type="date"
                toggleFilterInputs_priest();

                if (filterTypeFromUrl) {
                    categoryFilter_priest.value = filterTypeFromUrl;
                    toggleFilterInputs_priest();

                    if (filterTypeFromUrl === 'year' && urlParams.has('filter_year_value_priest') && yearValueInput_priest) {
                        yearValueInput_priest.value = urlParams.get('filter_year_value_priest');
                    } else if (filterTypeFromUrl === 'month') {
                        if (urlParams.has('filter_month_value_priest') && monthValueSelect_priest) {
                            monthValueSelect_priest.value = urlParams.get('filter_month_value_priest');
                        }
                        if (urlParams.has('filter_year_for_month_value_priest') && yearForMonthValueInput_priest) {
                            yearForMonthValueInput_priest.value = urlParams.get('filter_year_for_month_value_priest');
                        }
                    } else if (filterTypeFromUrl === 'specific_date' && urlParams.has('filter_date_value_priest') && dateValueInput_priest) {
                        dateValueInput_priest.value = urlParams.get('filter_date_value_priest');
                    }
                } else if (urlParams.has('sort_order_priest')) {
                    const sortOrder = urlParams.get('sort_order_priest');
                    if (sortOrder === 'asc') categoryFilter_priest.value = 'oldest_to_latest';
                    if (sortOrder === 'desc') categoryFilter_priest.value = 'latest_to_oldest';
                }
            }

            setFiltersFromUrl_priest(); // Call on page load
            // --- END: New Filter JavaScript for priestrecords.php ---
        });

        /*----- INITIALIZE VALIDATIONS ON DOM LOADED -----*/
        document.addEventListener('DOMContentLoaded', function() {
            initializeAddPriestValidation();
            initializeUpdatePriestValidation();
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