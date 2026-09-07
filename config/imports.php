<?php

return [
    'max_file_kb' => 10240,
    'max_rows' => 10000,
    'preview_rows' => 100,
    'disk' => env('IMPORT_DISK', 'local'),
    'path' => 'private/imports',
];
