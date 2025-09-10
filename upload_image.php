<?php
// Directory where uploaded images will be saved
$targetDir = "Upload_Images/";
if (!file_exists($targetDir)) {
    mkdir($targetDir, 0755, true);
}

// Handle the form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["uploadImage"])) {
    $file = $_FILES["uploadImage"];
    $page = $_POST["pageSelect"];

    // Validate file upload
    if ($file["error"] !== UPLOAD_ERR_OK) {
        echo "<script>window.parent.displayUploadError('Upload error: Code " . $file["error"] . "');</script>";
        exit();
    }

    // Check allowed file types
    $allowedTypes = ["jpg", "jpeg", "png", "gif"];
    $fileName = uniqid() . "_" . basename($file["name"]);
    $targetFile = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

    if (!in_array($fileType, $allowedTypes)) {
        echo "<script>window.parent.displayUploadError('Only JPG, JPEG, PNG, and GIF files are allowed.');</script>";
        exit();
    }

    // Move file to server
    if (!move_uploaded_file($file["tmp_name"], $targetFile)) {
        echo "<script>window.parent.displayUploadError('Failed to save uploaded file.');</script>";
        exit();
    }

    // Load existing JSON or create a new array
    $jsonPath = "image_path.json";
    $imageData = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

    if (!isset($imageData[$page]) || !is_array($imageData[$page])) {
        $imageData[$page] = [];
    }

    // Add new image path
    $imageData[$page][] = $targetFile;

    // Keep only the latest 3 images
    if (count($imageData[$page]) > 3) {
        // Optionally delete the oldest image file from server
        $oldImage = array_shift($imageData[$page]);
        if (file_exists($oldImage)) {
            unlink($oldImage);
        }
    }

    // Save updated paths
    file_put_contents($jsonPath, json_encode($imageData, JSON_PRETTY_PRINT));
    
    // Show modal
    echo "<script>
        window.parent.showSuccessModal('Image uploaded successfully.');
        setTimeout(function() {
            window.parent.location.reload();
        }, 500);
    </script>";
} else {
    echo "<script>window.parent.displayUploadError('Invalid request.');</script>";
}
?>
