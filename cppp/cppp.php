<?php
/*
 * Script Name: cppp.php
 * Version: 2.0
 * 
 * Description:
 * - Listens for incoming PayPal webhook notifications.
 * - Verifies webhook signatures for security.
 * - Implements rate limiting to prevent abuse.
 * - Parses and validates the JSON payload from PayPal.
 * - Stores payment details in an SQLite database with connection pooling.
 * - Provides detailed logging and error handling.
 * - Sends notifications to Telegram.
 * 
 * Dependencies:
 * - Webhook URL needs to be set in PayPal Developer Console.
 * - Proper permissions must be set for the webhook.
 * - The SQLite database to store payments must exist and be accessible.
 * - Python script `cppp.py` is needed to read from the database and send Telegram messages.
 */

// Define security constant to prevent direct access to config.php
define('SECURE_ACCESS', true);

// Load configuration
$config = require_once 'config.php';

// Initialize logging directory
if (!file_exists($config['logging']['path'])) {
    if (!@mkdir($config['logging']['path'], 0755, true)) {
        // If we can't create the directory, fall back to the current directory
        $config['logging']['path'] = dirname(__FILE__);
    }
}

// Set up error logging
ini_set('log_errors', 'On');
ini_set('error_log', $config['logging']['path'] . '/' . $config['logging']['filename']);

// Function to send HTTP response
function sendResponse($statusCode, $message) {
    http_response_code($statusCode);
    echo json_encode(['status' => $statusCode, 'message' => $message]);
    exit;
}

// Function to log access with size limit check
function logAccess($message) {
    global $config;
    $logFile = $config['logging']['path'] . '/' . $config['logging']['filename'];
    
    // Check if log file exists and is too large
    if (file_exists($logFile) && filesize($logFile) > $config['logging']['max_size']) {
        // Create backup of current log
        $backupFile = $logFile . '.' . date('Y-m-d-H-i-s') . '.bak';
        @rename($logFile, $backupFile);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Initialize database directory
$dbDir = dirname($config['database']['path']);
if (!file_exists($dbDir)) {
    if (!@mkdir($dbDir, 0755, true)) {
        die('Failed to create database directory. Please create it manually with proper permissions.');
    }
}

// Database connection pool
class DatabasePool {
    private static $instance = null;
    private $connections = [];
    private $config;
    
    private function __construct($config) {
        $this->config = $config;
    }
    
    public static function getInstance($config) {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    
    public function getConnection() {
        if (count($this->connections) < $this->config['database']['pool_size']) {
            $db = new SQLite3($this->config['database']['path']);
            if (!$db) {
                throw new Exception("Failed to connect to database: " . SQLite3::lastErrorMsg());
            }
            $this->connections[] = $db;
            return $db;
        }
        return $this->connections[array_rand($this->connections)];
    }
}

// Function to verify PayPal webhook signature
function verifyPayPalWebhook($payload, $headers) {
    global $config;
    
    $transmissionId = $headers['PayPal-Transmission-Id'] ?? '';
    $transmissionTime = $headers['PayPal-Transmission-Time'] ?? '';
    $certUrl = $headers['PayPal-Cert-Url'] ?? '';
    $webhookId = $config['paypal']['webhook_id'];
    
    if (empty($transmissionId) || empty($transmissionTime) || empty($certUrl)) {
        return false;
    }
    
    // Verify webhook signature using PayPal's API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api-m.paypal.com/v1/notifications/verify-webhook-signature");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'transmission_id' => $transmissionId,
        'transmission_time' => $transmissionTime,
        'cert_url' => $certUrl,
        'webhook_id' => $webhookId,
        'webhook_event' => $payload
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $config['paypal']['client_id'] . ":" . $config['paypal']['client_secret']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return false;
    }
    
    $result = json_decode($response, true);
    return $result['verification_status'] === 'SUCCESS';
}

// Function to check rate limiting
function checkRateLimit() {
    global $config;
    $ip = $_SERVER['REMOTE_ADDR'];
    $cacheFile = sys_get_temp_dir() . "/rate_limit_$ip.json";
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (time() - $data['timestamp'] > $config['rate_limiting']['time_window']) {
            $data = ['count' => 1, 'timestamp' => time()];
        } else if ($data['count'] >= $config['rate_limiting']['max_requests']) {
            return false;
        } else {
            $data['count']++;
        }
    } else {
        $data = ['count' => 1, 'timestamp' => time()];
    }
    
    file_put_contents($cacheFile, json_encode($data));
    return true;
}

// Function to calculate statistics and update database
function calculateAndUpdateStatistics($db, $amount, $currency) {
    $now = time();
    $dayAgo = $now - 86400;  // 24 hours
    $weekAgo = $now - 604800;  // 7 days
    $monthAgo = $now - 2419200;  // 28 days

    // Calculate statistics
    $stats24h = $db->query("SELECT COUNT(*) as count, SUM(amount) as sum FROM payments 
                           WHERE processed_at > datetime($dayAgo, 'unixepoch')")->fetch(PDO::FETCH_ASSOC);
    $stats7d = $db->query("SELECT COUNT(*) as count, SUM(amount) as sum FROM payments 
                          WHERE processed_at > datetime($weekAgo, 'unixepoch')")->fetch(PDO::FETCH_ASSOC);
    $stats28d = $db->query("SELECT COUNT(*) as count, SUM(amount) as sum FROM payments 
                           WHERE processed_at > datetime($monthAgo, 'unixepoch')")->fetch(PDO::FETCH_ASSOC);

    // Clean up old entries
    $cleanupDays = $config['database']['cleanup_days'];
    $cleanupDate = date('Y-m-d H:i:s', $now - ($cleanupDays * 86400));
    $db->exec("DELETE FROM payments WHERE processed_at < '$cleanupDate'");

    return [
        'payments24h' => $stats24h['count'] ?: 1,
        'sumamounts24h' => $stats24h['sum'] ?: $amount,
        'payments7d' => $stats7d['count'] ?: 1,
        'sumamounts7d' => $stats7d['sum'] ?: $amount,
        'payments28d' => $stats28d['count'] ?: 1,
        'sumamounts28d' => $stats28d['sum'] ?: $amount
    ];
}

// Function to send Telegram notification
function sendTelegramNotification($data) {
    $config = require 'config.php';
    if (empty($config['telegram']['bot_token']) || empty($config['telegram']['chat_id'])) {
        return;
    }

    // Calculate statistics
    $db = DatabasePool::getInstance()->getConnection();
    $stats = calculateAndUpdateStatistics($db, $data['amount'], $data['currency']);

    // Prepare message
    $message = $config['telegram']['message_template'];
    $replacements = array_merge($data, $stats, [
        'service_name' => $config['telegram']['service_name']
    ]);

    foreach ($replacements as $key => $value) {
        $message = str_replace("{{$key}}", $value, $message);
    }

    // Send message
    $url = "https://api.telegram.org/bot{$config['telegram']['bot_token']}/sendMessage";
    $params = [
        'chat_id' => $config['telegram']['chat_id'],
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    if ($result === false) {
        error_log("Failed to send Telegram notification");
    }
}

// Main execution
try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(405, 'Method Not Allowed');
    }
    
    // Check rate limiting
    if (!checkRateLimit()) {
        sendResponse(429, 'Too Many Requests');
    }
    
    // Get and verify webhook payload
    $payload = file_get_contents('php://input');
    $headers = getallheaders();
    
    if (!verifyPayPalWebhook($payload, $headers)) {
        sendResponse(401, 'Invalid Webhook Signature');
    }
    
    $data = json_decode($payload);
    if (!$data || !isset($data->event_type)) {
        sendResponse(400, 'Invalid Payload');
    }
    
    // Process payment data
    if ($data->event_type === 'PAYMENT.SALE.COMPLETED') {
        // Validate required fields
        $requiredFields = ['id', 'state', 'amount', 'create_time'];
        foreach ($requiredFields as $field) {
            if (!isset($data->resource->$field)) {
                sendResponse(400, "Missing required field: $field");
            }
        }
        
        // Validate amount
        if (!isset($data->resource->amount->total) || !is_numeric($data->resource->amount->total)) {
            sendResponse(400, 'Invalid amount');
        }
        
        // Get database connection from pool
        $db = DatabasePool::getInstance($config)->getConnection();
        
        // Create table if not exists
        $db->exec("CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payment_id TEXT NOT NULL,
            amount REAL NOT NULL,
            currency TEXT NOT NULL,
            status TEXT NOT NULL,
            create_time TEXT NOT NULL,
            processed_at TEXT NOT NULL,
            payments24h INTEGER DEFAULT 1,
            sumamounts24h REAL DEFAULT 0,
            payments7d INTEGER DEFAULT 1,
            sumamounts7d REAL DEFAULT 0,
            payments28d INTEGER DEFAULT 1,
            sumamounts28d REAL DEFAULT 0
        )");
        
        // Insert payment data
        $stmt = $db->prepare("INSERT INTO payments (payment_id, status, amount, currency, create_time, processed_at) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $db->lastErrorMsg());
        }
        
        $stmt->bindValue(1, $data->resource->id, SQLITE3_TEXT);
        $stmt->bindValue(2, $data->resource->state, SQLITE3_TEXT);
        $stmt->bindValue(3, $data->resource->amount->total, SQLITE3_FLOAT);
        $stmt->bindValue(4, $data->resource->amount->currency, SQLITE3_TEXT);
        $stmt->bindValue(5, $data->resource->create_time, SQLITE3_TEXT);
        $stmt->bindValue(6, date('Y-m-d H:i:s'), SQLITE3_TEXT);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to insert payment: " . $db->lastErrorMsg());
        }
        
        // Prepare notification data
        $notificationData = [
            'payment_id' => $data->resource->id,
            'amount' => $data->resource->amount->total,
            'currency' => $data->resource->amount->currency,
            'status' => $data->resource->state,
            'create_time' => $data->resource->create_time,
            'processed_at' => date('Y-m-d H:i:s')
        ];

        // Send Telegram notification after 5 seconds
        sleep(5);
        sendTelegramNotification($notificationData);
        
        logAccess("Payment {$data->resource->id} processed successfully");
        sendResponse(200, 'Payment processed successfully');
    } else {
        logAccess("Received irrelevant webhook event: {$data->event_type}");
        sendResponse(200, 'No relevant webhook event');
    }
    
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    sendResponse(500, 'Internal Server Error');
}
?>
