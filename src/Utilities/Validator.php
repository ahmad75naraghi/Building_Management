<?php

declare(strict_types=1);

namespace App\Utilities;

final class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            if (str_contains($rule, 'required') && empty($value) && $value !== '0') {
                $errors[$field] = "{$field} is required";
            }
            if (str_contains($rule, 'email') && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = "{$field} must be a valid email";
            }
            if (str_contains($rule, 'min:') && $value) {
                $min = (int) explode(':', $rule)[1];
                if (strlen($value) < $min) {
                    $errors[$field] = "{$field} must be at least {$min} characters";
                }
            }
        }
        return $errors;
    }
}
