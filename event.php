<?php

// Prevent caching of the page after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$pageTitle = "Events & Sacraments Management";
require_once 'session_timeout.php'; 

// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // If not logged in, redirect to the login page
    header("Location: Log_In\login_system.php");
    exit();
}

// --- START: Filter Logic for GET requests (Event Records) ---
$whereClauses = [];
$orderByClause = "ORDER BY er.EventID DESC"; // Default order for event records
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
                        $whereClauses[] = "YEAR(er.Date) = ?";
                        $filter_params[] = $year;
                        $filter_param_types .= "i";
                    }
                }
                break;
            case 'month':
                if (isset($_GET['filter_month_value']) && !empty($_GET['filter_month_value'])) {
                    $month = filter_var($_GET['filter_month_value'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 12]]);
                    if ($month) {
                        $whereClauses[] = "MONTH(er.Date) = ?";
                        $filter_params[] = $month;
                        $filter_param_types .= "i";

                        // Optional: Add year context if provided for month filter
                        if (isset($_GET['filter_year_for_month_value']) && !empty($_GET['filter_year_for_month_value'])) {
                            $year_for_month = filter_var($_GET['filter_year_for_month_value'], FILTER_VALIDATE_INT);
                            if ($year_for_month && strlen((string)$year_for_month) == 4) {
                                $whereClauses[] = "YEAR(er.Date) = ?";
                                $filter_params[] = $year_for_month;
                                $filter_param_types .= "i";
                            }
                        }
                    }
                }
                break;
            case 'specific_date':
                if (isset($_GET['filter_date_value']) && !empty($_GET['filter_date_value'])) {
                    $date_str = $_GET['filter_date_value']; // Expected YYYY-MM-DD from <input type="date">
                    // Validate the date format
                    $d = DateTime::createFromFormat('Y-m-d', $date_str);
                    if ($d && $d->format('Y-m-d') === $date_str) {
                        $whereClauses[] = "er.Date = ?";
                        $filter_params[] = $date_str;
                        $filter_param_types .= "s";
                    }
                }
                break;
            case 'oldest_to_latest':
                $orderByClause = "ORDER BY er.Date ASC, er.EventID ASC"; // Secondary sort by ID for tie-breaking
                break;
            case 'latest_to_oldest':
                $orderByClause = "ORDER BY er.Date DESC, er.EventID DESC"; // Secondary sort by ID
                break;
        }
    }
    // Handle standalone sort_order parameter if filter_type is not set
    if (isset($_GET['sort_order'])) {
        if ($_GET['sort_order'] === 'asc' && $filter_type !== 'oldest_to_latest') {
            $orderByClause = "ORDER BY er.Date ASC, er.EventID ASC";
        } elseif ($_GET['sort_order'] === 'desc' && $filter_type !== 'latest_to_oldest') {
            $orderByClause = "ORDER BY er.Date DESC, er.EventID DESC";
        }
    }
}
// --- END: Filter Logic (Event Records) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

    if ($conn->connect_error) {
        error_log("Database Connection Failed: " . $conn->connect_error);
        echo "<script>alert('Error: Could not connect to the database. Please try again later.'); window.history.back();</script>";
        exit();
    }

    $eventType = trim($_POST['EventType'] ?? '');
    $date      = trim($_POST['Date'] ?? '');
    $priestID  = trim($_POST['OfficiatingPriestID'] ?? '');
    $eventID   = null; // For updates

    // --- SERVER-SIDE VALIDATION ---
    $errors = [];

    // EventID validation (only if it's an update action)
    if (isset($_POST['updateRecord'])) {
        $eventID = trim($_POST['EventID'] ?? ''); // Get EventID for update
        if (empty($eventID)) {
            $errors[] = "Event ID is required for an update.";
        } elseif (!ctype_digit($eventID)) { // Assuming EventID is an integer
            $errors[] = "Invalid Event ID format for update.";
        }
    }

    // Validate EventType
    if (empty($eventType)) {
        $errors[] = "Event Type is required.";
    } elseif (!preg_match('/^(Baptism|Wedding|Funeral|Confirmation)$/i', $eventType)) {
        $errors[] = "Invalid Event Type submitted. Allowed: Baptism, Wedding, Funeral, Confirmation.";
    }

    // Validate Date
    if (empty($date)) {
        $errors[] = "Date is required.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = "Invalid Date format submitted. Expected YYYY-MM-DD.";
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $errors[] = "Invalid Date value (e.g., day or month out of range).";
        } else {
            $today = new DateTime();
            $today->setTime(0,0,0); // Compare dates only
            $selectedDateObj = new DateTime($date);
            $selectedDateObj->setTime(0,0,0);

            // For 'add' or 'update', date must be today or in the future
            if ($selectedDateObj < $today) {
                $errors[] = "Date must be today or in the future.";
            }
        }
    }

    // Validate OfficiatingPriestID
    if (empty($priestID)) {
        $errors[] = "Officiating Priest is required.";
    } elseif (!ctype_digit($priestID)) { // Assuming PriestID is an integer from the select value
        $errors[] = "Invalid Priest ID format.";
    }

    if (!empty($errors)) {
        $errorString = implode("\\n", $errors);
        echo "<script>alert('Server Validation Errors:\\n" . htmlspecialchars($errorString, ENT_QUOTES) . "'); window.history.back();</script>";
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


    if (isset($_POST['submitRecord'])) {
        $sql = "INSERT INTO event_records (EventType, Date, OfficiatingPriestID, ParishStaffID)
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("SQL Prepare Error (Insert): " . $conn->error);
            echo "<script>alert('Error preparing the record. Please try again.'); window.history.back();</script>";
            $conn->close();
            exit();
        }
        $stmt->bind_param("ssii", $eventType, $date, $priestID, $parishStaffID);

        if ($stmt->execute()) {
            echo "<script>alert('Record inserted successfully!'); window.location.href = window.location.href;</script>";
        } else {
            error_log("SQL Execute Error (Insert): " . $stmt->error);
            echo "<script>alert('Error inserting record: " . htmlspecialchars($stmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
        }
        $stmt->close();
        $conn->close();
        exit();

    } elseif (isset($_POST['updateRecord'])) {
        // Updating event but ParishStaffID usually doesn't change on update (if you want, you can add logic to update it)
        $sql = "UPDATE event_records
                SET EventType=?, Date=?, OfficiatingPriestID=?
                WHERE EventID=?";
        $updateStmt = $conn->prepare($sql);
        if ($updateStmt === false) {
            error_log("SQL Prepare Error (Update): " . $conn->error);
            echo "<script>alert('Error preparing the update. Please try again.'); window.history.back();</script>";
            $conn->close();
            exit();
        }
        $updateStmt->bind_param("ssii", $eventType, $date, $priestID, $eventID);

        if ($updateStmt->execute()) {
            echo "<script>alert('Record updated successfully!'); window.location.href = window.location.href;</script>";
        } else {
            error_log("SQL Execute Error (Update): " . $updateStmt->error);
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
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" href="/imagess/sacred.png">
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="stylesheet" href="eventstyle.css?v=14">
    <!-- Add these two lines for responsiveness -->
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="eventResponsive.css?v=6">
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
        <div class="section-title">Event Records</div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search..." style="flex-grow: 1;">

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
                    <input type="number" id="filterYearForMonthValue" name="filter_year_for_month_value" placeholder="YYYY (Opt.)">
                    <select id="filterMonthValue" name="filter_month_value">
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
                <div id="filterDateInputContainer" class="filter-input-group" style="display:none;">
                    <input type="date" id="filterDateValue" name="filter_date_value"> <!-- Easier date input -->
                </div>
                <button id="applyFilterBtn" class="filter-btn">Apply</button>
                <button id="clearFilterBtn" class="filter-btn">Clear</button>
            </div>
            <!-- END: Category Filter -->

            <div class="record-buttons" style="margin-left: auto;">
                <button id="updateRecordBtn">✎ Update Record</button>
                <button id="addRecordBtn">+ Add Record</button>
            </div>
        </div>

        <div class="table-scroll">
            <table id="recordsTable">
                <thead>
                <tr>
                    <th>Event ID</th>
                    <th>Event Type</th>
                    <th>Date</th>
                    <th>Officiating Priest</th>
                    <th>Created By</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error);
                }

                // $sql = "SELECT * FROM event_records";
                // START MODIFY FOR FILTER
                $baseSql = "SELECT 
                            er.*,
                            p.FullName AS PriestName,
                            COALESCE(au.username, su.username, 'Unknown') AS CreatedBy
                        FROM event_records er
                        LEFT JOIN priest p ON er.OfficiatingPriestID = p.PriestID
                        LEFT JOIN parishstaff ps ON er.ParishStaffID = ps.ParishStaffID
                        LEFT JOIN admin_users au ON ps.AdminUserID = au.ID
                        LEFT JOIN staff_users su ON ps.StaffUserID = su.id";

                $finalSql = $baseSql;

                if (!empty($whereClauses)) {
                    $finalSql .= " WHERE " . implode(" AND ", $whereClauses);
                }
                $finalSql .= " " . $orderByClause;

                $result = null; // Initialize result

                // Prepare and execute the statement if there are filter parameters
                if (!empty($filter_params)) {
                    $stmt = $conn->prepare($finalSql);
                    if ($stmt === false) {
                        error_log("SQL Prepare Error (Filter Event): " . $conn->error . " | SQL: " . $finalSql);
                        echo "<tr><td colspan='5'>Error preparing data to display.</td></tr>";
                    } else {
                        $stmt->bind_param($filter_param_types, ...$filter_params);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result === false) {
                            error_log("SQL Get Result Error (Filter Event): " . $stmt->error);
                            echo "<tr><td colspan='5'>Error retrieving filtered data.</td></tr>";
                        }
                    }
                } else { // Execute directly if no parameters (e.g., only default order by)
                    $result = $conn->query($finalSql);
                    if ($result === false) {
                        error_log("SQL Query Error (Event): " . $conn->error . " | SQL: " . $finalSql);
                        echo "<tr><td colspan='5'>Error fetching data to display.</td></tr>";
                    }
                }

                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        // Added htmlspecialchars for security
                        echo "<tr data-priest-id='" . htmlspecialchars($row["OfficiatingPriestID"] ?? '') . "'>";
                        echo "<td>" . htmlspecialchars($row["EventID"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["EventType"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["Date"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["PriestName"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["CreatedBy"] ?? '-') . "</td>";
                        echo "</tr>";
                    }
                } else if ($result) { // $result is valid (not false) but no rows found
                    echo "<tr><td colspan='5'>No event records found matching your criteria.</td></tr>";
                }
                 $conn->close(); // END FILTER
                ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal" id="recordModal">
        <form class="modal-content" id="eventForm" method="POST" action="" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>

            <div class="modal-header" style="background: #2c3e50; color: white; text-align: center; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Event Details</h3>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">


                <div style="flex: 1 1 45%;" class="form-group">
                    <label for="EventType" style="margin-left: 55px;">Event Type:</label><br>
                    <input type="text" id="EventType" name="EventType" required 
                        placeholder="e.g., Baptism, Wedding, Funeral"
                        style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="EventTypeError" class="error-message hidden" style="display: none; margin-left: 55px; color: red; font-size: 12px; margin-top: 5px;">
                        Event Type must be one of: Baptism, Wedding, Funeral, Confirmation
                    </small>
                </div>

                <div style="flex: 1 1 45%;" class="form-group">
                    <label for="Date" style="margin-left: 30px;">Date:</label><br>
                    <input type="date" id="Date" name="Date" required 
                        style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="DateError" class="error-message hidden" style="display: none; margin-left: 30px; color: red; font-size: 12px; margin-top: 5px;">
                        Date must be today or in the future
                    </small>
                </div>

                <!-- <div style="flex: 1 1 45%;">
                    <label for="OfficiatingPriestID" style="margin-left: 55px;">Officiating Priest ID:</label><br>
                    <input type="text" id="OfficiatingPriestID" name="OfficiatingPriestID" required style="width: 39.9%; padding: 5px; margin-left: 55px;">
                        <small style="display: block; margin-left: 55px; margin-top: 5px; color: gray;">
                            Format Example: PRT001
                        </small>
                </div> -->

                <div style="flex: 1 1 45%;">
                    <label for="OfficiatingPriestID" style="margin-left: 55px;">Select Priest:</label><br>
                    <select id="OfficiatingPriestID" name="OfficiatingPriestID" required style="width: 39.9%; padding: 5px; margin-left: 55px;">
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
                    <small id="PriestIDError" class="error-message hidden" style="display: none; margin-left: 55px; color: red; font-size: 12px; margin-top: 5px;">
                        Please select a priest.
                    </small>
                </div>
            </div>

            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" name="submitRecord" id="submitButton" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">+ Add Record</button>
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
        <form class="modal-content" id="updateEventForm" method="POST" action="event.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeUpdateModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>

            <div class="modal-header" style="background: #F39C12; color: white; text-align: center; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Update Event Record</h3>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">

                <div style="flex: 1 1 45%;">
                    <label for="updateEventID" style="margin-left: 55px;">Event ID:</label><br>
                    <input type="text" id="updateEventID" name="EventID" readonly style="width: 80%; padding: 5px; margin-left: 55px; background-color: #e9e9e9;">
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateEventType" style="margin-left: 30px;">Event Type:</label><br>
                    <input type="text" id="updateEventType" name="EventType" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateEventTypeError" class="error-message hidden" style="display: block; margin-left: 30px; color: red; font-size: 12px; margin-top: 5px;">Event Type must be one of: Baptism, Wedding, Funeral, Confirmation</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateDate" style="margin-left: 55px;">Date:</label><br>
                    <input type="date" id="updateDate" name="Date" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateDateError" class="error-message hidden" style="display: block; margin-left: 55px; color: red; font-size: 12px; margin-top: 5px;">Date must be today or in the future</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updatePriestID" style="margin-left: 30px;">Select Priest:</label><br>
                    <select name="OfficiatingPriestID" id="updatePriestID" required style="width: 80%; padding: 5px; margin-left: 30px;">
                        <option value="">-- Select Priest --</option>
                        <?php
                        // Database connection
                        $connLocal = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS"); // Use a different var name if $conn is in wider scope
                        if ($connLocal->connect_error) {
                            // die("Connection failed: " . $connLocal->connect_error); // Avoid die in HTML rendering part
                            echo "<option disabled>Error loading priests</option>";
                        } else {
                            $priestSql = "SELECT PriestID, FullName, ContactInfo, Status FROM Priest ORDER BY FullName";
                            $priestResult = $connLocal->query($priestSql);

                            if ($priestResult && $priestResult->num_rows > 0) {
                                while ($priest = $priestResult->fetch_assoc()) {
                                    $status = !empty($priest["Status"]) ? $priest["Status"] : "Active";
                                    $contact = !empty($priest["ContactInfo"]) ? $priest["ContactInfo"] : "No contact info";
                                    echo "<option value='" . htmlspecialchars($priest["PriestID"]) . "'>" .
                                        htmlspecialchars($priest["FullName"]) . " | " .
                                        htmlspecialchars($contact) . " | " .
                                        htmlspecialchars($status) .
                                        "</option>";
                                }
                            } else {
                                echo "<option disabled>No priests found</option>";
                            }
                            $connLocal->close();
                        }
                        ?>
                    </select>
                    <small id="updatePriestIDError" class="error-message hidden" style="display: block; margin-left: 30px; color: red; font-size: 12px; margin-top: 5px;">Please select a priest.</small>
                </div>
            </div>

            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" name="updateRecord" id="submitUpdateRecordBtn" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px;">✎ Update Record</button>
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



<!------- JAVASCRIPT ------->

<script>

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

    // Reset the record fields when modal is closed
    function resetRecordFields() {
        document.getElementById("EventID").value = '';
        document.getElementById("EventType").value = '';
        document.getElementById("Date").value = '';
        document.getElementById("OfficiatingPriestID").value = '';
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

    // Enable row click for editing
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
                const priestIdFromRow = row.dataset.priestId; // Get priest ID from data-attribute

                document.getElementById("updateEventID").value = cells[0].innerText.trim();
                document.getElementById("updateEventType").value = cells[1].innerText.trim();
                document.getElementById("updateDate").value = cells[2].innerText.trim();
                
                

                const updatePriestSelect = document.getElementById("updatePriestID");
                updatePriestSelect.value = priestIdFromRow;
                // Fallback if priest ID from row isn't in select options (e.g., priest was deleted)
                if (updatePriestSelect.value !== priestIdFromRow && priestIdFromRow) {
                    console.warn(`Priest ID "${priestIdFromRow}" from row not found in select. Defaulting.`);
                    // Optionally, you could add a temporary <option> or alert the user
                    // For now, it will default to the first option or be blank if "" is not an option
                    updatePriestSelect.value = ""; // Or the value of your "-- Select Priest --" option
                }


                document.getElementById("updateModal").style.display = "flex";
                triggerInitialUpdateValidation(); // Validate fields after populating
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
    /*----- UPDATE RECORDS [end]-----*/

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
    
    // Reset the form
    const eventForm = document.getElementById('eventForm');
    if (eventForm) {
        eventForm.reset();
    }
    
    // Reset validation states
    Object.keys(formState).forEach(key => formState[key] = false);
    
    // Hide all error messages
    document.getElementById('EventTypeError').style.display = 'none';
    document.getElementById('DateError').style.display = 'none';
    document.getElementById('PriestIDError').style.display = 'none';
    
    // Reset field borders
    document.getElementById('EventType').style.border = '';
    document.getElementById('Date').style.border = '';
    document.getElementById('OfficiatingPriestID').style.border = '';
    
    // Reset submit button
    document.getElementById('submitButton').disabled = true;
    document.getElementById('submitButton').style.backgroundColor = '#cccccc';
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

    /*----- ADD RECORDS validation [start]-----*/
    // Validation patterns and allowed values
    const regexPatterns = { // Renamed for clarity
        eventType: /^(Baptism|Wedding|Funeral|Confirmation)$/i, // Case-insensitive
        dateFormat: /^\d{4}-\d{2}-\d{2}$/,
        // For Priest ID, if it were a text input with a specific format like PRT001:
        // priestIdFormat: /^PRT\d{3}$/i
    };

    // Form validation state
    const formState = {
        eventType: false,
        date: false, // This will now be for both format and logic
        priestID: false // For <select>, this means "is selected"
    };

    // Add validation event listeners
    document.addEventListener('DOMContentLoaded', function() {
        const eventForm = document.getElementById('eventForm');
        if (eventForm) {
            const eventTypeField = document.getElementById('EventType');
            const dateField = document.getElementById('Date');
            const priestIDField = document.getElementById('OfficiatingPriestID'); // This is a <select>

            // Event Type validation
            eventTypeField.addEventListener('input', function() {
                validateField('eventType', this.value, this);
            });
            eventTypeField.addEventListener('blur', function() {
                validateField('eventType', this.value, this);
            });

            // Date validation
            dateField.addEventListener('input', function() {
                validateField('date', this.value, this);
            });
            dateField.addEventListener('blur', function() {
                validateField('date', this.value, this);
            });

            // Priest ID validation (for <select>, it's mainly about being selected)
            priestIDField.addEventListener('change', function() {
                validateField('priestID', this.value, this);
            });
            priestIDField.addEventListener('blur', function() {
                validateField('priestID', this.value, this);
            });


            eventForm.addEventListener('submit', function(e) {
                // Re-validate all on submit attempt
                validateField('eventType', eventTypeField.value, eventTypeField);
                validateField('date', dateField.value, dateField);
                validateField('priestID', priestIDField.value, priestIDField);

                if (!isFormValid()) {
                    e.preventDefault();
                    alert('Please correct all errors before submitting.');
                }
            });

            // Reset form when opening modal
            const addRecordBtn = document.getElementById("addRecordBtn");
            if (addRecordBtn) {
                addRecordBtn.onclick = function() {
                    document.getElementById("recordModal").style.display = "flex";
                    eventForm.reset();

                    // Reset field visual feedback and error messages
                    [eventTypeField, dateField, priestIDField].forEach(field => {
                        field.style.border = '';
                        const errorElementId = field.id + 'Error';
                        const errorElement = document.getElementById(errorElementId);
                        if (errorElement) {
                           errorElement.style.display = 'none';

                        }
                    });

                    Object.keys(formState).forEach(key => formState[key] = false);
                    document.getElementById('submitButton').disabled = true;
                    document.getElementById('submitButton').style.backgroundColor = '#cccccc';
                };
            }
        }

    // --- START: New Filter JavaScript for event.php ---
        const categoryFilterSelect_event = document.getElementById('categoryFilter'); // Suffix to avoid conflict if reusing names
        const yearInputContainer_event = document.getElementById('filterYearInputContainer');
        const monthInputContainer_event = document.getElementById('filterMonthInputContainer');
        const dateInputContainer_event = document.getElementById('filterDateInputContainer');

        const yearValueInput_event = document.getElementById('filterYearValue');
        const yearForMonthValueInput_event = document.getElementById('filterYearForMonthValue');
        const monthValueSelect_event = document.getElementById('filterMonthValue');
        const dateValueInput_event = document.getElementById('filterDateValue'); // This is type="date"

        const applyFilterButton_event = document.getElementById('applyFilterBtn');
        const clearFilterButton_event = document.getElementById('clearFilterBtn');
        const searchInput_event = document.getElementById('searchInput'); // For clearing text search

        function toggleFilterInputs_event() {
            if (!categoryFilterSelect_event) return;

            const selectedFilter = categoryFilterSelect_event.value;
            if(yearInputContainer_event) yearInputContainer_event.style.display = 'none';
            if(monthInputContainer_event) monthInputContainer_event.style.display = 'none';
            if(dateInputContainer_event) dateInputContainer_event.style.display = 'none';

            if (selectedFilter === 'year' && yearInputContainer_event) {
                yearInputContainer_event.style.display = 'inline-block';
            } else if (selectedFilter === 'month' && monthInputContainer_event) {
                monthInputContainer_event.style.display = 'inline-block';
            } else if (selectedFilter === 'specific_date' && dateInputContainer_event) {
                dateInputContainer_event.style.display = 'inline-block';
            }
        }

        if (categoryFilterSelect_event) {
            categoryFilterSelect_event.addEventListener('change', toggleFilterInputs_event);
        }

        if (applyFilterButton_event) {
            applyFilterButton_event.addEventListener('click', function() {
                if (!categoryFilterSelect_event) return;
                const filterType = categoryFilterSelect_event.value;
                if (!filterType) {
                    return; // No filter selected, do nothing
                }

                let queryParams = new URLSearchParams();
                queryParams.set('filter_type', filterType);

                if (filterType === 'year') {
                    if (!yearValueInput_event || !yearValueInput_event.value || !/^\d{4}$/.test(yearValueInput_event.value)) {
                        alert('Please enter a valid 4-digit year.');
                        return;
                    }
                    queryParams.set('filter_year_value', yearValueInput_event.value);
                } else if (filterType === 'month') {
                    if (!monthValueSelect_event || !monthValueSelect_event.value) {
                        alert('Please select a month.');
                        return;
                    }
                    queryParams.set('filter_month_value', monthValueSelect_event.value);
                    // Add optional year for month
                    if (yearForMonthValueInput_event && yearForMonthValueInput_event.value) {
                        if (!/^\d{4}$/.test(yearForMonthValueInput_event.value)) {
                            alert('If providing a year for the month, please enter a valid 4-digit year.');
                            return;
                        }
                        queryParams.set('filter_year_for_month_value', yearForMonthValueInput_event.value);
                    }
                } else if (filterType === 'specific_date') {
                    if (!dateValueInput_event || !dateValueInput_event.value) {
                        alert('Please select a date.');
                        return;
                    }
                    // Basic check, <input type="date"> provides YYYY-MM-DD
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValueInput_event.value)) {
                        alert('Invalid date format from date picker. Expected YYYY-MM-DD.');
                        return;
                    }
                    queryParams.set('filter_date_value', dateValueInput_event.value);
                } else if (filterType === 'oldest_to_latest') {
                    queryParams.set('sort_order', 'asc');
                } else if (filterType === 'latest_to_oldest') {
                    queryParams.set('sort_order', 'desc');
                }
                window.location.search = queryParams.toString();
            });
        }

        if (clearFilterButton_event) {
            clearFilterButton_event.addEventListener('click', function(event) {
                event.preventDefault();
                if (searchInput_event) {
                    searchInput_event.value = ''; // Also clear the text search input
                }
                window.location.href = window.location.pathname; // Reload page without query params
            });
        }

        // Function to set filter UI based on URL parameters on page load for event.php
        function setFiltersFromUrl_event() {
            if (!categoryFilterSelect_event) return;

            const urlParams = new URLSearchParams(window.location.search);
            const filterTypeFromUrl = urlParams.get('filter_type');

            // Reset UI elements to default state first
            categoryFilterSelect_event.value = "";
            if(yearValueInput_event) yearValueInput_event.value = "";
            if(yearForMonthValueInput_event) yearForMonthValueInput_event.value = "";
            if(monthValueSelect_event) monthValueSelect_event.value = "";
            if(dateValueInput_event) dateValueInput_event.value = "";
            toggleFilterInputs_event(); // Hide all conditional input groups

            // Now, if there are parameters in the URL, apply them to the UI
            if (filterTypeFromUrl) {
                categoryFilterSelect_event.value = filterTypeFromUrl;
                toggleFilterInputs_event(); // Show the correct input group for the applied filter

                if (filterTypeFromUrl === 'year' && urlParams.has('filter_year_value') && yearValueInput_event) {
                    yearValueInput_event.value = urlParams.get('filter_year_value');
                } else if (filterTypeFromUrl === 'month') {
                    if (urlParams.has('filter_month_value') && monthValueSelect_event) {
                        monthValueSelect_event.value = urlParams.get('filter_month_value');
                    }
                    if (urlParams.has('filter_year_for_month_value') && yearForMonthValueInput_event) {
                        yearForMonthValueInput_event.value = urlParams.get('filter_year_for_month_value');
                    }
                } else if (filterTypeFromUrl === 'specific_date' && urlParams.has('filter_date_value') && dateValueInput_event) {
                    dateValueInput_event.value = urlParams.get('filter_date_value');
                }
            } else if (urlParams.has('sort_order')) { // If only sorting is applied without a specific filter type
                const sortOrder = urlParams.get('sort_order');
                if (sortOrder === 'asc') categoryFilterSelect_event.value = 'oldest_to_latest';
                if (sortOrder === 'desc') categoryFilterSelect_event.value = 'latest_to_oldest';
            }
        }

        setFiltersFromUrl_event(); // Call on page load to reflect current filters
        // --- END: New Filter JavaScript for event.php ---
    });

    // Field validation function
    function validateField(fieldName, value, fieldElement) {
        let isValid = false;
        const submitButton = document.getElementById('submitButton');
        let errorElement;

        switch(fieldName) {
            case 'eventType':
                errorElement = document.getElementById('EventTypeError');
                isValid = regexPatterns.eventType.test(value.trim());
                if (isValid) {
                    fieldElement.style.border = '2px solid green';
                    errorElement.classList.add('hidden');
                    errorElement.textContent = 'Event Type must be one of: Baptism, Wedding, Funeral, Confirmation'; // Reset default
                } else {
                    fieldElement.style.border = '2px solid red';
                    errorElement.style.display = 'block';
                    if (value.trim() === '') {
                        errorElement.textContent = 'Event Type is required.';
                    } else {
                        errorElement.textContent = 'Invalid Event Type. Allowed: Baptism, Wedding, Funeral, Confirmation.';
                    }
                }
                formState.eventType = isValid;
                break;

            case 'date':
                errorElement = document.getElementById('DateError');
                let isFormatValid = regexPatterns.dateFormat.test(value);

                if (!isFormatValid) {
                    isValid = false;
                    fieldElement.style.border = '2px solid red';
                    errorElement.style.display = 'block';
                    if (value.trim() === '') {
                        errorElement.textContent = 'Date is required.';
                    } else {
                        errorElement.textContent = 'Date format must be YYYY-MM-DD.';
                    }
                } else {
                    // Format is valid, now check logic (future date)
                    const selectedDate = new Date(value);
                    const today = new Date();
                    selectedDate.setHours(0, 0, 0, 0);
                    today.setHours(0, 0, 0, 0);

                    isValid = selectedDate >= today;
                    if (isValid) {
                        fieldElement.style.border = '2px solid green';
                        errorElement.classList.add('hidden');
                        errorElement.textContent = 'Date must be today or in the future'; // Reset default
                    } else {
                        fieldElement.style.border = '2px solid red';
                        errorElement.style.display = 'block';

                        errorElement.textContent = 'Date must be today or in the future.';
                    }
                }
                formState.date = isValid;
                break;

            case 'priestID': // For the <select> element
                errorElement = document.getElementById('PriestIDError');
                isValid = value !== ''; // "" is the value of "-- Select Priest --"
                if (isValid) {
                    fieldElement.style.border = '2px solid green'; // Or no border change if preferred for select
                   errorElement.style.display = 'none';
                } else {
                    fieldElement.style.border = '2px solid red';
                      errorElement.style.display = 'block';
                }
                formState.priestID = isValid;
                break;
        }

        if (isFormValid()) {
            submitButton.disabled = false;
            submitButton.style.backgroundColor = '#28a745'; // Green
        } else {
            submitButton.disabled = true;
            submitButton.style.backgroundColor = '#cccccc'; // Grey
        }
        console.log("Validation state:", fieldName, isValid, formState);
    }

    function isFormValid() {
        return formState.eventType && formState.date && formState.priestID;
    }
    /*----- ADD RECORDS validation [end]-----*/

    /*----- UPDATE RECORDS validation [start]-----*/
    const updateFormState = {
        eventType: false,
        date: false,
        priestID: false
    };

    function initializeUpdateValidation() {
        const updateEventTypeField = document.getElementById('updateEventType');
        const updateDateField = document.getElementById('updateDate');
        const updatePriestIDField = document.getElementById('updatePriestID');
        const updateForm = document.getElementById('updateEventForm');
        const submitUpdateBtn = document.getElementById('submitUpdateRecordBtn');

        if (!updateEventTypeField || !updateDateField || !updatePriestIDField || !updateForm || !submitUpdateBtn) {
            console.warn("Update validation elements not found. Update validation may not work correctly.");
            return;
        }

        submitUpdateBtn.disabled = true;
        submitUpdateBtn.style.backgroundColor = '#cccccc';

        updateEventTypeField.addEventListener('input', () => validateUpdateField('eventType', updateEventTypeField.value, updateEventTypeField));
        updateDateField.addEventListener('input', () => validateUpdateField('date', updateDateField.value, updateDateField));
        updateDateField.addEventListener('change', () => validateUpdateField('date', updateDateField.value, updateDateField));
        updatePriestIDField.addEventListener('change', () => validateUpdateField('priestID', updatePriestIDField.value, updatePriestIDField));

        updateEventTypeField.addEventListener('blur', () => validateUpdateField('eventType', updateEventTypeField.value, updateEventTypeField));
        updateDateField.addEventListener('blur', () => validateUpdateField('date', updateDateField.value, updateDateField));
        updatePriestIDField.addEventListener('blur', () => validateUpdateField('priestID', updatePriestIDField.value, updatePriestIDField));

        updateForm.addEventListener('submit', function(e) {
            validateUpdateField('eventType', updateEventTypeField.value, updateEventTypeField);
            validateUpdateField('date', updateDateField.value, updateDateField);
            validateUpdateField('priestID', updatePriestIDField.value, updatePriestIDField);

            if (!isUpdateFormValid()) {
                e.preventDefault();
                alert('Please correct all errors before submitting the update.');
            }
        });
    }

    function validateUpdateField(fieldName, value, fieldElement) {
        let isValid = false;
        const submitButton = document.getElementById('submitUpdateRecordBtn');
        let errorElement;
        let defaultErrorMessage = ''; // For specific required messages

        // Assuming 'regexPatterns' is globally available from your add record validation
        if (typeof regexPatterns === 'undefined') {
            console.error("regexPatterns is not defined. Update validation will fail.");
            return;
        }

        switch(fieldName) {
            case 'eventType':
                errorElement = document.getElementById('updateEventTypeError');
                defaultErrorMessage = 'Event Type is required.';
                isValid = regexPatterns.eventType.test(value.trim());
                if (isValid) {
                    fieldElement.style.border = '2px solid green';
                    errorElement.classList.add('hidden');
                } else {
                    fieldElement.style.border = '2px solid red';
                    errorElement.classList.remove('hidden');
                    errorElement.textContent = value.trim() === '' ? defaultErrorMessage : 'Invalid Event Type. Allowed: Baptism, Wedding, Funeral, Confirmation.';
                }
                updateFormState.eventType = isValid;
                break;
            case 'date':
                errorElement = document.getElementById('updateDateError');
                defaultErrorMessage = 'Date is required.';
                let isFormatValid = regexPatterns.dateFormat.test(value);
                if (!isFormatValid) {
                    isValid = false;
                    fieldElement.style.border = '2px solid red';
                    errorElement.classList.remove('hidden');
                    errorElement.textContent = value.trim() === '' ? defaultErrorMessage : 'Date format must be YYYY-MM-DD.';
                } else {
                    const selectedDate = new Date(value + "T00:00:00"); // Ensure local date parsing
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    isValid = selectedDate >= today;
                    if (isValid) {
                        fieldElement.style.border = '2px solid green';
                        errorElement.classList.add('hidden');
                    } else {
                        fieldElement.style.border = '2px solid red';
                        errorElement.classList.remove('hidden');
                        errorElement.textContent = 'Date must be today or in the future.';
                    }
                }
                updateFormState.date = isValid;
                break;
            case 'priestID':
                errorElement = document.getElementById('updatePriestIDError');
                defaultErrorMessage = 'Please select a priest.';
                isValid = value !== '';
                if (isValid) {
                    fieldElement.style.border = '2px solid green';
                    errorElement.classList.add('hidden');
                } else {
                    fieldElement.style.border = '2px solid red';
                    errorElement.classList.remove('hidden');
                    errorElement.textContent = defaultErrorMessage;
                }
                updateFormState.priestID = isValid;
                break;
        }

        if (submitButton) {
            if (isUpdateFormValid()) {
                submitButton.disabled = false;
                submitButton.style.backgroundColor = '#F39C12'; // Update button color
            } else {
                submitButton.disabled = true;
                submitButton.style.backgroundColor = '#cccccc';
            }
        }
    }

    function isUpdateFormValid() {
        return updateFormState.eventType && updateFormState.date && updateFormState.priestID;
    }

    // Call this function when the update modal is populated with data
    function triggerInitialUpdateValidation() {
        const updateEventTypeField = document.getElementById('updateEventType');
        const updateDateField = document.getElementById('updateDate');
        const updatePriestIDField = document.getElementById('updatePriestID');

        if(updateEventTypeField && updateDateField && updatePriestIDField){
            // Reset state before validating populated data
            Object.keys(updateFormState).forEach(key => updateFormState[key] = false);

            validateUpdateField('eventType', updateEventTypeField.value, updateEventTypeField);
            validateUpdateField('date', updateDateField.value, updateDateField);
            validateUpdateField('priestID', updatePriestIDField.value, updatePriestIDField);
        } else {
            const submitButton = document.getElementById('submitUpdateRecordBtn');
            if(submitButton) {
                submitButton.disabled = true;
                submitButton.style.backgroundColor = '#cccccc';
            }
        }
    }
    function resetUpdateModalFields() {
        const updateEventTypeField = document.getElementById('updateEventType');
        const updateDateField = document.getElementById('updateDate');
        const updatePriestIDField = document.getElementById('updatePriestID');
        const submitUpdateBtn = document.getElementById('submitUpdateRecordBtn');

        if (updateEventTypeField) {
            updateEventTypeField.value = '';
            updateEventTypeField.style.border = '';
            document.getElementById('updateEventTypeError').classList.add('hidden');
        }
        if (updateDateField) {
            updateDateField.value = '';
            updateDateField.style.border = '';
            document.getElementById('updateDateError').classList.add('hidden');
        }
        if (updatePriestIDField) {
            updatePriestIDField.value = '';
            updatePriestIDField.style.border = '';
            document.getElementById('updatePriestIDError').classList.add('hidden');
        }
        if (submitUpdateBtn) {
            submitUpdateBtn.disabled = true;
            submitUpdateBtn.style.backgroundColor = '#cccccc';
        }
        Object.keys(updateFormState).forEach(key => updateFormState[key] = false);
    }

    // Modify closeUpdateModal to reset fields
    function closeUpdateModal() {
        document.getElementById("updateModal").style.display = "none";
        adminAuthenticated = false;
        disableRowClickEdit();
        resetUpdateModalFields(); // Add this line
    }


    // --- In your DOMContentLoaded ---
    document.addEventListener('DOMContentLoaded', function() {
        // ... (your existing DOMContentLoaded code for add form validation, nav buttons etc.)
        // It should contain the initialization for 'eventForm', 'EventType', 'Date', 'OfficiatingPriestID' fields
        // and their event listeners for the 'add record' modal.

        // Initialize update form validation
        initializeUpdateValidation();

    });
    /*----- UPDATE RECORDS validation [end]-----*/

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