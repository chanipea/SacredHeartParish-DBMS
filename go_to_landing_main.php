<?php
session_start();
$_SESSION['came_from_dashboard'] = true;
header("Location: landing_page-main.php");
exit();
?>
