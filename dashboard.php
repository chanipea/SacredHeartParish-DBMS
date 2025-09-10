<?php
// Prevent caching of the page after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$pageTitle = "Sacred Heart of Jesus DBMS";
require_once 'session_timeout.php'; 

// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // If not logged in, redirect to the login page
    header("Location: Log_In\login_system.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" type="image/x-icon" href="/images/sacred.png">
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <link rel="stylesheet" href="dashStyle.css?v=16">
    <link rel="stylesheet" href="modalResponsive.css?v=13">

</head>
<body>
<!-- Toggle Button for Mobile/Tablet -->
<button id="sidebarToggleBtn" class="sidebar-toggle-button">
    <img src="icons/Menu.png" alt="Menu">
</button>

<!-- Overlay for when sidebar is open on mobile/tablet -->
<div class="main-page-overlay"></div>
    <div class="container">
        <div class="header">
            <div class="icon"></div>
            <div class="title">Sacred Heart Database Management System</div>
        </div>
        <!-- Sidebar Menu -->
        <aside class="sidebar">
            <div class="logo">Menus</div>
            <nav class="menu">
                <ul>
                    <li id="dashboardButton">
                        <img src="icons\dashboard.png" alt="Dashboard Icon">
                        Dashboard
                    </li>

                    <li id="priestButton">
                        <img src="icons\priest.png" alt="Priest Icon">
                        Priest Records
                    </li>

                    <li id="eventsButton">
                        <img src="icons\event.png" alt="Event Icon">
                        Event Records
                    </li>

                    <li id="massButton">
                        <img src="icons\mass.png" alt="Mass Icon">
                        Mass Schedules
                    </li>

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
            </nav>
        </aside>

        <div class="bottom-buttons">
            <div id="uploadImageButton" class="bottom-right">
                <button id="uploadImageBtn" class="uploadOpenbtn" onclick="openUploadModal()">
                    <img src="icons/upload.png" alt="Upload Icon" class="button-icon">
                    Upload Image
                </button>
            </div>

            <div class="landingMain-btn">
                <a href="landing_page-main.php">
                    <button class="landing-btn" id="landingPageBtn">
                        <img src="icons/landing.png" alt="Home Icon">
                        Landing Page
                    </button>
                </a>
            </div>
        </div>

    <div class="content">
            <!-- Carousel -->
            <section id="slider">
                <input type="radio" name="slider" id="s1">
                <input type="radio" name="slider" id="s2"checked>
                <input type="radio" name="slider" id="s3">

                <label for="s1" id="slide1"><img src="" alt="Slide 1"></label>
                <label for="s2" id="slide2"><img src="" alt="Slide 2"></label>
                <label for="s3" id="slide3"><img src="" alt="Slide 3"></label>

                <button id="prevArrow" class="slider-arrow">&#10094;</button>
                <button id="nextArrow" class="slider-arrow">&#10095;</button>

                <div class="slider-indicators"> 
                    <span class="indicator" id="indicator1"></span>
                    <span class="indicator" id="indicator2"></span>
                    <span class="indicator" id="indicator3"></span>
                </div>

            </section>
            <div class="description">
                <p>
                    The Sacred Heart of Jesus Parish in Barrio Obrero, Davao City, stands as a testament to the enduring 
                    faith and unity of its diverse Catholic community. 
                    Under the guidance of Rev. Fr. Paul Lu Te-Shan and with the support of lay leaders and parishioners, 
                    the Sacred Heart of Jesus Parish was formally established in 1967.

                </p>
            </div>
        </div>
    </div>


    <!-- Modal for Client ID input WITH CHOOSING CERT -->
    <div id="certificateModal" class="modal">
        <div class="modal-content">
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

        <!-- Upload Modal -->
        <div id="uploadModal" class="modal">
    <div class="modal-content2">
        <span onclick="closeUploadModal()" class="close-btn">&times;</span>
        <h2>Upload Image</h2>
        <form id="uploadForm" action="upload_image.php" method="POST" enctype="multipart/form-data" target="uploadFrame">
            <label for="uploadImage">Select Image:</label><br>
            <input type="file" id="uploadImage" name="uploadImage" required><br><br>

           <div id="uploadError" style="color: red; font-size: 15px; position: relative; top: -15px;"></div>
            <br>

            <label for="pageSelect">Choose Page:</label><br>
            <select id="pageSelect" name="pageSelect" required>
                <option value="mass">Mass Schedule</option>
                <option value="events">Events/Sacraments</option>
                <option value="main">Landing Main Page</option>
            </select><br><br>

            <button type="submit" class="upload-btn">Upload</button>
        </form>
        <!-- Hidden iframe for uploading without page reload -->
        <iframe name="uploadFrame" style="display:none;"></iframe>
    </div>
</div>

<div id="successModal" class="modal">
  <div class="modal-content">
    <span class="close-btn" onclick="closeSuccessModal()">&times;</span>
    <h2>Success</h2>
    <p id="successMessage">Image uploaded successfully.</p>
    <button onclick="closeSuccessModal()">OK</button>
  </div>
</div>

    <script>

        let currentSlide = 1;
        const totalSlides = 3; 
        const intervalTime = 5000; 
        
        let slideInterval;                                

// Show slide
function showSlide(slideIndex) {
    if (slideIndex < 1) slideIndex = totalSlides;
    if (slideIndex > totalSlides) slideIndex = 1;
    currentSlide = slideIndex;
    document.getElementById("s" + currentSlide).checked = true;

    document.querySelectorAll(".indicator").forEach((dot, index) => {
        dot.classList.toggle("active", index + 1 === currentSlide);
    });
}

// Controls
function nextSlide() { showSlide(currentSlide + 1); }
function prevSlide() { showSlide(currentSlide - 1); }

// Auto slide
function startAutoSlide() {
    slideInterval = setInterval(nextSlide, intervalTime);
}
function stopAutoSlide() {
    clearInterval(slideInterval);
}

// Events
document.getElementById("nextArrow").addEventListener("click", () => {
    nextSlide();
    stopAutoSlide(); startAutoSlide();
});
document.getElementById("prevArrow").addEventListener("click", () => {
    prevSlide();
    stopAutoSlide(); startAutoSlide();
});

// Start on page load
window.addEventListener("load", () => {
    showSlide(currentSlide);
    startAutoSlide();
});

        window.addEventListener('load', function () {
         // Dynamically load slider images from image_path.json with cache-busting
        fetch('image_path.json?t=' + new Date().getTime())
            .then(response => response.json())
            .then(data => {
            const mainImages = data.main || [];
            const sliderImages = document.querySelectorAll('#slider img');

            sliderImages.forEach((img, index) => {
                if (mainImages[index]) {
                    img.src = mainImages[index] + '?t=' + new Date().getTime(); // Prevent browser caching
                }
            });
        })
        .catch(error => console.error('Error loading main slider images:', error));
        });

        function toggleSidebar() {
            document.querySelector(".sidebar").classList.toggle("active");
        }

        function toggleDropdown() {
            let dropdown = document.getElementById("certificateDropdown");
            let certificatesItem = document.getElementById("certificates");

            dropdown.classList.toggle("dropdown-active");
            certificatesItem.classList.toggle("open");
        }


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

        // open the modal for uploading image
        function openUploadModal() {
            document.getElementById("uploadModal").style.display = "block";
        }

        function closeUploadModal() {
            document.getElementById("uploadModal").style.display = "none";
        }

        window.onclick = function(event) {
            // close the certificate modal if clicked outside
            if (event.target == document.getElementById("certificateModal")) {
                closeCertModal();
            }

            // close the record modal if clicked outside
            if (event.target == document.getElementById("recordModal")) {
                closeModal();
            }
            if (event.target == document.getElementById("uploadModal")) {
                closeUploadModal();
            }
        }

        function closeModal() {
            document.getElementById("recordModal").style.display = "none";
        }

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

        document.getElementById("landingPageBtn").addEventListener("click", function() { // "landingPageBtn"
            window.location.href = "landing_page-main.php";
        });

        function displayUploadError(message) {
        document.getElementById('uploadError').innerText = message;
        document.getElementById('uploadModal').style.display = 'block';
    }
        // Add this to your existing JavaScript block
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

        function showSuccessModal(message) {
  document.getElementById("successMessage").innerText = message;    
}

function closeSuccessModal() {
  document.getElementById("successModal").style.display = "none";
}

    </script>
</body>
</html>