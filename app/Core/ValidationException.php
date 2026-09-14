<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Thrown by a service when a business rule refuses an action.
 *
 * Carries field-keyed errors for forms, and a summary message for flash
 * notices. Controllers catch it and redirect back — nothing has been changed,
 * because services throw before (or inside a rolled-back) transaction.
 */
final class ValidationException extends RuntimeException
{
    /** @param array<string,array<int,string>> $errors */
    public function __construct(string $message, private array $errors = [])
    {
        parent::__construct($message);
    }

    public static function withMessage(string $message): self
    {
        return new self($message);
    }

    /** @param array<string,array<int,string>> $errors */
    public static function withErrors(array $errors, string $message = 'Please correct the highlighted fields.'): self
    {
        return new self($message, $errors);
    }

    /** @return array<string,array<int,string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
