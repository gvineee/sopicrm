<?php

namespace App\Console\Commands;

use App\Domain\Auth\Actions\GrantPlatformAdminAction;
use App\Domain\Auth\Actions\RevokePlatformAdminAction;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * ADMIN-01: the bootstrap-safe way to grant/revoke platform-admin access
 * before ADMIN-02's in-app management UI exists. Requires server access by
 * design — same posture as `tokens:issue-machine` — and requires the acting
 * user to already be a platform admin (the very first grant is instead done
 * once, for the bootstrap account, by migration 2026_09_21_090000).
 */
class GrantPlatformAdmin extends Command
{
    protected $signature = 'admin:platform-admin
        {action : grant or revoke}
        {target-email : Email of the account to change}
        {--as= : Email of the acting platform admin (defaults to the target-email\'s own existing platform-admin status check being skipped only for the very first bootstrap grant)}
        {--reason= : Audit reason}';

    protected $description = 'Grant or revoke platform-admin access for a user account (ADMIN-01).';

    public function handle(GrantPlatformAdminAction $grant, RevokePlatformAdminAction $revoke): int
    {
        $action = $this->argument('action');

        if (! in_array($action, ['grant', 'revoke'], true)) {
            $this->error('action must be "grant" or "revoke".');

            return self::FAILURE;
        }

        $target = User::query()->where('email', $this->argument('target-email'))->first();

        if ($target === null) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $actorEmail = $this->option('as') ?? $this->argument('target-email');
        $actor = User::query()->where('email', $actorEmail)->first();

        if ($actor === null) {
            $this->error('No acting user with that email.');

            return self::FAILURE;
        }

        $reason = $this->option('reason') ?? "console: admin:platform-admin {$action}";

        try {
            if ($action === 'grant') {
                $grant->execute($target, $actor, $reason);
            } else {
                $revoke->execute($target, $actor, $reason);
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Platform admin {$action}ed for {$target->email}.");

        return self::SUCCESS;
    }
}
