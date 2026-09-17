<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * spec section 10/21: mobile camera capture, multi-photo upload, caption,
 * before/after tagging; upload time is the trusted timestamp, EXIF is
 * unverified metadata; optional/transparent GPS consent, never silent
 * tracking (`gpsConsent` must be explicitly true for any coordinate to be
 * stored at all — if false, lat/lng are discarded even if the client sent
 * them).
 *
 * docs/architecture.md §5/DEC-010: `storage_path` is server-generated
 * (random, never derived from the uploaded filename); the file goes to the
 * private disk only — never a publicly reachable path. Size/count limits
 * come from config/modules/tasks.php (spec section 21's starting defaults),
 * not hardcoded inline.
 *
 * No AV/malware scan is wired in yet (Attachment.status supports a
 * 'scanning'/'quarantined' step but no scanner service exists anywhere in
 * this codebase to call) — every successfully stored file goes straight to
 * `available`. Documented as a known gap in docs/decisions.md, not silently
 * assumed handled.
 */
class UploadTaskAttachment
{
    public function execute(
        Task $task,
        UploadedFile $file,
        User $uploader,
        ?string $classification,
        ?string $caption,
        ?string $takenAtClientClaimed,
        ?float $gpsLatitude,
        ?float $gpsLongitude,
        bool $gpsConsentGiven,
    ): Attachment {
        $config = config('modules.tasks.attachments');
        $mime = $file->getMimeType() ?? $file->getClientMimeType();
        $isPdf = $mime === 'application/pdf';
        $maxBytes = ($isPdf ? $config['max_pdf_mb'] : $config['max_photo_mb']) * 1024 * 1024;

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => 'ფაილის ზომა აღემატება დასაშვებ ლიმიტს.',
            ]);
        }

        $existingCount = Attachment::query()
            ->where('owner_type', Task::class)
            ->where('owner_id', $task->id)
            ->where('status', 'available')
            ->count();

        if ($existingCount >= $config['max_files_per_task']) {
            throw ValidationException::withMessages([
                'file' => 'ამ დავალებაზე მიმაგრებული ფაილების ლიმიტი ამოწურულია.',
            ]);
        }

        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        // Server-generated, never derived from the client's filename (spec
        // section 21 explicit rule).
        $storagePath = sprintf('tasks/%s/%s.%s', $task->id, (string) Str::uuid(), $extension);

        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'ფაილის წაკითხვა ვერ მოხერხდა.']);
        }

        try {
            $stored = Storage::disk('private')->put($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw ValidationException::withMessages([
                'file' => 'ფაილის ატვირთვა ჩაიშალა. სცადეთ ხელახლა.',
            ]);
        }

        return Attachment::create([
            'owner_type' => Task::class,
            'owner_id' => $task->id,
            'disk' => 'private',
            'storage_path' => $storagePath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'byte_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'status' => 'available',
            'uploaded_by_user_id' => $uploader->id,
            'caption' => $caption,
            'classification' => $classification,
            // Client-claimed EXIF/capture time — explicitly untrusted per
            // spec section 10; the Attachment's own created_at (upload
            // time) is the trusted timestamp everywhere this is displayed.
            'taken_at_client_claimed' => $takenAtClientClaimed,
            'gps_latitude' => $gpsConsentGiven ? $gpsLatitude : null,
            'gps_longitude' => $gpsConsentGiven ? $gpsLongitude : null,
            'gps_consent_given' => $gpsConsentGiven,
        ]);
    }
}
