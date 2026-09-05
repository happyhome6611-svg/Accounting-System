<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ItemMaintenanceService
{
    private const LINKS = ['sales_quotation_lines' => 'sales quotations', 'sales_order_lines' => 'sales orders', 'sales_invoice_lines' => 'sales invoices', 'sales_credit_note_lines' => 'sales credit notes'];

    public function __construct(private AuditLogger $audit, private TaxDefaultService $taxDefaults) {}

    public function blockers(Item $item): array
    {
        return collect(self::LINKS)->filter(fn ($label, $table) => DB::table($table)->where('item_id', $item->id)->exists())->values()->all();
    }

    public function isDeletable(Item $item): bool
    {
        return $this->blockers($item) === [];
    }

    public function update(Company $c, Item $item, array $data, User $u): Item
    {
        $this->access($c, $item, $u);
        $c->accounts()->findOrFail($data['revenue_account_id']);
        $this->taxDefaults->validate($c, $data['default_sales_tax_code_id'] ?? null);
        $this->taxDefaults->validate($c, $data['default_purchase_tax_code_id'] ?? null);

        return DB::transaction(function () use ($c, $item, $data, $u) {
            $old = $item->toArray();
            $item->update([...$data, 'updated_by' => $u->id]);
            $this->audit->log('item.updated', $item, $c->id, $u->id, $old, $item->fresh()->toArray());

            return $item->fresh();
        });
    }

    public function setActive(Company $c, Item $item, bool $active, User $u): Item
    {
        $this->access($c, $item, $u);

        return DB::transaction(function () use ($c, $item, $active, $u) {
            $item->update(['is_active' => $active, 'updated_by' => $u->id]);
            $this->audit->log($active ? 'item.reactivated' : 'item.deactivated', $item, $c->id, $u->id, null, ['is_active' => $active]);

            return $item;
        });
    }

    public function delete(Company $c, Item $item, User $u, string $confirmation): void
    {
        $this->access($c, $item, $u);
        if (! hash_equals($item->name, $confirmation)) {
            throw ValidationException::withMessages(['confirmation_name' => 'Enter the exact item name.']);
        }DB::transaction(function () use ($c, $item) {
            $locked = Item::withTrashed()->where('company_id', $c->id)->lockForUpdate()->findOrFail($item->id);
            $blockers = $this->blockers($locked);
            if ($blockers) {
                throw ValidationException::withMessages(['item' => 'This item is referenced by '.implode(', ', $blockers).'. Deactivate it instead.']);
            }DB::table('audit_logs')->where('company_id', $c->id)->where('auditable_type', Item::class)->where('auditable_id', $locked->id)->delete();
            $locked->forceDelete();
        });
    }

    private function access(Company $c, Item $item, User $u): void
    {
        abort_unless($u->companies()->whereKey($c->id)->exists() && $item->company_id === $c->id, 404);
    }
}
