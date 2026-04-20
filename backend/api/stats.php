<?php
/**
 * Statistics API
 * Returns dashboard statistics
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

    $isAdmin = isAdminUser();
    $fileColumns = $pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_COLUMN);
    $commandColumns = $pdo->query("SHOW COLUMNS FROM command_history")->fetchAll(PDO::FETCH_COLUMN);
    $emailColumns = $pdo->query("SHOW COLUMNS FROM emails")->fetchAll(PDO::FETCH_COLUMN);

    $fileTypeColumn = in_array('mime_type', $fileColumns, true) ? 'mime_type' : (in_array('type', $fileColumns, true) ? 'type' : "''");
    $hasFileUserId = in_array('user_id', $fileColumns, true);
    $hasCommandUserId = in_array('user_id', $commandColumns, true);
    $hasEmailUserId = in_array('user_id', $emailColumns, true);

    $fileStatsSql = "
        SELECT
            COUNT(*) AS total_files,
            COALESCE(SUM(size), 0) AS total_size,
            COUNT(DISTINCT {$fileTypeColumn}) AS file_types
        FROM files
        WHERE 1=1
    ";
    $fileStatsParams = [];
    if (!$isAdmin && $hasFileUserId) {
        $fileStatsSql .= " AND user_id = :user_id";
        $fileStatsParams[':user_id'] = (int) $currentUser['id'];
    }
    $fileStatsStmt = $pdo->prepare($fileStatsSql);
    $fileStatsStmt->execute($fileStatsParams);
    $fileStats = $fileStatsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_files' => 0, 'total_size' => 0, 'file_types' => 0];

    $commandStatsSql = "
        SELECT
            COUNT(*) AS total_commands,
            COALESCE(SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END), 0) AS successful,
            COALESCE(SUM(CASE WHEN result IN ('error', 'failed') THEN 1 ELSE 0 END), 0) AS failed
        FROM command_history
        WHERE 1=1
    ";
    $commandStatsParams = [];
    if (!$isAdmin && $hasCommandUserId) {
        $commandStatsSql .= " AND user_id = :user_id";
        $commandStatsParams[':user_id'] = (int) $currentUser['id'];
    }
    $commandStatsStmt = $pdo->prepare($commandStatsSql);
    $commandStatsStmt->execute($commandStatsParams);
    $commandStats = $commandStatsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_commands' => 0, 'successful' => 0, 'failed' => 0];

    $emailStatsSql = "
        SELECT
            COUNT(*) AS total_emails,
            COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS sent,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed
        FROM emails
        WHERE 1=1
    ";
    $emailStatsParams = [];
    if (!$isAdmin && $hasEmailUserId) {
        $emailStatsSql .= " AND user_id = :user_id";
        $emailStatsParams[':user_id'] = (int) $currentUser['id'];
    }
    $emailStatsStmt = $pdo->prepare($emailStatsSql);
    $emailStatsStmt->execute($emailStatsParams);
    $emailStats = $emailStatsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_emails' => 0, 'sent' => 0, 'failed' => 0];

    $recentActivity = [];

    $recentFilesSql = "
        SELECT filename AS description, created_via AS status, created_at, 'file' AS type
        FROM files
        WHERE 1=1
    ";
    $recentFilesParams = [];
    if (!$isAdmin && $hasFileUserId) {
        $recentFilesSql .= " AND user_id = :user_id";
        $recentFilesParams[':user_id'] = (int) $currentUser['id'];
    }
    $recentFilesSql .= " ORDER BY created_at DESC LIMIT 5";
    $recentFilesStmt = $pdo->prepare($recentFilesSql);
    $recentFilesStmt->execute($recentFilesParams);
    foreach ($recentFilesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentActivity[] = $row;
    }

    $recentEmailsSql = "
        SELECT subject AS description, status, created_at, 'email' AS type
        FROM emails
        WHERE 1=1
    ";
    $recentEmailsParams = [];
    if (!$isAdmin && $hasEmailUserId) {
        $recentEmailsSql .= " AND user_id = :user_id";
        $recentEmailsParams[':user_id'] = (int) $currentUser['id'];
    }
    $recentEmailsSql .= " ORDER BY created_at DESC LIMIT 5";
    $recentEmailsStmt = $pdo->prepare($recentEmailsSql);
    $recentEmailsStmt->execute($recentEmailsParams);
    foreach ($recentEmailsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentActivity[] = $row;
    }

    $recentCommandsSql = "
        SELECT command_type AS description, result AS status, created_at, 'command' AS type
        FROM command_history
        WHERE 1=1
    ";
    $recentCommandsParams = [];
    if (!$isAdmin && $hasCommandUserId) {
        $recentCommandsSql .= " AND user_id = :user_id";
        $recentCommandsParams[':user_id'] = (int) $currentUser['id'];
    }
    $recentCommandsSql .= " ORDER BY created_at DESC LIMIT 5";
    $recentCommandsStmt = $pdo->prepare($recentCommandsSql);
    $recentCommandsStmt->execute($recentCommandsParams);
    foreach ($recentCommandsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentActivity[] = $row;
    }

    usort($recentActivity, function ($a, $b) {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });
    $recentActivity = array_slice($recentActivity, 0, 5);

    $typeDistributionSql = "
        SELECT {$fileTypeColumn} AS type, COUNT(*) AS count
        FROM files
        WHERE 1=1
    ";
    $typeDistributionParams = [];
    if (!$isAdmin && $hasFileUserId) {
        $typeDistributionSql .= " AND user_id = :user_id";
        $typeDistributionParams[':user_id'] = (int) $currentUser['id'];
    }
    $typeDistributionSql .= " GROUP BY {$fileTypeColumn} ORDER BY count DESC LIMIT 10";
    $typeDistributionStmt = $pdo->prepare($typeDistributionSql);
    $typeDistributionStmt->execute($typeDistributionParams);
    $typeDistribution = array_map(function ($row) {
        return [
            'type' => $row['type'],
            'count' => (int) $row['count']
        ];
    }, $typeDistributionStmt->fetchAll(PDO::FETCH_ASSOC));

    $actionDistributionSql = "
        SELECT command_type AS action, COUNT(*) AS count
        FROM command_history
        WHERE 1=1
    ";
    $actionDistributionParams = [];
    if (!$isAdmin && $hasCommandUserId) {
        $actionDistributionSql .= " AND user_id = :user_id";
        $actionDistributionParams[':user_id'] = (int) $currentUser['id'];
    }
    $actionDistributionSql .= " GROUP BY command_type ORDER BY count DESC";
    $actionDistributionStmt = $pdo->prepare($actionDistributionSql);
    $actionDistributionStmt->execute($actionDistributionParams);
    $actionDistribution = array_map(function ($row) {
        return [
            'action' => $row['action'],
            'count' => (int) $row['count']
        ];
    }, $actionDistributionStmt->fetchAll(PDO::FETCH_ASSOC));
    
    echo json_encode([
        'success' => true,
        'data' => [
            'files' => [
                'total' => (int) $fileStats['total_files'],
                'total_size' => (int) $fileStats['total_size'],
                'types' => (int) $fileStats['file_types']
            ],
            'commands' => [
                'total' => (int) $commandStats['total_commands'],
                'successful' => (int) $commandStats['successful'],
                'failed' => (int) $commandStats['failed']
            ],
            'emails' => [
                'total' => (int) $emailStats['total_emails'],
                'sent' => (int) $emailStats['sent'],
                'failed' => (int) $emailStats['failed']
            ],
            'recent_activity' => $recentActivity,
            'file_types' => $typeDistribution,
            'action_distribution' => $actionDistribution,
            'viewer' => [
                'id' => (int) $currentUser['id'],
                'email' => (string) ($currentUser['email'] ?? ''),
                'is_admin' => $isAdmin
            ]
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
