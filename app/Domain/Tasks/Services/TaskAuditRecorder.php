<?php

namespace App\Domain\Tasks\Services;

use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * 03-Construction-Task-Manager-Spec-KA.md §16 DV-01: „მენეჯერი გასცემს,
 * შემსრულებელი იღებს → ორი ცალკე audit ჩანაწერი; სწორი task revision."
 *
 * Until now the Tasks domain wrote no audit events at all — sixteen Actions
 * and not one call to AuditLogger. Task transitions left a `task_status_events`
 * row, which records from/to/actor/reason and nothing else: no before/after
 * payload, no request id, no ip, and nothing at all for the changes that are
 * not status transitions. Creating a task, editing its fields, adding a
 * dependency, attaching evidence and commenting left no trace anywhere.
 *
 * This is the one place task audit rows are written, for the same reason
 * TaskStatusEventRecorder is the one place status history is written: a rule
 * that each Action has to remember is a rule that one Action eventually
 * forgets.
 *
 * Every row carries the task's own identity and the revision it was at, so a
 * reader can line an audit entry up against the task version it describes —
 * which is the half of DV-01 that „სწორი task revision" is asking for.
 */
class TaskAuditRecorder
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * A status transition. Called from TaskStatusEventRecorder rather than
     * from each Action, so a transition cannot be recorded in the status
     * history and quietly skipped in the audit trail.
     */
    public function recordTransition(Task $task, ?string $fromStatus, string $toStatus, ?User $actor, ?string $reason = null): AuditEvent
    {
        return $this->auditLogger->log(
            action: "tasks.task.{$toStatus}",
            target: $task,
            before: ['status' => $fromStatus],
            after: $this->context($task, ['status' => $toStatus]),
            reason: $reason,
            actor: $actor,
        );
    }

    /**
     * Anything that changes a task without changing its status: creation,
     * field edits, dependencies, evidence, comments, checklist answers.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        Task $task,
        ?User $actor,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?Model $target = null,
    ): AuditEvent {
        return $this->auditLogger->log(
            action: $action,
            // A child row (a dependency, an attachment, a comment) is its own
            // target so the event points at the thing that changed; the task
            // it belongs to travels in the payload, because `audit_events` has
            // no task column to join on.
            target: $target ?? $task,
            before: $before,
            after: $this->context($task, $after ?? []),
            reason: $reason,
            actor: $actor,
        );
    }

    /**
     * The task identity every task audit row carries, so an entry can be
     * attributed to a task and a revision without a join.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function context(Task $task, array $payload): array
    {
        return [
            ...$payload,
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'task_title' => $task->title,
            // `HasVersion` increments this on every update, so it identifies
            // the revision this event describes.
            'task_version' => (int) $task->version,
        ];
    }
}
