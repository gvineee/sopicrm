<?php

namespace App\Http\Controllers\Contractors;

use App\Domain\Contractors\Actions\CreateContractorAction;
use App\Domain\Contractors\Actions\UpdateContractorAction;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contractors\ContractorRequest;
use App\Http\Resources\Contractors\ContractorContractResource;
use App\Http\Resources\Contractors\ContractorProjectAssignmentResource;
use App\Http\Resources\Contractors\ContractorResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractorController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contractor::class);

        $contractors = Contractor::query()
            ->withCount('contracts')
            ->orderBy('name')
            ->get();

        return Inertia::render('Contractors/Index', [
            'contractors' => ContractorResource::collection($contractors),
            'canManage' => $request->user()->can('create', Contractor::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Contractor::class);

        return Inertia::render('Contractors/Create');
    }

    public function store(ContractorRequest $request, CreateContractorAction $action): RedirectResponse
    {
        $this->authorize('create', Contractor::class);

        $contractor = $action->execute($request->contractorData(), $request->user());

        return to_route('contractors.show', $contractor)->with('toast', [
            'type' => 'success',
            'message' => 'კონტრაქტორი დაემატა.',
        ]);
    }

    public function show(Request $request, Contractor $contractor): Response
    {
        $this->authorize('view', $contractor);

        $contractor->load(['contracts.project', 'contracts.submittedBy', 'contracts.approvedBy', 'projectAssignments.contractor']);

        // Attachments uploaded to this contractor but not yet claimed by any
        // act — once SubmitContractorAct re-owns one to the act, it drops out
        // of this pool automatically (same re-owning mechanism Tasks uses).
        $pendingEvidence = Attachment::query()
            ->where('owner_type', Contractor::class)
            ->where('owner_id', $contractor->id)
            ->where('status', 'available')
            ->orderByDesc('created_at')
            ->get(['id', 'original_filename', 'mime_type', 'caption']);

        return Inertia::render('Contractors/Show', [
            'contractor' => new ContractorResource($contractor),
            'contracts' => ContractorContractResource::collection($contractor->contracts),
            'projectAssignments' => ContractorProjectAssignmentResource::collection($contractor->projectAssignments),
            'pendingEvidence' => $pendingEvidence,
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('update', $contractor),
        ]);
    }

    public function edit(Contractor $contractor): Response
    {
        $this->authorize('update', $contractor);

        return Inertia::render('Contractors/Edit', ['contractor' => new ContractorResource($contractor)]);
    }

    public function update(ContractorRequest $request, Contractor $contractor, UpdateContractorAction $action): RedirectResponse
    {
        $this->authorize('update', $contractor);

        $action->execute($contractor, $request->contractorData(), $request->user());

        return to_route('contractors.index')->with('toast', [
            'type' => 'success',
            'message' => 'კონტრაქტორის მონაცემები განახლდა.',
        ]);
    }
}
