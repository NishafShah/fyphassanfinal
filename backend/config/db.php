<?php
/**
 * Database Configuration
 * 
 * Configure your MySQL database connection settings here.
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'virtual_assistant');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Files storage path (relative to backend directory)
define('UPLOADS_PATH', __DIR__ . '/../../uploads/');
define('DESKTOP_FILES_PATH', rtrim((getenv('USERPROFILE') ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'Desktop', '\\/') . DIRECTORY_SEPARATOR);
define('DRIVE_D_FILES_PATH', 'D:\\');

// Email configuration (SMTP Settings)
define('EMAIL_HOST', 'smtp.gmail.com');
define('EMAIL_PORT', 587);
define('EMAIL_USE_TLS', true);
define('EMAIL_USERNAME', 'ssyedmuhammadhassanshah@gmail.com');
define('EMAIL_PASSWORD', 'qtos rowy fibo zpmb');
define('EMAIL_FROM', 'shahmurrawat@gmail.com');
define('EMAIL_FROM_NAME', 'Virtual Assistant');

/**
 * Sanitize folder names used in uploads/mirrors.
 */
function sanitizeUploadFolder($folder) {
    if (empty($folder)) {
        return '';
    }

    $folder = trim($folder);
    $folder = preg_replace('/[\\/]+/', '', $folder);
    $folder = preg_replace('/[^a-zA-Z0-9_-]/', '', $folder);

    if (strlen($folder) > 100) {
        $folder = substr($folder, 0, 100);
    }

    return $folder;
}

// Create PDO connection
function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    return $pdo;
}

// Initialize database and create tables if they don't exist
function initDatabase() {
    $pdo = getDbConnection();
    
    if (!$pdo) {
        return false;
    }
    
    try {
        // Create files table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                filepath VARCHAR(500) NOT NULL,
                desktop_filepath VARCHAR(500) DEFAULT NULL,
                user_id INT DEFAULT NULL,
                created_via VARCHAR(20) DEFAULT 'created',
                size INT DEFAULT 0,
                mime_type VARCHAR(100) DEFAULT 'text/plain',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_files_user_id (user_id),
                INDEX idx_files_created_via (created_via),
                INDEX idx_files_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        ensureFilesTableSchema($pdo);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                last_login_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        ensureUsersTableSchema($pdo);
        
        // Create emails table for logging sent emails
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS emails (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                sender VARCHAR(255) DEFAULT NULL,
                recipient VARCHAR(255) NOT NULL,
                subject VARCHAR(500) NOT NULL,
                message TEXT,
                token VARCHAR(255) DEFAULT NULL,
                status ENUM('sent', 'failed') DEFAULT 'sent',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_emails_user_id (user_id),
                INDEX idx_emails_status (status),
                INDEX idx_emails_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        ensureEmailsTableSchema($pdo);
        
        // Create command_history table for logging commands
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS command_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                command_type VARCHAR(50) NOT NULL,
                command_data JSON,
                result VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_command_history_user_id (user_id),
                INDEX idx_command_history_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        ensureCommandHistoryTableSchema($pdo);
        
        // Create contacts table for contact form submissions
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS contacts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                subject VARCHAR(500) NOT NULL,
                message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Create uploads directory if it doesn't exist
        if (!file_exists(UPLOADS_PATH)) {
            mkdir(UPLOADS_PATH, 0755, true);
        }

        if (!file_exists(DESKTOP_FILES_PATH)) {
            @mkdir(DESKTOP_FILES_PATH, 0755, true);
        }

        if (!file_exists(DRIVE_D_FILES_PATH)) {
            @mkdir(DRIVE_D_FILES_PATH, 0755, true);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Database initialization failed: " . $e->getMessage());
        return false;
    }
}

function ensureFilesTableSchema($pdo) {
    $columns = $pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('filename', $columns, true)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN filename VARCHAR(255) NULL");
        if (in_array('name', $columns, true)) {
            $pdo->exec("UPDATE files SET filename = name WHERE filename IS NULL OR filename = ''");
        }
    }

    if (!in_array('filepath', $columns, true)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN filepath VARCHAR(500) NULL");
        if (in_array('path', $columns, true)) {
            $pdo->exec("UPDATE files SET filepath = path WHERE filepath IS NULL OR filepath = ''");
        }
    }

    if (!in_array('mime_type', $columns, true)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN mime_type VARCHAR(100) DEFAULT 'text/plain'");
        if (in_array('type', $columns, true)) {
            $pdo->exec("
                UPDATE files
                SET mime_type = CASE LOWER(type)
                    WHEN 'txt' THEN 'text/plain'
                    WHEN 'md' THEN 'text/markdown'
                    WHEN 'json' THEN 'application/json'
                    WHEN 'html' THEN 'text/html'
                    WHEN 'css' THEN 'text/css'
                    WHEN 'js' THEN 'application/javascript'
                    WHEN 'xml' THEN 'application/xml'
                    WHEN 'csv' THEN 'text/csv'
                    WHEN 'log' THEN 'text/plain'
                    ELSE 'text/plain'
                END
            ");
        }
    }

    if (!in_array('desktop_filepath', $columns, true)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN desktop_filepath VARCHAR(500) DEFAULT NULL");
    }

    if (!in_array('user_id', $columns, true)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN user_id INT DEFAULT NULL AFTER desktop_filepath");
    }

    if (!in_array('created_via', $columns, true)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN created_via VARCHAR(20) DEFAULT 'created' AFTER user_id");
    }

    if (in_array('name', $columns, true)) {
        $pdo->exec("UPDATE files SET name = filename WHERE filename IS NOT NULL AND (name IS NULL OR name = '')");
        $pdo->exec("ALTER TABLE files MODIFY name VARCHAR(255) NULL");
    }

    if (in_array('path', $columns, true)) {
        $pdo->exec("UPDATE files SET path = filepath WHERE filepath IS NOT NULL AND (path IS NULL OR path = '')");
        $pdo->exec("ALTER TABLE files MODIFY path VARCHAR(500) NULL");
    }

    if (in_array('type', $columns, true)) {
        $pdo->exec("
            UPDATE files
            SET type = CASE
                WHEN filename IS NOT NULL AND filename <> '' THEN LOWER(SUBSTRING_INDEX(filename, '.', -1))
                ELSE type
            END
            WHERE type IS NULL OR type = ''
        ");
        $pdo->exec("ALTER TABLE files MODIFY type VARCHAR(50) NULL DEFAULT 'txt'");
    }

    if (!in_array('folder', $columns, true)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN folder VARCHAR(255) DEFAULT ''");
    }

    $pdo->exec("UPDATE files SET filepath = CONCAT('" . addslashes(UPLOADS_PATH) . "', filename) WHERE filename IS NOT NULL AND (filepath IS NULL OR filepath = '')");
    $pdo->exec("UPDATE files SET desktop_filepath = CONCAT('" . addslashes(DESKTOP_FILES_PATH) . "', filename) WHERE filename IS NOT NULL AND (desktop_filepath IS NULL OR desktop_filepath = '')");
    $pdo->exec("ALTER TABLE files MODIFY filename VARCHAR(255) NOT NULL");
    $pdo->exec("ALTER TABLE files MODIFY filepath VARCHAR(500) NOT NULL");

    $indexes = $pdo->query("SHOW INDEX FROM files")->fetchAll(PDO::FETCH_ASSOC);
    $hasFilenameUnique = false;
    foreach ($indexes as $index) {
        if (($index['Column_name'] ?? '') === 'filename' && (int) ($index['Non_unique'] ?? 1) === 0) {
            $hasFilenameUnique = true;
            break;
        }
    }

    if (!$hasFilenameUnique) {
        $pdo->exec("ALTER TABLE files ADD UNIQUE KEY unique_filename (filename)");
    }

    $hasUserIdIndex = false;
    foreach ($indexes as $index) {
        if (($index['Column_name'] ?? '') === 'user_id') {
            $hasUserIdIndex = true;
            break;
        }
    }

    if (!$hasUserIdIndex) {
        $pdo->exec("ALTER TABLE files ADD INDEX idx_files_user_id (user_id)");
    }
}

function ensureUsersTableSchema($pdo) {
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('name', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '' AFTER id");
    }

    if (!in_array('email', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT '' AFTER name");
    }

    if (!in_array('password_hash', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER email");
    }

    if (!in_array('last_login_at', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER password_hash");
    }

    if (!in_array('is_admin', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash");
    }

    $indexes = $pdo->query("SHOW INDEX FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $hasEmailUnique = false;
    foreach ($indexes as $index) {
        if (($index['Column_name'] ?? '') === 'email' && (int) ($index['Non_unique'] ?? 1) === 0) {
            $hasEmailUnique = true;
            break;
        }
    }

    if (!$hasEmailUnique) {
        $pdo->exec("ALTER TABLE users ADD UNIQUE KEY unique_user_email (email)");
    }
}

function ensureEmailsTableSchema($pdo) {
    $columns = $pdo->query("SHOW COLUMNS FROM emails")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('user_id', $columns, true)) {
        $pdo->exec("ALTER TABLE emails ADD COLUMN user_id INT DEFAULT NULL AFTER id");
    }

    if (!in_array('sender', $columns, true)) {
        $pdo->exec("ALTER TABLE emails ADD COLUMN sender VARCHAR(255) DEFAULT NULL AFTER id");
    }

    if (!in_array('token', $columns, true)) {
        $pdo->exec("ALTER TABLE emails ADD COLUMN token VARCHAR(255) DEFAULT NULL AFTER message");
    }

    $indexes = $pdo->query("SHOW INDEX FROM emails")->fetchAll(PDO::FETCH_ASSOC);
    $hasUserIdIndex = false;
    foreach ($indexes as $index) {
        if (($index['Column_name'] ?? '') === 'user_id') {
            $hasUserIdIndex = true;
            break;
        }
    }

    if (!$hasUserIdIndex) {
        $pdo->exec("ALTER TABLE emails ADD INDEX idx_emails_user_id (user_id)");
    }
}

function ensureCommandHistoryTableSchema($pdo) {
    $columns = $pdo->query("SHOW COLUMNS FROM command_history")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('user_id', $columns, true)) {
        $pdo->exec("ALTER TABLE command_history ADD COLUMN user_id INT DEFAULT NULL AFTER id");
    }

    $indexes = $pdo->query("SHOW INDEX FROM command_history")->fetchAll(PDO::FETCH_ASSOC);
    $hasUserIdIndex = false;
    foreach ($indexes as $index) {
        if (($index['Column_name'] ?? '') === 'user_id') {
            $hasUserIdIndex = true;
            break;
        }
    }

    if (!$hasUserIdIndex) {
        $pdo->exec("ALTER TABLE command_history ADD INDEX idx_command_history_user_id (user_id)");
    }
}

function getDesktopFilePath($filename) {
    return DESKTOP_FILES_PATH . $filename;
}

function getDriveDFilePath($filename, $folder = '') {
    $base = DRIVE_D_FILES_PATH;
    $path = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if ($folder) {
        $path .= trim(sanitizeUploadFolder($folder), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    return $path . $filename;
}

function syncFileToDesktop($filename, $content) {
    $desktopPath = getDesktopFilePath($filename);
    $desktopDir = dirname($desktopPath);

    if (!file_exists($desktopDir) && !@mkdir($desktopDir, 0755, true)) {
        return false;
    }

    return file_put_contents($desktopPath, $content) !== false;
}

function syncFileToDriveD($filename, $content, $folder = '') {
    $driveDPath = getDriveDFilePath($filename, $folder);
    $driveDDir = dirname($driveDPath);

    if (!file_exists($driveDDir) && !@mkdir($driveDDir, 0755, true)) {
        return false;
    }

    return file_put_contents($driveDPath, $content) !== false;
}

function removeDesktopFile($filename) {
    $desktopPath = getDesktopFilePath($filename);
    return !file_exists($desktopPath) || @unlink($desktopPath);
}

function removeDriveDFile($filename, $folder = '') {
    $driveDPath = getDriveDFilePath($filename, $folder);
    return !file_exists($driveDPath) || @unlink($driveDPath);
}

// Log command to history
function logCommand($type, $data, $result) {
    $pdo = getDbConnection();
    
    if (!$pdo) {
        return false;
    }
    
    try {
        $currentUser = function_exists('getAuthenticatedUser') ? getAuthenticatedUser() : null;
        $stmt = $pdo->prepare("
            INSERT INTO command_history (user_id, command_type, command_data, result)
            VALUES (:user_id, :type, :data, :result)
        ");
        
        $stmt->execute([
            ':user_id' => $currentUser['id'] ?? null,
            ':type' => $type,
            ':data' => json_encode($data),
            ':result' => $result
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Failed to log command: " . $e->getMessage());
        return false;
    }
}
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("DB connection fail: " . $e->getMessage());
            $this->connection = null;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}

?>
