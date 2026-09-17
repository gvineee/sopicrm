<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteProjectDocumentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Attachment $attachment, User $actor): void
    {
        DB::transaction(function () use ($attachment, $actor): void {
            $before = $attachment->only(['owner_type', 'owner_id', 'original_filename', 'storage_path']);

            Storage::disk($attachment->disk)->delete($attachment->storage_path);
            $attachment->delete();

            $this->auditLogger->log(
                action: 'projects.document.deleted',
                target: $attachment,
                before: $before,
                actor: $actor,
            );
        });
    }
}
