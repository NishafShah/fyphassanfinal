<?php
/**
 * File Search Action
 * 
 * Searches for files by filename.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/auth.php';

function searchFiles($input) {
    $query = isset($input['query']) ? trim($input['query']) : '';
    $currentUser = getAuthenticatedUser();
    
    $pdo = getDbConnection();
    
    if (!$pdo) {
        return [
            'success' => false,
            'message' => 'Database connection failed.'
        ];
    }
    
    try {
        if (empty($query)) {
            // Return all files if no query
            return listAllFiles();
        }

        $columns = $pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_COLUMN);
        $hasUserId = in_array('user_id', $columns, true);

        $sql = "
            SELECT id, filename, size, mime_type, folder, filepath, user_id, created_at, updated_at
            FROM files
            WHERE filename LIKE :query
        ";
        $params = [':query' => '%' . $query . '%'];

        if (!isAdminUser() && $hasUserId) {
            $sql .= " AND user_id = :user_id";
            $params[':user_id'] = (int) ($currentUser['id'] ?? 0);
        }

        $sql .= " ORDER BY created_at DESC";

        // Search by filename using LIKE
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $files = $stmt->fetchAll();
        
        return [
            'success' => true,
            'message' => count($files) . ' file(s) found.',
            'files' => $files,
            'query' => $query
        ];
        
    } catch (Exception $e) {
        error_log("File search error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Search failed: ' . $e->getMessage()
        ];
    }
}

function listAllFiles() {
    $pdo = getDbConnection();
    $currentUser = getAuthenticatedUser();
    
    if (!$pdo) {
        return [
            'success' => false,
            'message' => 'Database connection failed.'
        ];
    }
    
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_COLUMN);
        $hasUserId = in_array('user_id', $columns, true);

        $sql = "
            SELECT id, filename, size, mime_type, folder, filepath, user_id, created_at, updated_at
            FROM files
            WHERE 1=1
        ";
        $params = [];

        if (!isAdminUser() && $hasUserId) {
            $sql .= " AND user_id = :user_id";
            $params[':user_id'] = (int) ($currentUser['id'] ?? 0);
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $files = $stmt->fetchAll();
        
        // Verify files exist on disk and sync database
        $validFiles = [];
        $deletedFiles = [];
        
        foreach ($files as $file) {
            $filepath = $file['filepath'] ?? (UPLOADS_PATH . $file['filename']);
            if (file_exists($filepath)) {
                $validFiles[] = $file;
            } else {
                $deletedFiles[] = $file['filename'];
            }
        }
        
        // Clean up database entries for missing files
        if (!empty($deletedFiles)) {
            $placeholders = str_repeat('?,', count($deletedFiles) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM files WHERE filename IN ($placeholders)");
            $stmt->execute($deletedFiles);
        }
        
        return [
            'success' => true,
            'message' => count($validFiles) . ' file(s) found.',
            'files' => $validFiles
        ];
        
    } catch (Exception $e) {
        error_log("List files error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to list files: ' . $e->getMessage()
        ];
    }
}
?>
