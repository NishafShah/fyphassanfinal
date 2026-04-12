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

        if (!removeDesktopFile($filename)) {
            return [
                'success' => false,
                'message' => 'File deleted from uploads but could not be removed from the desktop.'
            ];
        }

        if (!removeDriveDFile($filename, $folder)) {
            return [
                'success' => false,
                'message' => 'File deleted from uploads but could not be removed from D:\\.'
            ];
        }
        
        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM files WHERE filename = :filename");
        $stmt->execute([':filename' => $filename]);
        
        return [
            'success' => true,
            'message' => 'File deleted successfully.',
            'deleted' => $filename
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
