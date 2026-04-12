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
    
    $filepath = $file['filepath'] ?? (UPLOADS_PATH . $filename);
    $folder = $file['folder'] ?? '';
    
    try {
        // Delete from disk if exists
        if (file_exists($filepath)) {
            if (!unlink($filepath)) {
                return [
                    'success' => false,
                    'message' => 'Failed to delete file from disk.'
                ];
            }
        }

        $syncWarnings = [];
        if (!removeDesktopFile($filename)) {
            $syncWarnings[] = 'Desktop mirror copy could not be removed.';
        }

        if (!removeDriveDFile($filename, $folder)) {
            $syncWarnings[] = 'D drive mirror copy could not be removed.';
        }
        
        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM files WHERE filename = :filename");
        $stmt->execute([':filename' => $filename]);
        
        return [
            'success' => true,
            'message' => 'File deleted successfully.',
            'deleted' => $filename,
            'warnings' => $syncWarnings
        ];
        
    } catch (Exception $e) {
        error_log("File delete error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to delete file: ' . $e->getMessage()
        ];
    }
}
?>
