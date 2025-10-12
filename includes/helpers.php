<?php
if (!defined('LINKER_SECURE_INCLUDE')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

/**
 * Send a JSON response with the given data and status code.
 */
function linker_json_response($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Normalize a boolean-ish value returned from the database.
 */
function linker_boolval($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (bool)$value;
    }

    if (is_string($value)) {
        $value = strtolower(trim($value));
        return in_array($value, ['1', 'true', 't', 'yes', 'y', 'on'], true);
    }

    return false;
}

/**
 * Cast numeric fields to int/float if possible.
 */
function linker_cast_numeric(array $record, array $floatFields = [], array $intFields = []): array
{
    foreach ($floatFields as $field) {
        if (isset($record[$field]) && $record[$field] !== null && $record[$field] !== '') {
            $record[$field] = (float)$record[$field];
        }
    }

    foreach ($intFields as $field) {
        if (isset($record[$field]) && $record[$field] !== null && $record[$field] !== '') {
            $record[$field] = (int)$record[$field];
        }
    }

    return $record;
}
