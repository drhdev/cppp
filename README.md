# CPPP - PayPal Payment Processor

A secure and robust PHP-based payment processing system that handles PayPal webhook notifications, processes payments, and sends notifications via Telegram.

## Features

- 🔒 Secure PayPal webhook handling with signature verification
- 💾 SQLite database with connection pooling for payment storage
- 📊 Comprehensive payment statistics (24h, 7d, 28d)
- 📱 Telegram notifications for new payments
- ⚡ Rate limiting to prevent abuse
- 📝 Detailed logging with automatic log rotation
- 🧪 Comprehensive test suite

## Requirements

- PHP 7.4 or higher
- Python 3.9 or higher (for testing)
- SQLite3
- PayPal Business Account
- Telegram Bot Token

## Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd cppp
```

2. Set up the directory structure:
```bash
mkdir -p cppp_secure/database cppp_secure/logs
```

3. Configure the application:
   - Copy `config.php` and update the following settings:
     - PayPal webhook ID
     - PayPal client ID and secret
     - Telegram bot token and chat ID
     - Database path
     - Logging settings

4. Set proper permissions:
```bash
chmod 755 cppp
chmod 644 cppp/*.php
chmod 755 cppp_secure
chmod 755 cppp_secure/database
chmod 755 cppp_secure/logs
```

## Testing

The project includes a comprehensive test suite written in Python. To run the tests:

1. Create and activate a virtual environment:
```bash
python3 -m venv venv
source venv/bin/activate
```

2. Install required packages:
```bash
pip install requests
```

3. Run the tests:
```bash
python -m unittest test/test_cppp.py -v
```

The test suite covers:
- Configuration file structure
- Database operations
- Rate limiting
- Telegram notifications
- Log rotation
- PayPal webhook verification

## Usage

1. Set up a PayPal webhook in your PayPal Developer Dashboard:
   - Event type: `PAYMENT.SALE.COMPLETED`
   - URL: `https://your-domain.com/cppp/cppp.php`

2. Configure your web server (Apache/Nginx) to point to the `cppp` directory.

3. The system will automatically:
   - Verify incoming PayPal webhook signatures
   - Store payment information in the SQLite database
   - Send notifications to your configured Telegram chat
   - Maintain payment statistics
   - Rotate logs when they exceed the size limit

## Configuration

The `config.php` file contains all necessary configuration options:

```php
return [
    'database' => [
        'path' => '/path/to/cppp_secure/database/cppp.db',
        'pool_size' => 5,
        'cleanup_days' => 60
    ],
    'paypal' => [
        'webhook_id' => 'YOUR_WEBHOOK_ID',
        'client_id' => 'YOUR_CLIENT_ID',
        'client_secret' => 'YOUR_CLIENT_SECRET'
    ],
    'telegram' => [
        'bot_token' => 'YOUR_BOT_TOKEN',
        'chat_id' => 'YOUR_CHAT_ID',
        'service_name' => 'My Webservice',
        'message_template' => '...'
    ],
    'logging' => [
        'path' => '/path/to/cppp_secure/logs',
        'filename' => 'cppp.log',
        'max_size' => 5 * 1024 * 1024
    ],
    'rate_limiting' => [
        'max_requests' => 100,
        'time_window' => 3600
    ]
];
```

## Security Considerations

- The `cppp_secure` directory should be placed outside the web root
- All sensitive configuration is stored in `config.php`
- Webhook signatures are verified for each request
- Rate limiting prevents abuse
- Logs are automatically rotated to prevent disk space issues

## Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For support, please open an issue in the GitHub repository or contact the maintainers. 