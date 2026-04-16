-- Virtual Assistant Database Setup
-- Updated version with safe sample inserts

CREATE DATABASE IF NOT EXISTS virtual_assistant
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE virtual_assistant;

-- =========================================
-- Files table - stores file metadata and content
-- =========================================
CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    filepath VARCHAR(500) NOT NULL,
    user_id INT DEFAULT NULL,
    created_via VARCHAR(20) DEFAULT 'created',
    size INT DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT 'text/plain',
    folder VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_filename (filename),
    INDEX idx_created_at (created_at),
    INDEX idx_files_user_id (user_id),
    INDEX idx_files_created_via (created_via)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Email logs table - stores sent email records
-- =========================================
CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT,
    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Command history table - logs all assistant commands
-- =========================================
CREATE TABLE IF NOT EXISTS command_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    command TEXT NOT NULL,
    action VARCHAR(50) NOT NULL,
    status ENUM('success', 'error') DEFAULT 'success',
    result TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Contact messages table - stores contact form submissions
-- =========================================
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Users table - stores login and register accounts
-- =========================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Emails table - stores assistant email events per logged-in user
-- =========================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Activity log table - general activity tracking
-- =========================================
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    status VARCHAR(50) DEFAULT 'success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Safe sample data insert for files
-- If file name already exists, it will update existing row
-- =========================================
INSERT INTO files (filename, filepath, size, mime_type) VALUES
('welcome.txt', '/files/welcome.txt', 52, 'text/plain'),
('notes.md', '/files/notes.md', 108, 'text/markdown'),
('config.json', '/files/config.json', 85, 'application/json')
ON DUPLICATE KEY UPDATE
    filepath = VALUES(filepath),
    size = VALUES(size),
    mime_type = VALUES(mime_type),
    updated_at = CURRENT_TIMESTAMP;

-- =========================================
-- Sample command history
-- =========================================
INSERT INTO command_history (command, action, status, result) VALUES
('create file welcome.txt with content Welcome to Virtual Assistant!', 'create', 'success', 'File created successfully'),
('list all files', 'list', 'success', 'Found 3 files');

-- =========================================
-- Optional sample activity log
-- =========================================
INSERT INTO activity_log (action, description, status) VALUES
('system_init', 'Virtual Assistant database initialized successfully', 'success'),
('sample_data', 'Sample records inserted into files and command_history tables', 'success');

-- =========================================
-- Optional permissions
-- Uncomment and change username if needed
-- =========================================
-- GRANT ALL PRIVILEGES ON virtual_assistant.* TO 'your_username'@'localhost';
-- FLUSH PRIVILEGES;
