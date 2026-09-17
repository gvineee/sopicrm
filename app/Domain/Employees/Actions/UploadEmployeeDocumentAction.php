<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class UploadEmployeeDocumentAction
{
    private const DISK = 'private';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        Employee $employee,
        UploadedFile $file,
        ?string $caption,
        User $actor,
        string $classification = 'employee_document',
    ): Attachment {
        if (! in_array($classification, ['employee_document', 'employee_photo'], true)) {
            throw ValidationException::withMessages(['file' => ['ფაილის კლასიფიკაცია დაუშვებელია.']]);
        }

        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $storedPath = Storage::disk(self::DISK)->putFileAs(
            "employees/{$employee->id}/".($classification === 'employee_photo' ? 'photos' : 'documents'),
            $file,
            Str::uuid7().'.'.$extension,
        );

        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'file' => ['ფაილის შენახვა ვერ მოხერხდა.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($employee, $file, $caption, $actor, $classification, $storedPath): Attachment {
                $attachment = Attachment::query()->create([
                    'owner_type' => $employee->getMorphClass(),
                    'owner_id' => $employee->id,
                    'disk' => self::DISK,
                    'storage_path' => $storedPath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'byte_size' => $file->getSize(),
                    'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
                    'status' => 'available',
                    'uploaded_by_user_id' => $actor->id,
                    'caption' => $caption,
                    'classification' => $classification,
                ]);

                $this->auditLogger->log(
                    action: $classification === 'employee_photo'
                        ? 'employees.photo.uploaded'
                        : 'employees.document.uploaded',
                    target: $attachment,
                    after: $attachment->only(['owner_id', 'original_filename', 'mime_type', 'byte_size']),
                    actor: $actor,
                );

                return $attachment;
            });
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($storedPath);

            throw $exception;
        }
    }
}
