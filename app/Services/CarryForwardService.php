<?php

namespace App\Services;

use App\Models\CarryForward;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CarryForwardService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(Company $entity, array $data, User $user): CarryForward
    {
        foreach (['source_financial_year_id', 'destination_financial_year_id'] as $key) {
            if (! empty($data[$key]) && ! $entity->financialYears()->whereKey($data[$key])->exists()) {
                throw ValidationException::withMessages([$key => 'Financial Year must belong to this accounting entity.']);
            }
        }
        foreach (['source_tax_year_id', 'destination_tax_year_id'] as $key) {
            if (! empty($data[$key]) && ! $entity->taxYears()->whereKey($data[$key])->exists()) {
                throw ValidationException::withMessages([$key => 'Tax Year must belong to this accounting entity.']);
            }
        }
        if (empty($data['source_financial_year_id']) && empty($data['source_tax_year_id'])) {
            throw ValidationException::withMessages(['source' => 'A source Financial Year or Tax Year is required.']);
        }
        if (empty($data['destination_financial_year_id']) && empty($data['destination_tax_year_id'])) {
            throw ValidationException::withMessages(['destination' => 'A destination Financial Year or Tax Year is required.']);
        }

        return DB::transaction(function () use ($entity, $data, $user) {
            $amount = bcadd((string) $data['original_amount'], '0', 4);
            $record = CarryForward::create($data + ['company_id' => $entity->id, 'amount_used' => '0.0000', 'amount_remaining' => $amount, 'status' => 'available', 'created_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->log('carry_forward.created', $record, $entity->id, $user->id);

            return $record;
        });
    }

    public function use(CarryForward $record, string $amount, User $user): CarryForward
    {
        if (bccomp($amount, '0', 4) <= 0 || bccomp($amount, $record->amount_remaining, 4) > 0) {
            throw ValidationException::withMessages(['amount' => 'Usage must be positive and cannot exceed the remaining balance.']);
        }

        return DB::transaction(function () use ($record, $amount, $user) {
            $remaining = bcsub($record->amount_remaining, $amount, 4);
            $record->update(['amount_used' => bcadd($record->amount_used, $amount, 4), 'amount_remaining' => $remaining, 'status' => bccomp($remaining, '0', 4) === 0 ? 'exhausted' : 'partially_used', 'updated_by' => $user->id]);
            $this->audit->log('carry_forward.used', $record, $record->company_id, $user->id, null, ['amount' => $amount, 'remaining' => $remaining]);

            return $record;
        });
    }
}
