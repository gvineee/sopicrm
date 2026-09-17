<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Real, DB-backed document upload for a Project (spec section 10:
 * project carries "დოკუმენტები"). Deliberately narrower than the full
 * upload-lifecycle spec section 20 describes for the generic `attachments`
 * table (initiated -> signed-upload -> finalize -> scanning ->
 * available/quarantined/failed) — that async initiate/finalize contract and
 * a real virus-scanning hook are cross-cutting infrastructure nobody has
 * built yet anywhere in this codebase (verified: no controller/service
 * touches `attachments` before this module). Documented as a known,
 * scoped-down gap in docs/decisions.md rather than silently pretended away:
 * this action does a direct server-side multipart upload straight to the
 * private disk and marks the row `available` immediately (no
 * quarantine/scanning step exists to run first).
 *
 * What IS real here, matching the hard constraints: the storage path is
 * server-generated (never derived from the client filename, DEC-010/spec
 * section 21), the file goes to the non-public `private` disk, and the row
 * is tenant-scoped + Policy-gated on every read (see
 * ProjectDocumentController).
 */
class UploadProjectDocumentAction
{
    private const DISK = 'private';

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Project $project, UploadedFile $file, ?string $caption, ?string $classification, User $actor): Attachment
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => ['ფაილის ტიპი დაუშვებელია. ატვირთეთ PDF, სურათი ან Office დოკუმენტი.'],
            ]);
        }

        return DB::transaction(function () use ($project, $file, $caption, $classification, $actor): Attachment {
            // Server-generated storage path, never the client's original
            // filename (spec section 21 explicit hard rule).
            $extension = $file->getClientOriginalExtension() ?: 'bin';
            $storagePath = sprintf(
                'projects/%s/documents/%s.%s',
                $project->id,
                (string) Str::uuid7(),
                $extension,
            );

            $stream = fopen($file->getRealPath(), 'r');
            if ($stream === false) {
                throw ValidationException::withMessages([
                    'file' => ['The uploaded file could not be opened for storage.'],
                ]);
            }

            try {
                $stored = Storage::disk(self::DISK)->put($storagePath, $stream);
            } finally {
                fclose($stream);
            }

            if (! $stored) {
                throw ValidationException::withMessages([
                    'file' => ['The uploaded file could not be stored.'],
                ]);
            }

            $attachment = Attachment::create([
                'owner_type' => $project->getMorphClass(),
                'owner_id' => $project->id,
                'disk' => self::DISK,
                'storage_path' => $storagePath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'byte_size' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
                // No scanning pipeline exists yet (see class docblock) —
                // marked available immediately rather than faking a
                // 'scanning' state nothing ever resolves.
                'status' => 'available',
                'uploaded_by_user_id' => $actor->id,
                'caption' => $caption,
                'classification' => $classification,
            ]);

            $this->auditLogger->log(
                action: 'projects.document.uploaded',
                target: $attachment,
                after: $attachment->only(['owner_type', 'owner_id', 'original_filename', 'mime_type', 'byte_size', 'classification']),
                actor: $actor,
            );

            return $attachment;
        });
    }
}
