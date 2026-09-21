<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\Actions\CreateShiftTemplateAction;
use App\Domain\Attendance\Actions\UpdateShiftTemplateAction;
use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Devices\Models\Site;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ShiftTemplateRequest;
use App\Http\Resources\Attendance\ShiftTemplateResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftTemplateController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ShiftTemplate::class);

        $templates = ShiftTemplate::query()->with('site')->orderBy('name')->get();

        return Inertia::render('Attendance/ShiftTemplates/Index', [
            'shiftTemplates' => ShiftTemplateResource::collection($templates),
            'canManage' => $request->user()->can('create', ShiftTemplate::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ShiftTemplate::class);

        return Inertia::render('Attendance/ShiftTemplates/Create', [
            'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ShiftTemplateRequest $request, CreateShiftTemplateAction $action): RedirectResponse
    {
        $this->authorize('create', ShiftTemplate::class);

        $action->execute($request->shiftTemplateData(), $request->user());

        return to_route('attendance.shift-templates.index')->with('toast', [
            'type' => 'success',
            'message' => 'ცვლის შაბლონი დაემატა.',
        ]);
    }

    public function edit(ShiftTemplate $shiftTemplate): Response
    {
        $this->authorize('update', $shiftTemplate);

        return Inertia::render('Attendance/ShiftTemplates/Edit', [
            'shiftTemplate' => new ShiftTemplateResource($shiftTemplate),
            'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(ShiftTemplateRequest $request, ShiftTemplate $shiftTemplate, UpdateShiftTemplateAction $action): RedirectResponse
    {
        $this->authorize('update', $shiftTemplate);

        $action->execute($shiftTemplate, $request->shiftTemplateData(), $request->user());

        return to_route('attendance.shift-templates.index')->with('toast', [
            'type' => 'success',
            'message' => 'ცვლის შაბლონი განახლდა.',
        ]);
    }

    public function destroy(ShiftTemplate $shiftTemplate): RedirectResponse
    {
        $this->authorize('delete', $shiftTemplate);

        $shiftTemplate->delete();

        return to_route('attendance.shift-templates.index')->with('toast', [
            'type' => 'success',
            'message' => 'ცვლის შაბლონი წაიშალა.',
        ]);
    }
}
