@extends('layouts.app')
@section('content')
<h1 class="mb-4">Accounting Reports</h1>
<div class="card p-4">
<form method="get" class="row g-3">
    <div class="col-lg-4"><label class="form-label">Country / Jurisdiction</label><select id="report-country" name="country_id" class="form-select" onchange="changeReportCountry(this.form)">@foreach($countries as $item)<option value="{{ $item->id }}" @selected($country?->id===$item->id)>{{ $item->name }}</option>@endforeach</select></div>
    <div class="col-lg-4"><label class="form-label">Company</label><select id="report-company" name="company_id" class="form-select" onchange="changeReportCompany(this.form)">@foreach($companies as $c)<option value="{{ $c->id }}" @selected($company?->id===$c->id)>{{ $c->name }}</option>@endforeach</select></div>
    <div class="col-lg-4"><label class="form-label">Branch</label><select id="report-branch" name="branch_id" class="form-select" @disabled(! $company?->supportsBranches())><option value="">{{ $company?->supportsBranches() ? 'All branches (consolidated)' : 'Not applicable' }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($selectedBranchId===$branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>@endforeach</select></div>
    <div class="col-lg-4"><label class="form-label">Financial Year</label><select id="report-year" name="financial_year_id" class="form-select"><option value="">Current Financial Year (automatic)</option><option value="all" @selected($selectedFinancialYearId==='all')>All Financial Years (explicit)</option>@foreach($financialYears as $year)<option value="{{ $year->id }}" @selected($selectedFinancialYearId===$year->id)>{{ $year->name }} ({{ ucfirst($year->status) }})</option>@endforeach</select></div>
    <div class="col-md-4 col-lg-2"><label class="form-label">From</label><input type="date" name="from" value="{{ request('from') }}" class="form-control"></div>
    <div class="col-md-4 col-lg-2"><label class="form-label">To</label><input type="date" name="to" value="{{ request('to') }}" class="form-control"></div>
    <div class="col-lg-4"><label class="form-label">Account (General Ledger)</label><select id="report-account" name="account_id" class="form-select">@foreach($accounts as $account)<option value="{{ $account->id }}" @selected($selectedAccountId===$account->id)>{{ $account->code }} — {{ $account->name }}</option>@endforeach</select></div>
    <div class="col-12 d-flex gap-2 flex-wrap pt-2"><button formaction="{{ route('reports.ledger') }}" class="btn btn-primary">General Ledger</button><button formaction="{{ route('reports.trial') }}" class="btn btn-primary">Trial Balance</button><button formaction="{{ route('reports.profit-loss') }}" class="btn btn-primary">Profit & Loss</button><button formaction="{{ route('reports.balance-sheet') }}" class="btn btn-primary">Balance Sheet</button></div>
</form></div>
<script>
function submitReportContext(form, dependentFields) {
    dependentFields.forEach(name => {
        const field = form.elements.namedItem(name);
        if (field) field.disabled = true;
    });
    form.submit();
}

function changeReportCountry(form) {
    submitReportContext(form, ['company_id', 'branch_id', 'financial_year_id', 'account_id']);
}

function changeReportCompany(form) {
    submitReportContext(form, ['branch_id', 'financial_year_id', 'account_id']);
}
</script>
@endsection
