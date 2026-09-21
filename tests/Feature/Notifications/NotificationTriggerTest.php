<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Support\NotificationCreator;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Notification;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Actions\AddComment;
use App\Domain\Tasks\Actions\AssignTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('notifications');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->actor = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->actor->assignRole('owner');

    $this->performerUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->performerEmployee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->performerUser->id,
    ]);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);
});

test('assigning a task notifies the assigned performer exactly once', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'status' => 'draft',
    ]);
    TaskAssignee::factory()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $task->id,
        'employee_id' => $this->performerEmployee->id,
        'team_id' => null,
    ]);

    app(AssignTask::class)->execute($task->fresh(), $this->actor);

    $notifications = Notification::query()
        ->where('recipient_user_id', $this->performerUser->id)
        ->where('type', 'task_assigned')
        ->get();

    expect($notifications)->toHaveCount(1);
    expect($notifications->first()->payload['message'])->toContain($task->title);
});

test('a repeated identical trigger never duplicates the notification row', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
    ]);

    NotificationCreator::create(
        recipient: $this->performerUser,
        type: 'task_assigned',
        title: 'title',
        message: 'message',
        dedupKey: "task_assigned:{$task->id}:{$this->performerUser->id}",
    );
    NotificationCreator::create(
        recipient: $this->performerUser,
        type: 'task_assigned',
        title: 'title',
        message: 'message',
        dedupKey: "task_assigned:{$task->id}:{$this->performerUser->id}",
    );

    expect(Notification::query()->where('recipient_user_id', $this->performerUser->id)->count())->toBe(1);
});

test('a muted notification type is never created', function () {
    NotificationPreference::query()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->performerUser->id,
        'muted_types' => ['task_assigned'],
    ]);

    $result = NotificationCreator::create(
        recipient: $this->performerUser,
        type: 'task_assigned',
        title: 'title',
        message: 'message',
        dedupKey: 'some-dedup-key',
    );

    expect($result)->toBeNull();
    expect(Notification::query()->where('recipient_user_id', $this->performerUser->id)->count())->toBe(0);
});

test('SensitivePreviewGuard genuinely refuses a payload carrying a full monetary amount', function () {
    expect(fn () => NotificationCreator::create(
        recipient: $this->performerUser,
        type: 'task_assigned',
        title: 'გადახდა',
        message: 'თანხა 1500.00 GEL ჩაირიცხა',
        dedupKey: 'unsafe-dedup-key',
    ))->toThrow(RuntimeException::class);

    expect(Notification::query()->where('dedup_key', 'unsafe-dedup-key')->exists())->toBeFalse();
});

test('mentioning a user in a task comment notifies them but never the author for self-mention', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
    ]);

    app(AddComment::class)->execute($task, $this->actor, 'გამოხედვა @performer', [$this->performerUser->id, $this->actor->id]);

    expect(Notification::query()->where('recipient_user_id', $this->performerUser->id)->where('type', 'mention')->count())->toBe(1);
    expect(Notification::query()->where('recipient_user_id', $this->actor->id)->where('type', 'mention')->count())->toBe(0);
});
