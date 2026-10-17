<?php

namespace App\Actions\Relief;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams rows as a CSV download that opens correctly in Excel, including names with accents. A cell that starts
 * like a spreadsheet formula is marked as text, so a name typed as "=1+1" cannot run anything when opened.
 */
class CsvExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|bool|null>>  $rows
     */
    public function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // The byte order mark tells Excel the file is UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (mixed $cell): string => $this->safe($cell), $row), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safe(mixed $cell): string
    {
        $text = match (true) {
            $cell === null => '',
            is_bool($cell) => $cell ? 'Yes' : 'No',
            default => (string) $cell,
        };

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }
}
