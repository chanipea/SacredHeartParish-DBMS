<?php
require('FPDF/fpdf.php');

session_start(); // Start session for ParishStaffID and login check
ob_start(); // Output buffering for PDF

// Restrict access
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    $_SESSION['redirect_to'] = $_SERVER['REQUEST_URI'];
    header("Location: ../Log_In/login_system.php");
    exit();
}

// DB Connection
$mysqli = new mysqli('localhost', 'root', '', 'SacredHeartParish_DBMS');
if ($mysqli->connect_error) {
    die('Connection Failed: ' . $mysqli->connect_error);
}

// Get ParishStaffID
$parishStaffID = null;
if (isset($_SESSION['user_role'], $_SESSION['user_id'])) {
    $userRole = $_SESSION['user_role'];
    $userId = $_SESSION['user_id'];

    if ($userRole === 'admin') {
        $stmtStaff = $mysqli->prepare("SELECT ParishStaffID FROM parishstaff WHERE UserType = 'admin' AND AdminUserID = ?");
    } elseif ($userRole === 'staff') {
        $stmtStaff = $mysqli->prepare("SELECT ParishStaffID FROM parishstaff WHERE UserType = 'staff' AND StaffUserID = ?");
    } else {
        showError("Invalid user role.");
    }

    $stmtStaff->bind_param("i", $userId);
    $stmtStaff->execute();
    $stmtStaff->bind_result($psID);
    if ($stmtStaff->fetch()) {
        $parishStaffID = $psID;
        $_SESSION['ParishStaffID'] = $psID;
    }
    $stmtStaff->close();

    if (!$parishStaffID) {
        showError("Your user is not linked in the ParishStaff table. Contact the admin.");
    }
} else {
    showError("User session invalid.");
}

function ordinal_suffix($num) {
    if (!in_array(($num % 100), [11, 12, 13])) {
        switch ($num % 10) {
            case 1: return $num . 'st';
            case 2: return $num . 'nd';
            case 3: return $num . 'rd';
        }
    }
    return $num . 'th';
}

function format_date_with_ordinal($monthDay, $year) {
    $parts = explode(' ', $monthDay);
    if (count($parts) == 2) {
        $month = $parts[0];
        $day = (int)$parts[1];
        return ordinal_suffix($day) . " day of {$month} {$year}";
    }
    return "{$monthDay} {$year}";
}

function showError($message) {
    global $mysqli;
    $mysqli->close();
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Error</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="modal fade show" style="display:block;" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Error</h5>
      </div>
      <div class="modal-body">{$message}</div>
      <div class="modal-footer">
        <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
HTML;
    exit();
}

// Main Logic
if (!isset($_GET['client_id'], $_GET['type'])) {
    showError("Please provide both the Record ID and Certificate Type.");
}

$clientID = (int) $_GET['client_id'];
$type = $_GET['type'];
$query = "";

if ($type === 'baptismal') {
    $query = "
        SELECT 
            b.BaptismID, c.ClientID, c.FullName, c.Address,
            b.BdayYear, b.BdayMonthDay,
            b.BaptismYear, b.BaptismMonthDay,
            b.FatherName, b.FatherPlaceOfOrigin,
            b.MotherName, b.MotherPlaceOfOrigin,
            b.OneSponsorName, b.TwoSponsorName,
            p.FullName AS PriestName
        FROM baptismal_records b
        JOIN client c ON b.ClientID = c.ClientID
        JOIN priest p ON b.PriestID = p.PriestID
        WHERE b.BaptismID = ?
    ";
} elseif ($type === 'confirmation') {
    $query = "
        SELECT 
            cr.ConfirmationID, c.ClientID, c.FullName, c.Address,
            b.BdayYear, b.BdayMonthDay,
            b.BaptismYear, b.BaptismMonthDay,
            b.FatherName, b.FatherPlaceOfOrigin,
            b.MotherName, b.MotherPlaceOfOrigin,
            b.OneSponsorName, b.TwoSponsorName,
            p.FullName AS PriestName
        FROM confirmation_records cr
        JOIN client c ON cr.ClientID = c.ClientID
        JOIN baptismal_records b ON cr.ClientID = b.ClientID
        JOIN priest p ON cr.PriestID = p.PriestID
        WHERE cr.ConfirmationID = ?
    ";
} else {
    showError("Invalid certificate type.");
}

$stmt = $mysqli->prepare($query);
if (!$stmt) {
    showError("Query prepare error: " . $mysqli->error);
}
$stmt->bind_param('i', $clientID);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    ob_end_clean(); // Clean buffer before PDF output
    $pdf = new FPDF();
    $pdf->AddPage();

    $templatePath = $type === 'baptismal' ? 'Certificate_Templates/Baptismal Template.png' : 'Certificate_Templates/Confirmation Template.png';
    $pdf->Image($templatePath, 0, 0, 210, 297);

    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Ln(60);

    $clientName = strtoupper($row['FullName']);
    $priestName = strtoupper($row['PriestName'] ?? "FR. RONALD B. ARCILLAS");
    $fatherName = strtoupper($row['FatherName'] ?? "FATHER'S NAME");
    $motherName = strtoupper($row['MotherName'] ?? "MOTHER'S NAME");
    $godfatherName = strtoupper($row['OneSponsorName'] ?? "GODFATHER");
    $godmotherName = strtoupper($row['TwoSponsorName'] ?? "GODMOTHER");
    $address = strtoupper($row['Address'] ?? "DAVAO CITY");

    $birthDate = format_date_with_ordinal($row['BdayMonthDay'], $row['BdayYear']);
    $baptismDate = format_date_with_ordinal($row['BaptismMonthDay'], $row['BaptismYear']);

    $pdf->Ln(25);
    $pdf->Cell(0, 10, $clientName, 0, 1, 'C');
    $pdf->Ln(10);
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 7, "who was born on the {$birthDate} was solemnly baptized in this Parish, on the {$baptismDate} by {$priestName} of the Roman Catholic Church.", 0, 'C');
    $pdf->Ln(5);
    $pdf->Cell(0, 7, "Father: {$fatherName} from " . strtoupper($row['FatherPlaceOfOrigin']), 0, 1, 'C');
    $pdf->Cell(0, 7, "Mother: {$motherName} from " . strtoupper($row['MotherPlaceOfOrigin']), 0, 1, 'C');
    $pdf->Ln(10);
    $pdf->Cell(0, 7, $address, 0, 1, 'C');
    $pdf->Cell(0, 7, "(Address)", 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->Cell(0, 7, "Godfather: {$godfatherName}", 0, 1, 'C');
    $pdf->Cell(0, 7, "Godmother: {$godmotherName}", 0, 1, 'C');
    $pdf->Ln(10);
    $pdf->MultiCell(0, 7, "This certificate is a true copy of the " . ucfirst($type) . " Record kept in the Parish,", 0, 'C');
    $pdf->Cell(0, 7, "Given in Davao City this " . date('jS \d\a\y \of F Y'), 0, 1, 'C');

    // Log generation
    if (isset($_SESSION['ParishStaffID'])) {
        $logStmt = $mysqli->prepare("
            INSERT INTO certificate_generation_log (ClientID, CertificateType, ParishStaffID)
            VALUES (?, ?, ?)
        ");
        if ($logStmt) {
            $certType = strtolower($type);
            $logStmt->bind_param("isi", $row['ClientID'], $certType, $_SESSION['ParishStaffID']);
            $logStmt->execute();
            $logStmt->close();
        } else {
            error_log("Log insert failed: " . $mysqli->error);
        }
    }

    $pdf->Output('D', $type . '_certificate_' . $clientID . '.pdf');
    exit;
} else {
    $errorMsg = $type === 'baptismal' ?
        "No baptismal record found for this ID." :
        "No confirmation record or corresponding baptismal record found.";
    showError($errorMsg);
}
?>
