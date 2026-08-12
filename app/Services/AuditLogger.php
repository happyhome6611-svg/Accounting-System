<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    public function log(string $event, Model $model, int $companyId, int $userId, ?array $old = null, ?array $new = null): void
    {
        AuditLog::create(['company_id' => $companyId, 'user_id' => $userId, 'event' => $event, 'auditable_type' => $model::class, 'auditable_id' => $model->getKey(), 'old_values' => $old, 'new_values' => $new, 'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent()]);
    }
}
