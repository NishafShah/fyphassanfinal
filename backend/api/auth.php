<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/auth.php';

initDatabase();
ensureSessionStarted();

$method = $_SERVER['REQUEST_METHOD'];
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = '';
if ($method === 'GET') {
    $action = strtolower(trim($_GET['action'] ?? 'me'));
} else {
    $action = strtolower(trim($input['action'] ?? ''));
}

switch ($action) {
    case 'register':
        handleRegister($input);
        break;
    case 'login':
        handleLogin($input);
        break;
    case 'logout':
        handleLogout();
        break;
    case 'me':
    default:
        handleMe();
        break;
}

function handleRegister($input) {
    $name = trim((string) ($input['name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Name, email, and password are required.'
        ]);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please enter a valid email.'
        ]);
        return;
    }

    if (strlen($password) < 6) {
        echo json_encode([
            'success' => false,
            'message' => 'Password must be at least 6 characters.'
        ]);
        return;
    }

    $pdo = getDbConnection();
    if (!$pdo) {
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed.'
        ]);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'An account with this email already exists.'
            ]);
            return;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare("
            INSERT INTO users (name, email, password_hash, is_admin)
            VALUES (:name, :email, :password_hash, 0)
        ");
        $insert->execute([
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => $hash
        ]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $pdo->lastInsertId();
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['is_admin'] = false;

        echo json_encode([
            'success' => true,
            'message' => 'Registration successful.',
            'user' => [
                'id' => (int) $_SESSION['user_id'],
                'name' => $name,
                'email' => $email,
                'is_admin' => false
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Registration failed: ' . $e->getMessage()
        ]);
    }
}

function handleLogin($input) {
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');

    if ($email === '' || $password === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Email and password are required.'
        ]);
        return;
    }

    $pdo = getDbConnection();
    if (!$pdo) {
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed.'
        ]);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, email, password_hash, is_admin
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid email or password.'
            ]);
            return;
        }

        $update = $pdo->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id");
        $update->execute([':id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_name'] = (string) $user['name'];
        $_SESSION['user_email'] = (string) $user['email'];
        $_SESSION['is_admin'] = (bool) ($user['is_admin'] ?? false);

        echo json_encode([
            'success' => true,
            'message' => 'Login successful.',
            'user' => [
                'id' => (int) $user['id'],
                'name' => (string) $user['name'],
                'email' => (string) $user['email'],
                'is_admin' => (bool) ($user['is_admin'] ?? false)
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Login failed: ' . $e->getMessage()
        ]);
    }
}

function handleLogout() {
    ensureSessionStarted();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully.'
    ]);
}

function handleMe() {
    $user = getAuthenticatedUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Not logged in.'
        ]);
        return;
    }

    echo json_encode([
        'success' => true,
        'user' => $user
    ]);
}
