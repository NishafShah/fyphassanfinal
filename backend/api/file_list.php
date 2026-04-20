<?php
/**
 * File List API
 * Lists files from the database with optional filtering.
 * Regular users can only see their own files. Admins can see all uploads.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/db.php';
require_once '../helpers/auth.php';
requireAuthenticatedUser();
initDatabase();

try {
    $pdo = getDbConnection();
    $currentUser = getAuthenticatedUser();

    if (!$pdo || !$currentUser) {
        throw new Exception('Database connection failed.');
    }
    
    // Get filter parameters
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'created_at';
    $order = isset($_GET['order']) ? strtoupper(trim($_GET['order'])) : 'DESC';
    
    // Validate sort column
    $allowedSorts = ['name', 'type', 'size', 'created_at', 'updated_at'];
    if (!in_array($sort, $allowedSorts)) {
        $sort = 'created_at';
    }
    
    // Validate order
    if (!in_array($order, ['ASC', 'DESC'])) {
        $order = 'DESC';
    }

    $columns = $pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_COLUMN);
    $hasUserId = in_array('user_id', $columns, true);
    $hasFilename = in_array('filename', $columns, true);
    $hasFilepath = in_array('filepath', $columns, true);
    $hasMimeType = in_array('mime_type', $columns, true);
    $hasCreatedVia = in_array('created_via', $columns, true);
    $hasFolder = in_array('folder', $columns, true);
    $hasContent = in_array('content', $columns, true);
    $hasName = in_array('name', $columns, true);
    $hasPath = in_array('path', $columns, true);

    $selectName = $hasFilename ? 'filename' : ($hasName ? 'name' : "''");
    $selectPath = $hasFilepath ? 'filepath' : ($hasPath ? 'path' : "''");
    $selectType = $hasMimeType ? 'mime_type' : (in_array('type', $columns, true) ? 'type' : "''");
    $selectCreatedVia = $hasCreatedVia ? 'created_via' : "'created'";
    $selectFolder = $hasFolder ? 'folder' : "''";
    $selectContent = $hasContent ? 'content' : "NULL";
    $sortMap = [
        'name' => $selectName,
        'type' => $selectType,
        'size' => 'size',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at'
    ];
    $sortColumn = $sortMap[$sort] ?? 'created_at';

    $sql = "
        SELECT
            id,
            {$selectName} AS name,
            {$selectType} AS type,
            {$selectContent} AS content,
            size,
            {$selectPath} AS path,
            {$selectCreatedVia} AS created_via,
            {$selectFolder} AS folder,
            " . ($hasUserId ? 'user_id' : 'NULL') . " AS user_id,
            created_at,
            updated_at
        FROM files
        WHERE 1=1
    ";

    $params = [];

    if (!isAdminUser() && $hasUserId) {
        $sql .= " AND user_id = :user_id";
        $params[':user_id'] = (int) $currentUser['id'];
    }

    if (!empty($search)) {
        $searchParam = '%' . $search . '%';
        $searchParts = ["{$selectName} LIKE :search_name"];
        if ($hasContent) {
            $searchParts[] = "{$selectContent} LIKE :search_content";
            $params[':search_content'] = $searchParam;
        }
        $sql .= " AND (" . implode(' OR ', $searchParts) . ")";
        $params[':search_name'] = $searchParam;
    }

    if (!empty($type)) {
        $sql .= " AND {$selectType} = :type";
        $params[':type'] = $type;
    }

    $sql .= " ORDER BY {$sortColumn} {$order}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $files = [];
    foreach ($rows as $row) {
        $files[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'content' => $row['content'],
            'size' => (int) $row['size'],
            'path' => $row['path'],
            'created_via' => $row['created_via'],
            'folder' => $row['folder'],
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    $statsSql = "
        SELECT
            COUNT(*) AS total_files,
            COALESCE(SUM(size), 0) AS total_size,
            COUNT(DISTINCT {$selectType}) AS unique_types
        FROM files
        WHERE 1=1
    ";
    $statsParams = [];

    if (!isAdminUser() && $hasUserId) {
        $statsSql .= " AND user_id = :user_id";
        $statsParams[':user_id'] = (int) $currentUser['id'];
    }

    if (!empty($search)) {
        $searchParam = '%' . $search . '%';
        $searchParts = ["{$selectName} LIKE :search_name"];
        if ($hasContent) {
            $searchParts[] = "{$selectContent} LIKE :search_content";
            $statsParams[':search_content'] = $searchParam;
        }
        $statsSql .= " AND (" . implode(' OR ', $searchParts) . ")";
        $statsParams[':search_name'] = $searchParam;
    }

    if (!empty($type)) {
        $statsSql .= " AND {$selectType} = :type";
        $statsParams[':type'] = $type;
    }

    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_files' => 0,
        'total_size' => 0,
        'unique_types' => 0
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'files' => $files,
            'stats' => [
                'total_files' => (int) $stats['total_files'],
                'total_size' => (int) $stats['total_size'],
                'unique_types' => (int) $stats['unique_types']
            ],
            'viewer' => [
                'id' => (int) $currentUser['id'],
                'email' => (string) ($currentUser['email'] ?? ''),
                'is_admin' => !empty($currentUser['is_admin'])
            ]
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch files: ' . $e->getMessage()
    ]);
}
