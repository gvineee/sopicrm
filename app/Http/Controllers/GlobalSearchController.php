<?php

namespace App\Http\Controllers;

use App\Domain\Companies\Models\Company;
use App\Domain\Devices\Models\Device;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\PortableSearch;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOTIFY-01: real backend for the already-built resources/js/components/GlobalSearch.vue
 * (docs/decisions.md DEC-048). HARD SECURITY REQUIREMENT, not a nice-to-have:
 * every branch below reuses the SAME Policy/scoping logic the entity's own
 * index controller already enforces — a result the user could not otherwise
 * open must never appear here, since a search result existing at all already
 * discloses the record's existence. Each branch is a deliberate copy of that
 * controller's own real query, not a reimplementation — see the inline
 * reference to each source controller.
 */
class GlobalSearchController extends Controller
{
    private const PER_GROUP_LIMIT = 6;

    public function index(Request $request, TaskPolicy $taskPolicy): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        /** @var User $user */
        $user = $request->user();
        $like = "%{$query}%";

        $results = [];

        // Mirrors App\Http\Controllers\Projects\ProjectController::index()'s
        // own membership-scoping branch exactly.
        $projects = Project::query()
            ->when(! $user->can('viewAny', Project::class), function ($q) use ($user) {
                $q->whereHas('memberships', fn ($m) => $m->where('user_id', $user->id)->whereNull('removed_at'));
            })
            ->where(function ($q) use ($like) {
                PortableSearch::where($q, 'name', $like);
                PortableSearch::orWhere($q, 'code', $like);
            })
            ->limit(self::PER_GROUP_LIMIT)
            ->get(['id', 'name', 'code']);

        foreach ($projects as $project) {
            $results[] = ['id' => $project->id, 'title' => $project->name, 'subtitle' => $project->code, 'href' => "/projects/{$project->id}", 'group' => 'პროექტები'];
        }

        // Mirrors App\Http\Controllers\DashboardController's own
        // TaskPolicy::scopeVisibleToPerformer() fallback exactly.
        $taskQuery = Task::query();
        PortableSearch::where($taskQuery, 'title', $like);
        if (! $user->can('tasks.tasks.view')) {
            $taskPolicy->scopeVisibleToPerformer($taskQuery, $user);
        }
        $tasks = $taskQuery->limit(self::PER_GROUP_LIMIT)->get(['id', 'title', 'project_id']);

        foreach ($tasks as $task) {
            $results[] = ['id' => $task->id, 'title' => $task->title, 'subtitle' => null, 'href' => "/projects/{$task->project_id}/tasks/{$task->id}", 'group' => 'დავალებები'];
        }

        // Mirrors App\Http\Controllers\Employees\EmployeeController::index()'s
        // own viewAny gate — org-wide once held, no per-row scoping exists
        // there either.
        if ($user->can('viewAny', Employee::class)) {
            $employees = Employee::query()
                ->where(function ($q) use ($like) {
                    PortableSearch::where($q, 'first_name', $like);
                    PortableSearch::orWhere($q, 'last_name', $like);
                    PortableSearch::orWhere($q, 'internal_code', $like);
                })
                ->limit(self::PER_GROUP_LIMIT)
                ->get(['id', 'first_name', 'last_name', 'internal_code']);

            foreach ($employees as $employee) {
                $results[] = ['id' => $employee->id, 'title' => trim($employee->first_name.' '.$employee->last_name), 'subtitle' => $employee->internal_code, 'href' => "/employees/{$employee->id}", 'group' => 'თანამშრომლები'];
            }
        }

        // Mirrors App\Policies\DevicePolicy::viewAny() exactly.
        if ($user->hasRole('owner') || $user->can('devices.view')) {
            $devices = Device::query()
                ->where(function ($q) use ($like) {
                    PortableSearch::where($q, 'name', $like);
                    PortableSearch::orWhere($q, 'serial_number', $like);
                })
                ->limit(self::PER_GROUP_LIMIT)
                ->get(['id', 'name', 'serial_number']);

            foreach ($devices as $device) {
                $results[] = ['id' => $device->id, 'title' => $device->name, 'subtitle' => $device->serial_number, 'href' => "/devices/{$device->id}", 'group' => 'მოწყობილობები'];
            }
        }

        // Mirrors App\Policies\CompanyPolicy::viewAny() exactly.
        if ($user->can('companies.view')) {
            $companiesQuery = Company::query();
            PortableSearch::where($companiesQuery, 'name', $like);
            $companies = $companiesQuery->limit(self::PER_GROUP_LIMIT)->get(['id', 'name']);

            foreach ($companies as $company) {
                $results[] = ['id' => $company->id, 'title' => $company->name, 'subtitle' => null, 'href' => '/companies', 'group' => 'კომპანიები'];
            }
        }

        return response()->json(['results' => $results]);
    }
}
