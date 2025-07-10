<?php
// 初始化会话并设置安全参数
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

// --- Configuration ---
$upload_dir = __DIR__ . '/uploads/'; // Directory to store uploaded files
$data_file = __DIR__ . '/shares.json'; // File to store share metadata
$admin_password_correct = '00000000'; // MUST MATCH the one in share_page.php

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
    $share_data = load_share_data();
    do {
        $id = strval(random_int(1000, 9999));
    } while (isset($share_data[$id]));
    return $id;
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
        // $provided_token = $_POST['admin_auth_token'] ?? '';
        // if (!password_verify($admin_password_correct, $provided_token)) {
        if (!isset($_SESSION['isAdminAuthenticated']) || !$_SESSION['isAdminAuthenticated']) { // Session-based check
            error_log('SESSION DEBUG: ' . print_r($_SESSION, true));
            redirect_with_message('Admin authentication failed or expired.');
        }

        // 2. Get Upload Data
        $download_password = $_POST['download_password'] ?? null;
        $text_content = $_POST['text_content'] ?? null;
        $file_info = $_FILES['file_content'] ?? null;
        $upload_type = ''; // Determine this based on input

        // Determine upload type: file has priority
        if ($file_info && $file_info['error'] === UPLOAD_ERR_OK) {
            if ($file_info['size'] > 0) {
                $upload_type = 'file';
            } else {
                redirect_with_message('上传的文件不能为空。');
            }
        } elseif (!empty(trim($text_content))) {
            $upload_type = 'text';
        } else {
            redirect_with_message('必须提供文本内容或上传一个文件。');
        }

        $share_id = generate_share_id();
        $hashed_password = !empty($download_password) ? password_hash($download_password, PASSWORD_DEFAULT) : null;
        $share_data = load_share_data();
        $new_share = [
            'type' => $upload_type,
            'password_hash' => $hashed_password, // Can be null
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
            // 文件类型和大小验证
            $allowed_types = [
                // 镜像
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp',
                // 文档
                'application/pdf', 'text/plain', 'text/csv', 'text/html',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .doc, .docx
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xls, .xlsx
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .ppt, .pptx
                // 压缩文件
                'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
                // 音视频
                'audio/mpeg', 'audio/wav', 'video/mp4', 'video/webm'
            ];
            $max_size = 100 * 1024 * 1024; // 100MB

            // 简单的 MIME 类型检查
            if (!in_array($file_info['type'], $allowed_types)) {
                // 如果类型不在列表中，进行扩展名检查作为备用
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'pdf', 'txt', 'csv', 'html', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z', 'mp3', 'wav', 'mp4', 'webm'];
                $file_extension = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
                if (!in_array($file_extension, $allowed_extensions)) {
                    redirect_with_message('不被允许的文件类型。');
                }
            }

            if ($file_info['size'] > $max_size) {
                redirect_with_message('文件大小超过 100MB 限制。');
            }

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

        if (empty($share_id)) {
            redirect_with_message('Share ID is required.');
        }

        $share_data = load_share_data();

        if (!isset($share_data[$share_id])) {
            redirect_with_message('Invalid Share ID.');
        }

        $share_info = $share_data[$share_id];

        // --- Password Verification Logic ---
        $is_password_protected = !empty($share_info['password_hash']);

        if ($is_password_protected) {
            // Share is password protected, so a password is required
            if (empty($access_password)) {
                redirect_with_message('此分享受密码保护，请输入下载密码。');
            }
            // Verify the provided password
            if (!password_verify($access_password, $share_info['password_hash'])) {
                redirect_with_message('下载密码错误。');
            }
        }
        // If not password protected, or if the correct password was provided, proceed to download.

        // Password correct or no password, provide content
        if ($share_info['type'] === 'text') {
            // 将文本内容通过重定向参数返回到 share_page.php
            $text = urlencode($share_info['text']);
            header('Location: share_page.php?text_content=' . $text . '&status=success');
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

    } else if ($action === 'delete') {
        // --- Delete Logic ---
        $share_id = $_POST['share_id'] ?? null;
        if (empty($share_id)) {
            redirect_with_message('缺少任务ID，无法删除。');
        }
        $share_data = load_share_data();
        if (!isset($share_data[$share_id])) {
            redirect_with_message('任务不存在或已被删除。');
        }
        // 如果是文件类型，尝试删除文件
        if (isset($share_data[$share_id]['type']) && $share_data[$share_id]['type'] === 'file') {
            $filepath = $share_data[$share_id]['filepath'] ?? null;
            if ($filepath && file_exists($filepath)) {
                @unlink($filepath);
            }
        }
        unset($share_data[$share_id]);
        if (save_share_data($share_data)) {
            redirect_with_message('任务已成功删除！', 'success');
        } else {
            redirect_with_message('删除失败，数据保存异常。');
        }
    } else {
        redirect_with_message('Invalid action specified.');
    }

} else {
    // Handle GET requests or invalid methods
    redirect_with_message('Invalid request method.');
}

?>