<?php

namespace App\Models\Concerns;

use LogicException;

trait ProtectsFinalSalesDocument
{
    protected static function bootProtectsFinalSalesDocument(): void
    {
        static::updating(function ($document) {
            if ($document->getOriginal('status') !== 'draft' && ! app()->bound('sales.system-write')) {
                throw new LogicException('Final sales documents are immutable.');
            }
        });
        static::deleting(function ($document) {
            if ($document->status !== 'draft') {
                throw new LogicException('Final sales documents cannot be deleted.');
            }
        });
    }
}
