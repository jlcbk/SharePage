<?php
// TODO: Add session start if using sessions for admin auth
// session_start();

// --- Configuration ---
$upload_dir = __DIR__ . '/uploads/'; // Directory to store uploaded files
$data_file = __DIR__ . '/shares.json'; // File to store share metadata
$admin_password_correct = 'YOUR_ADMIN_UPLOAD_PASSWORD'; // MUST MATCH the one in share_page.php

// --- Helper Functions ---

/**
 * Redirects the user back to the share page with a message.
 * @param string $message The message to display.
 * @param string $status 'success' or 'error'.
 */
function redirect_with_message($message, $status = 'error') {
    header('Location: share_page.php?message=' . urlencode($message) . '&status=' . $status);
    exit;
}

/**
 * Generates a unique share ID.
 * @return string A unique ID.
 */
function generate_share_id() {
    return bin2hex(random_bytes(8)); // Generates a 16-character hex ID
}

/**
 * Loads share data from the JSON file.
 * @return array An associative array of shares.
 */
function load_share_data() {
    global $data_file;
    if (!file_exists($data_file)) {
        return [];
    }
    $json_content = file_get_contents($data_file);
    $data = json_decode($json_content, true);
    return is_array($data) ? $data : [];
}

/**
 * Saves share data to the JSON file.
 * @param array $data The share data array.
 * @return bool True on success, false on failure.
 */
function save_share_data($data) {
    global $data_file;
    $json_content = json_encode($data, JSON_PRETTY_PRINT);
    if ($json_content === false) {
        return false;
    }
    return file_put_contents($data_file, $json_content) !== false;
}

// --- Ensure directories and files exist ---
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        redirect_with_message('Error: Could not create upload directory.');
    }
}
if (!file_exists($data_file)) {
    if (file_put_contents($data_file, json_encode([])) === false) {
        redirect_with_message('Error: Could not create data storage file.');
    }
}
if (!is_writable($upload_dir) || !is_writable($data_file)) {
    redirect_with_message('Error: Upload directory or data file is not writable.');
}

// --- Request Handling ---
$action = $_GET['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'upload') {
        // --- Upload Logic ---

        // 1. Verify Admin Authentication (Simple Token Check)
        // A more robust method (like sessions) is recommended for production
        $provided_token = $_POST['admin_auth_token'] ?? '';
        if (!password_verify($admin_password_correct, $provided_token)) {
        // if (!isset($_SESSION['isAdminAuthenticated']) || !$_SESSION['isAdminAuthenticated']) { // Session-based check
            redirect_with_message('Admin authentication failed or expired.');
        }

        // 2. Get Upload Data
        $upload_type = $_POST['upload_type'] ?? 'text';
        $download_password = $_POST['download_password'] ?? null;
        $text_content = $_POST['text_content'] ?? null;
        $file_info = $_FILES['file_content'] ?? null;

        if (empty($download_password)) {
            redirect_with_message('Download password is required.');
        }

        $share_id = generate_share_id();
        $hashed_password = password_hash($download_password, PASSWORD_DEFAULT);
        $share_data = load_share_data();
        $new_share = [
            'type' => $upload_type,
            'password_hash' => $hashed_password,
            'timestamp' => time(),
            'filename' => null, // Original filename
            'filepath' => null, // Path on server for files
            'text' => null
        ];

        // 3. Process Text or File
        if ($upload_type === 'text') {
            if (empty($text_content)) {
                redirect_with_message('Text content cannot be empty.');
            }
            $new_share['text'] = $text_content;
        } elseif ($upload_type === 'file') {
            if ($file_info === null || $file_info['error'] !== UPLOAD_ERR_OK) {
                redirect_with_message('File upload error: ' . ($file_info['error'] ?? 'Unknown error'));
            }
            if ($file_info['size'] === 0) {
                 redirect_with_message('Uploaded file is empty.');
            }
            // TODO: Add more robust validation (file type, size limit)

            $original_filename = basename($file_info['name']);
            $safe_filename = $share_id . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $original_filename);
            $destination = $upload_dir . $safe_filename;

            if (move_uploaded_file($file_info['tmp_name'], $destination)) {
                $new_share['filename'] = $original_filename;
                $new_share['filepath'] = $destination;
            } else {
                redirect_with_message('Failed to move uploaded file.');
            }
        } else {
            redirect_with_message('Invalid upload type.');
        }

        // 4. Save Share Data
        $share_data[$share_id] = $new_share;
        if (save_share_data($share_data)) {
            redirect_with_message('Content uploaded successfully! Share ID: ' . $share_id, 'success');
        } else {
            // Clean up uploaded file if saving data failed
            if ($upload_type === 'file' && isset($destination) && file_exists($destination)) {
                unlink($destination);
            }
            redirect_with_message('Failed to save share data.');
        }

    } elseif ($action === 'download') {
        // --- Download Logic ---

        $share_id = $_POST['share_id'] ?? null;
        $access_password = $_POST['access_password'] ?? null;

        if (empty($share_id) || empty($access_password)) {
            redirect_with_message('Share ID and download password are required.');
        }

        $share_data = load_share_data();

        if (!isset($share_data[$share_id])) {
            redirect_with_message('Invalid Share ID.');
        }

        $share_info = $share_data[$share_id];

        if (!password_verify($access_password, $share_info['password_hash'])) {
            redirect_with_message('Incorrect download password.');
        }

        // Password correct, provide content
        if ($share_info['type'] === 'text') {
            // Display text directly (consider escaping if displaying in HTML context elsewhere)
            header('Content-Type: text/plain; charset=utf-8');
            echo $share_info['text'];
            exit;
        } elseif ($share_info['type'] === 'file') {
            $filepath = $share_info['filepath'];
            $filename = $share_info['filename'] ?? 'downloaded_file'; // Use original or default

            if (file_exists($filepath) && is_readable($filepath)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream'); // Generic type
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filepath));
                flush(); // Flush system output buffer
                readfile($filepath);
                exit;
            } else {
                redirect_with_message('Error: File not found or not readable on server.');
            }
        } else {
            redirect_with_message('Error: Unknown share type.');
        }

    } else {
        redirect_with_message('Invalid action specified.');
    }

} else {
    // Handle GET requests or invalid methods
    redirect_with_message('Invalid request method.');
}

?>