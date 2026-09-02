<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PriorPeriodAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PriorPeriodAdjustmentService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(Company $entity, array $data, User $user): PriorPeriodAdjustment
    {
        $origin = $entity->financialYears()->findOrFail($data['origin_financial_year_id']);
        $adjustment = $entity->financialYears()->findOrFail($data['adjustment_financial_year_id']);
        if (! in_array($origin->status, ['closed', 'filed'], true)) {
            throw ValidationException::withMessages(['origin_financial_year_id' => 'A Prior-Period Adjustment requires a Closed or Filed origin Financial Year.']);
        }
        if ($adjustment->status !== 'open') {
            throw ValidationException::withMessages(['adjustment_financial_year_id' => 'The adjustment Financial Year must be open.']);
        }

        return DB::transaction(function () use ($entity, $data, $user) {
            $record = PriorPeriodAdjustment::create($data + ['company_id' => $entity->id, 'status' => 'draft', 'created_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->log('prior_period_adjustment.created', $record, $entity->id, $user->id);

            return $record;
        });
    }
}
