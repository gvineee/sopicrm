<?php

namespace App\Http\Controllers\Companies;

use App\Domain\Companies\Actions\CreateCompanyAction;
use App\Domain\Companies\Actions\DeleteCompanyAction;
use App\Domain\Companies\Actions\UpdateCompanyAction;
use App\Domain\Companies\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\CompanyRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Company::class);
        $user = $request->user();

        $companies = Company::query()
            ->when(
                ! $user->can('companies.manage'),
                fn ($query) => $query->whereHas('memberships', fn ($memberships) => $memberships->where('user_id', $user->id)),
            )
            ->withCount(['memberships', 'users'])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company): array => $this->resource($company));

        return Inertia::render('Companies/Index', [
            'companies' => $companies,
            'currentCompanyId' => $user->current_company_id,
            'canManage' => $user->can('create', Company::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Company::class);

        return Inertia::render('Companies/Create');
    }

    public function store(CompanyRequest $request, CreateCompanyAction $action): RedirectResponse
    {
        $this->authorize('create', Company::class);
        $company = $action->execute($request->companyData(), $request->user());

        // Return to the stable list route after creation. The edit route is
        // intentionally separate and can be opened from the rendered row;
        // redirecting there immediately made a newly-created tenant row look
        // like a 404 when a stale session had not yet refreshed its scope.
        return to_route('companies.index')->with('toast', [
            'type' => 'success',
            'message' => 'კომპანია დაემატა.',
        ]);
    }

    public function edit(Company $company): Response
    {
        $this->authorize('update', $company);

        return Inertia::render('Companies/Edit', ['company' => $this->resource($company)]);
    }

    public function update(CompanyRequest $request, Company $company, UpdateCompanyAction $action): RedirectResponse
    {
        $this->authorize('update', $company);
        $action->execute($company, $request->companyData(), $request->user());

        return to_route('companies.index')->with('toast', [
            'type' => 'success',
            'message' => 'კომპანიის მონაცემები განახლდა.',
        ]);
    }

    public function destroy(Request $request, Company $company, DeleteCompanyAction $action): RedirectResponse
    {
        $this->authorize('delete', $company);
        $action->execute($company, $request->user());

        return to_route('companies.index')->with('toast', [
            'type' => 'success',
            'message' => 'კომპანია წაიშალა.',
        ]);
    }

    /** @return array<string, mixed> */
    private function resource(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'code' => $company->code,
            'default_currency' => $company->default_currency,
            'default_timezone' => $company->default_timezone,
            'is_active' => $company->is_active,
            'memberships_count' => $company->memberships_count ?? null,
            'users_count' => $company->users_count ?? null,
        ];
    }
}
