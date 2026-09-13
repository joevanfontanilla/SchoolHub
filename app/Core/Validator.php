<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Server-side validation. All validation happens here — never in a controller
 * and never only in the browser (docs/00).
 *
 * Usage:
 *   $v = Validator::make($request->all(), [
 *       'email'    => 'required|email|max:150',
 *       'password' => 'required|min:8',
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 */
final class Validator
{
    private array $errors = [];

    private function __construct(
        private array $data,
        private array $rules,
        private array $labels = []
    ) {
        $this->run();
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,array<int,string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $rules = explode('|', $ruleString);

            // "nullable" means: skip every other rule when the value is empty.
            $nullable = in_array('nullable', $rules, true);
            if ($nullable && $this->isEmpty($value)) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'nullable') {
                    continue;
                }
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
                $this->apply($field, $value, $name, $argument);
            }
        }
    }

    private function apply(string $field, mixed $value, string $rule, ?string $argument): void
    {
        $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {
            case 'required':
                if ($this->isEmpty($value)) {
                    $this->addError($field, "{$label} is required.");
                }
                break;

            case 'email':
                if (!$this->isEmpty($value) && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "{$label} must be a valid email address.");
                }
                break;

            case 'min':
                if (!$this->isEmpty($value) && mb_strlen((string) $value) < (int) $argument) {
                    $this->addError($field, "{$label} must be at least {$argument} characters.");
                }
                break;

            case 'max':
                if (!$this->isEmpty($value) && mb_strlen((string) $value) > (int) $argument) {
                    $this->addError($field, "{$label} must not exceed {$argument} characters.");
                }
                break;

            case 'numeric':
                if (!$this->isEmpty($value) && !is_numeric($value)) {
                    $this->addError($field, "{$label} must be a number.");
                }
                break;

            case 'integer':
                if (!$this->isEmpty($value) && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->addError($field, "{$label} must be a whole number.");
                }
                break;

            case 'in':
                $allowed = explode(',', (string) $argument);
                if (!$this->isEmpty($value) && !in_array((string) $value, $allowed, true)) {
                    $this->addError($field, "{$label} is not a valid selection.");
                }
                break;

            case 'confirmed':
                if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
                    $this->addError($field, "{$label} confirmation does not match.");
                }
                break;

            case 'date':
                if (!$this->isEmpty($value) && strtotime((string) $value) === false) {
                    $this->addError($field, "{$label} must be a valid date.");
                }
                break;

            case 'alpha_dash':
                if (!$this->isEmpty($value) && !preg_match('/^[A-Za-z0-9_.-]+$/', (string) $value)) {
                    $this->addError($field, "{$label} may contain only letters, numbers, dots, dashes and underscores.");
                }
                break;
        }
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }
}
