<?php

namespace App\ImportExport;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class FileParser
{
    public function worksheets(string $path, string $format): array
    {
        if ($format === 'csv') {
            return ['CSV'];
        }

        try {
            return IOFactory::createReaderForFile($path)->listWorksheetNames($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'The uploaded workbook is unreadable or malformed.']);
        }
    }

    /** @return array{headers:array,rows:array} */
    public function parse(string $path, string $format, ?string $worksheet = null): array
    {
        if ($format === 'csv') {
            return $this->parseCsv($path);
        }
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            if ($format === 'xlsx' && $worksheet) {
                $reader->setLoadSheetsOnly([$worksheet]);
            }
            $spreadsheet = $reader->load($path);
            $sheet = $worksheet ? $spreadsheet->getSheetByName($worksheet) : $spreadsheet->getActiveSheet();
            if (! $sheet) {
                throw ValidationException::withMessages(['worksheet' => 'Select a worksheet that exists in this workbook.']);
            }
            $matrix = $sheet->toArray(null, false, false, false);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is unreadable or malformed.']);
        }

        if (! $matrix || ! array_filter($matrix[0] ?? [], fn ($value) => trim((string) $value) !== '')) {
            throw ValidationException::withMessages(['file' => 'The file is empty or has no header row.']);
        }
        $headers = array_map(fn ($value) => trim((string) $value), array_shift($matrix));
        if (count(array_filter($headers)) !== count($headers) || count(array_unique(array_map('mb_strtolower', $headers))) !== count($headers)) {
            throw ValidationException::withMessages(['file' => 'Headers must be non-empty and unique.']);
        }
        $rows = [];
        foreach ($matrix as $index => $values) {
            if (! array_filter($values, fn ($value) => $value !== null && trim((string) $value) !== '')) {
                continue;
            }
            if (count($values) > count($headers) && array_filter(array_slice($values, count($headers)))) {
                throw ValidationException::withMessages(['file' => 'Source row '.($index + 2).' has more values than the header row.']);
            }
            $rows[] = ['number' => $index + 2, 'values' => array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), null))];
            if (count($rows) > config('imports.max_rows')) {
                throw ValidationException::withMessages(['file' => 'The file exceeds the configured '.config('imports.max_rows').' row limit.']);
            }
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The file contains headers but no data rows.']);
        }

        return compact('headers', 'rows');
    }

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'The uploaded CSV is unreadable.']);
        }
        try {
            $matrix = [];
            while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                $matrix[] = $row;
            }
        } finally {
            fclose($handle);
        }
        if ($matrix) {
            $matrix[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($matrix[0][0] ?? ''));
        }
        if (! $matrix || ! array_filter($matrix[0] ?? [], fn ($value) => trim((string) $value) !== '')) {
            throw ValidationException::withMessages(['file' => 'The file is empty or has no header row.']);
        }
        $headers = array_map(fn ($value) => trim((string) $value), array_shift($matrix));
        if (count(array_filter($headers)) !== count($headers) || count(array_unique(array_map('mb_strtolower', $headers))) !== count($headers)) {
            throw ValidationException::withMessages(['file' => 'Headers must be non-empty and unique.']);
        }
        $rows = [];
        foreach ($matrix as $index => $values) {
            if (! array_filter($values, fn ($value) => $value !== null && trim((string) $value) !== '')) {
                continue;
            }
            if (count($values) !== count($headers)) {
                throw ValidationException::withMessages(['file' => 'Source row '.($index + 2).' has an unexpected number of values.']);
            }
            $rows[] = ['number' => $index + 2, 'values' => array_combine($headers, $values)];
            if (count($rows) > config('imports.max_rows')) {
                throw ValidationException::withMessages(['file' => 'The file exceeds the configured '.config('imports.max_rows').' row limit.']);
            }
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The file contains headers but no data rows.']);
        }

        return compact('headers', 'rows');
    }
}
