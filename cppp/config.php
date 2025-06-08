<?php
// Prevent direct access to this file
if (!defined('SECURE_ACCESS')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access to this file is forbidden.');
}

// Define paths
$basePath = dirname(__FILE__);  // This is the web-accessible directory
$parentPath = dirname($basePath);  // This is the parent directory of the web root
$securePath = $parentPath . '/cppp_secure';  // This will be outside the web root

return [
    'database' => [
        'path' => $securePath . '/database/cppp.db',  // Outside web root
        'pool_size' => 5,
        'cleanup_days' => 60  // Delete entries older than 60 days
    ],
    'paypal' => [
        'webhook_id' => 'YOUR_WEBHOOK_ID', // Replace with your actual webhook ID
        'client_id' => 'YOUR_CLIENT_ID',   // Replace with your PayPal client ID
        'client_secret' => 'YOUR_CLIENT_SECRET' // Replace with your PayPal client secret
    ],
    'telegram' => [
        'bot_token' => 'YOUR_BOT_TOKEN',  // Replace with your Telegram bot token
        'chat_id' => 'YOUR_CHAT_ID',      // Replace with your Telegram chat ID
        'service_name' => 'My Webservice', // Name of your service
        'message_template' => "🆕 NEW PAYMENT at {service_name}\n\n" .
                            "💫 Current Transaction:\n" .
                            "━━━━━━━━━━━━━━━━━━━━\n" .
                            "💰 Amount: {amount} {currency}\n" .
                            "🆔 Payment ID: {payment_id}\n" .
                            "📅 Time: {create_time}\n" .
                            "✅ Status: {status}\n\n" .
                            "📊 Statistics:\n" .
                            "━━━━━━━━━━━━━━━━━━━━\n" .
                            "📈 Last 24 Hours:\n" .
                            "   • Transactions: {payments24h}\n" .
                            "   • Total Amount: {sumamounts24h} {currency}\n\n" .
                            "📈 Last 7 Days:\n" .
                            "   • Transactions: {payments7d}\n" .
                            "   • Total Amount: {sumamounts7d} {currency}\n\n" .
                            "📈 Last 28 Days:\n" .
                            "   • Transactions: {payments28d}\n" .
                            "   • Total Amount: {sumamounts28d} {currency}"
    ],
    'logging' => [
        'path' => $securePath . '/logs',  // Outside web root
        'filename' => 'cppp.log',
        'max_size' => 5 * 1024 * 1024  // 5MB log size limit
    ],
    'rate_limiting' => [
        'max_requests' => 100,
        'time_window' => 3600 // 1 hour in seconds
    ]
]; 