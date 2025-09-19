<?php

// Prevent caching of the page after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$pageTitle = "Confirmation Records Management";
require_once 'session_timeout.php'; 

// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // If not logged in, redirect to the login page
    header("Location: Log_In/login_system.php"); // Correct path if needed
    exit();
}

// --- START: Filter Logic for GET requests (Confirmation Records by YearOfConfirmation) ---
$whereClauses_confirmation = [];
// Default order for confirmation records
$orderByClause_confirmation = "ORDER BY cr.ConfirmationID DESC";
$filter_params_confirmation = []; // Parameters for prepared statement
$filter_param_types_confirmation = ""; // Types for prepared statement

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (isset($_GET['filter_type_confirmation']) && !empty($_GET['filter_type_confirmation'])) {
        $filter_type = $_GET['filter_type_confirmation'];

        switch ($filter_type) {
            case 'year':
                if (isset($_GET['filter_year_value_confirmation']) && !empty($_GET['filter_year_value_confirmation'])) {
                    $year = filter_var($_GET['filter_year_value_confirmation'], FILTER_VALIDATE_INT);
                    if ($year && strlen((string)$year) == 4) {
                        $whereClauses_confirmation[] = "cr.YearOfConfirmation = ?";
                        $filter_params_confirmation[] = $year;
                        $filter_param_types_confirmation .= "i";
                    }
                }
                break;
            // Month and Specific Date cases are removed as column is not available
            case 'oldest_to_latest':
                $orderByClause_confirmation = "ORDER BY cr.YearOfConfirmation ASC, cr.ConfirmationID ASC";
                break;
            case 'latest_to_oldest':
                $orderByClause_confirmation = "ORDER BY cr.YearOfConfirmation DESC, cr.ConfirmationID DESC";
                break;
        }
    }
    // Handle standalone sort_order parameter
    if (isset($_GET['sort_order_confirmation'])) {
        if ($_GET['sort_order_confirmation'] === 'asc' && $filter_type !== 'oldest_to_latest') {
            $orderByClause_confirmation = "ORDER BY cr.YearOfConfirmation ASC, cr.ConfirmationID ASC";
        } elseif ($_GET['sort_order_confirmation'] === 'desc' && $filter_type !== 'latest_to_oldest') {
            $orderByClause_confirmation = "ORDER BY cr.YearOfConfirmation DESC, cr.ConfirmationID DESC";
        }
    }
}
// --- END: Filter Logic (Confirmation Records) ---


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");

    if ($conn->connect_error) {
        error_log("Database Connection Failed: " . $conn->connect_error); // Log error instead of dying
        echo "<script>alert('Error: Could not connect to the database. Please try again later.'); window.history.back();</script>";
        exit();
    }

    // --- Retrieve and Trim POST data ---
    $confirmationID_for_update = trim($_POST['ConfirmationID'] ?? ''); // Used only for update
    $yearOfConfirmation        = trim($_POST['YearOfConfirmation'] ?? '');
    $clientID                  = trim($_POST['ClientID'] ?? '');
    // $fullName                  = trim($_POST['FullName'] ?? '');
    $parentName                = trim($_POST['ParentName'] ?? '');
    $sponsorName               = trim($_POST['SponsorName'] ?? '');
    $priestID                  = trim($_POST['PriestID'] ?? ''); // Used for both add/update

    // --- SERVER-SIDE VALIDATION ---
    $errors = [];
    $currentYear = date("Y");

    // ConfirmationID validation (only if it's an update action)
    if (isset($_POST['updateRecord'])) {
        if (empty($confirmationID_for_update)) {
            $errors[] = "Confirmation ID is required for an update.";
        } elseif (!ctype_digit($confirmationID_for_update) || (int)$confirmationID_for_update <= 0) {
            $errors[] = "Invalid Confirmation ID format for update (must be a positive number).";
        }
    }

    // YearOfConfirmation
    if (empty($yearOfConfirmation)) {
        $errors[] = "Year of Confirmation is required.";
    } elseif (!ctype_digit($yearOfConfirmation) || strlen($yearOfConfirmation) !== 4) {
        $errors[] = "Invalid Year of Confirmation format (must be a 4-digit number).";
    } elseif ((int)$yearOfConfirmation < 1900 || (int)$yearOfConfirmation > ($currentYear + 5)) { // Allow up to 5 years in the future as a buffer
        $errors[] = "Year of Confirmation must be between 1900 and " . ($currentYear + 5) . ".";
    }


    // ClientID
    if (empty($clientID)) {
        $errors[] = "Client ID is required.";
    } elseif (!ctype_digit($clientID) || (int)$clientID <= 0) {
        $errors[] = "Invalid Client ID format (must be a positive number).";
    }

    // FullName (using a similar regex to your Priest example for names)
    $nameRegex = '/^[a-zA-Z\s.\'-]{2,100}$/';
    // if (empty($fullName)) {
    //     $errors[] = "Full Name is required.";
    // } elseif (!preg_match($nameRegex, $fullName)) {
    //     $errors[] = "Invalid Full Name format (letters, spaces, '.', ''', - allowed, 2-100 chars).";
    // }

    // ParentName (using the same regex for names)
    if (empty($parentName)) {
        $errors[] = "Parent's Name is required.";
    } elseif (!preg_match($nameRegex, $parentName)) {
        $errors[] = "Invalid Parent's Name format (letters, spaces, '.', ''', - allowed, 2-100 chars).";
    }

    // SponsorName (using the same regex for names)
    if (empty($sponsorName)) {
        $errors[] = "Sponsor's Name is required.";
    } elseif (!preg_match($nameRegex, $sponsorName)) {
        $errors[] = "Invalid Sponsor's Name format (letters, spaces, '.', ''', - allowed, 2-100 chars).";
    }

    // PriestID
    if (empty($priestID)) {
        $errors[] = "Priest selection is required.";
    } elseif (!ctype_digit($priestID) || (int)$priestID <= 0) {
        $errors[] = "Invalid Priest ID received."; // Should not happen with select, but good safety check
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
        $sql = "INSERT INTO confirmation_records (YearOfConfirmation, ClientID, ParentName, SponsorName, PriestID, ParishStaffID)
                VALUES (?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("SQL Prepare Error (Insert Confirmation): " . $conn->error);
            echo "<script>alert('Error preparing the record. Please try again.'); window.history.back();</script>";
            $conn->close();
            exit();
        }
        $stmt->bind_param("iissii", $yearOfConfirmation, $clientID, $parentName, $sponsorName, $priestID, $parishStaffID);

        if ($stmt->execute()) {
            echo "<script>alert('Record inserted successfully!'); window.location.href = window.location.href;</script>";
            exit();
        } else {
            error_log("SQL Execute Error (Insert Confirmation): " . $stmt->error);
            echo "<script>alert('Error inserting record: " . htmlspecialchars($stmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
        }
        $stmt->close();
        // FOR UPDATING RECORDS
    } elseif (isset($_POST['updateRecord'])) {
        // Admin password check (retained from your original code)
        $adminPassword = $_POST['adminPassword'] ?? '';
        $isValid = false;

        $sql = "SELECT PasswordHash FROM admin_users";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                if (password_verify($adminPassword, $row['PasswordHash'])) {
                    $isValid = true;
                    break;
                }
            }
        }

        if (!$isValid) {
            echo "<script>alert('Incorrect admin password. Update denied.'); window.history.back();</script>";
        } else {
            // $confirmationID_for_update was validated earlier
            $sql = "UPDATE confirmation_records
                    SET YearOfConfirmation=?, ClientID=?, ParentName=?, SponsorName=?, PriestID=?
                    WHERE ConfirmationID=?";
            $updateStmt = $conn->prepare($sql);
            if ($updateStmt === false) {
                error_log("SQL Prepare Error (Update Confirmation): " . $conn->error);
                echo "<script>alert('Error preparing the update. Please try again.'); window.history.back();</script>";
                $conn->close();
                exit();
            }
            $updateStmt->bind_param("iissii", $yearOfConfirmation, $clientID, $parentName, $sponsorName, $priestID, $confirmationID_for_update);

            if ($updateStmt->execute()) {
                if ($updateStmt->affected_rows > 0) {
                    echo "<script>alert('Record updated successfully! " . $updateStmt->affected_rows . " row(s) affected.'); window.location.href = window.location.href;</script>";
                } else if ($updateStmt->affected_rows === 0) {
                    echo "<script>alert('Update query executed, but 0 rows were affected. This might mean the Confirmation ID was not found, or the data submitted was identical to the existing record.'); window.location.href = window.location.href;</script>";
                } else {
                    echo "<script>alert('Record updated successfully (unknown affected rows)!'); window.location.href = window.location.href;</script>";
                }
                exit();
            } else {
                error_log("SQL Execute Error (Update Confirmation): " . $updateStmt->error . " | ConfirmationID: " . $confirmationID_for_update);
                echo "<script>alert('Update error: " . htmlspecialchars($updateStmt->error, ENT_QUOTES) . "'); window.history.back();</script>";
            }
            $updateStmt->close();
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
    <link rel="stylesheet" href="confirmationstyle.css?v=17">
    <link rel="stylesheet" href="responsive.css?v=16">
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
        <div class="section-title">Confirmation Records</div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search..." style="flex-grow: 1;">

            <!-- START: Category Filter for Confirmation -->
            <div class="filter-container">
                <select id="categoryFilterConfirmation" name="category_filter_confirmation" title="Category Filter">
                    <option value="">-- Filter By Year --</option>
                    <option value="year">Year of Confirmation</option>
                    <option value="oldest_to_latest">Oldest to Latest (Year)</option>
                    <option value="latest_to_oldest">Latest to Oldest (Year)</option>
                    <!-- Month and Specific Date options are removed as there's no full date column -->
                </select>

                <div id="filterYearInputContainerConfirmation" class="filter-input-group" style="display:none;">
                    <input type="number" id="filterYearValueConfirmation" name="filter_year_value_confirmation" placeholder="YYYY">
                </div>
                <!-- Month and Specific Date input containers are removed -->

                <button id="applyFilterBtnConfirmation" class="filter-btn">Apply</button>
                <button id="clearFilterBtnConfirmation" class="filter-btn">Clear</button>
            </div>
            <!-- END: Category Filter for Confirmation -->

            <div class="record-buttons" style="margin-left: auto;">
                <button id="updateRecordBtn">✎ Update Record</button>
                <button id="addRecordBtn">+ Add Record</button>
            </div>
        </div>

        <div class="table-scroll">
            <table id="recordsTable">
                <thead>
                <tr>
                    <th>Confirmation ID</th>
                    <th>Year of Confirmation</th>
                    <th>Client ID</th>
                    <!-- <th>Full Name</th> -->
                    <th>Parent Name</th>
                    <th>Sponsor Name</th>
                    <th>Priest Name</th>
                    <th>Created By</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $conn = new mysqli("localhost", "root", "", "SacredHeartParish_DBMS");
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error);
                }
                // start modify for filter

                $baseSqlConfirmation = "SELECT 
                        cr.*,
                        p.FullName AS PriestName,
                        c.FullName AS ClientName,
                        COALESCE(au.username, su.username, 'Unknown') AS CreatedBy
                    FROM confirmation_records cr
                    LEFT JOIN priest p ON cr.PriestID = p.PriestID
                    LEFT JOIN parishstaff ps ON cr.ParishStaffID = ps.ParishStaffID
                    LEFT JOIN admin_users au ON ps.AdminUserID = au.ID
                    LEFT JOIN staff_users su ON ps.StaffUserID = su.id
                    LEFT JOIN client c ON cr.ClientID = c.ClientID
                    ";

                $finalSqlConfirmation = $baseSqlConfirmation;

                if (!empty($whereClauses_confirmation)) {
                    $finalSqlConfirmation .= " WHERE " . implode(" AND ", $whereClauses_confirmation);
                }
                $finalSqlConfirmation .= " " . $orderByClause_confirmation;

                $resultConfirmation = null; // Initialize

                if (!empty($filter_params_confirmation)) {
                    $stmtConfirmation = $conn->prepare($finalSqlConfirmation);
                    if ($stmtConfirmation === false) {
                        error_log("SQL Prepare Error (Filter Confirmation): " . $conn->error . " | SQL: " . $finalSqlConfirmation);
                        echo "<tr><td colspan='7'>Error preparing confirmation data.</td></tr>"; // Adjusted colspan
                    } else {
                        $stmtConfirmation->bind_param($filter_param_types_confirmation, ...$filter_params_confirmation);
                        $stmtConfirmation->execute();
                        $resultConfirmation = $stmtConfirmation->get_result();
                        if ($resultConfirmation === false) {
                            error_log("SQL Get Result Error (Filter Confirmation): " . $stmtConfirmation->error);
                            echo "<tr><td colspan='7'>Error retrieving filtered confirmation data.</td></tr>";
                        }
                    }
                } else {
                    $resultConfirmation = $conn->query($finalSqlConfirmation);
                    if ($resultConfirmation === false) {
                        error_log("SQL Query Error (Confirmation): " . $conn->error . " | SQL: " . $finalSqlConfirmation);
                        echo "<tr><td colspan='7'>Error fetching confirmation data.</td></tr>";
                    }
                }

                if ($resultConfirmation && $resultConfirmation->num_rows > 0) {
                    while ($row = $resultConfirmation->fetch_assoc()) {
                        // Using htmlspecialchars for all echoed data
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row["ConfirmationID"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["YearOfConfirmation"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["ClientName"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["ParentName"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["SponsorName"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["PriestName"] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row["CreatedBy"] ?? '-') . "</td>";
                        echo "</tr>";
                    }
                } else if ($resultConfirmation) {
                    echo "<tr><td colspan='7'>No confirmation records found matching your criteria.</td></tr>"; // Adjusted colspan
                }
                $conn->close(); //  end filter
                ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Modal [start] -->
    <div class="modal" id="recordModal">
        <form class="modal-content" id="addConfirmationForm" method="POST" action="confirmation.php" style="width: 1000px; height: 650px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>

            <div class="modal-header" style="background: #2c3e50; color: white; text-align: center; border-radius: 0; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Confirmation Details</h3>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">

                <div style="flex: 1 1 45%;">
                    <label for="YearOfConfirmation" style="margin-left: 55px;">Year of Confirmation:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
                    <input type="number" name="YearOfConfirmation" id="addYearOfConfirmation" min="1900" max="2099" step="1"
                           required style="width: 80%; padding: 5px; margin-left: 55px;"
                           onfocus="showFormat('yearFormat')" onblur="hideFormat('yearFormat')">
                    <small id="addYearOfConfirmationError" class="error-message hidden" style="margin-left: 55px;">Year must be a 4-digit number (e.g., 2023).</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="ParentName" style="margin-left: 30px;">Parent's Name:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
                    <input type="text" name="ParentName" id="addParentName" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addParentNameError" class="error-message hidden" style="margin-left: 30px;">Parent's Name is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="ClientID" style="margin-left: 55px;">Client ID:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
                    <input type="number" name="ClientID" id="addClientID" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addClientIDError" class="error-message hidden" style="margin-left: 55px;">Client ID is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="SponsorName" style="margin-left: 30px;">Sponsor's Name:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
                    <input type="text" name="SponsorName" id="addSponsorName" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="addSponsorNameError" class="error-message hidden" style="margin-left: 30px;">Sponsor's Name is required.</small>
                </div>
<!-- 
                <div style="flex: 1 1 45%;">
                    <label for="FullName" style="margin-left: 55px;">Full Name:</label><br>
                    <input type="text" name="FullName" id="addFullName" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="addFullNameError" class="error-message hidden" style="margin-left: 55px;">Full Name is required.</small>
                </div> -->


                <div style="flex: 1 1 45%;">
                    <label for="PriestID" style="margin-left: 55px;">Select Priest:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
                    <select name="PriestID" id="addPriestID" required style="width: 39%; padding: 5px; margin-left: 55px;">
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
                    <small id="addPriestIDError" class="error-message hidden" style="margin-left: 30px;">Priest selection is required.</small>
                </div>
            </div>

            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" name="submitRecord" id="addSubmitButton" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px; cursor: not-allowed;">+ Add Record</button>
            </div>
        </form>
    </div>
    <!-- Add Modal [end] -->

    <!-- Update Modal [start] -->
    <div class="modal" id="updateModal">
        <form class="modal-content" id="updateConfirmationForm" method="POST" action="confirmation.php" style="width: 1000px; height: 600px; background: #f3f3f3; border-radius: 8px; padding: 10px; position: relative;">
            <span onclick="closeUpdateModal()" style="position: absolute; top: 90px; left: 20px; font-weight: bolder; font-size: 24px; cursor: pointer;">←</span>

            <div class="modal-header" style="background: #F39C12; color: white; text-align: center; margin: -10px -10px; width: 102%; padding: 20px 0;">
                <h3 style="margin: 0; font-size: 25px;">Update Confirmation Record</h3>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 90px; justify-content: space-between;">

                <div style="flex: 1 1 45%;">
                    <label for="updateConfirmationID" style="margin-left: 55px;">Confirmation ID:</label><br>
                    <input type="text" id="updateConfirmationID" name="ConfirmationID" readonly style="width: 80%; padding: 5px; margin-left: 55px; background-color: #e9e9e9;">
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateParentName" style="margin-left: 30px;">Parent's Name:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
                    <input type="text" id="updateParentName" name="ParentName" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateParentNameError" class="error-message hidden" style="margin-left: 30px;">Parent's Name is required.</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateYearOfConfirmation" style="margin-left: 55px;">Year of Confirmation:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
                    <input type="number" id="updateYearOfConfirmation" name="YearOfConfirmation" min="1900" max="2099" step="1"
                           required style="width: 80%; padding: 5px; margin-left: 55px;"
                           onfocus="showFormat('yearFormat')" onblur="hideFormat('yearFormat')">
                    <small id="updateYearOfConfirmationError" class="error-message hidden" style="margin-left: 55px;">Year must be a 4-digit number (e.g., 2023).</small>
                </div>

                <div style="flex: 1 1 45%;">
                    <label for="updateSponsorName" style="margin-left: 30px;">Sponsor's Name:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
                    <input type="text" id="updateSponsorName" name="SponsorName" required style="width: 80%; padding: 5px; margin-left: 30px;">
                    <small id="updateSponsorNameError" class="error-message hidden" style="margin-left: 30px;">Sponsor's Name is required.</small>
                </div>


                <div style="flex: 1 1 45%;">
                    <label for="updateClientID" style="margin-left: 55px;">Client ID:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
                    <input type="number" id="updateClientID" name="ClientID" required style="width: 80%; padding: 5px; margin-left: 55px;">
                    <small id="updateClientIDError" class="error-message hidden" style="margin-left: 55px;">Client ID is required.</small>
                </div>



                <div style="flex: 1 1 45%;">
                    <label for="updatePriestID" style="margin-left: 30px;">Select Priest:
                        <span class="required-asterisk" style="color:red">*</span>
                    </label><br>
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
                    <small id="updatePriestIDError" class="error-message hidden" style="margin-left: 30px;">Priest selection is required.</small>
                </div>

                <!-- <div style="flex: 1 1 45%;">
                    <label for="updateFullName" style="margin-left: 55px;">Full Name:</label><br>
                    <input type="text" id="updateFullName" name="FullName" required style="width: 39.8%; padding: 5px; margin-left: 55px;">
                    <small id="updateFullNameError" class="error-message hidden" style="margin-left: 55px;">Full Name is required.</small>
                </div> -->
            </div>

            <div class="modal-footer" style="text-align: center; margin-top: 60px;">
                <button type="submit" name="updateRecord" id="updateSubmitButton" disabled style="background-color: #cccccc; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px; cursor: not-allowed;">✎ Update Record</button>
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
        const confirmationRegexPatterns = {
            year: /^\d{4}$/, // 4-digit number
            positiveInteger: /^\d+$/, // One or more digits
            name: /^[a-zA-Z\s.\'-]{2,100}$/ // Letters, spaces, '.', ''', - (2-100 chars)
        };
        const currentYear = new Date().getFullYear();

        /*----- ADMIN PERMISSION [start]-----*/
        let adminAuthenticated = false;

        // Close modal on ESC key (added for admin and message modals)
        document.addEventListener('keydown', function (event) {
            if (event.key === "Escape") {
                if (document.getElementById("adminModal").style.display === "block" || document.getElementById("adminModal").style.display === "flex") {
                    closeAdminModal();
                }
                if (document.getElementById("messageModal").style.display === "block" || document.getElementById("messageModal").style.display === "flex") {
                    document.getElementById('messageModal').style.display = 'none';
                }
            }
        });

        function openAdminModal() {
            document.getElementById("adminPassword").value = ""; // Clear previous password
            document.getElementById("adminModal").style.display = "flex";
        }

        function closeAdminModal() {
            document.getElementById("adminModal").style.display = "none";
            adminAuthenticated = false;  // Reset auth
            document.getElementById("adminPassword").value = ''; // Clear password input
            document.getElementById("hiddenAdminPassword").value = ''; // Clear hidden field
            disableRowClickEdit(); // Disable row editing if admin modal is closed without successful auth
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

        /*----- COMMON FIELD VALIDATION FUNCTION -----*/
        function validateConfirmationField(fieldName, value, fieldElement, formTypePrefix) { // formTypePrefix is 'add' or 'update'
            let isValid = false;
            const errorElement = document.getElementById(formTypePrefix + fieldName + 'Error');
            // Use the correct form state object based on prefix
            const currentFormState = (formTypePrefix === 'add') ? addConfirmationFormState : updateConfirmationFormState;
            const submitBtn = (formTypePrefix === 'add') ? addSubmitButton : updateSubmitButton;

            if (!errorElement) {
                console.error("Error element not found for:", formTypePrefix + fieldName + 'Error');
                // Assume valid if error element is missing, to avoid blocking
                currentFormState[fieldName] = true;
                if (fieldElement) fieldElement.style.border = '';
                checkConfirmationFormOverallValidity(formTypePrefix);
                return;
            }

            value = value.trim();
            let defaultErrorMsg = fieldName.replace(/([A-Z])/g, ' $1').trim() + ' is required.'; // Basic "Field Name is required."
            let specificErrorMsg = '';

            if (value === '') {
                isValid = false;
                specificErrorMsg = defaultErrorMsg;
            } else {
                switch(fieldName) {
                    case 'YearOfConfirmation':
                        isValid = confirmationRegexPatterns.year.test(value) && parseInt(value) >= 1900 && parseInt(value) <= (currentYear + 5); // Check range
                        specificErrorMsg = 'Please enter a valid 4-digit year between 1900 and ' + (currentYear + 5) + '.';
                        break;
                    case 'ClientID':
                        isValid = confirmationRegexPatterns.positiveInteger.test(value) && parseInt(value) > 0;
                        specificErrorMsg = 'Client ID must be a positive number.';
                        break;
                    // case 'FullName':
                    case 'ParentName':
                    case 'SponsorName':
                        isValid = confirmationRegexPatterns.name.test(value);
                        specificErrorMsg = 'Invalid name format. Use letters, spaces, periods, apostrophes, hyphens (2-100 chars).';
                        break;
                    case 'PriestID': // This comes from a select, value should be a positive integer
                        // Check if a non-empty, non-zero value is selected
                        isValid = value !== '' && value !== '0';
                        // Optional: You could add regex check for /^\d+$/ if needed, but select limits input
                        specificErrorMsg = 'Please select a Priest.';
                        break;
                    default:
                        isValid = true; // Should not happen for defined fields
                }
            }


            currentFormState[fieldName] = isValid;
            if (fieldElement) {
                if (isValid) {
                    fieldElement.style.border = '2px solid green';
                    errorElement.classList.add('hidden');
                } else {
                    fieldElement.style.border = '2px solid red';
                    errorElement.classList.remove('hidden');
                    errorElement.textContent = specificErrorMsg;
                }
            }
            checkConfirmationFormOverallValidity(formTypePrefix);
        }

        // Check overall form validity and enable/disable button
        function checkConfirmationFormOverallValidity(formTypePrefix) {
            const currentFormState = (formTypePrefix === 'add') ? addConfirmationFormState : updateConfirmationFormState;
            const currentFields = (formTypePrefix === 'add') ? addConfirmationFields : updateConfirmationFields;
            const submitBtn = (formTypePrefix === 'add') ? addSubmitButton : updateSubmitButton;

            if (!submitBtn) return;

            // Check if ALL required fields have a state of true
            const allValid = Object.keys(currentFields).every(fieldName => {
                // If a field element doesn't exist in the current form's fields, skip it (like ConfirmationID in add)
                return currentFields[fieldName] ? currentFormState[fieldName] === true : true;
            });

            if (allValid) {
                submitBtn.disabled = false;
                submitBtn.style.backgroundColor = (formTypePrefix === 'add') ? '#28a745' : '#F39C12';
                submitBtn.style.cursor = 'pointer';
            } else {
                submitBtn.disabled = true;
                submitBtn.style.backgroundColor = '#cccccc';
                submitBtn.style.cursor = 'not-allowed';
            }
        }

        /*----- ADD RECORDS [start]-----*/
        const addConfirmationForm = document.getElementById('recordModal').querySelector('form');
        const addConfirmationFields = {
            YearOfConfirmation: document.getElementById('addYearOfConfirmation'),
            ClientID: document.getElementById('addClientID'),
            // FullName: document.getElementById('addFullName'),
            ParentName: document.getElementById('addParentName'),
            SponsorName: document.getElementById('addSponsorName'),
            PriestID: document.getElementById('addPriestID') // Select element
        };
        const addConfirmationFormState = {}; // To track validation state of each field
        const addSubmitButton = document.getElementById('addSubmitButton');


        function initializeAddConfirmationValidation() {
            if (!addConfirmationForm || !addSubmitButton) return;

            addSubmitButton.disabled = true; // Start disabled
            addSubmitButton.style.backgroundColor = '#cccccc';
            addSubmitButton.style.cursor = 'not-allowed';


            Object.keys(addConfirmationFields).forEach(fieldName => {
                const fieldElement = addConfirmationFields[fieldName];
                if (fieldElement) {
                    // Initialize state to false or check current value if pre-filled
                    addConfirmationFormState[fieldName] = false; // Start assuming invalid

                    const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'number') ? 'change' : 'input';
                    fieldElement.addEventListener(eventType, function() {
                        validateConfirmationField(fieldName, this.value, this, 'add');
                    });
                    fieldElement.addEventListener('blur', function() { // Validate on blur as well
                        validateConfirmationField(fieldName, this.value, this, 'add');
                    });

                    // Initial validation on load if fields have values (unlikely for add modal initially)
                    if(fieldElement.value) {
                        validateConfirmationField(fieldName, fieldElement.value, fieldElement, 'add');
                    }
                }
            });

            // Add a submit event listener to perform a final check before allowing submission
            addConfirmationForm.addEventListener('submit', function(event) {
                let formIsValid = true;
                // Re-validate all fields on submit
                Object.keys(addConfirmationFields).forEach(fieldName => {
                    const fieldElement = addConfirmationFields[fieldName];
                    if (fieldElement) {
                        validateConfirmationField(fieldName, fieldElement.value, fieldElement, 'add');
                        if (!addConfirmationFormState[fieldName]) {
                            formIsValid = false; // If any field is invalid, the form is invalid
                        }
                    }
                });

                if (!formIsValid) {
                    event.preventDefault(); // Prevent actual submission if validation fails
                    alert('Please correct the errors before submitting.');
                }
                // If formIsValid is true, the default submit action will proceed
            });
        }


        function resetAddConfirmationForm() {
    if (addConfirmationForm) addConfirmationForm.reset();
    Object.keys(addConfirmationFields).forEach(fieldName => {
        const fieldElement = addConfirmationFields[fieldName];
        const errorElement = document.getElementById('add' + fieldName + 'Error');
        if (fieldElement) {
            fieldElement.style.border = '';
        }
        if (errorElement) {
            errorElement.classList.add('hidden');
            errorElement.textContent = '';
        }
        addConfirmationFormState[fieldName] = false;
    });
    if(addSubmitButton) {
        addSubmitButton.disabled = true;
        addSubmitButton.style.backgroundColor = '#cccccc';
        addSubmitButton.style.cursor = 'not-allowed';  // Fixed typo here
    }
}
        

        function closeModal() {
        document.getElementById("recordModal").style.display = "none";
        resetAddConfirmationForm(); // Reset form when modal closes
        }


        // Close modals if clicking outside
        window.onclick = function (event) {
            const recordModal = document.getElementById("recordModal");
            const updateModal = document.getElementById("updateModal");
            const adminModal = document.getElementById("adminModal");
            const certModal = document.getElementById("certificateModal");


            if (event.target === recordModal) {
                closeModal();
            }
            if (event.target === updateModal) {
                closeUpdateModal();
            }
            if (event.target === adminModal) {
                closeAdminModal();
            }
            if (event.target === certModal) {
                closeCertModal();
            }
        };
        /*----- ADD RECORDS [end]-----*/

        /*----- UPDATE RECORDS [start]-----*/
        const updateConfirmationForm = document.getElementById('updateModal').querySelector('form');
        const updateConfirmationFields = {
            // ConfirmationID is read-only, not included here for validation state tracking
            YearOfConfirmation: document.getElementById('updateYearOfConfirmation'),
            ClientID: document.getElementById('updateClientID'),
            // FullName: document.getElementById('updateFullName'),
            ParentName: document.getElementById('updateParentName'),
            SponsorName: document.getElementById('updateSponsorName'),
            PriestID: document.getElementById('updatePriestID') // Select element
        };
        const updateConfirmationFormState = {}; // To track validation state
        const updateSubmitButton = document.getElementById('updateSubmitButton');


        function initializeUpdateConfirmationValidation() {
            if (!updateConfirmationForm || !updateSubmitButton) return;

            updateSubmitButton.disabled = true; // Start disabled
            updateSubmitButton.style.backgroundColor = '#cccccc';
            updateSubmitButton.style.cursor = 'not-allowed';


            Object.keys(updateConfirmationFields).forEach(fieldName => {
                const fieldElement = updateConfirmationFields[fieldName];
                if (fieldElement) {
                    updateConfirmationFormState[fieldName] = false; // Start assuming invalid

                    const eventType = (fieldElement.tagName === 'SELECT' || fieldElement.type === 'number') ? 'change' : 'input';
                    fieldElement.addEventListener(eventType, function() {
                        validateConfirmationField(fieldName, this.value, this, 'update');
                    });
                    fieldElement.addEventListener('blur', function() {
                        validateConfirmationField(fieldName, this.value, this, 'update');
                    });
                    // Note: Initial validation on load happens AFTER row click populates fields
                }
            });

            // Add a submit event listener for the update form
            updateConfirmationForm.addEventListener('submit', function(event) {
                let formIsValid = true;
                // Re-validate all fields on submit
                Object.keys(updateConfirmationFields).forEach(fieldName => {
                    const fieldElement = updateConfirmationFields[fieldName];
                    if (fieldElement) {
                        validateConfirmationField(fieldName, fieldElement.value, fieldElement, 'update');
                        if (!updateConfirmationFormState[fieldName]) {
                            formIsValid = false;
                        }
                    }
                });

                // Also check the hidden admin password is set (though server-side is primary)
                const hiddenAdminPassword = document.getElementById('hiddenAdminPassword').value;
                if (hiddenAdminPassword === '') {
                    alert('Admin password is required to update.');
                    formIsValid = false;
                }


                if (!formIsValid) {
                    event.preventDefault();
                    alert('Please correct the errors before submitting the update.');
                }
            });
        }


        function resetUpdateConfirmationForm() {
            // Do NOT reset the whole form as it would clear the read-only ConfirmationID
            // updatePriestForm.reset();

            // Reset validation state and clear error messages/borders for editable fields
            Object.keys(updateConfirmationFields).forEach(fieldName => {
                const fieldElement = updateConfirmationFields[fieldName];
                const errorElement = document.getElementById('update' + fieldName + 'Error');

                if (fieldElement) {
                    // Clear values for editable fields
                    if (fieldElement.tagName === 'SELECT') fieldElement.value = '';
                    else if (fieldElement.type !== 'hidden') fieldElement.value = '';

                    fieldElement.style.border = ''; // Clear border
                }
                if (errorElement) {
                    errorElement.classList.add('hidden'); // Hide error message
                    errorElement.textContent = ''; // Clear error text
                }
                updateConfirmationFormState[fieldName] = false; // Reset state
            });

            // Also clear the read-only Confirmation ID field
            const confirmationIdField = document.getElementById('updateConfirmationID');
            if(confirmationIdField) confirmationIdField.value = '';


            // Disable submit button
            if(updateSubmitButton) {
                updateSubmitButton.disabled = true;
                updateSubmitButton.style.backgroundColor = '#cccccc';
                updateSubmitButton.style.cursor = 'not-allowed';
            }

            // Clear the hidden admin password field
            document.getElementById('hiddenAdminPassword').value = '';
        }


        // Trigger modal when update button is clicked
        document.getElementById("updateRecordBtn").onclick = function () {
            // Require admin auth each time the update process starts
            adminAuthenticated = false;
            openAdminModal(); // Open the admin password modal
            resetUpdateConfirmationForm(); // Clear previous update data and state
        };

        // Enable row click for editing AFTER admin authentication
        function enableRowClickEdit() {
            const rows = document.querySelectorAll("#recordsTable tbody tr");

            rows.forEach(row => {
                row.style.cursor = "pointer";
                row.onclick = function () {
                    if (!adminAuthenticated) {
                        showMessageModal("Admin authentication required to edit. Please click '✎ Update Record' first and enter the password.");
                        return; // Do nothing if not authenticated
                    }

                    const cells = row.querySelectorAll("td");

                    // Populate Update Modal fields
                    document.getElementById("updateConfirmationID").value = cells[0].innerText.trim();
                    document.getElementById("updateYearOfConfirmation").value = cells[1].innerText.trim();
                    document.getElementById("updateClientID").value = cells[2].innerText.trim();
                    // document.getElementById("updateFullName").value = cells[3].innerText.trim();
                    document.getElementById("updateParentName").value = cells[3].innerText.trim();
                    document.getElementById("updateSponsorName").value = cells[4].innerText.trim();

                    // Find the PriestID based on the Priest Name displayed in the table
                    // This requires looking up the selected priest from the dropdown options
                    const priestNameInTable = cells[6].innerText.trim();
                    const priestSelect = document.getElementById("updatePriestID");
                    let matchedPriestID = '';

                    for(let i = 0; i < priestSelect.options.length; i++) {
                        const optionText = priestSelect.options[i].text;
                        // Simple check: does the option text contain the priest's name?
                        if (optionText.includes(priestNameInTable) && priestNameInTable !== '-') {
                            matchedPriestID = priestSelect.options[i].value;
                            break; // Found the match
                        }
                    }
                    priestSelect.value = matchedPriestID;


                    // Trigger client-side validation for pre-filled update fields
                    Object.keys(updateConfirmationFields).forEach(fieldName => {
                        const fieldElement = updateConfirmationFields[fieldName];
                        if(fieldElement) { // Check if the element exists in our fields list
                            validateConfirmationField(fieldName, fieldElement.value, fieldElement, 'update');
                        }
                    });

                    document.getElementById("updateModal").style.display = "flex";
                };
            });
        }

        function disableRowClickEdit() {
            const rows = document.querySelectorAll("#recordsTable tbody tr");
            rows.forEach(row => {
                row.onclick = null; // remove the click handler
                row.style.cursor = "default"; // optionally remove pointer cursor
                row.style.border = ""; // Remove any highlight border if added
            });
        }

        function closeUpdateModal() {
            document.getElementById("updateModal").style.display = "none";
            adminAuthenticated = false; // Revoke access again
            disableRowClickEdit();      // Remove event listeners for row clicks
            resetUpdateConfirmationForm(); // Clear fields and validation state
        }
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
    resetAddConfirmationForm();
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

        /*----- INITIALIZE VALIDATIONS ON DOM LOADED -----*/
        document.addEventListener('DOMContentLoaded', function() {
            initializeAddConfirmationValidation();
            initializeUpdateConfirmationValidation();
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
            const addForm = document.getElementById('addConfirmationForm');
            const updateForm = document.getElementById('updateConfirmationForm');

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

                            fetch('confirmation.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.text())
                            .then(data => {
                                if (data.includes('Record inserted successfully')) {
                                    alert('Record inserted successfully!');
                                    window.location.reload();
                                } else {
                                    alert('Error inserting record. Record may already exists.');
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

                            fetch('confirmation.php', {
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

            // --- START: New Filter JavaScript for confirmation.php ---
            const categoryFilter_confirmation = document.getElementById('categoryFilterConfirmation');
            const yearInputContainer_confirmation = document.getElementById('filterYearInputContainerConfirmation');
            // Month and Date input containers are not needed for this version

            const yearValueInput_confirmation = document.getElementById('filterYearValueConfirmation');
            // Month and Date value inputs are not needed

            const applyFilterButton_confirmation = document.getElementById('applyFilterBtnConfirmation');
            const clearFilterButton_confirmation = document.getElementById('clearFilterBtnConfirmation');
            const searchInput_confirmation = document.getElementById('searchInput');

            function toggleFilterInputs_confirmation() {
                if (!categoryFilter_confirmation) return;
                const selectedFilter = categoryFilter_confirmation.value;

                if(yearInputContainer_confirmation) yearInputContainer_confirmation.style.display = 'none';
                // Hide logic for month/date is removed

                if (selectedFilter === 'year' && yearInputContainer_confirmation) {
                    yearInputContainer_confirmation.style.display = 'inline-block';
                }
                // Else if for month/date is removed
            }

            if (categoryFilter_confirmation) {
                categoryFilter_confirmation.addEventListener('change', toggleFilterInputs_confirmation);
            }

            if (applyFilterButton_confirmation) {
                applyFilterButton_confirmation.addEventListener('click', function() {
                    if (!categoryFilter_confirmation) return;
                    const filterType = categoryFilter_confirmation.value;
                    if (!filterType) return;

                    let queryParams = new URLSearchParams();
                    queryParams.set('filter_type_confirmation', filterType);

                    if (filterType === 'year') {
                        if (!yearValueInput_confirmation || !yearValueInput_confirmation.value || !/^\d{4}$/.test(yearValueInput_confirmation.value)) {
                            alert('Please enter a valid 4-digit year.'); return;
                        }
                        queryParams.set('filter_year_value_confirmation', yearValueInput_confirmation.value);
                    }
                    // Logic for month/specific_date removed
                    else if (filterType === 'oldest_to_latest') {
                        queryParams.set('sort_order_confirmation', 'asc');
                    } else if (filterType === 'latest_to_oldest') {
                        queryParams.set('sort_order_confirmation', 'desc');
                    }
                    window.location.search = queryParams.toString();
                });
            }

            if (clearFilterButton_confirmation) {
                clearFilterButton_confirmation.addEventListener('click', function(event) {
                    event.preventDefault();
                    if (searchInput_confirmation) {
                        searchInput_confirmation.value = '';
                    }
                    window.location.href = window.location.pathname;
                });
            }

            function setFiltersFromUrl_confirmation() {
                if (!categoryFilter_confirmation) return;
                const urlParams = new URLSearchParams(window.location.search);
                const filterTypeFromUrl = urlParams.get('filter_type_confirmation');

                categoryFilter_confirmation.value = "";
                if(yearValueInput_confirmation) yearValueInput_confirmation.value = "";
                // Reset for month/date removed
                toggleFilterInputs_confirmation();

                if (filterTypeFromUrl) {
                    categoryFilter_confirmation.value = filterTypeFromUrl;
                    toggleFilterInputs_confirmation();

                    if (filterTypeFromUrl === 'year' && urlParams.has('filter_year_value_confirmation') && yearValueInput_confirmation) {
                        yearValueInput_confirmation.value = urlParams.get('filter_year_value_confirmation');
                    }
                    // Logic for setting month/date values from URL removed
                } else if (urlParams.has('sort_order_confirmation')) {
                    const sortOrder = urlParams.get('sort_order_confirmation');
                    if (sortOrder === 'asc') categoryFilter_confirmation.value = 'oldest_to_latest';
                    if (sortOrder === 'desc') categoryFilter_confirmation.value = 'latest_to_oldest';
                }
            }

            setFiltersFromUrl_confirmation(); // Call on page load
            // --- END: New Filter JavaScript for confirmation.php ---
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