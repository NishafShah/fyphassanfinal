<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../actions/file_create.php';

header('Content-Type: application/json');

if (empty($_FILES['files'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No files provided.'
    ]);
    exit;
}

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]);
    exit;
}

$folder = sanitizeUploadFolder($_POST['folder'] ?? '');

$files = $_FILES['files'];
$fileCount = count($files['name']);
if ($fileCount === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'No files were uploaded.'
    ]);
    exit;
}

$responses = [];

$uploadsBaseDir = UPLOADS_PATH;
if (!file_exists($uploadsBaseDir)) {
    mkdir($uploadsBaseDir, 0755, true);
}

$targetDir = $uploadsBaseDir;
if ($folder) {
    $targetDir = rtrim($uploadsBaseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;
}

if (!file_exists($targetDir) && !mkdir($targetDir, 0755, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create upload directory.'
    ]);
    exit;
}

for ($i = 0; $i < $fileCount; $i++) {
    if (!isset($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) {
        $responses[] = [
            'filename' => $files['name'][$i] ?? 'unknown',
            'success' => false,
            'message' => 'File upload error.'
        ];
        continue;
    }

    $originalName = $files['name'][$i];
    $sanitized = sanitizeFilename($originalName);
    if (!$sanitized) {
        $responses[] = [
            'filename' => $originalName,
            'success' => false,
            'message' => 'Invalid filename.'
        ];
        continue;
    }

    $filename = resolveUniqueFilename($targetDir, $sanitized);
    $destinationPath = $targetDir . $filename;

    if (!move_uploaded_file($files['tmp_name'][$i], $destinationPath)) {
        $responses[] = [
            'filename' => $filename,
            'success' => false,
            'message' => 'Failed to move uploaded file.'
        ];
        continue;
    }

    $content = file_get_contents($destinationPath);
    if ($content === false) {
        unlink($destinationPath);
        $responses[] = [
            'filename' => $filename,
            'success' => false,
            'message' => 'Failed to read uploaded file.'
        ];
        continue;
    }

    $desktopPath = getDesktopFilePath($filename);
    $drivePath = getDriveDFilePath($filename, $folder);

    if (!syncFileToDesktop($filename, $content) || !syncFileToDriveD($filename, $content, $folder)) {
        if (file_exists($destinationPath)) {
            unlink($destinationPath);
        }
        if (file_exists($desktopPath)) {
            unlink($desktopPath);
        }
        $responses[] = [
            'filename' => $filename,
            'success' => false,
            'message' => 'Failed to sync file copies.'
        ];
        continue;
    }

    $filesize = filesize($destinationPath);
    $mimeType = getMimeType($filename);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO files (filename, filepath, desktop_filepath, size, mime_type, folder)
            VALUES (:filename, :filepath, :desktop_filepath, :size, :mime_type, :folder)
        ");

        $stmt->execute([
            ':filename' => $filename,
            ':filepath' => $destinationPath,
            ':desktop_filepath' => $desktopPath,
            ':size' => $filesize,
            ':mime_type' => $mimeType,
            ':folder' => $folder
        ]);

        $responses[] = [
            'filename' => $filename,
            'success' => true,
            'uploads_path' => realpath($destinationPath) ?: $destinationPath
        ];
    } catch (Exception $e) {
        if (file_exists($destinationPath)) {
            unlink($destinationPath);
        }
        $responses[] = [
            'filename' => $filename,
            'success' => false,
            'message' => 'Failed to save file metadata: ' . $e->getMessage()
        ];
    }
}

$successful = array_filter($responses, fn($item) => $item['success']);
$failed = array_filter($responses, fn($item) => !$item['success']);

echo json_encode([
    'success' => !empty($successful),
    'message' => empty($successful) ? 'All uploads failed.' : 'Upload completed.',
    'files' => array_values($successful),
    'errors' => array_values($failed),
    'folder' => $folder
]);

function resolveUniqueFilename($directory, $filename) {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $candidate = $filename;
    $counter = 1;

    while (file_exists($directory . $candidate)) {
        $suffix = '-' . $counter++;
        $candidate = $base . $suffix . ($extension ? '.' . $extension : '');
    }

    return $candidate;
}
