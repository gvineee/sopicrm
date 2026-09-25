<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\Actions\CreateClientAction;
use App\Domain\Projects\Actions\UpdateClientAction;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\PortableSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreClientRequest;
use App\Http\Requests\Projects\UpdateClientRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit A24: „კლიენტი" existed on the project form with an empty dropdown and
 * no way anywhere to fill it. There was no controller, no route, no menu
 * entry — and `projects.clients.manage`, the permission ClientPolicy checks,
 * had never been created, so `create` there could not return true for anyone.
 *
 * This is the missing management screen. It stays as narrow as the domain
 * actions behind it: a client here is a name and contact details a project
 * points at. Full client relationship management (contracts, pipeline) is
 * spec section 14 and is not this.
 *
 * Companies, clients and contractors are three different things and the
 * audit asked for that to be visible: a Company is one of OUR legal entities,
 * a Client is who a project is built FOR, a Contractor is who is hired to do
 * part of it. Each has its own screen; this one says so in as many words.
 */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $search = trim((string) $request->query('search', ''));

        $clients = Client::query()
            ->when($search !== '', fn ($query) => PortableSearch::where($query, 'name', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // Shown so that an operator can tell at a glance which clients are
        // actually in use before renaming one.
        $projectCounts = Project::query()
            ->whereIn('client_id', collect($clients->items())->pluck('id'))
            ->selectRaw('client_id, count(*) as total')
            ->groupBy('client_id')
            ->pluck('total', 'client_id');

        return Inertia::render('Projects/Clients/Index', [
            'clients' => collect($clients->items())->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'contact_info' => $client->contact_info,
                'project_count' => (int) ($projectCounts[$client->id] ?? 0),
            ])->all(),
            'pagination' => [
                'page' => $clients->currentPage(),
                'perPage' => $clients->perPage(),
                'total' => $clients->total(),
            ],
            'filters' => ['search' => $search],
            'can' => ['manage' => $request->user()->can('create', Client::class)],
        ]);
    }

    public function store(StoreClientRequest $request, CreateClientAction $action): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $action->execute($request->validated('name'), $request->contactInfo(), $request->user());

        return to_route('clients.index')->with('toast', ['type' => 'success', 'message' => 'კლიენტი დაემატა.']);
    }

    public function update(UpdateClientRequest $request, Client $client, UpdateClientAction $action): RedirectResponse
    {
        $this->authorize('update', $client);

        $action->execute($client, $request->validated('name'), $request->contactInfo(), $request->user());

        return to_route('clients.index')->with('toast', ['type' => 'success', 'message' => 'კლიენტი განახლდა.']);
    }
}
