<?php
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

    $columns = $pdo->query("SHOW COLUMNS FROM emails")->fetchAll(PDO::FETCH_COLUMN);
    $hasUserId = in_array('user_id', $columns, true);
    $hasCategory = in_array('category', $columns, true);
    $hasAttachmentCount = in_array('attachment_count', $columns, true);
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $limit = max(1, min($limit, 100));

    $summarySql = "
        SELECT
            COUNT(*) AS total_emails,
            COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS sent_count,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count,
            MAX(created_at) AS last_email_at
        FROM emails
        WHERE 1=1
    ";
    $summaryParams = [];

    if ($hasUserId) {
        $summarySql .= " AND user_id = :user_id";
        $summaryParams[':user_id'] = (int) $currentUser['id'];
    } else {
        $summarySql .= " AND 1=0";
    }

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($summaryParams);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_emails' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
        'last_email_at' => null
    ];

    $historySql = "
        SELECT id, sender, recipient, subject, message, status, created_at
        FROM emails
        WHERE 1=1
    ";
    if ($hasCategory) {
        $historySql = "
            SELECT id, sender, recipient, subject, message, category, status, created_at
            FROM emails
            WHERE 1=1
        ";
    }
    if ($hasCategory && $hasAttachmentCount) {
        $historySql = "
            SELECT id, sender, recipient, subject, message, category, attachment_count, status, created_at
            FROM emails
            WHERE 1=1
        ";
    }
    $historyParams = [];

    if ($hasUserId) {
        $historySql .= " AND user_id = :user_id";
        $historyParams[':user_id'] = (int) $currentUser['id'];
    } else {
        $historySql .= " AND 1=0";
    }

    $historySql .= " ORDER BY created_at DESC LIMIT :limit";

    $historyStmt = $pdo->prepare($historySql);
    foreach ($historyParams as $key => $value) {
        $historyStmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $historyStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $historyStmt->execute();
    $emails = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($emails as &$email) {
        $email['category'] = normalizeEmailCategory(
            $email['category'] ?? null,
            $email['subject'] ?? '',
            $email['message'] ?? '',
            (int) ($email['attachment_count'] ?? 0)
        );
        unset($email['message']);
    }
    unset($email);

    $categorySql = "
        SELECT subject, message
        FROM emails
        WHERE 1=1
    ";
    if ($hasCategory && $hasAttachmentCount) {
        $categorySql = "
            SELECT subject, message, category, attachment_count
            FROM emails
            WHERE 1=1
        ";
    } elseif ($hasCategory) {
        $categorySql = "
            SELECT subject, message, category
            FROM emails
            WHERE 1=1
        ";
    }

    $categoryParams = [];
    if ($hasUserId) {
        $categorySql .= " AND user_id = :user_id";
        $categoryParams[':user_id'] = (int) $currentUser['id'];
    } else {
        $categorySql .= " AND 1=0";
    }

    $categoryStmt = $pdo->prepare($categorySql);
    foreach ($categoryParams as $key => $value) {
        $categoryStmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $categoryStmt->execute();

    $categoryCounts = [];
    foreach ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) as $emailRow) {
        $category = normalizeEmailCategory(
            $emailRow['category'] ?? null,
            $emailRow['subject'] ?? '',
            $emailRow['message'] ?? '',
            (int) ($emailRow['attachment_count'] ?? 0)
        );
        if (!isset($categoryCounts[$category])) {
            $categoryCounts[$category] = 0;
        }
        $categoryCounts[$category]++;
    }

    arsort($categoryCounts);

    $categorySummary = [];
    foreach ($categoryCounts as $name => $count) {
        $categorySummary[] = [
            'name' => $name,
            'label' => formatCategoryLabel($name),
            'count' => (int) $count
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'summary' => [
                'total' => (int) ($summary['total_emails'] ?? 0),
                'sent' => (int) ($summary['sent_count'] ?? 0),
                'failed' => (int) ($summary['failed_count'] ?? 0),
                'last_email_at' => $summary['last_email_at'] ?? null
            ],
            'category_summary' => $categorySummary,
            'emails' => $emails,
            'viewer' => [
                'id' => (int) $currentUser['id'],
                'email' => (string) ($currentUser['email'] ?? '')
            ]
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load email history: ' . $e->getMessage()
    ]);
}

function normalizeEmailCategory($storedCategory, $subject, $message, $attachmentCount) {
    $storedCategory = trim((string) $storedCategory);
    if ($storedCategory !== '') {
        return strtolower($storedCategory);
    }

    if ($attachmentCount > 0) {
        return 'file-share';
    }

    $haystack = strtolower(trim($subject . ' ' . $message));
    $keywordMap = [
        'notification' => ['alert', 'notice', 'notification', 'notify', 'announcement', 'update', 'reminder'],
        'request' => ['request', 'please', 'help', 'need', 'approve', 'approval', 'urgent'],
        'follow-up' => ['follow up', 'follow-up', 'checking in', 're:', 'reply'],
        'work' => ['meeting', 'project', 'task', 'report', 'client', 'invoice', 'deadline']
    ];

    foreach ($keywordMap as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && strpos($haystack, $keyword) !== false) {
                return $category;
            }
        }
    }

    return 'general';
}

function formatCategoryLabel($category) {
    $parts = preg_split('/[-_\\s]+/', strtolower((string) $category));
    $parts = array_filter($parts, function ($part) {
        return $part !== '';
    });

    if (empty($parts)) {
        return 'General';
    }

    return implode(' ', array_map('ucfirst', $parts));
}
