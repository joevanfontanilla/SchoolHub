<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Converts PHP errors and uncaught exceptions into safe responses.
 *
 * Internal details are logged, never displayed (docs/00, docs/18).
 */
final class ErrorHandler
{
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleException(Throwable $e): void
    {
        $status = $e instanceof HttpException ? $e->status() : 500;

        if ($status >= 500) {
            self::log($e);
        }

        if (headers_sent()) {
            return;
        }

        $request = new Request();
        [$status, $message, $detail] = self::describe($e);

        if ($request->expectsJson()) {
            Response::json(['success' => false, 'message' => $message], $status);
            return;
        }

        try {
            Response::html(View::render('errors/error', [
                'status'  => $status,
                'message' => $message,
                'detail'  => $detail,
            ], 'layouts/plain'), $status);
        } catch (Throwable) {
            // The error page itself failed — fall back to plain text.
            Response::html('<h1>' . $status . '</h1><p>' . e($message) . '</p>', $status);
        }
    }

    /**
     * What a visitor is told about an error: its status, a message, and (debug only)
     * the technical detail. A deliberate HttpException keeps its plain message; any other
     * failure says only that something went wrong, unless debug is on.
     *
     * @return array{0:int,1:string,2:?string}
     */
    public static function describe(Throwable $e): array
    {
        $status = $e instanceof HttpException ? $e->status() : 500;
        $debug = (bool) Config::get('app.debug', false);
        $message = $e instanceof HttpException
            ? $e->getMessage()
            : ($debug ? $e->getMessage() : 'An unexpected error occurred. The problem has been logged.');

        return [$status, $message, ($debug && $status >= 500) ? self::detail($e) : null];
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::logLine(sprintf('FATAL %s in %s:%d', $error['message'], $error['file'], $error['line']));
        }
    }

    private static function detail(Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage()
            . "\n" . $e->getFile() . ':' . $e->getLine()
            . "\n\n" . $e->getTraceAsString();
    }

    private static function log(Throwable $e): void
    {
        self::logLine(sprintf(
            '%s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    private static function logLine(string $line): void
    {
        $path = STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log';
        @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }
}
