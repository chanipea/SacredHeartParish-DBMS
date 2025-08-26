<?php
session_start();

$timeout_duration = 3600; 
$warning_time = 60;     
 

// AJAX session reset
if (isset($_GET['reset']) && $_GET['reset'] == 'true') {
    $_SESSION['LAST_ACTIVITY'] = time();
    http_response_code(200);
    exit();
}

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY'])) {
    if (time() - $_SESSION['LAST_ACTIVITY'] > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: Logout/logout.php");
        exit();
    }
}
$_SESSION['LAST_ACTIVITY'] = time();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $pageTitle; ?></title>
    <style>
        #timeoutModal, #expiredModal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fff;
            margin: 15% auto;
            padding: 20px;
            width: 300px;
            text-align: center;
            border-radius: 10px;
        }

        button {
            margin-top: 10px;
            padding: 8px 16px;
            border: none;
            background-color: #2c7;
            color: white;
            border-radius: 5px;
            cursor: pointer;
        }

        button:hover {
            background-color: #1a5;
        }
    </style>
</head>
<body>

<!-- First Modal: Session warning -->
<div id="timeoutModal">
    <div class="modal-content">
        <p>Your session will expire in <span id="countdown">60</span> seconds.</p>
        <button id="stayLoggedIn">Stay Logged In</button>
    </div>
</div>

<!-- Second Modal: Session expired -->
<div id="expiredModal">
    <div class="modal-content">
        <p>Your session has been timed out.</p>
        <button id="goBack">OK</button>
    </div>
</div>

<script>

function startWarningTimer() {
    const timeoutDuration = <?php echo $timeout_duration; ?> * 1000;
    const warningTime = <?php echo $warning_time; ?> * 1000;
    const showWarningAfter = timeoutDuration - warningTime;

    setTimeout(() => {
        const warningModal = document.getElementById("timeoutModal");
        const countdown = document.getElementById("countdown");
        let secondsLeft = <?php echo $warning_time; ?>;

        warningModal.style.display = "block";
        countdown.textContent = secondsLeft;

        const interval = setInterval(() => {
            secondsLeft--;
            countdown.textContent = secondsLeft;

            if (secondsLeft <= 0) {
                clearInterval(interval);
                warningModal.style.display = "none";

                const expiredModal = document.getElementById("expiredModal");
                expiredModal.style.display = "block";

                document.getElementById("goBack").onclick = () => {
                    window.location.href = "Logout/logout.php";
                };
            }
        }, 1000);

        document.getElementById("stayLoggedIn").onclick = () => {
            fetch("<?php echo basename(__FILE__); ?>?reset=true")
                .then(() => {
                    clearInterval(interval);
                    warningModal.style.display = "none";
                    startWarningTimer(); 
                });
        };
    }, showWarningAfter);
}

window.onload = startWarningTimer;

</script>
</body>
</html>
