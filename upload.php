<?php
include 'db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set unlimited execution time for large uploads
set_time_limit(0);

// Fetch client details based on the upload token
$upload_token = isset($_GET['token']) ? $_GET['token'] : null;

if ($upload_token === null) {
    echo "Upload token not provided.";
    exit;
}

$client_sql = "SELECT * FROM Onboarding WHERE UploadToken = ?";
$stmt = $conn->prepare($client_sql);
$stmt->bind_param('s', $upload_token);
$stmt->execute();
$client_result = $stmt->get_result();
$client = $client_result->fetch_assoc();

if (!$client) {
    echo "Invalid upload link. Please contact your representative for assistance.";
    exit;
}

$client_name = htmlspecialchars($client['ClientName']);
$client_id = htmlspecialchars($client['ClientID']);

// Create data directory if it doesn't exist
$base_dir = __DIR__ . '/data';
if (!file_exists($base_dir)) {
    if (!mkdir($base_dir, 0777, true)) {
        die("Failed to create base data directory. Please contact support.");
    }
}

// Use the upload token for the folder name
$upload_dir = $base_dir . '/' . $upload_token;

// Handle chunked upload
if (isset($_POST['chunk_upload'])) {
    header('Content-Type: application/json');

    $chunk_number = intval($_POST['chunk_number']);
    $total_chunks = intval($_POST['total_chunks']);
    $file_name = preg_replace("/[^a-zA-Z0-9._-]/", "_", $_POST['file_name']);

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $temp_dir = $upload_dir . '/temp';
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }

    $chunk_file = $temp_dir . '/' . $file_name . '.part' . $chunk_number;

    if (isset($_FILES['chunk']) && $_FILES['chunk']['error'] === UPLOAD_ERR_OK) {
        if (move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_file)) {
            // Check if all chunks have been uploaded
            $all_chunks_uploaded = true;
            for ($i = 0; $i < $total_chunks; $i++) {
                if (!file_exists($temp_dir . '/' . $file_name . '.part' . $i)) {
                    $all_chunks_uploaded = false;
                    break;
                }
            }

            if ($all_chunks_uploaded) {
                // Combine all chunks
                $final_file = $upload_dir . '/' . $file_name;
                $out = fopen($final_file, 'wb');

                for ($i = 0; $i < $total_chunks; $i++) {
                    $chunk_path = $temp_dir . '/' . $file_name . '.part' . $i;
                    $in = fopen($chunk_path, 'rb');
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    unlink($chunk_path); // Delete chunk after combining
                }

                fclose($out);
                chmod($final_file, 0666);

                // Log upload in history
                $history_sql = "INSERT INTO OnboardingHistory (ClientID, ActionType, ActionDetails)
                               VALUES (?, 'File Upload', ?)";
                $stmt = $conn->prepare($history_sql);
                $action_details = "Client uploaded file: " . $file_name;
                $stmt->bind_param('ss', $client_id, $action_details);
                $stmt->execute();
                $stmt->close();

                echo json_encode(['success' => true, 'complete' => true, 'message' => 'Upload completed successfully!']);
            } else {
                echo json_encode(['success' => true, 'complete' => false]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save chunk']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Chunk upload failed']);
    }
    exit;
}

// Handle regular upload (for smaller files)
$message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    if ($_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file_name = basename($_FILES['file']['name']);
        $file_name = preg_replace("/[^a-zA-Z0-9._-]/", "_", $file_name);
        $upload_file = $upload_dir . '/' . $file_name;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $upload_file)) {
            $message = "Upload completed successfully!";
            chmod($upload_file, 0666);

            // Log upload in history
            $history_sql = "INSERT INTO OnboardingHistory (ClientID, ActionType, ActionDetails)
                           VALUES (?, 'File Upload', ?)";
            $stmt = $conn->prepare($history_sql);
            $action_details = "Client uploaded file: " . $file_name;
            $stmt->bind_param('ss', $client_id, $action_details);
            $stmt->execute();
            $stmt->close();
        } else {
            $error_message = "Failed to upload file. Please try again or contact support.";
        }
    } else {
        switch ($_FILES['file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
                $error_message = "File is too large (max: " . ini_get('upload_max_filesize') . ")";
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $error_message = "File exceeds form limit";
                break;
            case UPLOAD_ERR_PARTIAL:
                $error_message = "File was only partially uploaded. Please try again.";
                break;
            default:
                $error_message = "Upload failed. Please try again or contact support.";
        }
    }
}

// Get PHP configuration values
$max_upload = ini_get('upload_max_filesize');
$max_post = ini_get('post_max_size');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Upload Portal - Taxware Systems</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-image: url('https://kb.taxwaresystems.com/web_texture_mirrored.svg');
            background-repeat: no-repeat;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-color: #f8f8f8;
            min-height: 100vh;
        }

        .header { background-color: #A53323; padding: 20px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header-content { max-width: 1200px; margin: 0 auto; padding: 0 20px; text-align: center; }
        .logo { height: 40px; width: auto; }

        .nav { background-color: #f5f5f5; border-bottom: 1px solid #ddd; padding: 10px 0; }
        .nav-content { max-width: 1200px; margin: 0 auto; padding: 0 20px; color: #666; font-size: 14px; }
        .nav-content span { color: #A53323; }
        .nav-content i { margin: 0 5px; color: #999; font-size: 12px; }

        .container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        .content-box { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .content-header { background: linear-gradient(135deg, #A53323 0%, #8B2918 100%); color: white; padding: 30px; text-align: center; }
        .content-header h1 { font-size: 28px; margin-bottom: 10px; font-weight: 400; }
        .client-info { color: rgba(255,255,255,0.9); font-size: 16px; }
        .content-body { padding: 40px; }

        .info-box {
            background: #fff8f0;
            border-left: 4px solid #A53323;
            padding: 15px 20px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        .info-box h3 { color: #A53323; font-size: 16px; margin-bottom: 8px; font-weight: 600; }
        .info-box p { color: #666; font-size: 14px; line-height: 1.5; }

        /* Upload Area */
        .upload-area {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 60px 20px;
            text-align: center;
            background: #fafafa;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .upload-area:hover { border-color: #A53323; background: #fff8f0; }
        .upload-area.dragover { border-color: #A53323; background: #fff8f0; transform: scale(1.02); }

        .upload-icon {
            width: 80px; height: 80px;
            margin: 0 auto 20px;
            background: #A53323;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 36px; color: white;
        }
        .upload-text { color: #333; font-size: 20px; margin-bottom: 10px; }
        .upload-subtext { color: #999; font-size: 14px; margin-bottom: 20px; }

        input[type="file"] { display: none; }

        .file-input-label {
            display: inline-block;
            padding: 12px 30px;
            background: #A53323;
            color: white;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-transform: uppercase;
            font-size: 14px;
            letter-spacing: 0.5px;
        }
        .file-input-label:hover {
            background: #8B2918;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(165,51,35,0.3);
        }

        .selected-file {
            margin-top: 30px;
            padding: 20px;
            background: #f8f8f8;
            border-radius: 8px;
            display: none;
        }
        .selected-file.show { display: block; }

        .file-info { display: flex; align-items: center; justify-content: space-between; }
        .file-details { flex: 1; }
        .file-name { color: #333; font-weight: 600; font-size: 16px; margin-bottom: 5px; }
        .file-size { color: #666; font-size: 14px; }

        .clear-btn {
            padding: 8px 16px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        .clear-btn:hover { background: #c82333; }

        .upload-btn {
            width: 100%;
            padding: 15px;
            background: #A53323;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 16px;
            margin-top: 20px;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: none;
        }
        .upload-btn.show { display: block; }
        .upload-btn:hover:not(:disabled) {
            background: #8B2918;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(165,51,35,0.3);
        }
        .upload-btn:disabled { opacity: 0.6; cursor: not-allowed; }

        /* ? Disabled styling for BOTH buttons */
        .clear-btn:disabled,
        .upload-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .progress-container { margin-top: 30px; display: none; }
        .progress-container.show { display: block; }
        .progress-bar { width: 100%; height: 8px; background-color: #e9ecef; border-radius: 4px; overflow: hidden; }
        .progress-bar-inner { height: 100%; background: #A53323; width: 0; transition: width 0.3s; }
        .progress-text { text-align: center; margin-top: 15px; color: #666; font-size: 14px; }
        .progress-details { display: flex; justify-content: space-between; margin-top: 10px; font-size: 13px; color: #999; }

        .message {
            padding: 15px 20px;
            background: #d4edda;
            color: #155724;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            animation: slideIn 0.5s ease;
        }
        .message i { margin-right: 8px; }

        .error {
            padding: 15px 20px;
            background: #f8d7da;
            color: #721c24;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            animation: slideIn 0.5s ease;
        }
        .error i { margin-right: 8px; }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .upload-another {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .upload-another a { color: #A53323; text-decoration: none; font-weight: 500; }
        .upload-another a:hover { text-decoration: underline; }

        .footer { text-align: center; padding: 40px 20px; color: #666; font-size: 14px; }
        .footer p { margin: 5px 0; }
        .footer a { color: #A53323; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        .footer i { margin-right: 5px; }

        @media (max-width: 600px) {
            .content-header h1 { font-size: 24px; }
            .content-body { padding: 30px 20px; }
            .upload-area { padding: 40px 20px; }
            .upload-icon { width: 60px; height: 60px; font-size: 28px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <img src="https://kb.taxwaresystems.com/logo.png" alt="Taxware Systems Inc" class="logo">
        </div>
    </div>

    <div class="nav">
        <div class="nav-content">
            <span>Taxware Systems</span> <i class="fas fa-chevron-right"></i> File Upload Portal
        </div>
    </div>

    <div class="container">
        <div class="content-box">
            <div class="content-header">
                <h1>File Upload Portal</h1>
                <div class="client-info"><?php echo $client_name; ?></div>
            </div>

            <div class="content-body">
                <!-- JS-driven (no refresh) messages -->
                <div class="message" id="success-message" style="display:none;">
                    <i class="fas fa-check-circle"></i> Upload completed successfully!
                </div>
                <div class="error" id="error-message" style="display:none;">
                    <i class="fas fa-exclamation-triangle"></i> Upload failed. Please try again.
                </div>

                <!-- PHP messages (kept harmless; JS flow doesn't refresh) -->
                <?php if (!empty($message)): ?>
                    <div class="message"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <div class="info-box">
                    <h3>Upload Information</h3>
                    <p>
                        Maximum file size: <strong><?php echo $max_upload; ?></strong><br>
                        Large files will be automatically processed in chunks for reliable upload.<br>
                        Supported formats: All file types are accepted.
                    </p>
                </div>

                <!-- ? This area will be HIDDEN after a successful upload -->
                <div class="upload-area" id="upload-area" ondrop="handleDrop(event)" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)">
                    <div class="upload-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <div class="upload-text">Drag & Drop your file here</div>
                    <div class="upload-subtext">or click to browse</div>
                    <input type="file" id="file-input" onchange="handleFileSelect(event)">
                    <label for="file-input" class="file-input-label">Select File</label>
                </div>

                <div class="selected-file" id="selected-file">
                    <div class="file-info">
                        <div class="file-details">
                            <div class="file-name" id="file-name"></div>
                            <div class="file-size" id="file-size"></div>
                        </div>
                        <button class="clear-btn" id="remove-btn" onclick="resetForm()">Remove</button>
                    </div>
                </div>

                <button class="upload-btn" id="upload-btn" onclick="uploadFile()">
                    Upload File
                </button>

                <div class="progress-container" id="progress-container">
                    <div class="progress-bar">
                        <div class="progress-bar-inner" id="progress-bar"></div>
                    </div>
                    <div class="progress-text" id="progress-text">Uploading... 0%</div>
                    <div class="progress-details">
                        <span id="upload-speed"></span>
                        <span id="upload-eta"></span>
                    </div>
                </div>

                <!-- ? After upload, this becomes the ONLY way to start a new upload -->
                <div class="upload-another" id="upload-another" style="display:none;">
                    <p>Need to upload another file? <a href="#" onclick="resetForm(); return false;">Click here to upload another file</a></p>
                </div>

            </div>
        </div>
    </div>

    <div class="footer">
        <p><i class="far fa-copyright"></i> <?php echo date('Y'); ?> Taxware Systems Inc. All rights reserved.</p>
        <p>Need assistance? Contact <a href="mailto:support@taxwaresystems.com">support@taxwaresystems.com</a></p>
    </div>

    <script>
        let selectedFile = null;
        const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB
        const LARGE_FILE_THRESHOLD = 100 * 1024 * 1024; // 100MB
        let startTime;
        let uploadCompleted = false;

        function hideMessages() {
            document.getElementById('success-message').style.display = 'none';
            document.getElementById('error-message').style.display = 'none';
        }

        function showSuccess(text) {
            const el = document.getElementById('success-message');
            el.innerHTML = `<i class="fas fa-check-circle"></i> ${text}`;
            el.style.display = 'block';
            document.getElementById('upload-another').style.display = 'block';
        }

        function showError(text) {
            const el = document.getElementById('error-message');
            el.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${text}`;
            el.style.display = 'block';
        }

        // ? After upload completes successfully:
        // - keep upload/remove disabled & gray
        // - HIDE the drag/drop area so only "upload another" link can restart
        function lockUploadUI() {
            uploadCompleted = true;

            // Disable upload button
            const uploadBtn = document.getElementById('upload-btn');
            uploadBtn.disabled = true;

            // Disable remove button
            const removeBtn = document.getElementById('remove-btn');
            if (removeBtn) removeBtn.disabled = true;

            // Disable file input
            document.getElementById('file-input').disabled = true;

            // ? Hide upload area (drag/drop + select file UI)
            document.getElementById('upload-area').style.display = 'none';
        }

        function handleDragOver(e) {
            if (uploadCompleted) return;
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('upload-area').classList.add('dragover');
        }

        function handleDragLeave(e) {
            if (uploadCompleted) return;
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('upload-area').classList.remove('dragover');
        }

        function handleDrop(e) {
            if (uploadCompleted) return;
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('upload-area').classList.remove('dragover');

            const files = e.dataTransfer.files;
            if (files.length > 0) {
                selectFile(files[0]);
            }
        }

        function handleFileSelect(event) {
            if (uploadCompleted) return;
            if (event.target.files.length > 0) {
                selectFile(event.target.files[0]);
            }
        }

        function selectFile(file) {
            hideMessages();
            selectedFile = file;

            const sizeInMB = (file.size / 1048576).toFixed(2);
            const sizeInGB = (file.size / 1073741824).toFixed(2);
            const sizeText = file.size > 1073741824 ? `${sizeInGB} GB` : `${sizeInMB} MB`;

            document.getElementById('file-name').textContent = file.name;
            document.getElementById('file-size').textContent = `File size: ${sizeText}`;
            document.getElementById('selected-file').classList.add('show');

            const uploadBtn = document.getElementById('upload-btn');
            uploadBtn.classList.add('show');
            uploadBtn.disabled = false;

            const removeBtn = document.getElementById('remove-btn');
            if (removeBtn) removeBtn.disabled = false;
        }

        // ? Clicking "upload another file" resets everything and shows upload UI again
        function resetForm() {
            uploadCompleted = false;
            selectedFile = null;

            document.getElementById('file-input').disabled = false;
            document.getElementById('file-input').value = '';

            // Show upload area again
            document.getElementById('upload-area').style.display = 'block';

            document.getElementById('selected-file').classList.remove('show');

            const uploadBtn = document.getElementById('upload-btn');
            uploadBtn.classList.remove('show');
            uploadBtn.disabled = false;

            const removeBtn = document.getElementById('remove-btn');
            if (removeBtn) removeBtn.disabled = false;

            document.getElementById('progress-container').classList.remove('show');
            document.getElementById('upload-another').style.display = 'none';
            hideMessages();

            document.getElementById('progress-bar').style.width = '0%';
            document.getElementById('progress-text').textContent = 'Uploading... 0%';
            document.getElementById('upload-speed').textContent = '';
            document.getElementById('upload-eta').textContent = '';
        }

        async function uploadFile() {
            if (!selectedFile) {
                alert('Please select a file first');
                return;
            }

            hideMessages();
            document.getElementById('progress-container').classList.add('show');

            // Disable buttons during upload
            document.getElementById('upload-btn').disabled = true;
            const removeBtn = document.getElementById('remove-btn');
            if (removeBtn) removeBtn.disabled = true;

            startTime = Date.now();

            if (selectedFile.size > LARGE_FILE_THRESHOLD) {
                await uploadInChunks();
            } else {
                await uploadRegular();
            }
        }

        async function uploadInChunks() {
            const totalChunks = Math.ceil(selectedFile.size / CHUNK_SIZE);
            let uploadedChunks = 0;
            let totalUploaded = 0;

            for (let i = 0; i < totalChunks; i++) {
                const start = i * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, selectedFile.size);
                const chunk = selectedFile.slice(start, end);

                const formData = new FormData();
                formData.append('chunk', chunk);
                formData.append('chunk_upload', '1');
                formData.append('file_name', selectedFile.name);
                formData.append('chunk_number', i.toString());
                formData.append('total_chunks', totalChunks.toString());

                try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (!result.success) throw new Error(result.error || 'Upload failed');

                    uploadedChunks++;
                    totalUploaded += chunk.size;

                    const progress = (uploadedChunks / totalChunks) * 100;
                    updateProgress(progress, totalUploaded);

                    if (result.complete) {
                        updateProgress(100, selectedFile.size);
                        showSuccess(result.message || 'Upload completed successfully!');
                        lockUploadUI(); // ? disables buttons + hides upload area
                        return;
                    }
                } catch (error) {
                    showError('Upload failed: ' + error.message);

                    // Re-enable if failed
                    document.getElementById('upload-btn').disabled = false;
                    const removeBtn = document.getElementById('remove-btn');
                    if (removeBtn) removeBtn.disabled = false;
                    document.getElementById('progress-container').classList.remove('show');
                    return;
                }
            }
        }

        async function uploadRegular() {
            const formData = new FormData();
            formData.append('file', selectedFile);

            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const progress = (e.loaded / e.total) * 100;
                    updateProgress(progress, e.loaded);
                }
            });

            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    updateProgress(100, selectedFile.size);
                    showSuccess('Upload completed successfully!');
                    lockUploadUI(); // ? disables buttons + hides upload area
                } else {
                    showError('Upload failed. Please try again.');
                    document.getElementById('upload-btn').disabled = false;
                    const removeBtn = document.getElementById('remove-btn');
                    if (removeBtn) removeBtn.disabled = false;
                }
            });

            xhr.addEventListener('error', function() {
                showError('Upload failed. Please check your connection and try again.');
                document.getElementById('upload-btn').disabled = false;
                const removeBtn = document.getElementById('remove-btn');
                if (removeBtn) removeBtn.disabled = false;
                document.getElementById('progress-container').classList.remove('show');
            });

            xhr.open('POST', '');
            xhr.send(formData);
        }

        function updateProgress(percent, loaded) {
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');
            const uploadSpeed = document.getElementById('upload-speed');
            const uploadEta = document.getElementById('upload-eta');

            progressBar.style.width = percent.toFixed(1) + '%';

            if (percent >= 100) {
                progressText.textContent = 'Upload complete!';
                uploadSpeed.textContent = '';
                uploadEta.textContent = '';
            } else {
                progressText.textContent = `Uploading... ${percent.toFixed(1)}%`;

                const elapsedTime = (Date.now() - startTime) / 1000;
                const speed = elapsedTime > 0 ? (loaded / elapsedTime) / 1048576 : 0;
                uploadSpeed.textContent = `Speed: ${speed.toFixed(2)} MB/s`;

                const remaining = selectedFile.size - loaded;
                const rate = loaded / elapsedTime;
                const eta = (rate > 0) ? (remaining / rate) : 0;

                const etaMinutes = Math.floor(eta / 60);
                const etaSeconds = Math.floor(eta % 60);

                uploadEta.textContent = etaMinutes > 0 ? `ETA: ${etaMinutes}m ${etaSeconds}s` : `ETA: ${etaSeconds}s`;
            }
        }

        // Click anywhere on upload area to trigger file selection (blocked after completion)
        document.getElementById('upload-area').addEventListener('click', function(e) {
            if (uploadCompleted) return;
            if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'LABEL') {
                document.getElementById('file-input').click();
            }
        });
    </script>
</body>
</html>

<?php $conn->close(); ?>
