<?php
/**
 * Authentication helpers for session-based login.
 */

function ensureSessionStarted() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function isAuthenticated() {
    ensureSessionStarted();
    return !empty($_SESSION['user_id']);
}

function getAuthenticatedUser() {
    ensureSessionStarted();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'is_admin' => !empty($_SESSION['is_admin'])
    ];
}

function isAdminUser() {
    $user = getAuthenticatedUser();
    return !empty($user['is_admin']);
}

function canAccessUserFiles($ownerUserId) {
    $user = getAuthenticatedUser();
    if (!$user) {
        return false;
    }

    if (!empty($user['is_admin'])) {
        return true;
    }

    return (int) $ownerUserId === (int) ($user['id'] ?? 0);
}

function enforceFileOwnership($fileRecord) {
    $ownerUserId = isset($fileRecord['user_id']) ? (int) $fileRecord['user_id'] : null;
    $user = getAuthenticatedUser();

    if (!$user) {
        return false;
    }

    if ($ownerUserId === null || $ownerUserId === 0) {
        return !empty($user['is_admin']);
    }

    return canAccessUserFiles($ownerUserId);
}

function requireAuthenticatedUser() {
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.'
        ]);
        exit();
    }
}

function requireAdminUser() {
    requireAuthenticatedUser();
    ensureSessionStarted();

    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required.'
        ]);
        exit();
    }
}
