<?php
// 初始化会话并设置安全参数
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'use_strict_mode' => true
]); 

// Placeholder for admin upload password check - replace with actual check
$isAdminAuthenticated = false; // Assume not authenticated initially
$admin_password_correct = '00000000'; // Replace with your desired admin password
$session_timeout = 1800; // 30分钟

if (isset($_POST['admin_password'])) {
    if ($_POST['admin_password'] === $admin_password_correct) {
        $isAdminAuthenticated = true;
        $_SESSION['isAdminAuthenticated'] = true;
    }
} else if (isset($_SESSION['isAdminAuthenticated']) && $_SESSION['isAdminAuthenticated']) {
    $isAdminAuthenticated = true;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件/文本分享</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 600px; margin: auto; }
        h1, h2 { color: #333; text-align: center; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #555; }
        input[type="text"], input[type="password"], textarea, input[type="file"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        textarea { min-height: 100px; resize: vertical; }
        button { 
            background-color: #0073aa; /* WordPress blue */
            color: white; 
            padding: 12px 20px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 16px; 
            transition: background-color 0.3s ease; 
            display: block;
            width: 100%;
            margin-top: 10px;
        }
        button:hover { background-color: #005a87; }
        .section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .section:last-child { border-bottom: none; }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .form-group { margin-bottom: 15px; }
        /* 新增：任务列表小按钮样式 */
        .task-list-btn {
            background: none;
            border: none;
            color: #0073aa;
            padding: 0;
            font-size: 13px;
            cursor: pointer;
            text-decoration: underline;
            display: inline-block;
            margin-left: 4px;
            margin-right: 0;
            line-height: 1.5;
            vertical-align: baseline;
            box-shadow: none;
            width: auto;
        }
        .task-list-btn:hover {
            color: #005a87;
            text-decoration: underline;
            background: none;
        }
        .task-list-inline {
            display: inline-block;
            white-space: nowrap;
            line-height: 1.5;
            vertical-align: baseline;
        }
        .task-list-inline strong,
        .task-list-inline span {
            margin-right: 4px;
        }
        .task-list-inline form {
            display: inline;
            margin: 0;
        }
        .task-list-inline li,
        .task-list-inline div {
            background: none !important;
            box-shadow: none !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>安全分享</h1>

        <?php if (!$isAdminAuthenticated): ?>
        <div class="section" id="admin-login">
            <h2>管理员上传认证</h2>
            <form action="share_page.php" method="post">
                <div class="form-group">
                    <label for="admin_password">管理员密码:</label>
                    <input type="password" id="admin_password" name="admin_password" required>
                </div>
                <button type="submit">认证</button>
            </form>
             <?php if (isset($_POST['admin_password']) && !$isAdminAuthenticated): ?>
                <p class="message error">管理员密码错误。</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($isAdminAuthenticated): ?>
        <div class="section" id="upload-section">
            <h2>上传内容</h2>
            <form action="share_handler.php?action=upload" method="post" enctype="multipart/form-data">
                <input type="hidden" name="admin_auth_token" value="<?php echo htmlspecialchars(password_hash($admin_password_correct, PASSWORD_DEFAULT)); // Simple token example ?>">
                <div class="form-group">
                    <label for="upload_type">上传类型:</label>
                    <select id="upload_type" name="upload_type" onchange="toggleUploadFields()">
                        <option value="text">文本</option>
                        <option value="file">文件</option>
                    </select>
                </div>
                <div class="form-group" id="text_content_group">
                    <label for="text_content">文本内容:</label>
                    <textarea id="text_content" name="text_content"></textarea>
                </div>
                <div class="form-group" id="file_content_group" style="display: none;">
                    <label for="file_content">选择文件:</label>
                    <input type="file" id="file_content" name="file_content">
                </div>
                <div class="form-group">
                    <label for="download_password">设置下载密码 (可选):</label>
                    <input type="password" id="download_password" name="download_password">
                </div>
                <button type="submit">上传</button>
            </form>
        </div>
        <!-- 新增：显示所有已上传任务 -->
        <div class="section" id="task-list-section">
            <h2>已上传任务列表</h2>
            <ul>
            <?php
            $data_file = __DIR__ . '/shares.json';
            if (file_exists($data_file)) {
                $json = file_get_contents($data_file);
                $shares = json_decode($json, true);
                if (is_array($shares) && count($shares) > 0) {
                    foreach ($shares as $share_id => $share) {
                        $desc = '';
                        if ($share['type'] === 'file') {
                            $original_filename = $share['filename'] ? $share['filename'] : '未知文件';
                            if (mb_strlen($original_filename, 'UTF-8') > 15) {
                                $desc = mb_substr($original_filename, 0, 15, 'UTF-8');
                            } else {
                                $desc = $original_filename;
                            }
                        } elseif ($share['type'] === 'text') {
                            $desc = isset($share['text']) ? mb_substr($share['text'], 0, 10, 'UTF-8') : '无内容';
                        } else {
                            $desc = '未知类型';
                        }
                        echo "<li><div class=\"task-list-inline\"><strong>任务号:</strong> {$share_id} &nbsp; <strong>内容:</strong> " . htmlspecialchars($desc);
                        // 复制按钮
                        $copyValue = $share['type'] === 'file' ? ($share['filename'] ? $share['filename'] : '未知文件') : (isset($share['text']) ? $share['text'] : '无内容');
                        echo '<button type="button" class="copy-btn task-list-btn" data-copy="' . htmlspecialchars($copyValue) . '">复制</button>';
                        echo '<form action="share_handler.php?action=delete" method="post" style="display:inline;margin:0;">
                            <input type="hidden" name="share_id" value="' . htmlspecialchars($share_id) . '">
                            <button type="submit" class="task-list-btn" onclick=\"return confirm(\'确定要删除该任务吗？\');\">删除</button>';
                        echo '</form>';
                        echo '</div></li>';
                    }
                } else {
                    echo "<li>暂无任务</li>";
                }
            } else {
                echo "<li>暂无任务</li>";
            }
            ?>
            </ul>
        </div>
<?php endif; ?>

        <div class="section" id="download-section">
            <h2>下载内容</h2>
            <form action="share_handler.php?action=download" method="post">
                <div class="form-group">
                    <label for="share_id">分享ID:</label>
                    <input type="text" id="share_id" name="share_id" required>
                </div>
                <div class="form-group">
                    <label for="access_password">下载密码 (如果需要):</label>
                    <input type="password" id="access_password" name="access_password">
                </div>
                <button type="submit">获取内容</button>
            </form>
        </div>

        <!-- Display messages from handler -->
        <?php 
        if (isset($_GET['message'])) {
            $message = htmlspecialchars($_GET['message']);
            $status = isset($_GET['status']) ? $_GET['status'] : 'info'; // info, success, error
            echo "<div class='message {$status}'>{$message}</div>";
        }
        // 新增：如果有文本内容参数，则显示文本内容
        if (isset($_GET['text_content'])) {
            $text_content = htmlspecialchars(urldecode($_GET['text_content']));
            echo "<div class='message success'><strong>分享文本内容：</strong><br><pre style='white-space:pre-wrap;word-break:break-all;'>" . $text_content . "</pre></div>";
        }
        ?>

    </div>

    <script>
        function toggleUploadFields() {
            const uploadType = document.getElementById('upload_type').value;
            const textGroup = document.getElementById('text_content_group');
            const fileGroup = document.getElementById('file_content_group');
            const textInput = document.getElementById('text_content');
            const fileInput = document.getElementById('file_content');

            if (uploadType === 'text') {
                textGroup.style.display = 'block';
                fileGroup.style.display = 'none';
                textInput.required = true;
                fileInput.required = false;
            } else {
                textGroup.style.display = 'none';
                fileGroup.style.display = 'block';
                textInput.required = false;
                fileInput.required = true;
            }
        }
        // Initial call to set the correct fields based on default selection
        document.addEventListener('DOMContentLoaded', toggleUploadFields);

        // 复制按钮功能
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.copy-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const val = btn.getAttribute('data-copy');
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(val).then(function() {
                            btn.textContent = '已复制';
                            setTimeout(function(){ btn.textContent = '复制'; }, 1000);
                        });
                    } else {
                        // 兼容旧浏览器
                        const textarea = document.createElement('textarea');
                        textarea.value = val;
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        btn.textContent = '已复制';
                        setTimeout(function(){ btn.textContent = '复制'; }, 1000);
                    }
                });
            });
        });
    </script>

</body>
</html>