<?php
/**
 * File Edit Action
 * 
 * Updates the content of an existing file.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/auth.php';

function editFile($input) {
    // Validate input
    if (empty($input['filename'])) {
        return [
            'success' => false,
            'message' => 'Filename is required.'
        ];
    }
    
    $filename = basename($input['filename']);
    $content = isset($input['content']) ? $input['content'] : '';
    $append = isset($input['append']) && $input['append'] === true;
    
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
            'message' => 'File not found: ' . $filename . '. Use create to make a new file.'
        ];
    }

    if (!enforceFileOwnership($file)) {
        return [
            'success' => false,
            'message' => 'You do not have permission to edit this file.'
        ];
    }
    
    $filepath = $file['filepath'] ?? (UPLOADS_PATH . $filename);
    $desktopFilepath = getDesktopFilePath($filename);
    $folder = $file['folder'] ?? '';
    $driveDFilepath = getDriveDFilePath($filename, $folder);
    $syncWarnings = [];
    
    // Check if file exists on disk
    if (!file_exists($filepath)) {
        return [
            'success' => false,
            'message' => 'File not found on disk.'
        ];
    }
    
    try {
        // If append mode, add to existing content
        if ($append) {
            $existingContent = file_get_contents($filepath);
            $content = $existingContent . "\n" . $content;
        }
        
        // Write new content
        if (file_put_contents($filepath, $content) === false) {
            return [
                'success' => false,
                'message' => 'Failed to write to file.'
            ];
        }

        if (!syncFileToDesktop($filename, $content)) {
            $syncWarnings[] = 'Desktop mirror copy could not be updated.';
        }

        if (!syncFileToDriveD($filename, $content, $folder)) {
            $syncWarnings[] = 'D drive mirror copy could not be updated.';
        }
        
        $newSize = strlen($content);
        
        // Update database
        $stmt = $pdo->prepare("
            UPDATE files 
            SET size = :size, desktop_filepath = :desktop_filepath, updated_at = CURRENT_TIMESTAMP
            WHERE filename = :filename
        ");
        
        $stmt->execute([
            ':size' => $newSize,
            ':desktop_filepath' => $desktopFilepath,
            ':filename' => $filename
        ]);
        
        return [
            'success' => true,
            'message' => 'File updated successfully.',
            'file' => [
                'filename' => $filename,
                'size' => $newSize,
                'user_id' => isset($file['user_id']) ? (int) $file['user_id'] : null,
                'desktop_filepath' => $desktopFilepath,
                'drive_d_filepath' => $driveDFilepath
            ],
            'warnings' => $syncWarnings
        ];
        
    } catch (Exception $e) {
        error_log("File edit error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to update file: ' . $e->getMessage()
        ];
    }
}
?>
