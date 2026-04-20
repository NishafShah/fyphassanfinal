<?php
/**
 * Command History API
 * Retrieves and manages command history
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, DELETE, OPTIONS');
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

    $columns = $pdo->query("SHOW COLUMNS FROM command_history")->fetchAll(PDO::FETCH_COLUMN);
    $hasUserId = in_array('user_id', $columns, true);
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $action = isset($_GET['action']) ? trim($_GET['action']) : '';
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        
        $limit = min($limit, 100);
        
        $sql = "
            SELECT id, user_id, command_type, command_data, result, created_at
            FROM command_history
            WHERE 1=1
        ";
        $params = [];

        if (!isAdminUser() && $hasUserId) {
            $sql .= " AND user_id = :user_id";
            $params[':user_id'] = (int) $currentUser['id'];
        }
        
        if (!empty($action)) {
            $sql .= " AND command_type = :action";
            $params[':action'] = $action;
        }
        
        if (!empty($status)) {
            $sql .= " AND result = :status";
            $params[':status'] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $history = [];
        foreach ($rows as $row) {
            $commandData = [];
            if (!empty($row['command_data'])) {
                $decoded = json_decode((string) $row['command_data'], true);
                if (is_array($decoded)) {
                    $commandData = $decoded;
                }
            }

            $history[] = [
                'id' => (int) $row['id'],
                'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                'command' => $commandData,
                'action' => $row['command_type'],
                'status' => $row['result'],
                'result' => $row['result'],
                'created_at' => $row['created_at']
            ];
        }

        $countSql = "SELECT COUNT(*) FROM command_history WHERE 1=1";
        $countParams = [];
        if (!isAdminUser() && $hasUserId) {
            $countSql .= " AND user_id = :user_id";
            $countParams[':user_id'] = (int) $currentUser['id'];
        }
        if (!empty($action)) {
            $countSql .= " AND command_type = :action";
            $countParams[':action'] = $action;
        }
        if (!empty($status)) {
            $countSql .= " AND result = :status";
            $countParams[':status'] = $status;
        }
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int) $countStmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'history' => $history,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'viewer' => [
                    'id' => (int) $currentUser['id'],
                    'email' => (string) ($currentUser['email'] ?? ''),
                    'is_admin' => !empty($currentUser['is_admin'])
                ]
            ]
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($id > 0) {
            $sql = "DELETE FROM command_history WHERE id = :id";
            $params = [':id' => $id];
            if (!isAdminUser() && $hasUserId) {
                $sql .= " AND user_id = :user_id";
                $params[':user_id'] = (int) $currentUser['id'];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            
            echo json_encode([
                'success' => true,
                'message' => $affected > 0 ? 'Entry deleted' : 'Entry not found'
            ]);
        } else {
            if (isAdminUser()) {
                $pdo->exec("TRUNCATE TABLE command_history");
            } elseif ($hasUserId) {
                $stmt = $pdo->prepare("DELETE FROM command_history WHERE user_id = :user_id");
                $stmt->execute([':user_id' => (int) $currentUser['id']]);
            } else {
                throw new Exception('Cannot clear user-specific history on this schema.');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'History cleared'
            ]);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
