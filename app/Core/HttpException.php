<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Thrown by middleware and controllers to end a request with a status code.
 */
class HttpException extends RuntimeException
{
    public function __construct(private int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status));
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function unauthorized(string $message = ''): self
    {
        return new self(401, $message);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400     => 'Bad request.',
            401     => 'You need to sign in to continue.',
            403     => 'You are not allowed to perform this action.',
            404     => 'The page you requested was not found.',
            429     => 'Too many attempts. Please wait and try again.',
            default => 'Something went wrong.',
        };
    }
}
