<?php

namespace App\Http\Controllers\Devices;

use App\Domain\Companies\Models\Company;
use App\Domain\Devices\Models\Site;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\AssignSiteCompanyRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TENANT-01: a minimal Sites screen scoped to this ticket's own next
 * slice — the "unmapped sites" admin list and the company-assignment
 * action. A full Sites CRUD is not this ticket's job (Sites are currently
 * only ever created by an earlier scaffold/seeder — no create/edit UI
 * exists anywhere yet, and adding one is out of scope here).
 */
class SiteController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Site::class);

        $sites = Site::query()
            ->with('company:id,name')
            ->withCount('devices')
            ->orderByRaw('company_id is not null') // unmapped (NULL) first
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'company_id', 'is_active']);

        return Inertia::render('Devices/Sites/Index', [
            'sites' => $sites->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'address' => $site->address,
                'is_active' => $site->is_active,
                'company_id' => $site->company_id,
                'company_name' => $site->company?->name,
                'device_count' => $site->devices_count,
            ]),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function assignCompany(AssignSiteCompanyRequest $request, Site $site): RedirectResponse
    {
        $this->authorize('manage', $site);

        $site->update(['company_id' => $request->validated('company_id')]);

        return back()->with('success', 'საიტს მიენიჭა კომპანია.');
    }
}
