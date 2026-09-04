<?php

namespace App\Models\Concerns;

use LogicException;

trait ProtectsPostedPurchaseDocument
{
    protected static function bootProtectsPostedPurchaseDocument(): void
    {
        static::updating(function ($document) {
            if ($document->getOriginal('status') !== 'draft' && ! app()->bound('purchases.system-write')) {
                throw new LogicException('Posted purchase documents are immutable.');
            }
        });
        static::deleting(function ($document) {
            if ($document->status !== 'draft') {
                throw new LogicException('Posted purchase documents cannot be deleted.');
            }
        });
    }
}
