@php
    $savedPurchaseTaxLines = old('lines', $document?->lines?->map(fn ($line) => [
        'tax_code_id' => $line->tax_code_id,
        'tax_inclusive' => $line->tax_inclusive,
    ])->all() ?? []);
    $purchaseTaxCodes = $taxCodes->map->only(['id', 'code', 'name', 'treatment'])->values();
@endphp
<script>
    (() => {
        const codes = @json($purchaseTaxCodes);
        const saved = @json($savedPurchaseTaxLines);
        const container = document.querySelector('#lines');
        if (!container) return;

        function addTaxControls(row, position) {
            if (row.querySelector('.purchase-tax-code')) return;
            const quantity = row.querySelector('[name*="[quantity]"]');
            const match = quantity?.name.match(/^lines\[([^\]]+)]/);
            if (!match) return;
            const index = match[1];
            const current = saved[position] || {};
            const wrapper = document.createElement('div');
            wrapper.className = 'col-md-3';
            const options = codes.map(code => `<option value="${code.id}" ${String(current.tax_code_id || '') === String(code.id) ? 'selected' : ''}>${code.code} — ${code.name}</option>`).join('');
            wrapper.innerHTML = `<label class="form-label">Tax Code</label><select class="form-select purchase-tax-code" name="lines[${index}][tax_code_id]"><option value="">Use configured default / no tax</option>${options}</select><label class="small mt-1"><input type="checkbox" name="lines[${index}][tax_inclusive]" value="1" ${current.tax_inclusive ? 'checked' : ''}> Price includes tax</label>`;
            row.insertBefore(wrapper, row.lastElementChild);
        }

        function refresh() {
            container.querySelectorAll('.purchase-line').forEach(addTaxControls);
        }

        new MutationObserver(refresh).observe(container, { childList: true });
        refresh();
    })();
</script>
