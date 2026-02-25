<?php
include 'db.php';

$upload_token = isset($_POST['token']) ? $_POST['token'] : null;
$chunkNumber = isset($_POST['chunkNumber']) ? intval($_POST['chunkNumber']) : null;
$totalChunks = isset($_POST['totalChunks']) ? intval($_POST['totalChunks']) : null;
$fileName = isset($_POST['fileName']) ? $_POST['fileName'] : null;

if ($upload_token === null || $chunkNumber === null || $totalChunks === null || $fileName === null) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);
    exit;
}

$client_sql = "SELECT * FROM Onboarding WHERE UploadToken = ?";
$stmt = $conn->prepare($client_sql);
$stmt->bind_param('s', $upload_token);
$stmt->execute();
$client_result = $stmt->get_result();
$client = $client_result->fetch_assoc();

if (!$client) {
    echo json_encode(['success' => false, 'error' => 'Client not found.']);
    exit;
}

$upload_dir = __DIR__ . '/data/' . htmlspecialchars($client['ClientID']) . '-' . htmlspecialchars($client['AssignedTech']);
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$chunkFile = $upload_dir . '/' . $fileName . '.part' . $chunkNumber;
if (move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
    // Check if all chunks have been uploaded
    if ($chunkNumber == $totalChunks) {
        // Combine all chunks into one file
        $finalFile = $upload_dir . '/' . $fileName;
        $out = fopen($finalFile, 'wb');
        for ($i = 1; $i <= $totalChunks; $i++) {
            $chunkFile = $upload_dir . '/' . $fileName . '.part' . $i;
            $in = fopen($chunkFile, 'rb');
            while ($buff = fread($in, 4096)) {
                fwrite($out, $buff);
            }
            fclose($in);
            unlink($chunkFile); // Delete the chunk
        }
        fclose($out);
    }
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to move uploaded chunk.']);
}

$conn->close();
?>
