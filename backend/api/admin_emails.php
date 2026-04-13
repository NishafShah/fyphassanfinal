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

try {
    $summaryStmt = $pdo->query("
        SELECT
            u.id AS user_id,
            u.name,
            u.email,
            u.is_admin,
            COALESCE(SUM(CASE WHEN e.status = 'sent' THEN 1 ELSE 0 END), 0) AS sent_count,
            COALESCE(SUM(CASE WHEN e.status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count,
            COALESCE(COUNT(e.id), 0) AS total_count,
            MAX(e.created_at) AS last_email_at
        FROM users u
        LEFT JOIN emails e ON e.user_id = u.id
        GROUP BY u.id, u.name, u.email, u.is_admin
        ORDER BY total_count DESC, u.name ASC
    ");

    $users = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    $logsStmt = $pdo->prepare("
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
        ORDER BY e.created_at DESC
        LIMIT :limit
    ");
    $logsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $logsStmt->execute();
    $emails = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalsStmt = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS sent_total,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_total,
            COALESCE(COUNT(*), 0) AS all_total
        FROM emails
    ");
    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'sent_total' => (int) ($totals['sent_total'] ?? 0),
            'failed_total' => (int) ($totals['failed_total'] ?? 0),
            'all_total' => (int) ($totals['all_total'] ?? 0),
            'users_total' => count($users)
        ],
        'users' => $users,
        'emails' => $emails
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch admin email analytics: ' . $e->getMessage()
    ]);
}

