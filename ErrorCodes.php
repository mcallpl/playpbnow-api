<?php
/**
 * Error Codes - Central error code constants for API responses
 * Used by BaseController to return standardized error responses
 */

class ErrorCodes {
    // Input validation errors
    const INVALID_INPUT = 'INVALID_INPUT';
    const MISSING_FIELD = 'MISSING_FIELD';
    const INVALID_EMAIL = 'INVALID_EMAIL';
    const INVALID_PHONE = 'INVALID_PHONE';
    const INVALID_INTEGER = 'INVALID_INTEGER';
    const STRING_TOO_SHORT = 'STRING_TOO_SHORT';
    const STRING_TOO_LONG = 'STRING_TOO_LONG';

    // Authentication errors
    const UNAUTHORIZED = 'UNAUTHORIZED';
    const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    const TOKEN_EXPIRED = 'TOKEN_EXPIRED';
    const TOKEN_INVALID = 'TOKEN_INVALID';

    // Authorization errors
    const FORBIDDEN = 'FORBIDDEN';
    const SUBSCRIPTION_REQUIRED = 'SUBSCRIPTION_REQUIRED';
    const INSUFFICIENT_CREDITS = 'INSUFFICIENT_CREDITS';

    // Resource errors
    const NOT_FOUND = 'NOT_FOUND';
    const ALREADY_EXISTS = 'ALREADY_EXISTS';
    const CONFLICT = 'CONFLICT';

    // Rate limiting errors
    const RATE_LIMITED = 'RATE_LIMITED';

    // Database errors
    const DATABASE_ERROR = 'DATABASE_ERROR';
    const QUERY_ERROR = 'QUERY_ERROR';
    const INSERT_ERROR = 'INSERT_ERROR';
    const UPDATE_ERROR = 'UPDATE_ERROR';
    const DELETE_ERROR = 'DELETE_ERROR';

    // Server errors
    const INTERNAL_ERROR = 'INTERNAL_ERROR';
    const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    const INVALID_REQUEST = 'INVALID_REQUEST';

    // Business logic errors
    const DUPLICATE_PLAYER = 'DUPLICATE_PLAYER';
    const INVALID_GROUP = 'INVALID_GROUP';
    const INVALID_MATCH = 'INVALID_MATCH';
    const INVALID_INVITE = 'INVALID_INVITE';

    // SMS/Twilio errors
    const SMS_SEND_FAILED = 'SMS_SEND_FAILED';
    const INVALID_PHONE_NUMBER = 'INVALID_PHONE_NUMBER';

    // Get human-readable message for error code
    public static function getMessage($code) {
        $messages = [
            self::INVALID_INPUT => 'Invalid input provided',
            self::MISSING_FIELD => 'Required field is missing',
            self::INVALID_EMAIL => 'Invalid email address',
            self::INVALID_PHONE => 'Invalid phone number',
            self::INVALID_INTEGER => 'Field must be an integer',
            self::STRING_TOO_SHORT => 'String is too short',
            self::STRING_TOO_LONG => 'String is too long',
            self::UNAUTHORIZED => 'Unauthorized access',
            self::INVALID_CREDENTIALS => 'Invalid credentials',
            self::TOKEN_EXPIRED => 'Authentication token has expired',
            self::TOKEN_INVALID => 'Invalid authentication token',
            self::FORBIDDEN => 'Access denied',
            self::SUBSCRIPTION_REQUIRED => 'Active subscription required',
            self::INSUFFICIENT_CREDITS => 'Insufficient SMS credits',
            self::NOT_FOUND => 'Resource not found',
            self::ALREADY_EXISTS => 'Resource already exists',
            self::CONFLICT => 'Request conflicts with existing data',
            self::RATE_LIMITED => 'Too many requests. Please try again later',
            self::DATABASE_ERROR => 'Database error occurred',
            self::QUERY_ERROR => 'Query execution error',
            self::INSERT_ERROR => 'Failed to insert record',
            self::UPDATE_ERROR => 'Failed to update record',
            self::DELETE_ERROR => 'Failed to delete record',
            self::INTERNAL_ERROR => 'Internal server error',
            self::SERVICE_UNAVAILABLE => 'Service temporarily unavailable',
            self::INVALID_REQUEST => 'Invalid request',
            self::DUPLICATE_PLAYER => 'Duplicate player record',
            self::INVALID_GROUP => 'Invalid group',
            self::INVALID_MATCH => 'Invalid match',
            self::INVALID_INVITE => 'Invalid invite',
            self::SMS_SEND_FAILED => 'Failed to send SMS',
            self::INVALID_PHONE_NUMBER => 'Invalid phone number format',
        ];

        return $messages[$code] ?? 'Unknown error';
    }
}
?>
