<?php
/**
 * File Delete Action
 * 
 * Deletes a file from the system.
 */

require_once __DIR__ . '/../config/db.php';

function deleteFile($input) {
    // Validate input
    if (empty($input['filename'])) {
        return [
            'success' => false,
            'message' => 'Filename is required.'
        ];
    }
    
    $filename = basename($input['filename']);
    
    $pdo = getDbConnection();
    
    if (!$pdo) {
        return [
            'success' => false,
            'message' => 'Database connection failed.'
        ];
    }
    
    // Get file info from database
    $stmt = $pdo->prepare("SELECT * FROM files WHERE filename = :filename");
    $stmt->execute([':filename' => $filename]);
    $file = $stmt->fetch();
    
    if (!$file) {
        return [
            'success' => false,
            'message' => 'File not found: ' . $filename
        ];
    }
    
    $result = deleteFileRecord($pdo, $file);
    if (!$result['success']) {
        return [
            'success' => false,
            'message' => $result['message']
        ];
    }

    return [
        'success' => true,
        'message' => 'File deleted successfully.',
        'deleted' => $filename,
        'warnings' => $result['warnings']
    ];
}

function deleteItems($input) {
    $rawFiles = isset($input['files']) && is_array($input['files']) ? $input['files'] : [];
    $rawFolders = isset($input['folders']) && is_array($input['folders']) ? $input['folders'] : [];

    $files = [];
    foreach ($rawFiles as $f) {
        $name = basename((string) $f);
        if ($name !== '') {
            $files[] = $name;
        }
    }
    $files = array_values(array_unique($files));

    $folders = [];
    foreach ($rawFolders as $folder) {
        $name = sanitizeUploadFolder((string) $folder);
        if ($name !== '') {
            $folders[] = $name;
        }
    }
    $folders = array_values(array_unique($folders));

    if (empty($files) && empty($folders)) {
        return [
            'success' => false,
            'message' => 'Provide at least one file or folder to delete.'
        ];
    }

    $pdo = getDbConnection();
    if (!$pdo) {
        return [
            'success' => false,
            'message' => 'Database connection failed.'
        ];
    }

    $deletedFiles = [];
    $deletedFolders = [];
    $warnings = [];
    $errors = [];

    foreach ($files as $filename) {
        $stmt = $pdo->prepare("SELECT * FROM files WHERE filename = :filename LIMIT 1");
        $stmt->execute([':filename' => $filename]);
        $file = $stmt->fetch();

        if (!$file) {
            $errors[] = 'File not found: ' . $filename;
            continue;
        }

        $result = deleteFileRecord($pdo, $file);
        if (!$result['success']) {
            $errors[] = $result['message'];
            continue;
        }

        $deletedFiles[] = $filename;
        $warnings = array_merge($warnings, $result['warnings']);
    }

    foreach ($folders as $folder) {
        $folderPath = rtrim(UPLOADS_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
        $driveDFolderPath = rtrim(DRIVE_D_FILES_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
        $uploadsFolderExisted = is_dir($folderPath);
        $driveFolderExisted = is_dir($driveDFolderPath);

        $stmt = $pdo->prepare("SELECT * FROM files WHERE folder = :folder");
        $stmt->execute([':folder' => $folder]);
        $folderFiles = $stmt->fetchAll();

        foreach ($folderFiles as $file) {
            $result = deleteFileRecord($pdo, $file);
            if ($result['success']) {
                $deletedFiles[] = $file['filename'];
                $warnings = array_merge($warnings, $result['warnings']);
            } else {
                $errors[] = $result['message'];
            }
        }

        $folderRemoved = true;
        $driveRemoved = true;

        if ($uploadsFolderExisted) {
            $uploadsDeleteErrors = [];
            $folderRemoved = removeDirectoryRecursive($folderPath, $uploadsDeleteErrors);
            if (!$folderRemoved) {
                $warnings[] = 'Could not fully remove uploads folder: ' . $folder . '. ' . implode(' | ', $uploadsDeleteErrors);
            }
        }

        if ($driveFolderExisted) {
            $driveDeleteErrors = [];
            $driveRemoved = removeDirectoryRecursive($driveDFolderPath, $driveDeleteErrors);
            if (!$driveRemoved) {
                $warnings[] = 'Could not fully remove D drive folder: ' . $folder . '. ' . implode(' | ', $driveDeleteErrors);
            }
        }

        if ($uploadsFolderExisted || $driveFolderExisted) {
            if ($folderRemoved || $driveRemoved) {
                $deletedFolders[] = $folder;
            }
        } else if (empty($folderFiles)) {
            $errors[] = 'Folder not found: ' . $folder;
        }
    }

    $deletedFiles = array_values(array_unique($deletedFiles));
    $deletedFolders = array_values(array_unique($deletedFolders));
    $warnings = array_values(array_unique(array_filter($warnings)));
    $errors = array_values(array_unique(array_filter($errors)));

    return [
        'success' => !empty($deletedFiles) || !empty($deletedFolders),
        'message' => (!empty($deletedFiles) || !empty($deletedFolders))
            ? 'Delete request processed.'
            : 'No items were deleted.',
        'deleted_files' => $deletedFiles,
        'deleted_folders' => $deletedFolders,
        'warnings' => $warnings,
        'errors' => $errors
    ];
}

function deleteFileRecord($pdo, $file) {
    $filename = $file['filename'] ?? '';
    $filepath = $file['filepath'] ?? (UPLOADS_PATH . $filename);
    $folder = $file['folder'] ?? '';

    if ($filename === '') {
        return [
            'success' => false,
            'message' => 'Invalid file record.',
            'warnings' => []
        ];
    }

    try {
        if (file_exists($filepath) && !unlink($filepath)) {
            return [
                'success' => false,
                'message' => 'Failed to delete file from disk: ' . $filename,
                'warnings' => []
            ];
        }

        $syncWarnings = [];
        if (!removeDesktopFile($filename)) {
            $syncWarnings[] = 'Desktop mirror copy could not be removed for ' . $filename . '.';
        }

        if (!removeDriveDFile($filename, $folder)) {
            $syncWarnings[] = 'D drive mirror copy could not be removed for ' . $filename . '.';
        }

        $stmt = $pdo->prepare("DELETE FROM files WHERE id = :id");
        $stmt->execute([':id' => $file['id']]);

        return [
            'success' => true,
            'message' => 'Deleted: ' . $filename,
            'warnings' => $syncWarnings
        ];
    } catch (Exception $e) {
        error_log("File delete error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to delete file ' . $filename . ': ' . $e->getMessage(),
            'warnings' => []
        ];
    }
}

function removeDirectoryRecursive($path, &$errors = []) {
    if (!file_exists($path)) {
        return true;
    }

    if (!is_dir($path)) {
        $errors[] = 'Not a directory: ' . $path;
        return false;
    }

    $items = scandir($path);
    if ($items === false) {
        $errors[] = 'Cannot read directory: ' . $path;
        return false;
    }

    $allDeleted = true;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            if (!removeDirectoryRecursive($child, $errors)) {
                $allDeleted = false;
            }
        } else {
            @chmod($child, 0666);
            if (!@unlink($child)) {
                $allDeleted = false;
                $errors[] = 'Cannot delete file: ' . $child;
            }
        }
    }

    @chmod($path, 0777);
    if (!@rmdir($path)) {
        $errors[] = 'Cannot remove directory: ' . $path;
        return false;
    }

    return $allDeleted;
}
?>
