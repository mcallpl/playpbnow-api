<?php
/**
 * PlayPBNow Database Migration Runner
 *
 * Purpose: Safely execute all pending migrations in order
 * Handles: idempotency, error recovery, transaction logging
 *
 * Usage: php run_migrations.php
 */

/**
 * Parse SQL statements while respecting DELIMITER directives
 * Handles stored procedures and complex multi-statement blocks
 */
function parse_sql_statements($content) {
    $statements = [];
    $delimiter = ';';
    $current_statement = '';

    $lines = explode("\n", $content);

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Check for DELIMITER directive
        if (stripos($trimmed, 'DELIMITER') === 0) {
            // Extract the new delimiter
            $parts = explode(' ', $trimmed);
            if (count($parts) >= 2) {
                $delimiter = $parts[1];
            }
            continue;
        }

        $current_statement .= $line . "\n";

        // Check if line ends with current delimiter
        if (substr(rtrim($trimmed), -strlen($delimiter)) === $delimiter) {
            $stmt = trim(str_replace($delimiter, '', $current_statement));
            if (!empty($stmt) && substr($stmt, 0, 2) !== '--') {
                $statements[] = $stmt;
            }
            $current_statement = '';
        }
    }

    // Add any remaining statement
    if (!empty(trim($current_statement))) {
        $stmt = trim(str_replace($delimiter, '', $current_statement));
        if (!empty($stmt) && substr($stmt, 0, 2) !== '--') {
            $statements[] = $stmt;
        }
    }

    return $statements;
}

// Database configuration
$db_host = 'localhost';
$db_user = 'mcallpl';
$db_pass = 'amazing123';
$db_name = 'playpbnow';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "=== PlayPBNow Database Migration Runner ===\n";
echo "Connected to database: {$db_name}\n\n";

// List of migrations to run
$migrations = [
    'migration_001_soft_deletes.sql',
    'migration_002_audit_log.sql',
    'migration_003_indexes.sql',
    'migration_004_transactions.sql'
];

$migration_dir = __DIR__;
$success_count = 0;
$error_count = 0;

foreach ($migrations as $migration_file) {
    $file_path = "{$migration_dir}/{$migration_file}";

    if (!file_exists($file_path)) {
        echo "[ERROR] Migration file not found: {$file_path}\n";
        $error_count++;
        continue;
    }

    echo "Running: {$migration_file}...\n";

    // Read migration file
    $sql_content = file_get_contents($file_path);

    // Handle DELIMITER statements properly for stored procedures
    // Split by standard semicolon, but handle DELIMITER directives
    $statements = parse_sql_statements($sql_content);

    foreach ($statements as $statement) {
        // Skip comments and empty statements
        if (empty($statement) || substr(trim($statement), 0, 2) === '--') {
            continue;
        }

        // Execute statement with exception handling
        try {
            $conn->query($statement);
        } catch (Exception $e) {
            $error_msg = $e->getMessage();

            // Handle duplicate column errors gracefully (already exists)
            if (strpos($error_msg, 'Duplicate column') !== false) {
                // Not an error - column already exists
                continue;
            }

            // Handle duplicate key/index errors gracefully (already exists)
            if (strpos($error_msg, 'Duplicate key') !== false) {
                // Not an error - index already exists
                continue;
            }

            // Handle table already exists
            if (strpos($error_msg, 'already exists') !== false) {
                // Not an error - table already exists, will be ignored
                continue;
            }

            // Handle key column doesn't exist (index before column added in some cases)
            if (strpos($error_msg, "doesn't exist") !== false) {
                echo "  ⚠ Warning: {$error_msg}\n";
                continue;
            }

            // Handle other errors
            echo "  [ERROR] Query failed: {$error_msg}\n";
            echo "  Query: " . substr($statement, 0, 100) . "...\n";
            $error_count++;
            continue;
        }
    }

    echo "✓ {$migration_file} completed\n\n";
    $success_count++;
}

// Close connection
$conn->close();

echo "=== Migration Summary ===\n";
echo "Successful migrations: {$success_count}\n";
echo "Failed migrations: {$error_count}\n";
echo "Total processed: " . count($migrations) . "\n\n";

if ($error_count === 0) {
    echo "✓ All migrations completed successfully!\n";
    exit(0);
} else {
    echo "[WARNING] Some migrations had errors. Review above.\n";
    exit(1);
}
