<script>
    (() => {
        const itemDefaults = @json($items->pluck('default_sales_tax_code_id', 'id'));
        const customerDefaults = @json($customers->pluck('default_sales_tax_code_id', 'id'));
        const entityDefault = @json($company->taxSetting()->value('default_sales_tax_code_id'));
        const body = document.querySelector('#sales-lines');
        const customer = document.querySelector('[name="customer_id"]');
        if (!body) return;

        function suggested(row) {
            return itemDefaults[row.querySelector('.item-select')?.value] || customerDefaults[customer?.value] || entityDefault || '';
        }

        function apply(row) {
            const field = row.querySelector('.tax-code');
            if (field && !field.value) field.value = suggested(row);
        }

        body.addEventListener('change', event => {
            if (event.target.matches('.item-select')) {
                const field = event.target.closest('tr').querySelector('.tax-code');
                if (field) field.value = suggested(event.target.closest('tr'));
            }
        });
        customer?.addEventListener('change', () => body.querySelectorAll('tr').forEach(apply));
        new MutationObserver(() => body.querySelectorAll('tr').forEach(apply)).observe(body, { childList: true });
        body.querySelectorAll('tr').forEach(apply);
    })();
</script>
