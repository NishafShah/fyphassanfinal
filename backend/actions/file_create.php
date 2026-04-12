<?php
/**
 * File Create Action
 * 
 * Creates a new file with the given filename and content.
 */

require_once __DIR__ . '/../config/db.php';

function createFile($input) {
    // Validate input
    if (empty($input['filename'])) {
        return [
            'success' => false,
            'message' => 'Filename is required.'
        ];
    }
    
    $filename = sanitizeFilename($input['filename']);
    $content = isset($input['content']) ? $input['content'] : '';
    $uploadsFolder = isset($input['folder']) ? sanitizeUploadFolder($input['folder']) : '';

    if (!$filename) {
        return [
            'success' => false,
            'message' => 'Invalid filename. Please use only letters, numbers, underscores, hyphens, and dots.'
        ];
    }
    
    // Check file extension (allow common text file extensions)
    $allowedExtensions = ['txt', 'md', 'json', 'html', 'css', 'js', 'xml', 'csv', 'log', 'php', 'py'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if (!in_array($extension, $allowedExtensions)) {
        // If no extension or invalid extension, add .txt
        $filename = $filename . '.txt';
    }
    
    $pdo = getDbConnection();
    
    if (!$pdo) {
        return [
            'success' => false,
            'message' => 'Database connection failed.'
        ];
    }
    
    // Check if file already exists
    $stmt = $pdo->prepare("SELECT id FROM files WHERE filename = :filename");
    $stmt->execute([':filename' => $filename]);
    
    if ($stmt->fetch()) {
        return [
            'success' => false,
            'message' => 'A file with this name already exists. Try a different name or use edit to modify the existing file.'
        ];
    }
    
    // Determine upload directory
    $uploadsBaseDir = UPLOADS_PATH;
    if (!file_exists($uploadsBaseDir)) {
        mkdir($uploadsBaseDir, 0755, true);
    }

    $targetUploadsDir = $uploadsBaseDir;
    if ($uploadsFolder) {
        $targetUploadsDir = rtrim($uploadsBaseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $uploadsFolder . DIRECTORY_SEPARATOR;
    }

    // Ensure target directory exists
    if (!file_exists($targetUploadsDir) && !mkdir($targetUploadsDir, 0755, true)) {
        return [
            'success' => false,
            'message' => 'Failed to create the target uploads folder.'
        ];
    }

    $filepath = $targetUploadsDir . $filename;
    $desktopFilepath = getDesktopFilePath($filename);
    $driveDFilepath = getDriveDFilePath($filename, $uploadsFolder);
    
    try {
        if (file_put_contents($filepath, $content) === false) {
            return [
                'success' => false,
                'message' => 'Failed to create file on disk.'
            ];
        }

        if (!syncFileToDesktop($filename, $content)) {
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            return [
                'success' => false,
                'message' => 'Failed to save file on the desktop.'
            ];
        }

        if (!syncFileToDriveD($filename, $content, $uploadsFolder)) {
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            removeDesktopFile($filename);

            return [
                'success' => false,
                'message' => 'Failed to save file on D:\\.'
            ];
        }
        
        $filesize = strlen($content);
        $mimeType = getMimeType($filename);
        
        // Insert into database, keeping legacy columns in sync when they still exist.
        $columns = $pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_COLUMN);
        $insertColumns = ['filename', 'filepath', 'desktop_filepath', 'size', 'mime_type'];
        $insertValues = [
            ':filename' => $filename,
            ':filepath' => $filepath,
            ':desktop_filepath' => $desktopFilepath,
            ':size' => $filesize,
            ':mime_type' => $mimeType
        ];

        if (in_array('name', $columns, true)) {
            $insertColumns[] = 'name';
            $insertValues[':name'] = $filename;
        }

        if (in_array('path', $columns, true)) {
            $insertColumns[] = 'path';
            $insertValues[':path'] = $filepath;
        }

        if (in_array('type', $columns, true)) {
            $insertColumns[] = 'type';
            $insertValues[':type'] = $extension ?: 'txt';
        }

        if (in_array('folder', $columns, true)) {
            $insertColumns[] = 'folder';
            $insertValues[':folder'] = $uploadsFolder;
        }

        $placeholders = array_map(function ($column) {
            return ':' . $column;
        }, $insertColumns);

        $stmt = $pdo->prepare("
            INSERT INTO files (" . implode(', ', $insertColumns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
        ");
        
        $stmt->execute($insertValues);
        
        $uploadsDirectoryName = $uploadsFolder ?: basename(rtrim(UPLOADS_PATH, DIRECTORY_SEPARATOR));
        $uploadsDirectoryPath = realpath($targetUploadsDir) ?: $targetUploadsDir;

        return [
            'success' => true,
            'message' => 'File created successfully.',
            'file' => [
                'id' => $pdo->lastInsertId(),
                'filename' => $filename,
                'size' => $filesize,
                'desktop_filepath' => $desktopFilepath,
                'drive_d_filepath' => $driveDFilepath,
                'uploads_folder' => $uploadsDirectoryName,
                'uploads_path' => $uploadsDirectoryPath
            ]
        ];
        
    } catch (Exception $e) {
        error_log("File create error: " . $e->getMessage());
        
        // Clean up file if database insert failed
        if (file_exists($filepath)) {
            unlink($filepath);
        }

        removeDesktopFile($filename);
        removeDriveDFile($filename, $uploadsFolder);
        
        return [
            'success' => false,
            'message' => 'Failed to create file: ' . $e->getMessage()
        ];
    }
}

/**
 * Sanitize filename to prevent directory traversal and invalid characters
 */
function sanitizeFilename($filename) {
    // Remove any directory components
    $filename = basename($filename);
    
    // Remove any characters that aren't alphanumeric, underscore, hyphen, or dot
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);
    
    // Ensure filename is not empty and not just dots
    if (empty($filename) || preg_match('/^\.+$/', $filename)) {
        return null;
    }
    
    // Limit filename length
    if (strlen($filename) > 200) {
        $filename = substr($filename, 0, 200);
    }
    
    return $filename;
}

/**
 * Get MIME type based on file extension
 */
function getMimeType($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $mimeTypes = [
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'json' => 'application/json',
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'xml' => 'application/xml',
        'csv' => 'text/csv',
        'log' => 'text/plain',
        'php' => 'application/x-httpd-php',
        'py' => 'text/x-python'
    ];
    
    return isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'text/plain';
}
?>
