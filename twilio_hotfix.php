<?php
/**
 * CRITICAL HOTFIX for Twilio SMS Bug
 *
 * This script patches db_config.php to fix the Twilio API call syntax.
 * The messages->create() method requires 'to' as a key in the params array.
 *
 * Run: php twilio_hotfix.php
 *
 * NO CHANGES NEEDED — this is self-contained and safe to run on production.
 */

// Read db_config.php
$file = __DIR__ . '/db_config.php';
if (!file_exists($file)) {
    die("ERROR: db_config.php not found at $file\n");
}

$content = file_get_contents($file);
$original = $content;

// Check if already patched
if (strpos($content, "'to' => \$phone") !== false) {
    echo "✅ db_config.php is already patched!\n";
    exit(0);
}

// Replace the broken pattern:
//   $client->messages->create(
//       $phone,
//       [
//           'from' => TWILIO_PHONE_NUMBER,
// With:
//   $client->messages->create([
//       'to' => $phone,
//       'from' => TWILIO_PHONE_NUMBER,

$broken = <<<'BROKEN'
$message = $client->messages->create(
            $phone,
            [
                'from' => TWILIO_PHONE_NUMBER,
                'body' => "Your Play PB Now verification code is: {$code}\n\nThis code expires in " . CODE_EXPIRY_MINUTES . " minutes."
            ]
        );
BROKEN;

$fixed = <<<'FIXED'
$message = $client->messages->create([
            'to' => $phone,
            'from' => TWILIO_PHONE_NUMBER,
            'body' => "Your Play PB Now verification code is: {$code}\n\nThis code expires in " . CODE_EXPIRY_MINUTES . " minutes."
        ]);
FIXED;

$content = str_replace($broken, $fixed, $content);

if ($content === $original) {
    die("ERROR: Could not find the pattern to replace. The file may already be fixed or different.\n");
}

// Write back
if (!file_put_contents($file, $content)) {
    die("ERROR: Could not write to db_config.php\n");
}

echo "✅ db_config.php patched successfully!\n";
echo "✅ Twilio SMS will now work for password reset codes\n";
echo "   (and all other SMS features)\n";
?>
