<?php

namespace App\Console\Commands;

use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Auth\Models\Organization;
use App\Domain\Notifications\Support\NotificationCreator;
use App\Domain\Notifications\Support\NotificationType;
use App\Domain\Notifications\Support\TaskNotificationRecipients;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

/**
 * NOTIFY-01: the scheduled half of the `overdue` (task) and
 * `tool_return_due` (asset custody) notification types — neither has a
 * natural "this just happened" write-path hook the way task_assigned/
 * task_returned/mention do (an overdue task or an overdue tool return is a
 * fact about the CURRENT time, not a discrete event), so both need a
 * periodic check. Combined into one command since both are simple
 * "past-due, not yet resolved, no existing notification for today" scans —
 * splitting them into two files would duplicate the per-organization
 * context plumbing for no real benefit.
 *
 * Dedup is per calendar day (`{type}:{id}:{date}`), a deliberate choice
 * over "once ever" or "every run": a task overdue for a week gets one
 * reminder per day, not a single silent one, but never more than once in
 * the same day even if this command runs more than once — see
 * routes/console.php for the actual schedule.
 */
class NotifyDueItems extends Command
{
    protected $signature = 'notifications:notify-due-items';

    protected $description = 'Notify performers/warehouse of overdue tasks and tools past their expected return date.';

    public function handle(): int
    {
        $today = now()->toDateString();

        Organization::query()->each(function (Organization $organization) use ($today): void {
            $previousOrganizationId = CurrentOrganization::id();

            try {
                CurrentOrganization::set($organization->id);
                app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

                $this->notifyOverdueTasks($organization->id, $today);
                $this->notifyOverdueToolReturns($organization->id, $today);
            } finally {
                CurrentOrganization::set($previousOrganizationId);
            }
        });

        return self::SUCCESS;
    }

    private function notifyOverdueTasks(string $organizationId, string $today): void
    {
        $overdueTasks = Task::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['assignees.employee.user', 'assignees.team.members.user'])
            ->get();

        foreach ($overdueTasks as $task) {
            foreach (TaskNotificationRecipients::forTask($task) as $recipient) {
                NotificationCreator::create(
                    recipient: $recipient,
                    type: NotificationType::TASK_OVERDUE,
                    title: 'ვადაგადაცილებული დავალება',
                    message: "დავალება \"{$task->title}\" ვადაგადაცილებულია",
                    dedupKey: "overdue:{$task->id}:{$today}",
                    deepLink: "/projects/{$task->project_id}/tasks/{$task->id}",
                );
            }
        }
    }

    private function notifyOverdueToolReturns(string $organizationId, string $today): void
    {
        $overdue = CustodyTransaction::query()
            ->where('organization_id', $organizationId)
            ->where('type', 'issue')
            ->whereIn('status', ['issued', 'awaiting_receipt'])
            ->whereNotNull('expected_return_at')
            ->where('expected_return_at', '<', now())
            ->with('receivingEmployee.user')
            ->get();

        foreach ($overdue as $transaction) {
            $recipient = $transaction->receivingEmployee?->user;

            if ($recipient === null) {
                continue;
            }

            NotificationCreator::create(
                recipient: $recipient,
                type: NotificationType::TOOL_RETURN_DUE,
                title: 'ხელსაწყოს დაბრუნების ვადა',
                message: 'თქვენს მიერ აღებული ხელსაწყოს დაბრუნების ვადა გასულია',
                dedupKey: "tool_return_due:{$transaction->id}:{$today}",
                deepLink: '/assets',
            );
        }
    }
}
