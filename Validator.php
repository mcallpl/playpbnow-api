<?php
/**
 * Validator - Input validation layer
 * Validates user input against rules and throws ValidationException on failure
 */

class ValidationException extends Exception {
    public $errors = [];

    public function __construct($message = "", $code = 0, $errors = []) {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }
}

class Validator {
    /**
     * Validate input data against rules
     *
     * @param array $input Data to validate
     * @param array $rules Validation rules (field => 'rule1|rule2')
     * @return array Validated data
     * @throws ValidationException
     */
    public static function validate($input, $rules) {
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $input[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $rule = trim($rule);

                // Parse rule with parameters (e.g., "min:5")
                $parts = explode(':', $rule);
                $ruleName = $parts[0];
                $ruleParam = $parts[1] ?? null;

                try {
                    self::applyRule($field, $value, $ruleName, $ruleParam);
                } catch (ValidationException $e) {
                    $errors[$field] = $e->getMessage();
                    break; // Stop checking this field after first error
                }
            }

            // If no errors, add to validated data
            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Validation failed', 0, $errors);
        }

        return $validated;
    }

    /**
     * Apply a single validation rule
     */
    private static function applyRule($field, $value, $rule, $param = null) {
        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || $value === []) {
                    throw new ValidationException("Field '{$field}' is required");
                }
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new ValidationException("Field '{$field}' must be a valid email");
                }
                break;

            case 'phone':
                if (!self::isValidPhone($value)) {
                    throw new ValidationException("Field '{$field}' must be a valid phone number");
                }
                break;

            case 'integer':
                if (!is_int($value) && !ctype_digit((string)$value)) {
                    throw new ValidationException("Field '{$field}' must be an integer");
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    throw new ValidationException("Field '{$field}' must be numeric");
                }
                break;

            case 'string':
                if (!is_string($value) && $value !== null) {
                    throw new ValidationException("Field '{$field}' must be a string");
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    throw new ValidationException("Field '{$field}' must be an array");
                }
                break;

            case 'boolean':
                if (!is_bool($value) && $value !== 'true' && $value !== 'false' && $value !== 0 && $value !== 1) {
                    throw new ValidationException("Field '{$field}' must be a boolean");
                }
                break;

            case 'min':
                if ($param === null) {
                    throw new ValidationException("Rule 'min' requires a parameter");
                }
                if (is_string($value) && strlen($value) < (int)$param) {
                    throw new ValidationException("Field '{$field}' must be at least {$param} characters");
                }
                if (is_numeric($value) && (int)$value < (int)$param) {
                    throw new ValidationException("Field '{$field}' must be at least {$param}");
                }
                break;

            case 'max':
                if ($param === null) {
                    throw new ValidationException("Rule 'max' requires a parameter");
                }
                if (is_string($value) && strlen($value) > (int)$param) {
                    throw new ValidationException("Field '{$field}' must not exceed {$param} characters");
                }
                if (is_numeric($value) && (int)$value > (int)$param) {
                    throw new ValidationException("Field '{$field}' must not exceed {$param}");
                }
                break;

            case 'regex':
                if ($param === null) {
                    throw new ValidationException("Rule 'regex' requires a parameter");
                }
                if (!preg_match($param, $value)) {
                    throw new ValidationException("Field '{$field}' format is invalid");
                }
                break;

            case 'in':
                if ($param === null) {
                    throw new ValidationException("Rule 'in' requires a parameter");
                }
                $allowedValues = explode(',', $param);
                $allowedValues = array_map('trim', $allowedValues);
                if (!in_array($value, $allowedValues, true)) {
                    throw new ValidationException("Field '{$field}' must be one of: " . implode(', ', $allowedValues));
                }
                break;

            case 'not_in':
                if ($param === null) {
                    throw new ValidationException("Rule 'not_in' requires a parameter");
                }
                $forbiddenValues = explode(',', $param);
                $forbiddenValues = array_map('trim', $forbiddenValues);
                if (in_array($value, $forbiddenValues, true)) {
                    throw new ValidationException("Field '{$field}' contains an invalid value");
                }
                break;

            default:
                throw new ValidationException("Unknown validation rule: {$rule}");
        }
    }

    /**
     * Check if phone number is valid (10 or 11 digits, with optional formatting)
     */
    private static function isValidPhone($phone) {
        if ($phone === null || $phone === '') {
            return false;
        }

        // Remove all non-digit characters
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // Must be 10 digits (US) or 11 digits (with country code)
        return strlen($clean) === 10 || strlen($clean) === 11;
    }
}
?>
