<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Spreadsheet export with native fputcsv (docs/00: no library).
 *
 * Every text cell that a spreadsheet would read as a formula (starting with =, +,
 * -, @, a tab, or a carriage return) is prefixed with an apostrophe, so a student's
 * name or a reference number can never run as a formula when the file is opened
 * (CSV injection). Numbers, including negative amounts, are left as numbers.
 */
final class CsvExport
{
    /**
     * @param array<int,string>              $header
     * @param iterable<array<int,mixed>>     $rows
     */
    public static function send(string $filename, array $header, iterable $rows): void
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'export.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, so Excel shows ₱ and ñ correctly
        self::write($out, $header, $rows);
        fclose($out);
        exit;
    }

    /**
     * @param resource                   $handle
     * @param array<int,string>          $header
     * @param iterable<array<int,mixed>> $rows
     */
    public static function write($handle, array $header, iterable $rows): void
    {
        fputcsv($handle, array_map([self::class, 'cell'], $header));
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'cell'], $row));
        }
    }

    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = (string) $value;
        if (is_int($value) || is_float($value) || preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return $text;
        }
        return preg_match('/^[=+\-@\t\r]/', $text) ? "'" . $text : $text;
    }
}
