<?php
// ============================================
// Database Configuration for PlayPBNow
// ============================================
// COPY this file to db_config.php and fill in your real credentials.
// db_config.php is in .gitignore and will NOT be pushed to GitHub.

// Set timezone to Pacific
date_default_timezone_set('America/Los_Angeles');

// GoDaddy Database Connection Settings
// IMPORTANT: Update these values with your actual database credentials
define('DB_HOST', 'localhost'); // Usually 'localhost' on GoDaddy
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');

// Create mysqli connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Database connection failed'
        ]);
        exit;
    }

    $conn->set_charset('utf8mb4');

    // SET TIMEZONE TO PACIFIC (UTC-8)
    $conn->query("SET time_zone = '-08:00'");

    return $conn;
}

// Helper function to execute queries safely
function dbQuery($sql, $params = []) {
    $conn = getDBConnection();
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Query preparation failed: " . $conn->error);
        return false;
    }

    if (!empty($params)) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    return $stmt;
}

// Helper function to get single row
function dbGetRow($sql, $params = []) {
    $conn = getDBConnection();
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Query preparation failed: " . $conn->error);
        return null;
    }

    if (!empty($params)) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();
    $conn->close();

    return $row;
}

// Helper function to get all rows
function dbGetAll($sql, $params = []) {
    $conn = getDBConnection();
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Query preparation failed: " . $conn->error);
        return [];
    }

    if (!empty($params)) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    $conn->close();

    return $rows;
}

// Helper function to insert and get last ID
function dbInsert($sql, $params = []) {
    $conn = getDBConnection();
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Insert preparation failed: " . $conn->error);
        return false;
    }

    if (!empty($params)) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log("Insert execution failed: " . $stmt->error);
        error_log("SQL: $sql");
        error_log("Params: " . json_encode($params));
        $stmt->close();
        $conn->close();
        return false;
    }

    $insertId = $conn->insert_id;

    $stmt->close();
    $conn->close();

    return $insertId;
}

// Helper function to check if user has feature access
function userHasAccess($userId, $feature) {
    $access = dbGetRow(
        "SELECT * FROM feature_access WHERE user_id = ?",
        [$userId]
    );

    if (!$access) {
        // No access record = free tier defaults
        $defaults = [
            'can_create_matches' => true,
            'can_edit_matches' => false, // PREMIUM FEATURE
            'can_delete_matches' => false,
            'can_generate_reports' => false, // PREMIUM FEATURE
            'can_create_groups' => true,
            'max_groups' => 1,
            'max_players_per_group' => 100
        ];
        return $defaults[$feature] ?? false;
    }

    return $access[$feature] ?? false;
}

// Helper function to check subscription status
function userHasActiveSubscription($userId) {
    $user = dbGetRow(
        "SELECT subscription_status, subscription_end_date
         FROM users
         WHERE id = ?",
        [$userId]
    );

    if (!$user) return false;

    if ($user['subscription_status'] === 'active') {
        // Check if subscription hasn't expired
        if ($user['subscription_end_date'] &&
            strtotime($user['subscription_end_date']) > time()) {
            return true;
        }
    }

    return false;
}

// Helper function to get or create user by device_id
function getOrCreateUser($deviceId) {
    // Try to find existing user
    $user = dbGetRow(
        "SELECT * FROM users WHERE device_id = ?",
        [$deviceId]
    );

    if ($user) {
        return $user;
    }

    // Create new user
    $userId = dbInsert(
        "INSERT INTO users (device_id, subscription_status, subscription_tier)
         VALUES (?, 'none', 'free')",
        [$deviceId]
    );

    // Create default feature access
    dbQuery(
        "INSERT INTO feature_access
         (user_id, can_create_matches, can_edit_matches, can_delete_matches,
          can_generate_reports, can_create_groups, max_groups, max_players_per_group)
         VALUES (?, 1, 0, 0, 0, 1, 1, 100)",
        [$userId]
    );

    return dbGetRow("SELECT * FROM users WHERE id = ?", [$userId]);
}

// ============================================
// TWILIO CONFIGURATION
// ============================================
define('TWILIO_ACCOUNT_SID', 'YOUR_TWILIO_SID');
define('TWILIO_AUTH_TOKEN', 'YOUR_TWILIO_AUTH_TOKEN');
define('TWILIO_PHONE_NUMBER', '+1XXXXXXXXXX'); // Your Twilio number

// Verification code settings
define('CODE_LENGTH', 6);
define('CODE_EXPIRY_MINUTES', 10);

// Include Twilio SDK
require_once __DIR__ . '/vendor/autoload.php';

use Twilio\Rest\Client;

function sendVerificationCode($phone, $code) {
    try {
        $client = new Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);

        $message = $client->messages->create(
            $phone,
            [
                'from' => TWILIO_PHONE_NUMBER,
                'body' => "Your PlayPBNow verification code is: {$code}\n\nThis code expires in " . CODE_EXPIRY_MINUTES . " minutes."
            ]
        );

        error_log("SMS sent to {$phone}: {$message->sid}");
        return true;
    } catch (Exception $e) {
        error_log("Twilio error: " . $e->getMessage());
        return false;
    }
}

function generateVerificationCode() {
    return str_pad(rand(0, 999999), CODE_LENGTH, '0', STR_PAD_LEFT);
}

function cleanPhoneNumber($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($clean) === 10) {
        $clean = '1' . $clean;
    }
    return '+' . $clean;
}

?>
