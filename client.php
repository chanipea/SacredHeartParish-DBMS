<?php

// Prevent caching of the page after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$pageTitle = "Client Management";
require_once 'session_timeout.php'; 

// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // If not logged in, redirect to the login page
    header("Location: Log_In/login_system.php"); // Correct path if needed
    exit();
}

// --- START: Filter Logic for GET requests (Client Records by DateOfSaidService) ---
$whereClauses_client = [];
$orderByClause_client = "ORDER BY c.ClientID DESC"; // Default order for client records
$filter_params_client = []; // Parameters for prepared statement
$filter_param_types_client = ""; // Types for prepared statement

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (isset($_GET['filter_type_client']) && !empty($_GET['filter_type_client'])) {
        $filter_type = $_GET['filter_type_client'];

        switch ($filter_type) {
            case 'year':
                if (isset($_GET['filter_year_value_client']) && !empty($_GET['filter_year_value_client'])) {
                    $year = filter_var($_GET['filter_year_value_client'], FILTER_VALIDATE_INT);
                    if ($year && strlen((string)$year) == 4) {
                        $whereClauses_client[] = "YEAR(c.DateOfSaidService) = ?";
                        $filter_params_client[] = $year;
                        $filter_param_types_client .= "i";
                    }
                }
                break;
            case 'month':
                if (isset($_GET['filter_month_value_client']) && !empty($_GET['filter_month_value_client'])) {
                    $month = filter_var($_GET['filter_month_value_client'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 12]]);
                    if ($month) {
                        $whereClauses_client[] = "MONTH(c.DateOfSaidService) = ?";
                        $filter_params_client[] = $month;
                        $filter_param_types_client .= "i";

                        if (isset($_GET['filter_year_for_month_value_client']) && !empty($_GET['filter_year_for_month_value_client'])) {
                            $year_for_month = filter_var($_GET['filter_year_for_month_value_client'], FILTER_VALIDATE_INT);
                            if ($year_for_month && strlen((string)$year_for_month) == 4) {
                                $whereClauses_client[] = "YEAR(c.DateOfSaidService) = ?";
                                $filter_params_client[] = $year_for_month;
                                $filter_param_types_client .= "i";
                            }
                        }
                    }
                }
                break;
            case 'specific_date':
                if (isset($_GET['filter_date_value_client']) && !empty($_GET['filter_date_value_client'])) {
                    $date_str = $_GET['filter_date_value_client']; // Expected YYYY-MM-DD
                    $d = DateTime::createFromFormat('Y-m-d', $date_str);
                    if ($d && $d->format('Y-m-d') === $date_str) {
                        $whereClauses_client[] = "c.DateOfSaidService = ?";
                        $filter_params_client[] = $date_str;
                        $filter_param_types_client .= "s";
                    }
                }
                break;
            case 'oldest_to_latest':
                $orderByClause_client = "ORDER BY c.DateOfSaidService ASC, c.ClientID ASC";
                break;
            case 'latest_to_oldest':
                $orderByClause_client = "ORDER BY c.DateOfSaidService DESC, c.ClientID DESC";
                break;
        }
    }
    // Handle standalone sort_order parameter
    if (isset($_GET['sort_order_client'])) {
        if ($_GET['sort_order_client'] === 'asc' && $filter_type !== 'oldest_to_latest') {
            $orderByClause_client = "ORDER BY c.DateOfSaidService ASC, c.ClientID ASC";
        } elseif ($_GET['sort_order_client'] === 'desc' && $filter_type !== 'latest_to_oldest') {
            $orderByClause_client = "ORDER BY c.DateOfSaidService DESC, c.ClientID DESC";
        }
    }
}
// --- END: Filter Logic (Client Records) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

    if ($conn->connect_error) {
        error_log("Database Connection Failed: " . $conn->connect_error);
        echo "<script>alert('Error: Could not connect to the database. Please try again later.'); window.history.back();</script>";
        exit();
    }

    // --- Retrieve and Trim POST data ---
    $clientID_for_update = trim($_POST['ClientID'] ?? ''); // Used only for update WHERE clause
    $fullName            = trim($_POST['FullName'] ?? '');
    $sex                 = trim($_POST['Sex'] ?? '');
    $dateOfBirth         = trim($_POST['DateOfBirth'] ?? '');
    $contactInfo         = trim($_POST['ContactInfo'] ?? '');
    $serviceAvailed      = trim($_POST['ServiceAvailed'] ?? '');
    $dateOfSaidService   = trim($_POST['DateOfSaidService'] ?? '');
    $address             = trim($_POST['Address'] ?? '');
    $description         = trim($_POST['Description'] ?? ''); // Optional field

    // --- SERVER-SIDE VALIDATION ---
    $errors = [];
    $today = new DateTime();
    $today->setTime(0, 0, 0); // For date comparisons without time

    // Define Regex Patterns
    $nameRegex = '/^[a-zA-Z\s.\'-]{2,100}$/';
    $phPhoneRegex = '/^(09|\+639)\d{9}$/'; // Starts with 09 or +639, followed by 9 digits
    $addressRegex = '/^[a-zA-Z0-9\s.,\'()#\/-]{5,255}$/'; // Allow more chars for address
    $serviceRegex = '/^[a-zA-Z\s.,\'-]{2,100}$/'; // Basic text for service
    $descriptionRegex = '/^.{0,500}$/s'; // Allow empty or up to 500 chars, including newlines

    // ClientID validation (only if it's an update action, for the WHERE clause)
    if (isset($_POST['updateRecord'])) {
        if (empty($clientID_for_update)) {
            $errors[] = "Client ID is missing for the update operation.";
        } elseif (!ctype_digit($clientID_for_update) || (int)$clientID_for_update <= 0) {
            $errors[] = "Invalid Client ID format for update.";
        }
    }

    // FullName
    if (empty($fullName)) {
        $errors[] = "Full Name is required.";
    } elseif (!preg_match($nameRegex, $fullName)) {
        $errors[] = "Invalid Full Name format (letters, spaces, '.', ''', - allowed, 2-100 chars).";
    }

    // Sex
    if (empty($sex)) {
        $errors[] = "Sex is required.";
    } elseif (!in_array($sex, ['Male', 'Female'])) {
        $errors[] = "Invalid Sex selected.";
    }

    // Date of Birth (DOB)
    $dobDateObj = null; // Initialize
    if (empty($dateOfBirth)) {
        $errors[] = "Date of Birth is required.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
        $errors[] = "Invalid Date of Birth format (YYYY-MM-DD).";
    } else {
        try {
            $dobDateObj = new DateTime($dateOfBirth);
            // Check if the created date matches the input string (handles invalid dates like 2023-02-30)
            if ($dobDateObj->format('Y-m-d') !== $dateOfBirth) {
                throw new Exception("Invalid date components.");
            }
            $dobDateObj->setTime(0,0,0); // Normalize time for comparison
            if ($dobDateObj >= $today) {
                $errors[] = "Date of Birth must be in the past.";
                $dobDateObj = null; // Invalidate object
            }
        } catch (Exception $e) {
            $errors[] = "Invalid Date of Birth (e.g., day/month out of range).";
            $dobDateObj = null; // Invalidate object
        }
    }

    // ContactInfo (Philippine Phone Number)
    if (empty($contactInfo)) {
        $errors[] = "Contact Info (Philippine Phone Number) is required.";
    } else {
        // Normalize: remove spaces, hyphens if any before matching
        $normalizedContact = preg_replace('/[\s-]+/', '', $contactInfo);
        if (!preg_match($phPhoneRegex, $normalizedContact)) {
            $errors[] = "Invalid Philippine Phone Number format. Must be 09xxxxxxxxx or +639xxxxxxxxx.";
        } else {
            $contactInfo = $normalizedContact; // Use the normalized version
        }
    }


    // ServiceAvailed
    if (empty($serviceAvailed)) {
        $errors[] = "Service Availed is required.";
    } elseif (!preg_match($serviceRegex, $serviceAvailed)) {
        $errors[] = "Invalid Service Availed format (letters, spaces, '.', ''', - allowed, 2-100 chars).";
    }

    // DateOfSaidService
    $serviceDateObj = null; // Initialize
    if (empty($dateOfSaidService)) {
        $errors[] = "Date of Said Service is required.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfSaidService)) {
        $errors[] = "Invalid Date of Said Service format (YYYY-MM-DD).";
    } else {
        try {
            $serviceDateObj = new DateTime($dateOfSaidService);
            if ($serviceDateObj->format('Y-m-d') !== $dateOfSaidService) {
                throw new Exception("Invalid date components.");
            }
            $serviceDateObj->setTime(0,0,0); // Normalize

            // Check if service date is before DOB (only if DOB is valid)
            if ($dobDateObj && $serviceDateObj < $dobDateObj) {
                $errors[] = "Date of Said Service cannot be before Date of Birth.";
                $serviceDateObj = null; // Invalidate object
            }
        } catch (Exception $e) {
            $errors[] = "Invalid Date of Said Service (e.g., day/month out of range).";
            $serviceDateObj = null; // Invalidate object
        }
    }

    // Address
    if (empty($address)) {
        $errors[] = "Address is required.";
    } elseif (!preg_match($addressRegex, $address)) {
        $errors[] = "Invalid Address format (5-255 chars, letters, numbers, basic punctuation allowed).";
    }

    // Description (Optional)
    if (!empty($description) && !preg_match($descriptionRegex, $description)) {
        // Check only if not empty
        $errors[] = "Description cannot exceed 500 characters.";
    }


    // If errors found during validation, show them and exit
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


    // --- Proceed with Database Operation if No Errors ---

    // FOR ADDING RECORDS
    if (isset($_POST['submitRecord'])) {
        $sql = "INSERT INTO client (FullName, Sex, DateOfBirth, ContactInfo, ServiceAvailed, DateOfSaidService, Address, Description, ParishStaffID)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("SQL Prepare Error (Insert Client): " . $conn->error);
            echo "<script>alert('Error preparing the record. Please try again.'); window.history.back();</script>";
            $conn->close();
            exit();
        }
        // Bind using the potentially normalized $contactInfo
        $stmt->bind_param("ssssssssi", $fullName, $sex, $dateOfBirth, $contactInfo, $serviceAvailed, $dateOfSaidService, $address, $description, $parishStaffID);

        if ($stmt->execute()) {
            echo "<script>alert('Client record inserted successfully!'); window.location.href = window.location.href;</script>";
            exit();
        } else {
            error_log("SQL Execute Error (Insert Client): " . $stmt->error);
            echo "<script>alert('Error inserting client record: " . htmlspecialchars($stmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
        }
        $stmt->close();

        // FOR UPDATING RECORDS
    } elseif (isset($_POST['updateRecord'])) {
        // Admin password check
        $adminPassword = $_POST['adminPassword'] ?? '';
        $isAdminValid = false;
        $sqlPass = "SELECT PasswordHash FROM admin_users";
        $resultPass = $conn->query($sqlPass);
        if ($resultPass && $resultPass->num_rows > 0) {
            while ($rowPass = $resultPass->fetch_assoc()) {
                if (password_verify($adminPassword, $rowPass['PasswordHash'])) {
                    $isAdminValid = true;
                    break;
                }
            }
        }


            // $clientID_for_update was validated earlier
            $sql = "UPDATE client
                    SET FullName=?, Sex=?, DateOfBirth=?, ContactInfo=?, ServiceAvailed=?, DateOfSaidService=?, Address=?, Description=?
                    WHERE ClientID=?";
            $updateStmt = $conn->prepare($sql);
            if ($updateStmt === false) {
                error_log("SQL Prepare Error (Update Client): " . $conn->error);
                echo "<script>alert('Error preparing the update. Please try again.'); window.history.back();</script>";
                $conn->close();
                exit();
            }
            // Bind using potentially normalized $contactInfo and the validated $clientID_for_update
            $updateStmt->bind_param("ssssssssi", $fullName, $sex, $dateOfBirth, $contactInfo, $serviceAvailed, $dateOfSaidService, $address, $description, $clientID_for_update);

            if ($updateStmt->execute()) {
                if ($updateStmt->affected_rows > 0) {
                    echo "<script>alert('Client record updated successfully! " . $updateStmt->affected_rows . " row(s) affected.'); window.location.href = window.location.href;</script>";
                } else if ($updateStmt->affected_rows === 0) {
                    echo "<script>alert('Update query executed, but 0 rows were affected. This might mean the Client ID was not found, or the data submitted was identical to the existing record.'); window.location.href = window.location.href;</script>";
                } else {
                    echo "<script>alert('Client record updated successfully (unknown affected rows)!'); window.location.href = window.location.href;</script>";
                }
                exit();
            } else {
                error_log("SQL Execute Error (Update Client): " . $updateStmt->error . " | ClientID: " . $clientID_for_update);
                echo "<script>alert('Update error: " . htmlspecialchars($updateStmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
            }
            $updateStmt->close();
        }
    
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" href="/imagess/sacred.png">
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="stylesheet" href="client.css?v=11">
    <!-- Add these two lines for responsiveness -->
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="clientResponsive.css?v=5">
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
        <div class="section-title">Clients</div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search..." style="flex-grow: 1;">

            <!-- START: Category Filter for Clients -->
            <div class="filter-container">
                <select id="categoryFilterClient" name="category_filter_client" title="Category Filter">
                    <option value="">-- Filter By Service Date --</option>
                    <option value="year">Year</option>
                    <option value="month">Month</option>
                    <option value="specific_date">Specific Date</option>
                    <option value="oldest_to_latest">Oldest Service Date to Latest</option>
                    <option value="latest_to_oldest">Latest Service Date to Oldest</option>
                </select>

                <div id="filterYearInputContainerClient" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearValueClient" name="filter_year_value_client" placeholder="YYYY">
                </div>
                <div id="filterMonthInputContainerClient" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearForMonthValueClient" name="filter_year_for_month_value_client" placeholder="YYYY (Opt.)">
                    <select id="filterMonthValueClient" name="filter_month_value_client">
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
                <div id="filterDateInputContainerClient" class="filter-input-group" style="display:none;">
                    <input type="date" id="filterDateValueClient" name="filter_date_value_client">
                </div>
                <button id="applyFilterBtnClient" class="filter-btn">Apply</button>
                <button id="clearFilterBtnClient" class="filter-btn">Clear</button>
            </div>
            <!-- END: Category Filter for Clients -->

            <div class="record-buttons" style="margin-left: auto;">
                <button id="updateRecordBtn">✎ Update Record</button>
                <button id="addRecordBtn">+ Add Record</button>
            </div>
        </div>

        <div class="table-scroll">
            <table id="recordsTable">
                <thead>
                <tr>
                    <th>Client ID</th>
                    <th>Full Name</th>
                    <th>Sex</th>
                    <th>Date Of Birth</th>
                    <th>Contact Info</th>
                    <th>Service Availed</th>
                    <th>Date of Said Service</th>
                    <th>Address</th>
                    <th>Description</th>
                    <th>Created By</th>
                </tr>
                </thead>

                <tbody>
                <?php
                $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error);
                }

                // START MODIFY FOR FILTER
                $baseSqlClient = "SELECT 
                        c.*,
                        COALESCE(au.Username, su.username, 'Unknown') AS CreatedBy
                    FROM client c
                    LEFT JOIN parishstaff ps ON c.ParishStaffID = ps.ParishStaffID
                    LEFT JOIN admin_users au ON ps.AdminUserID = au.ID
                    LEFT JOIN staff_users su ON ps.StaffUserID = su.id";

                $finalSqlClient = $baseSqlClient;

                if (!empty($whereClauses_client)) {
                    $finalSqlClient .= " WHERE " . implode(" AND ", $whereClauses_client);
                }
                $finalSqlClient .= " " . $orderByClause_client;

                $resultClient = null; // Initialize

                if (!empty($filter_params_client)) {
                    $stmtClient = $conn->prepare($finalSqlClient);
                    if ($stmtClient === false) {
                        error_log("SQL Prepare Error (Filter Client): " . $conn->error . " | SQL: " . $finalSqlClient);
                        echo "<tr><td colspan='10'>Error preparing client data.</td></tr>";
                    } else {
                        $stmtClient->bind_param($filter_param_types_client, ...$filter_params_client);
                        $stmtClient->execute();
                        $resultClient = $stmtClient->get_result();
                        if ($resultClient === false) {
                            error_log("SQL Get Result Error (Filter Client): " . $stmtClient->error);
                            echo "<tr><td colspan='10'>Error retrieving filtered client data.</td></tr>";
                        }
                    }
                } else {
                    $resultClient = $conn->query($finalSqlClient);
                    if ($resultClient === false) {
                        error_log("SQL Query Error (Client): " . $conn->error . " | SQL: " . $finalSqlClient);
                        echo "<tr><td colspan='10'>Error fetching client data.</td></tr>";
                    }
                }

                if ($resultClient && $resultClient->num_rows > 0) {
                    while ($row = $resultClient->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row["ClientID"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["FullName"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["Sex"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["DateOfBirth"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["ContactInfo"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["ServiceAvailed"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["DateOfSaidService"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["Address"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["Description"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["CreatedBy"] ?? '-') . "</td>";
                        echo "</tr>";
                    }
                } else if ($resultClient) {
                    echo "<tr><td colspan='10'>No client records found matching your criteria.</td></tr>";
                }
                $conn->close(); // END FILTER FOR CLIENT
                ?>
                </tbody>
            </table>
        </div>
    </div>


    <div class="modal" id="recordModal">
        <form class="modal-content" id="addClientForm" method="POST" action="client.php" style="width: 1000px; height:600px ; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeModal()" style="position: absolute; top: 90px; left: 20px;font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>

            <div class="modal-header" style="background: #2c3e50; color: white; text-align: center; border-radius: 0; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Client Details</h3>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">

                <!-- <div style="flex: 1 1 45%;">
                    <label for="ClientID" style="margin-left: 55px;">Client ID:</label><br>
                    <input type="text" name="ClientID" style="width: 80%; padding: 5px; margin-left: 55px;">
                </div> -->

                <div style="flex: 1 1 45%;">
                    <label for="ServiceAvailed" style="margin-left: 55px;">Service Availed:</label><br>
                    <input type="text" name="ServiceAvailed" id="addServiceAvailed" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addServiceAvailedError" class="error-message hidden" style="margin-left: 55px;">Service Availed is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="FullName" style="margin-left: 30px;">Full Name:</label><br>
                    <input type="text" name="FullName" id="addFullName" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addFullNameError" class="error-message hidden" style="margin-left: 30px;">Full Name is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="DateOfSaidService" style="margin-left: 55px;">Date Of Said Service:</label><br>
                    <input type="date" name="DateOfSaidService" id="addDateOfSaidService" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addDateOfSaidServiceError" class="error-message hidden" style="margin-left: 55px;">Date of Service is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="Sex" style="margin-left: 30px;">Sex:</label><br>
                    <select name="Sex" id="addSex" required style="width: 80%; padding: 5px; margin-left: 30px;">
                        <option value="">-- Select Sex --</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                    <small id="addSexError" class="error-message hidden" style="margin-left: 30px;">Sex selection is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="Address" style="margin-left: 55px;">Address:</label><br>
                    <input type="text" name="Address" id="addAddress" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addAddressError" class="error-message hidden" style="margin-left: 55px;">Address is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="DateOfBirth" style="margin-left: 30px;">Date of Birth:</label><br>
                    <input type="date" name="DateOfBirth" id="addDateOfBirth" style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addDateOfBirthError" class="error-message hidden" style="margin-left: 30px;">Date of Birth is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="ContactInfo" style="margin-left: 55px;">Contact Info:</label><br>
                    <input type="text" name="ContactInfo" id="addContactInfo" style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addContactInfoError" class="error-message hidden" style="margin-left: 55px;">Valid PH phone number required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="Description" style="margin-left: 30px;">Description:</label><br>
                    <input type="text" name="Description" id="addDescription" style="width: 87%; height: 90px; padding: 5px; margin-left: 30px;">
                    <small id="addDescriptionError" class="error-message hidden" style="margin-left: 30px;">Description too long.</small>
                </div>


            </div>

            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" name="submitRecord" id="addSubmitButton" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px; cursor: not-allowed;">+ Add Record</button>
            </div>
        </form>
    </div>
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
    <form class="modal-content"  id="updateClientForm" method="POST" action="client.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
        <span onclick="closeUpdateModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>

        <div class="modal-header" style="background: #F39C12; color: white; text-align: center; margin: -10px -10px; width: 102%; padding: 20px 0;">
            <h3 style="margin: 0; font-size: 25px;">Update Client Record</h3>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">

            <div style="flex: 1 1 45%;">
                <label for="updateClientID" style="margin-left: 55px;">Client ID:</label><br>
                <input type="text" id="updateClientID" name="ClientID" readonly style="width: 80%; padding: 5px; margin-left: 55px; background-color: #e9e9e9;">
            </div>

            <div style="flex: 1 1 45%;">
                <label for="updateFullName" style="margin-left: 30px;">Full Name:</label><br>
                <input type="text" id="updateFullName" name="FullName" style="width: 80%; padding: 5px; margin-left: 30px;">
                <small id="updateFullNameError" class="error-message hidden" style="margin-left: 30px;">Full Name is required.</small>
            </div>

            <div style="flex: 1 1 45%;">
                <label for="updateSex" style="margin-left: 55px;">Sex:</label><br>
                <select name="Sex" id="updateSex" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <option value="">-- Select Sex --</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
                <small id="updateSexError" class="error-message hidden" style="margin-left: 55px;">Sex selection is required.</small>
            </div>

            <div style="flex: 1 1 45%;">
                <label for="updateDateOfBirth" style="margin-left: 30px;">Date of Birth:</label><br>
                <input type="date" id="updateDateOfBirth" name="DateOfBirth" style="width: 80%; padding: 5px; margin-left: 30px;">
                <small id="updateDateOfBirthError" class="error-message hidden" style="margin-left: 30px;">Date of Birth is required.</small>
            </div>

            <div style="flex: 1 1 45%;">
                <label for="updateContactInfo" style="margin-left: 55px;">Contact Info:</label><br>
                <input type="text" id="updateContactInfo" name="ContactInfo" style="width: 80%; padding: 5px; margin-left: 55px;">
                <small id="updateContactInfoError" class="error-message hidden" style="margin-left: 55px;">Valid PH phone number required.</small>
            </div>

            <div style="flex: 1 1 45%;">
                <label for="updateServiceAvailed" style="margin-left: 30px;">Service Availed:</label><br>
                <input type="text" id="updateServiceAvailed" name="ServiceAvailed" style="width: 80%; padding: 5px; margin-left: 30px;">
                <small id="updateServiceAvailedError" class="error-message hidden" style="margin-left: 30px;">Service Availed is required.</small>
            </div>

            <div style="flex: 1 1 45%;">
                <label for="updateDateOfSaidService" style="margin-left: 55px;">Date Of Said Service:</label><br>
                <input type="date" id="updateDateOfSaidService" name="DateOfSaidService" style="width: 80%; padding: 5px; margin-left: 55px;">
                <small id="updateDateOfSaidServiceError" class="error-message hidden" style="margin-left: 55px;">Date of Service is required.</small>
            </div>

            <div style="flex: 1 1 45%;">
                <label for="updateAddress" style="margin-left: 30px;">Address:</label><br>
                <input type="text" id="updateAddress" name="Address" style="width: 80%; padding: 5px; margin-left: 30px;">
                <small id="updateAddressError" class="error-message hidden" style="margin-left: 30px;">Address is required.</small>
            </div>

            <div style="flex: 1 1 45%;">
                <label for="updateDescription" style="margin-left: 55px;">Description:</label><br>
                <input type="text" id="updateDescription" name="Description" style="width: 87%; height: 90px; padding: 5px; margin-left: 55px;">
                <small id="updateDescriptionError" class="error-message hidden" style="margin-left: 30px;">Description too long.</small>
            </div>

        </div>

        <div class="modal-footer" style="text-align: center; margin-top: 60px;">
            <button type="submit" name="updateRecord" id="updateSubmitButton" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px; cursor: not-allowed;">✎ Update Record</button>
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
    const clientRegexPatterns = {
        name: /^[a-zA-Z\s.\'-]{2,100}$/,
        phPhone: /^(09|\+639)\d{9}$/, // Matches 09xxxxxxxxx or +639xxxxxxxxx
        address: /^[a-zA-Z0-9\s.,'()#\/-]{5,255}$/,
        service: /^[a-zA-Z\s.,\'-]{2,100}$/,
        description: /^.{0,500}$/s, // Allows empty or up to 500 chars, including newlines
        dateYYYYMMDD: /^\d{4}-\d{2}-\d{2}$/
    };

    /*----- ADMIN PERMISSION [start]-----*/
    let adminAuthenticated = false;

    document.addEventListener('keydown', function (event) {
        if (event.key === "Escape") {
            const adminModal = document.getElementById("adminModal");
            const messageModal = document.getElementById("messageModal");
            if (adminModal && (adminModal.style.display === "block" || adminModal.style.display === "flex")) {
                closeAdminModal();
            }
            if (messageModal && (messageModal.style.display === "block" || messageModal.style.display === "flex")) {
                messageModal.style.display = 'none';
            }
        }
    });

    function openAdminModal() {
        const adminModal = document.getElementById("adminModal");
        if (adminModal) {
            document.getElementById("adminPassword").value = "";
            adminModal.style.display = "flex";
        }
    }

    function closeAdminModal() {
        const adminModal = document.getElementById("adminModal");
        if (adminModal) {
            adminModal.style.display = "none";
        }
        adminAuthenticated = false;
        document.getElementById("adminPassword").value = '';
        document.getElementById("hiddenAdminPassword").value = '';
        disableRowClickEdit();
    }

    // Validate admin password (using fetch to a separate PHP file)
    function validateAdmin(event) {
        event.preventDefault(); // Prevent form submission
        const inputPassword = document.getElementById("adminPassword").value;

        // Assuming you have a separate PHP file like update_validation.php
        fetch("update_validation.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: "password=" + encodeURIComponent(inputPassword)
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    adminAuthenticated = true;
                    document.getElementById("adminModal").style.display = "none";
                    showMessageModal("Access granted. Please click on a record to edit.");
                    document.getElementById("hiddenAdminPassword").value = inputPassword; // Store password for the update form submission
                    enableRowClickEdit(); // Enable row clicks only after successful auth
                } else {
                    adminAuthenticated = false;
                    showMessageModal("Incorrect password.");
                    disableRowClickEdit(); // Ensure rows are not clickable on failure
                }
                document.getElementById("adminPassword").value = ''; // Clear password input regardless
            })
            .catch(error => {
                console.error("Error validating admin:", error);
                showMessageModal("An error occurred during admin validation. Please try again.");
                adminAuthenticated = false; // Assume failure on error
                disableRowClickEdit(); // Ensure rows are not clickable on error
            });
        return false; // Stop the default form submission
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
        modal.style.display = "flex"; // Use flex for centering
    }
    /*----- ADMIN PERMISSION [end]-----*/

    /*----- COMMON VALIDATION & UTILITY FUNCTIONS -----*/
    function applyValidationStyles(fieldElement, errorElement, isValid, specificErrorMsg) {
        if (!fieldElement || !errorElement) return;
        if (isValid) {
            fieldElement.classList.remove('invalid-input');
            fieldElement.classList.add('valid-input');
            errorElement.classList.add('hidden');
            errorElement.textContent = '';
        } else {
            fieldElement.classList.remove('valid-input');
            fieldElement.classList.add('invalid-input');
            errorElement.classList.remove('hidden');
            errorElement.textContent = specificErrorMsg;
        }
    }

    function checkOverallValidity(formTypePrefix) {
        const state = (formTypePrefix === 'add') ? addClientFormState : updateClientFormState;
        const fields = (formTypePrefix === 'add') ? addClientFields : updateClientFields;
        const button = (formTypePrefix === 'add') ? addSubmitButton : updateSubmitButton;

        if (!button || !state) {
            // console.error("Button or state object missing for", formTypePrefix); // Keep this commented unless debugging
            return;
        }

        const allValid = Object.keys(fields).every(fieldName => {
            const fieldIsValid = state.hasOwnProperty(fieldName) && state[fieldName] === true;
            return fieldIsValid;
        });
        button.disabled = !allValid;
        
        // Use correct colors for Add vs Update
        const enabledColor = (formTypePrefix === 'add') ? '#28a745' : '#F39C12'; // Green for Add, Orange for Update
        const disabledColor = '#cccccc'; // Grey for disabled

        if (allValid) {
            button.style.backgroundColor = enabledColor;
            button.style.cursor = 'pointer';
        } else {
            button.style.backgroundColor = disabledColor;
            button.style.cursor = 'not-allowed';
        }
    }

    /*----- CLIENT FIELD VALIDATION -----*/
    function validateClientField(fieldName, value, fieldElement, formTypePrefix) {
        let isValid = false;
        const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
        const currentFormState = (formTypePrefix === 'add') ? addClientFormState : updateClientFormState;

        if (!errorElement) {
            console.error("Error element not found for:", formTypePrefix + fieldName + 'Error');
            if (currentFormState) currentFormState[fieldName] = false; // Assume invalid if element missing
            checkOverallValidity(formTypePrefix);
            return;
        }

        value = value.trim();
        let defaultErrorMsg = fieldName.replace(/([A-Z])/g, ' $1').trim() + ' is required.';
        let specificErrorMsg = '';
        const isOptional = (fieldName === 'Description'); // Identify optional fields

        // Handle empty checks first
        if (value === '' && !isOptional) {
            isValid = false;
            specificErrorMsg = defaultErrorMsg;
        } else {
            // Proceed with format/logic checks
            switch (fieldName) {
                case 'FullName':
                    isValid = clientRegexPatterns.name.test(value);
                    specificErrorMsg = 'Invalid name format (letters, spaces, .\'- allowed, 2-100 chars).';
                    break;
                case 'Sex':
                    isValid = (value === 'Male' || value === 'Female');
                    specificErrorMsg = 'Please select Male or Female.';
                    break;
                case 'DateOfBirth':
                    isValid = clientRegexPatterns.dateYYYYMMDD.test(value);
                    if (isValid) {
                        try {
                            const dob = new Date(value + "T00:00:00"); // Ensure local date
                            const today = new Date(); today.setHours(0,0,0,0);
                            if (dob >= today) {
                                isValid = false;
                                specificErrorMsg = 'Date of Birth must be in the past.';
                            }
                        } catch (e) { isValid = false; specificErrorMsg = 'Invalid date.'; }
                    } else { specificErrorMsg = 'Invalid date format (YYYY-MM-DD).'; }
                    break;
                case 'ContactInfo':
                    // Normalize before testing
                    const normalizedContact = value.replace(/[\s-]+/g, '');
                    isValid = clientRegexPatterns.phPhone.test(normalizedContact);
                    specificErrorMsg = 'Invalid PH Phone Number (e.g., 09xxxxxxxxx or +639xxxxxxxxx).';
                    break;
                case 'ServiceAvailed':
                    isValid = clientRegexPatterns.service.test(value);
                    specificErrorMsg = 'Invalid Service Availed format (letters, spaces, .,\'- allowed, 2-100 chars).';
                    break;
                case 'DateOfSaidService':
                    isValid = clientRegexPatterns.dateYYYYMMDD.test(value);
                    if(isValid) {
                        try {
                            // Check against DOB if DOB is valid
                            const dobField = document.getElementById(formTypePrefix + 'DateOfBirth');
                            const dobValue = dobField ? dobField.value : null;
                            if (dobValue && clientRegexPatterns.dateYYYYMMDD.test(dobValue)) {
                                const dobDate = new Date(dobValue + "T00:00:00");
                                const serviceDate = new Date(value + "T00:00:00");
                                if (serviceDate < dobDate) {
                                    isValid = false;
                                    specificErrorMsg = 'Service date cannot be before Date of Birth.';
                                }
                            }
                        } catch(e) { isValid = false; specificErrorMsg = 'Invalid date.';}
                    } else { specificErrorMsg = 'Invalid date format (YYYY-MM-DD).'; }
                    break;
                case 'Address':
                    isValid = clientRegexPatterns.address.test(value);
                    specificErrorMsg = 'Invalid Address (5-255 chars, letters, numbers, ., \'()#/- allowed).';
                    break;
                case 'Description': // Optional field
                    isValid = clientRegexPatterns.description.test(value); // Regex allows empty
                    specificErrorMsg = 'Description cannot exceed 500 characters.';
                    // If empty, it's valid for an optional field
                    if (value === '') isValid = true;
                    break;
                default:
                    isValid = true; // Should not happen
            }
            // If the field was initially empty and optional, override specificErrorMsg
            if (value === '' && isOptional) {
                isValid = true;
                specificErrorMsg = '';
            }
        }


        if (currentFormState) {
            currentFormState[fieldName] = isValid;
        }

        applyValidationStyles(fieldElement, errorElement, isValid, specificErrorMsg);
        checkOverallValidity(formTypePrefix);
    }

    /*----- ADD CLIENT RECORD -----*/
    const addClientForm = document.getElementById('addClientForm');
    const addClientFields = {
        FullName: document.getElementById('addFullName'),
        Sex: document.getElementById('addSex'),
        DateOfBirth: document.getElementById('addDateOfBirth'),
        ContactInfo: document.getElementById('addContactInfo'),
        ServiceAvailed: document.getElementById('addServiceAvailed'),
        DateOfSaidService: document.getElementById('addDateOfSaidService'),
        Address: document.getElementById('addAddress'),
        Description: document.getElementById('addDescription') // Optional
    };
    const addClientFormState = {};
    const addSubmitButton = document.getElementById('addSubmitButton');

    function initializeAddClientValidation() {
        if (!addClientForm || !addSubmitButton) {
            console.warn("Add Client form or submit button not found."); return;
        }
        addSubmitButton.disabled = true;

        Object.keys(addClientFields).forEach(fieldName => {
            const fieldElement = addClientFields[fieldName];
            if (fieldElement) {
                // Description starts valid because it's optional and empty
                addClientFormState[fieldName] = (fieldName === 'Description');

                const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'date' || fieldElement.tagName === 'TEXTAREA') ? 'change' : 'input';
                const listener = () => validateClientField(fieldName, fieldElement.value, fieldElement, 'add');

                fieldElement.addEventListener(eventType, listener);
                fieldElement.addEventListener('blur', listener);
            } else {
                console.warn(`Field element not found for addClientFields.${fieldName}`);
            }
        });

        addClientForm.addEventListener('submit', function (event) {
            let formIsValid = true;
            Object.keys(addClientFields).forEach(fieldName => {
                const fieldElement = addClientFields[fieldName];
                if (fieldElement) {
                    validateClientField(fieldName, fieldElement.value, fieldElement, 'add');
                    if (!addClientFormState[fieldName]) {
                        formIsValid = false;
                    }
                }
            });

            if (!formIsValid) {
                event.preventDefault();
                alert('Please correct the errors highlighted in red before submitting.');
            }
        });
        // Initial check in case the form has default values (unlikely for add)
        checkOverallValidity('add');
    }

    function resetAddClientForm() {
        if (addClientForm) addClientForm.reset();
        Object.keys(addClientFields).forEach(fieldName => {
            const fieldElement = addClientFields[fieldName];
            const errorElement = document.getElementById('add' + fieldName + 'Error');
            if (fieldElement) {
                fieldElement.classList.remove('valid-input', 'invalid-input');
            }
            if (errorElement) {
                errorElement.classList.add('hidden');
                errorElement.textContent = '';
            }
            // Reset state - optional fields are valid if empty
            addClientFormState[fieldName] = (fieldName === 'Description');
        });
        if (addSubmitButton) addSubmitButton.disabled = true; // Should be disabled after reset
        checkOverallValidity('add'); // Re-check validity after reset
    }

    document.getElementById("addRecordBtn")?.addEventListener("click", function () {
        resetAddClientForm();
        const recordModal = document.getElementById("recordModal");
        if (recordModal) recordModal.style.display = "flex";
    });

    function closeModal() { // For Add Record Modal
        const recordModal = document.getElementById("recordModal");
        if (recordModal) {
            recordModal.style.display = "none";
            resetAddClientForm();
        }
    }
    /*----- ADD CLIENT RECORD [end] -----*/


    /*----- UPDATE CLIENT RECORD -----*/
    const updateClientForm = document.getElementById('updateClientForm');
    const updateClientFields = {
        // ClientID is read-only, not in this list for validation triggering
        FullName: document.getElementById('updateFullName'),
        Sex: document.getElementById('updateSex'),
        DateOfBirth: document.getElementById('updateDateOfBirth'),
        ContactInfo: document.getElementById('updateContactInfo'),
        ServiceAvailed: document.getElementById('updateServiceAvailed'),
        DateOfSaidService: document.getElementById('updateDateOfSaidService'),
        Address: document.getElementById('updateAddress'),
        Description: document.getElementById('updateDescription') // Optional
    };
    const updateClientFormState = {};
    const updateSubmitButton = document.getElementById('updateSubmitButton');


    function initializeUpdateClientValidation() {
        if (!updateClientForm || !updateSubmitButton) {
            console.warn("Update Client form or submit button not found."); return;
        }
        updateSubmitButton.disabled = true;

        Object.keys(updateClientFields).forEach(fieldName => {
            const fieldElement = updateClientFields[fieldName];
            if (fieldElement) {
                updateClientFormState[fieldName] = false; // Assume invalid until populated and validated

                const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'date' || fieldElement.tagName === 'TEXTAREA') ? 'change' : 'input';
                const listener = () => validateClientField(fieldName, fieldElement.value, fieldElement, 'update');

                fieldElement.addEventListener(eventType, listener);
                fieldElement.addEventListener('blur', listener);
            } else {
                console.warn(`Field element not found for updateClientFields.${fieldName}`);
            }
        });

        updateClientForm.addEventListener('submit', function (event) {
            let formIsValid = true;
            Object.keys(updateClientFields).forEach(fieldName => {
                const fieldElement = updateClientFields[fieldName];
                if (fieldElement) {
                    validateClientField(fieldName, fieldElement.value, fieldElement, 'update');
                    if (!updateClientFormState[fieldName]) {
                        formIsValid = false;
                    }
                }
            });

            // Also check admin password presence client-side
            const hiddenAdminPassword = document.getElementById('hiddenAdminPassword').value;
            if (hiddenAdminPassword === '') {
                if (formIsValid) alert('Admin password authentication is required to update.'); // Only show if other fields were okay
                formIsValid = false;
            }

            if (!formIsValid) {
                event.preventDefault();
                if(hiddenAdminPassword !== '') { // Only show general alert if password was provided
                    alert('Please correct the errors highlighted in red before submitting the update.');
                }
            }
        });
        // Initial check happens after populating fields via row click
    }

    function resetUpdateClientForm() {
        // Reset only editable fields
        Object.keys(updateClientFields).forEach(fieldName => {
            const fieldElement = updateClientFields[fieldName];
            const errorElement = document.getElementById('update' + fieldName + 'Error');
            if (fieldElement) {
                if (fieldElement.tagName === 'SELECT') fieldElement.value = '';
                else fieldElement.value = '';
                fieldElement.classList.remove('valid-input', 'invalid-input');
            }
            if (errorElement) {
                errorElement.classList.add('hidden');
                errorElement.textContent = '';
            }
            updateClientFormState[fieldName] = false; // Reset state
        });

        // Clear read-only Client ID
        const clientIdField = document.getElementById('updateClientID');
        if (clientIdField) clientIdField.value = '';

        if (updateSubmitButton) updateSubmitButton.disabled = true;
        document.getElementById('hiddenAdminPassword').value = '';
        // Don't need checkOverallValidity here, as it's called by validateClientField when populated
    }


    document.getElementById("updateRecordBtn")?.addEventListener("click", function () {
        adminAuthenticated = false; // Require auth each time
        resetUpdateClientForm();    // Reset fields before showing admin modal
        openAdminModal();
    });

    // Enable row click for editing AFTER admin authentication
    function enableRowClickEdit() {
        const rows = document.querySelectorAll("#recordsTable tbody tr");
        rows.forEach(row => {
            // Clone to remove previous listeners
            const newRow = row.cloneNode(true);
            row.parentNode.replaceChild(newRow, row);
            newRow.style.cursor = "pointer";

            newRow.addEventListener('click', function () {
                if (!adminAuthenticated) {
                    showMessageModal("Admin authentication required. Click '✎ Update Record' first.");
                    return;
                }

                const cells = this.querySelectorAll("td");
                const clientData = {
                    ClientID: cells[0]?.innerText.trim() ?? '',
                    FullName: cells[1]?.innerText.trim() ?? '',
                    Sex: cells[2]?.innerText.trim() ?? '',
                    DateOfBirth: cells[3]?.innerText.trim() ?? '',
                    ContactInfo: cells[4]?.innerText.trim() ?? '',
                    ServiceAvailed: cells[5]?.innerText.trim() ?? '',
                    DateOfSaidService: cells[6]?.innerText.trim() ?? '',
                    Address: cells[7]?.innerText.trim() ?? '',
                    Description: cells[8]?.innerText.trim() ?? ''
                };

                // Populate Update Modal fields
                document.getElementById("updateClientID").value = clientData.ClientID; // Use value for readonly
                document.getElementById("updateFullName").value = clientData.FullName;
                document.getElementById("updateSex").value = clientData.Sex;
                document.getElementById("updateDateOfBirth").value = clientData.DateOfBirth;
                document.getElementById("updateContactInfo").value = clientData.ContactInfo;
                document.getElementById("updateServiceAvailed").value = clientData.ServiceAvailed;
                document.getElementById("updateDateOfSaidService").value = clientData.DateOfSaidService;
                document.getElementById("updateAddress").value = clientData.Address;
                document.getElementById("updateDescription").value = clientData.Description;


                // Trigger validation for ALL populated update fields
                Object.keys(updateClientFields).forEach(fieldName => {
                    const fieldElement = updateClientFields[fieldName];
                    if (fieldElement) {
                        validateClientField(fieldName, fieldElement.value, fieldElement, 'update');
                    }
                });

                // Open the update modal
                const updateModal = document.getElementById("updateModal");
                if (updateModal) updateModal.style.display = "flex";
            });
        });
    }

    function disableRowClickEdit() {
        const rows = document.querySelectorAll("#recordsTable tbody tr");
        rows.forEach(row => {
            const newRow = row.cloneNode(true); // Clone to remove listener
            row.parentNode.replaceChild(newRow, row);
            newRow.style.cursor = "default";
        });
    }

    function closeUpdateModal() {
        const updateModal = document.getElementById("updateModal");
        if (updateModal) {
            updateModal.style.display = "none";
        }
        adminAuthenticated = false; // Reset auth when modal closes
        disableRowClickEdit();
        resetUpdateClientForm(); // Reset form state
    }
    /*----- UPDATE CLIENT RECORD [end] -----*/


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
        const clientId = row.querySelectorAll("td")[0]?.textContent.toLowerCase() || "";
        row.style.display = clientId.includes(filter) ? "" : "none";
    });
});


    // Close modals on outside click
    window.addEventListener("click", function (event) {
        const recordModal = document.getElementById("recordModal");
        const updateModal = document.getElementById("updateModal");
        const adminModal = document.getElementById("adminModal");
        const certModal = document.getElementById("certificateModal");

        if (event.target === recordModal) closeModal();
        if (event.target === updateModal) closeUpdateModal();
        if (event.target === adminModal) closeAdminModal();
        if (event.target === certModal) closeCertModal();
    });

    /*----- INITIALIZE ON DOM LOADED -----*/
    document.addEventListener('DOMContentLoaded', function() {
        initializeAddClientValidation();
        initializeUpdateClientValidation();

        // Set sidebar state based on current page
        const certificateDropdown = document.getElementById("certificateDropdown");
        const certificatesItem = document.getElementById("certificates");
        const clientButton = document.getElementById("clientButton"); // Get client button
        const dropdownIcon = certificatesItem?.querySelector("#dropdownIcon");

        // --- START: New Filter JavaScript for client.php ---
        const categoryFilter_client = document.getElementById('categoryFilterClient');
        const yearInputContainer_client = document.getElementById('filterYearInputContainerClient');
        const monthInputContainer_client = document.getElementById('filterMonthInputContainerClient');
        const dateInputContainer_client = document.getElementById('filterDateInputContainerClient');

        const yearValueInput_client = document.getElementById('filterYearValueClient');
        const yearForMonthValueInput_client = document.getElementById('filterYearForMonthValueClient');
        const monthValueSelect_client = document.getElementById('filterMonthValueClient');
        const dateValueInput_client = document.getElementById('filterDateValueClient');

        const applyFilterButton_client = document.getElementById('applyFilterBtnClient');
        const clearFilterButton_client = document.getElementById('clearFilterBtnClient');
        const searchInput_client = document.getElementById('searchInput'); // If you want to clear it too

        function toggleFilterInputs_client() {
            if (!categoryFilter_client) return;
            const selectedFilter = categoryFilter_client.value;

            if(yearInputContainer_client) yearInputContainer_client.style.display = 'none';
            if(monthInputContainer_client) monthInputContainer_client.style.display = 'none';
            if(dateInputContainer_client) dateInputContainer_client.style.display = 'none';

            if (selectedFilter === 'year' && yearInputContainer_client) {
                yearInputContainer_client.style.display = 'inline-block';
            } else if (selectedFilter === 'month' && monthInputContainer_client) {
                monthInputContainer_client.style.display = 'inline-block';
            } else if (selectedFilter === 'specific_date' && dateInputContainer_client) {
                dateInputContainer_client.style.display = 'inline-block';
            }
        }

        if (categoryFilter_client) {
            categoryFilter_client.addEventListener('change', toggleFilterInputs_client);
        }

        if (applyFilterButton_client) {
            applyFilterButton_client.addEventListener('click', function() {
                if (!categoryFilter_client) return;
                const filterType = categoryFilter_client.value;
                if (!filterType) return;

                let queryParams = new URLSearchParams();
                queryParams.set('filter_type_client', filterType); // Use _client suffix for GET param

                if (filterType === 'year') {
                    if (!yearValueInput_client || !yearValueInput_client.value || !/^\d{4}$/.test(yearValueInput_client.value)) {
                        alert('Please enter a valid 4-digit year.');
                        return;
                    }
                    queryParams.set('filter_year_value_client', yearValueInput_client.value);
                } else if (filterType === 'month') {
                    if (!monthValueSelect_client || !monthValueSelect_client.value) {
                        alert('Please select a month.');
                        return;
                    }
                    queryParams.set('filter_month_value_client', monthValueSelect_client.value);
                    if (yearForMonthValueInput_client && yearForMonthValueInput_client.value) {
                        if (!/^\d{4}$/.test(yearForMonthValueInput_client.value)) {
                            alert('If providing a year for the month, please enter a valid 4-digit year.');
                            return;
                        }
                        queryParams.set('filter_year_for_month_value_client', yearForMonthValueInput_client.value);
                    }
                } else if (filterType === 'specific_date') {
                    if (!dateValueInput_client || !dateValueInput_client.value) {
                        alert('Please select a date.');
                        return;
                    }
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValueInput_client.value)) {
                        alert('Invalid date format. Expected YYYY-MM-DD.'); return;
                    }
                    queryParams.set('filter_date_value_client', dateValueInput_client.value);
                } else if (filterType === 'oldest_to_latest') {
                    queryParams.set('sort_order_client', 'asc');
                } else if (filterType === 'latest_to_oldest') {
                    queryParams.set('sort_order_client', 'desc');
                }
                window.location.search = queryParams.toString();
            });
        }

        if (clearFilterButton_client) {
            clearFilterButton_client.addEventListener('click', function(event) {
                event.preventDefault();
                if (searchInput_client) {
                    searchInput_client.value = ''; // Clear text search
                }
                window.location.href = window.location.pathname; // Reload without query params
            });
        }

        function setFiltersFromUrl_client() {
            if (!categoryFilter_client) return;
            const urlParams = new URLSearchParams(window.location.search);
            const filterTypeFromUrl = urlParams.get('filter_type_client');

            // Reset UI elements
            categoryFilter_client.value = "";
            if(yearValueInput_client) yearValueInput_client.value = "";
            if(yearForMonthValueInput_client) yearForMonthValueInput_client.value = "";
            if(monthValueSelect_client) monthValueSelect_client.value = "";
            if(dateValueInput_client) dateValueInput_client.value = ""; // type="date" handles empty value well
            toggleFilterInputs_client(); // Hide all conditional inputs

            if (filterTypeFromUrl) {
                categoryFilter_client.value = filterTypeFromUrl;
                toggleFilterInputs_client(); // Show correct input group

                if (filterTypeFromUrl === 'year' && urlParams.has('filter_year_value_client') && yearValueInput_client) {
                    yearValueInput_client.value = urlParams.get('filter_year_value_client');
                } else if (filterTypeFromUrl === 'month') {
                    if (urlParams.has('filter_month_value_client') && monthValueSelect_client) {
                        monthValueSelect_client.value = urlParams.get('filter_month_value_client');
                    }
                    if (urlParams.has('filter_year_for_month_value_client') && yearForMonthValueInput_client) {
                        yearForMonthValueInput_client.value = urlParams.get('filter_year_for_month_value_client');
                    }
                } else if (filterTypeFromUrl === 'specific_date' && urlParams.has('filter_date_value_client') && dateValueInput_client) {
                    dateValueInput_client.value = urlParams.get('filter_date_value_client');
                }
            } else if (urlParams.has('sort_order_client')) {
                const sortOrder = urlParams.get('sort_order_client');
                if (sortOrder === 'asc') categoryFilter_client.value = 'oldest_to_latest';
                if (sortOrder === 'desc') categoryFilter_client.value = 'latest_to_oldest';
            }
        }

        setFiltersFromUrl_client(); // Call on page load
        // --- END: New Filter JavaScript for client.php ---
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