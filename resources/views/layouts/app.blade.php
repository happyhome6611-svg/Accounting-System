<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7fb; }
        .navbar-brand { font-weight: 700; }
        .card { border: 0; box-shadow: 0 .25rem 1rem rgba(20, 40, 70, .08); }
        .sidebar a { color: #30415d; text-decoration: none; padding: .65rem .8rem; border-radius: .4rem; }
        .sidebar a:hover { background: #e8eef7; }
        .sales-decimal-input,
        #sales-lines input[type="number"] { appearance: textfield; -moz-appearance: textfield; }
        .sales-decimal-input::-webkit-inner-spin-button,
        .sales-decimal-input::-webkit-outer-spin-button,
        #sales-lines input[type="number"]::-webkit-inner-spin-button,
        #sales-lines input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-primary px-4">
        <a class="navbar-brand" href="{{ route('dashboard') }}">{{ config('app.name') }}</a>
        @auth
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button class="btn btn-outline-light btn-sm">Sign out</button>
            </form>
        @endauth
    </nav>
    <div class="container-fluid">
        <div class="row">
            @auth
                <aside class="col-md-2 bg-white min-vh-100 p-3 sidebar d-flex flex-column gap-1">
                    @foreach (['dashboard' => 'Dashboard', 'companies.index' => 'Accounting Entities', 'accounting' => 'Accounting', 'sales' => 'Sales', 'purchases' => 'Purchases', 'banking' => 'Banking', 'period-end' => 'Period-End', 'tax' => 'Tax', 'reports' => 'Reports', 'settings' => 'Settings'] as $route => $label)
                        <a href="{{ route($route) }}">{{ $label }}</a>
                    @endforeach
                </aside>
            @endauth
            <main class="{{ auth()->check() ? 'col-md-10' : 'col-12' }} p-4">
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @yield('content')
            </main>
        </div>
    </div>
    @includeWhen(isset($taxCodes, $type) && in_array($type, ['bills', 'credits'], true), 'purchases.tax-line-controls')
    @includeWhen(isset($taxCodes, $items, $customers, $type, $company) && in_array($type, ['invoices', 'credit-notes'], true), 'sales.transactions.tax-defaults')
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('wheel', function (event) {
            if (event.target.matches('.sales-decimal-input, #sales-lines input[type="number"]')) {
                event.preventDefault();
            }
        }, { passive: false });
        document.addEventListener('keydown', function (event) {
            if (event.target.matches('.sales-decimal-input, #sales-lines input[type="number"]')
                && ['ArrowUp', 'ArrowDown'].includes(event.key)) {
                event.preventDefault();
            }
        });
    </script>
</body>
</html>
