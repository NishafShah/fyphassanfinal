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
