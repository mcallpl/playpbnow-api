<?php
/**
 * AI Content Generator for Broadcast Messages
 * Uses Google Gemini to generate subject, SMS text, and landing page HTML
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Gemini API config
$GEMINI_API_KEY = 'AIzaSyDEaZULitnUZBpH-l7PGy33puDmRUyPcmM';
$GEMINI_MODEL = 'gemini-2.5-flash-lite';

function requireAdmin($user_id) {
    if (!$user_id) {
        echo json_encode(['status' => 'error', 'message' => 'user_id is required']); exit;
    }
    $userRow = dbGetRow("SELECT is_admin FROM users WHERE id = ?", [$user_id]);
    if (!$userRow || !$userRow['is_admin']) {
        echo json_encode(['status' => 'error', 'message' => 'Admin access required']); exit;
    }
    return true;
}

function callGemini($prompt, $apiKey, $model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'object',
                'properties' => [
                    'subject' => [
                        'type' => 'string',
                        'description' => 'Email/broadcast subject line, catchy and concise (under 60 chars)'
                    ],
                    'sms_text' => [
                        'type' => 'string',
                        'description' => 'Short SMS message text (under 140 chars to save costs). Engaging, action-oriented. Do NOT include any URLs — the system adds them automatically.'
                    ],
                    'body_html' => [
                        'type' => 'string',
                        'description' => 'Rich HTML content for the broadcast landing page. Use styled HTML with inline CSS. Include headings, paragraphs, bullet points as needed. Make it visually appealing and informative. Use the color scheme: accent green #87ca37, dark background #0f1b2d, light text #e8f0fe. Do NOT wrap in full HTML document tags — just the inner content (h2, p, ul, etc.)'
                    ],
                ],
                'required' => ['subject', 'sms_text', 'body_html'],
            ],
            'temperature' => 0.8,
            'maxOutputTokens' => 2048,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => "Curl error: {$curlError}"];
    }

    if ($httpCode !== 200) {
        $decoded = json_decode($response, true);
        $msg = $decoded['error']['message'] ?? "HTTP {$httpCode}";
        return ['error' => "Gemini API error: {$msg}"];
    }

    $decoded = json_decode($response, true);

    // Check for safety blocks
    if (isset($decoded['candidates'][0]['finishReason']) && $decoded['candidates'][0]['finishReason'] === 'SAFETY') {
        return ['error' => 'Content was blocked by safety filters. Try a different prompt.'];
    }

    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        return ['error' => 'No content returned from Gemini'];
    }

    $result = json_decode($text, true);
    if (!$result) {
        return ['error' => 'Failed to parse Gemini response as JSON'];
    }

    return $result;
}

switch ($action) {

    case 'generate':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);

        $theme = trim($input['theme'] ?? '');
        if (empty($theme)) {
            echo json_encode(['status' => 'error', 'message' => 'Please describe what this broadcast is about']); exit;
        }

        $prompt = <<<PROMPT
You are a marketing copywriter for PlayPBNow, a pickleball app that connects coordinators with hundreds of eager players. The app lets coordinators send SMS invites, organize matches, track scores, and fill courts fast.

The admin wants to send a mass broadcast message to all pool players. Here is what they want to communicate:

"{$theme}"

Generate three pieces of content:

1. **subject**: A catchy, concise subject line (under 60 characters) for this broadcast.

2. **sms_text**: A short, engaging SMS message (under 140 characters) that gets players excited and wanting to tap the link for details. Be conversational and action-oriented. Do NOT include any URLs — the system appends the link automatically.

3. **body_html**: Rich HTML content for the landing page recipients will see when they tap the link. Make it visually compelling with:
   - A bold headline
   - Engaging body copy that expands on the SMS
   - Key details in bullet points or highlighted sections
   - A clear call-to-action
   - Use inline CSS with this color palette: accent green #87ca37, dark navy #0f1b2d, soft blue-white #e8f0fe
   - Use clean, modern styling (rounded corners, good spacing, readable fonts)
   - Do NOT include <html>, <head>, <body> tags — just the inner content elements (h2, p, ul, div, etc.)
   - Make it look professional and exciting

Keep the tone energetic, friendly, and pickleball-focused.
PROMPT;

        $result = callGemini($prompt, $GEMINI_API_KEY, $GEMINI_MODEL);

        if (isset($result['error'])) {
            echo json_encode(['status' => 'error', 'message' => $result['error']]);
        } else {
            echo json_encode([
                'status' => 'success',
                'subject' => strip_tags($result['subject'] ?? '', '<strong><em><b><i>'),
                'sms_text' => strip_tags($result['sms_text'] ?? ''),
                'body_html' => $result['body_html'] ?? '',
            ]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
