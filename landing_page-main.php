<?php
session_start();

// Check if the user came here from dashboard.php or landing_main.php or landing_events.php
// If NOT, clear the 'came_from_dashboard' flag so button won't show
if (!isset($_SERVER['HTTP_REFERER']) || 
    (strpos($_SERVER['HTTP_REFERER'], 'dashboard.php') === false && 
     strpos($_SERVER['HTTP_REFERER'], 'landing_page-main.php') === false &&
     strpos($_SERVER['HTTP_REFERER'], 'landing_page-events.php') === false &&
     strpos($_SERVER['HTTP_REFERER'], 'landing_page-mass.php') === false)) {
    unset($_SESSION['came_from_dashboard']);
}

// Now get your flags for use in HTML
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$fromDashboard = isset($_SESSION['came_from_dashboard']) && $_SESSION['came_from_dashboard'] === true;
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sacred Heart of Jesus - Main Landing Page</title>
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="stylesheet" href="landingStyle.css?v=4">
    <!-- Add these two lines for responsiveness -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="landing_responsive.css?v=4">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon"></div>
            <div class="title">Sacred Heart of Jesus - Main Page</div>
        </div>

        <div class="content">
            <div class="options">
                
         <?php if ($isLoggedIn && $fromDashboard): ?>
            <button onclick="window.location.href='dashboard.php'">Back to Dashboard</button>
        <?php endif; ?>
                <button id="eventsButton">Go to Sacramental Events</button>
                <button id="massButton">Go to Mass Schedule</button>
            </div>

            <section id="slider">
                <input type="radio" name="slider" id="s1">
                <input type="radio" name="slider" id="s2"checked>
                <input type="radio" name="slider" id="s3">

                <label for="s1" id="slide1"><img src="" alt="Slide 1"></label>
                <label for="s2" id="slide2"><img src="" alt="Slide 2"></label>
                <label for="s3" id="slide3"><img src="" alt="Slide 3"></label>
            </section>

            <div class="description">
                <p><br><br>Welcome to the Sacred Heart of Jesus Database Management System. Here, you can explore our sacramental events and mass schedules.</p>
            </div>
        </div>
    </div>

    <script>
        window.addEventListener('load', function () {
            // Cache-busting for image_path.json
            fetch('image_path.json?t=' + new Date().getTime())
                .then(response => response.json())
                .then(data => {
                    const mainImages = data.main || [];
                    const sliderImages = document.querySelectorAll('#slider img');
        
                    sliderImages.forEach((img, index) => {
                        if (mainImages[index]) {
                            // Cache-busting for each image
                            img.src = mainImages[index] + '?t=' + new Date().getTime();
                        }
                    });
                })
                .catch(error => console.error('Error loading main slider images:', error));
        });
        
        window.onload = function() {
    // Fetch and set images
    fetch('image_path.json?t=' + new Date().getTime())
        .then(response => response.json())
        .then(data => {
            const eventImages = data.events;
            const sliderImages = document.querySelectorAll('#slider img');

            sliderImages.forEach((img, index) => {
                if (eventImages[index]) {
                    img.src = eventImages[index] + '?t=' + new Date().getTime();
                }
            });
        })
        .catch(error => console.error('Error loading image_path.json:', error));
        };

        document.getElementById('massButton').onclick = function() {
            window.location.href = 'landing_page-mass.php';
        };

        document.getElementById('eventsButton').onclick = function() {
            window.location.href = 'landing_page-events.php';
        };

        document.getElementById('backToDashboardBtn').onclick = function() {
    fetch('clear_dashboard_flag.php')
    .then(() => {
        window.location.href = 'dashboard.php';
    });
};
        

        </script>
        
</body>

</html>
