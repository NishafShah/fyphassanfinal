<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/auth.php';

initDatabase();
requireAdminUser();

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]);
    exit();
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
$limit = max(10, min($limit, 1000));
$userSearch = trim((string) ($_GET['user_search'] ?? ''));
$emailSearch = trim((string) ($_GET['email_search'] ?? ''));

try {
    $summarySql = "
        SELECT
            u.id AS user_id,
            u.name,
            u.email,
            u.is_admin,
            COALESCE(es.sent_count, 0) AS sent_count,
            COALESCE(es.failed_count, 0) AS failed_count,
            COALESCE(es.total_count, 0) AS total_count,
            es.last_email_at,
            COALESCE(fs.uploaded_count, 0) AS uploaded_count,
            COALESCE(fs.created_count, 0) AS created_count,
            COALESCE(fs.files_total, 0) AS files_total
        FROM users u
        LEFT JOIN (
            SELECT
                user_id,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                COUNT(*) AS total_count,
                MAX(created_at) AS last_email_at
            FROM emails
            GROUP BY user_id
        ) es ON es.user_id = u.id
        LEFT JOIN (
            SELECT
                user_id,
                SUM(CASE WHEN created_via = 'uploaded' THEN 1 ELSE 0 END) AS uploaded_count,
                SUM(CASE WHEN created_via = 'created' THEN 1 ELSE 0 END) AS created_count,
                COUNT(*) AS files_total
            FROM files
            GROUP BY user_id
        ) fs ON fs.user_id = u.id
    ";
    $summaryParams = [];
    if ($userSearch !== '') {
        $summarySql .= " WHERE (u.name LIKE :user_search_name OR u.email LIKE :user_search_email)";
        $summaryParams[':user_search_name'] = '%' . $userSearch . '%';
        $summaryParams[':user_search_email'] = '%' . $userSearch . '%';
    }
    $summarySql .= " ORDER BY total_count DESC, u.name ASC";
    $summaryStmt = $pdo->prepare($summarySql);
    foreach ($summaryParams as $k => $v) {
        $summaryStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $summaryStmt->execute();

    $users = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    $logsSql = "
        SELECT
            e.id,
            e.user_id,
            COALESCE(u.name, 'Unknown') AS user_name,
            COALESCE(u.email, '') AS user_email,
            e.sender,
            e.recipient,
            e.subject,
            e.status,
            e.created_at
        FROM emails e
        LEFT JOIN users u ON u.id = e.user_id
    ";

    $whereParts = [];
    if ($userSearch !== '') {
        $whereParts[] = "(u.name LIKE :logs_user_search_name OR u.email LIKE :logs_user_search_email)";
    }
    if ($emailSearch !== '') {
        $whereParts[] = "(e.sender LIKE :email_search_sender OR e.recipient LIKE :email_search_recipient OR e.subject LIKE :email_search_subject)";
    }
    if (!empty($whereParts)) {
        $logsSql .= " WHERE " . implode(' AND ', $whereParts);
    }
    $logsSql .= " ORDER BY e.created_at DESC LIMIT :limit";

    $logsStmt = $pdo->prepare($logsSql);
    if ($userSearch !== '') {
        $logsStmt->bindValue(':logs_user_search_name', '%' . $userSearch . '%', PDO::PARAM_STR);
        $logsStmt->bindValue(':logs_user_search_email', '%' . $userSearch . '%', PDO::PARAM_STR);
    }
    if ($emailSearch !== '') {
        $logsStmt->bindValue(':email_search_sender', '%' . $emailSearch . '%', PDO::PARAM_STR);
        $logsStmt->bindValue(':email_search_recipient', '%' . $emailSearch . '%', PDO::PARAM_STR);
        $logsStmt->bindValue(':email_search_subject', '%' . $emailSearch . '%', PDO::PARAM_STR);
    }
    $logsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $logsStmt->execute();
    $emails = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    $filesSql = "
        SELECT
            f.id,
            f.filename,
            f.created_via,
            f.size,
            f.folder,
            f.created_at,
            f.updated_at,
            COALESCE(u.name, 'Unknown') AS user_name,
            COALESCE(u.email, '') AS user_email
        FROM files f
        LEFT JOIN users u ON u.id = f.user_id
    ";
    $filesWhere = [];
    if ($userSearch !== '') {
        $filesWhere[] = "(u.name LIKE :files_user_search_name OR u.email LIKE :files_user_search_email)";
    }
    if (!empty($filesWhere)) {
        $filesSql .= " WHERE " . implode(' AND ', $filesWhere);
    }
    $filesSql .= " ORDER BY f.created_at DESC LIMIT :limit";

    $filesStmt = $pdo->prepare($filesSql);
    if ($userSearch !== '') {
        $filesStmt->bindValue(':files_user_search_name', '%' . $userSearch . '%', PDO::PARAM_STR);
        $filesStmt->bindValue(':files_user_search_email', '%' . $userSearch . '%', PDO::PARAM_STR);
    }
    $filesStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $filesStmt->execute();
    $files = $filesStmt->fetchAll(PDO::FETCH_ASSOC);
    $uploadedFiles = array_values(array_filter($files, function ($item) {
        return ($item['created_via'] ?? '') === 'uploaded';
    }));
    $createdFiles = array_values(array_filter($files, function ($item) {
        return ($item['created_via'] ?? 'created') !== 'uploaded';
    }));

    $totalsSql = "
        SELECT
            COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS sent_total,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_total,
            COALESCE(COUNT(*), 0) AS all_total
        FROM emails
    ";
    $totalsStmt = $pdo->query($totalsSql);
    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

    $filesTotalsSql = "
        SELECT
            COALESCE(SUM(CASE WHEN created_via = 'uploaded' THEN 1 ELSE 0 END), 0) AS uploaded_total,
            COALESCE(SUM(CASE WHEN created_via = 'created' THEN 1 ELSE 0 END), 0) AS created_total,
            COALESCE(COUNT(*), 0) AS files_total
        FROM files
    ";
    $filesTotalsStmt = $pdo->query($filesTotalsSql);
    $filesTotals = $filesTotalsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'sent_total' => (int) ($totals['sent_total'] ?? 0),
            'failed_total' => (int) ($totals['failed_total'] ?? 0),
            'all_total' => (int) ($totals['all_total'] ?? 0),
            'uploaded_total' => (int) ($filesTotals['uploaded_total'] ?? 0),
            'created_total' => (int) ($filesTotals['created_total'] ?? 0),
            'files_total' => (int) ($filesTotals['files_total'] ?? 0),
            'users_total' => count($users)
        ],
        'users' => $users,
        'emails' => $emails,
        'files' => $files,
        'uploaded_files' => $uploadedFiles,
        'created_files' => $createdFiles
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch admin email analytics: ' . $e->getMessage()
    ]);
}
