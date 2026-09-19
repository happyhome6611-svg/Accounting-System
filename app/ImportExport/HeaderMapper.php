<?php

namespace App\ImportExport;

final class HeaderMapper
{
    public function exactSuggestions(array $headers, array $fields): array
    {
        $suggestions = [];
        foreach ($headers as $header) {
            $candidate = mb_strtolower(trim($header));
            $matches = collect($fields)->filter(fn ($definition, $field) => in_array($candidate, [
                mb_strtolower(trim((string) $field)),
                mb_strtolower(trim($definition['label'])),
            ], true));
            if ($matches->count() === 1) {
                $suggestions[$header] = $matches->keys()->first();
            }
        }

        return $suggestions;
    }

    public function canAutoMap(array $headers, array $fields): bool
    {
        $mapping = $this->exactSuggestions($headers, $fields);
        if (count($mapping) !== count($headers)) {
            return false;
        }

        $targets = array_values($mapping);

        return collect($fields)->every(fn ($definition, $field) => ! $definition['required'] || in_array($field, $targets, true));
    }

    public function suggestions(array $headers, array $fields): array
    {
        $suggestions = [];
        foreach ($headers as $header) {
            $normalized = $this->normalize($header);
            $matches = collect($fields)->filter(fn ($definition, $field) => in_array($normalized, array_map(fn ($candidate) => $this->normalize($candidate), [$field, $definition['label'], ...$definition['aliases']]), true));
            if ($matches->count() === 1) {
                $suggestions[$header] = $matches->keys()->first();
            }
        }

        return $suggestions;
    }

    public function compatible(array $expected, array $actual): bool
    {
        return array_map([$this, 'normalize'], $expected) === array_map([$this, 'normalize'], $actual);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value)));
    }
}
