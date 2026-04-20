<?php
/**
 * Send Email Action
 * 
 * Sends an email using PHPMailer with SMTP (Gmail configuration)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/auth.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Check if PHPMailer is available, if not use a simple SMTP implementation
function sendEmail($input) {
    // Validate input
    if (empty($input['from'])) {
        return [
            'success' => false,
            'message' => 'Sender email is required.'
        ];
    }

    if (empty($input['to'])) {
        return [
            'success' => false,
            'message' => 'Recipient email is required.'
        ];
    }
    
    if (empty($input['subject'])) {
        return [
            'success' => false,
            'message' => 'Email subject is required.'
        ];
    }
    
    if (empty($input['message'])) {
        return [
            'success' => false,
            'message' => 'Email message is required.'
        ];
    }
    
    $from = filter_var($input['from'], FILTER_VALIDATE_EMAIL);
    $to = filter_var($input['to'], FILTER_VALIDATE_EMAIL);

    if (!$from || !$to) {
        return [
            'success' => false,
            'message' => 'Both sender and receiver email addresses must be valid.'
        ];
    }
    
    $subject = $input['subject'];
    $messageBody = $input['message'];
    $token = generateEmailToken($from, $to, $subject);
    $currentUser = getAuthenticatedUser();
    $currentUserId = $currentUser['id'] ?? null;
    
    try {
        $attachments = extractAttachmentsFromInput($input);
    } catch (InvalidArgumentException $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }

    $category = detectEmailCategory($subject, $messageBody, $attachments);
    $attachmentCount = count($attachments);

    $pdo = getDbConnection();

    if (!$pdo) {
        return [
            'success' => false,
            'message' => 'Database connection failed.'
        ];
    }
    
    try {
        // Try to send email using PHPMailer if available
        $emailSent = false;
        $status = 'failed';
        $errorMessage = '';
        
        // Check if PHPMailer class exists
        $phpmailerPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
        
        if (file_exists($phpmailerPath)) {
            require_once $phpmailerPath;
            require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
            require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
            
            $mail = new PHPMailer(true);
            
            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host = EMAIL_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = EMAIL_USERNAME;
                $mail->Password = EMAIL_PASSWORD;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = EMAIL_PORT;
                
                // Recipients
                $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
                $mail->addAddress($to);
                $mail->addReplyTo($from);
                $mail->addCustomHeader('X-VirtualAI-Email-Token', $token);
                
                // Content
                $mail->isHTML(false);
                $mail->Subject = $subject;
                $mail->Body = buildTokenizedMessageBody($from, $to, $messageBody, $token);
                foreach ($attachments as $attachPath) {
                    $mail->addAttachment($attachPath);
                }
                
                $mail->send();
                $emailSent = true;
                $status = 'sent';
            } catch (Exception $e) {
                $errorMessage = $mail->ErrorInfo;
            }
        } else {
            // Fallback: Use native PHP SMTP socket connection
            $smtpResult = sendEmailViaSMTP($from, $to, $subject, $messageBody, $token, $attachments);
            $emailSent = $smtpResult['success'];
            if ($emailSent) {
                $status = 'sent';
            } else {
                $errorMessage = $smtpResult['message'];
            }
        }
        
        // Log to database
        $stmt = $pdo->prepare("
            INSERT INTO emails (user_id, sender, recipient, subject, message, token, category, attachment_count, status)
            VALUES (:user_id, :sender, :recipient, :subject, :message, :token, :category, :attachment_count, :status)
        ");
        
        $stmt->execute([
            ':user_id' => $currentUserId,
            ':sender' => $from,
            ':recipient' => $to,
            ':subject' => $subject,
            ':message' => $messageBody,
            ':token' => $token,
            ':category' => $category,
            ':attachment_count' => $attachmentCount,
            ':status' => $status
        ]);
        
        if ($emailSent) {
            return [
                'success' => true,
                'message' => 'Email sent successfully to ' . $to,
                'email' => [
                    'id' => $pdo->lastInsertId(),
                    'from' => $from,
                    'to' => $to,
                    'subject' => $subject,
                    'token' => $token
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $errorMessage
            ];
        }
        
    } catch (Exception $e) {
        error_log("Send email error: " . $e->getMessage());
        
        // Log failed attempt
        try {
            $stmt = $pdo->prepare("
                INSERT INTO emails (user_id, sender, recipient, subject, message, token, category, attachment_count, status)
                VALUES (:user_id, :sender, :recipient, :subject, :message, :token, :category, :attachment_count, 'failed')
            ");
            
            $stmt->execute([
                ':user_id' => $currentUserId,
                ':sender' => $from,
                ':recipient' => $to,
                ':subject' => $subject,
                ':message' => $messageBody,
                ':token' => $token,
                ':category' => $category,
                ':attachment_count' => $attachmentCount
            ]);
        } catch (Exception $logError) {
            error_log("Failed to log email: " . $logError->getMessage());
        }
        
        return [
            'success' => false,
            'message' => 'Failed to send email. Please try again later.'
        ];
    }
}

function extractAttachmentsFromInput($input) {
    $attachments = [];

    if (empty($input['attachments']) || !is_array($input['attachments'])) {
        return [];
    }

    foreach ($input['attachments'] as $attachment) {
        $attachment = trim($attachment);
        if ($attachment === '') {
            continue;
        }

        $resolved = resolveAttachmentPath($attachment);
        if (!$resolved) {
            throw new InvalidArgumentException('Attachment not found: ' . $attachment);
        }
        $attachments[] = $resolved;
    }

    return $attachments;
}

function resolveAttachmentPath($filename) {
    $normalized = basename($filename);
    if (!$normalized) {
        return null;
    }

    $pdo = getDbConnection();
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT * FROM files WHERE filename = :filename LIMIT 1");
        if ($stmt->execute([':filename' => $normalized])) {
            $row = $stmt->fetch();
            if ($row && enforceFileOwnership($row) && !empty($row['filepath']) && file_exists($row['filepath'])) {
                return $row['filepath'];
            }
        }
    }

    return null;
}

/**
 * Send email via SMTP socket (fallback when PHPMailer is not available)
 */
function sendEmailViaSMTP($replyTo, $to, $subject, $message, $token, array $attachments = []) {
    $host = EMAIL_HOST;
    $username = EMAIL_USERNAME;
    $password = EMAIL_PASSWORD;
    $from = EMAIL_FROM;
    $fromName = EMAIL_FROM_NAME;

    $attempts = [
        [
            'transport' => 'ssl',
            'host' => 'ssl://' . $host,
            'port' => 465,
            'needs_starttls' => false,
            'label' => 'SSL 465'
        ],
        [
            'transport' => 'tcp',
            'host' => $host,
            'port' => EMAIL_PORT ?: 587,
            'needs_starttls' => true,
            'label' => 'STARTTLS 587'
        ]
    ];

    $lastError = 'SMTP connection failed';

    foreach ($attempts as $attempt) {
        $result = smtpSendAttempt(
            $attempt,
            $username,
            $password,
            $from,
            $fromName,
            $replyTo,
            $to,
            $subject,
            $message,
            $token,
            $attachments
        );

        if ($result['success']) {
            return $result;
        }

        $lastError = $attempt['label'] . ': ' . $result['message'];
        error_log('SMTP attempt failed: ' . $lastError);
    }

    return [
        'success' => false,
        'message' => $lastError
    ];
}

function smtpSendAttempt($attempt, $username, $password, $from, $fromName, $replyTo, $to, $subject, $message, $token, array $attachments = []) {
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        ]
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $attempt['host'] . ':' . $attempt['port'],
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return [
            'success' => false,
            'message' => "Connection failed: {$errstr} ({$errno})"
        ];
    }

    stream_set_timeout($socket, 30);

    try {
        smtpExpect($socket, 220, 'Server greeting');
        smtpCommand($socket, 'EHLO ' . gethostname(), 250, 'EHLO');

        if (!empty($attempt['needs_starttls'])) {
            smtpCommand($socket, 'STARTTLS', 220, 'STARTTLS');

            $cryptoEnabled = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );

            if ($cryptoEnabled !== true) {
                throw new RuntimeException('TLS negotiation failed');
            }

            smtpCommand($socket, 'EHLO ' . gethostname(), 250, 'EHLO after STARTTLS');
        }

        smtpCommand($socket, 'AUTH LOGIN', 334, 'AUTH LOGIN');
        smtpCommand($socket, base64_encode($username), 334, 'SMTP username');
        smtpCommand($socket, base64_encode($password), 235, 'SMTP password');
        smtpCommand($socket, "MAIL FROM:<{$from}>", 250, 'MAIL FROM');
        smtpCommand($socket, "RCPT TO:<{$to}>", [250, 251], 'RCPT TO');
        smtpCommand($socket, 'DATA', 354, 'DATA');

        $payloadParts = buildSmtpPayload($from, $fromName, $replyTo, $to, $subject, $token, $message, $attachments);
        $payload = smtpEscapeMessage($payloadParts['headers'] . $payloadParts['body']) . "\r\n.";

        fwrite($socket, $payload . "\r\n");
        smtpExpect($socket, 250, 'Message body');

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return [
            'success' => true,
            'message' => 'Email sent successfully'
        ];
    } catch (Throwable $e) {
        fclose($socket);

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function smtpCommand($socket, $command, $expectedCodes, $stepLabel) {
    fwrite($socket, $command . "\r\n");
    smtpExpect($socket, $expectedCodes, $stepLabel);
}

function smtpExpect($socket, $expectedCodes, $stepLabel) {
    $response = smtpReadResponse($socket);
    $code = (int) substr($response, 0, 3);
    $expectedCodes = (array) $expectedCodes;

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException($stepLabel . ' failed: ' . trim($response));
    }

    return $response;
}

function smtpReadResponse($socket) {
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }

        $response .= $line;

        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('SMTP server timed out');
        }
        throw new RuntimeException('No response from SMTP server');
    }

    return $response;
}

function buildSmtpPayload($from, $fromName, $replyTo, $to, $subject, $token, $message, array $attachments = []) {
    $headers = "From: {$fromName} <{$from}>\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Reply-To: {$replyTo}\r\n";
    $headers .= 'Subject: ' . encodeSmtpHeader($subject) . "\r\n";
    $headers .= "X-VirtualAI-Email-Token: {$token}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";

    if (!empty($attachments)) {
        $boundary = '==Multipart_Boundary_' . bin2hex(random_bytes(10));
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= buildTokenizedMessageBody($replyTo, $to, $message, $token) . "\r\n";

        foreach ($attachments as $attachment) {
            $filename = basename($attachment);
            $mimeType = getAttachmentMimeType($attachment);
            $encoded = chunk_split(base64_encode(file_get_contents($attachment)), 76, "\r\n");

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$mimeType}; name=\"{$filename}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $body .= $encoded . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body = buildTokenizedMessageBody($replyTo, $to, $message, $token);
    }

    return [
        'headers' => $headers,
        'body' => $body
    ];
}

function getAttachmentMimeType($path) {
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        if ($mime) {
            return $mime;
        }
    }
    return 'application/octet-stream';
}

function encodeSmtpHeader($value) {
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtpEscapeMessage($message) {
    $normalized = str_replace(["\r\n", "\r"], "\n", $message);
    $normalized = str_replace("\n.", "\n..", $normalized);
    return str_replace("\n", "\r\n", $normalized);
}

/**
 * Get email history
 */
function getEmailHistory($limit = 50) {
    $pdo = getDbConnection();
    
    if (!$pdo) {
        return [
            'success' => false,
            'message' => 'Database connection failed.'
        ];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, recipient, subject, status, created_at
            FROM emails
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $emails = $stmt->fetchAll();
        
        return [
            'success' => true,
            'emails' => $emails
        ];
        
    } catch (Exception $e) {
        error_log("Get email history error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to get email history.'
        ];
    }
}

function generateEmailToken($from, $to, $subject) {
    return hash('sha256', implode('|', [$from, $to, $subject, microtime(true), bin2hex(random_bytes(8))]));
}

function buildTokenizedMessageBody($from, $to, $messageBody, $token) {
    return $messageBody;
}

function detectEmailCategory($subject, $messageBody, array $attachments = []) {
    if (!empty($attachments)) {
        return 'file-share';
    }

    $haystack = strtolower(trim($subject . ' ' . $messageBody));

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
?>
