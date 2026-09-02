<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Services\BranchService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    private function company(Request $request, Company $company): Company
    {
        $company = $request->user()->companies()->findOrFail($company->id);
        abort_unless($company->supportsBranches(), 404);

        return $company;
    }

    public function index(Request $request, Company $company, BranchService $service)
    {
        $company = $this->company($request, $company);
        $branches = $company->branches()->orderByDesc('is_main_branch')->orderBy('code')->get();

        return view('companies.branches.index', compact('company', 'branches', 'service'));
    }

    public function create(Request $request, Company $company)
    {
        $company = $this->company($request, $company);

        return view('companies.branches.form', compact('company') + ['branch' => new Branch]);
    }

    public function store(Request $request, Company $company, BranchService $service)
    {
        $company = $this->company($request, $company);
        $service->create($company, $request->validate($this->rules($company)), $request->user());

        return redirect()->route('companies.branches.index', $company)->with('success', 'Branch created.');
    }

    public function edit(Request $request, Company $company, Branch $branch)
    {
        $company = $this->company($request, $company);
        abort_unless($branch->company_id === $company->id, 404);

        return view('companies.branches.form', compact('company', 'branch'));
    }

    public function update(Request $request, Company $company, Branch $branch, BranchService $service)
    {
        $company = $this->company($request, $company);
        abort_unless($branch->company_id === $company->id, 404);
        $service->update($company, $branch, $request->validate($this->rules($company, $branch)), $request->user());

        return redirect()->route('companies.branches.index', $company)->with('success', 'Branch updated.');
    }

    public function status(Request $request, Company $company, Branch $branch, BranchService $service)
    {
        $company = $this->company($request, $company);
        abort_unless($branch->company_id === $company->id, 404);
        $data = $request->validate(['is_active' => 'required|boolean']);
        $service->setActive($company, $branch, (bool) $data['is_active'], $request->user());

        return back()->with('success', $data['is_active'] ? 'Branch reactivated.' : 'Branch deactivated.');
    }

    public function destroy(Request $request, Company $company, Branch $branch, BranchService $service)
    {
        $company = $this->company($request, $company);
        abort_unless($branch->company_id === $company->id, 404);
        $data = $request->validate(['confirmation_name' => 'required|string']);
        $service->delete($company, $branch, $data['confirmation_name'], $request->user());

        return redirect()->route('companies.branches.index', $company)->with('success', 'Unused branch permanently deleted.');
    }

    private function rules(Company $company, ?Branch $branch = null): array
    {
        return ['code' => ['required', 'string', 'max:40', Rule::unique('branches')->where('company_id', $company->id)->ignore($branch?->id)], 'name' => 'required|string|max:255', 'legal_name' => 'nullable|string|max:255', 'address' => 'nullable|string', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:40', 'timezone' => 'nullable|timezone', 'is_main_branch' => 'sometimes|boolean'];
    }
}
