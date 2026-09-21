<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\Contractor;
use App\Domain\Shared\Models\Attachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors App\Domain\Tasks\Actions\UploadTaskAttachment (server-generated
 * storage_path, private disk, no AV scan wired in — same documented gap),
 * scoped down to what a contractor act's evidence actually needs.
 */
class UploadContractorAttachment
{
    private const MAX_BYTES = 15 * 1024 * 1024;

    public function execute(Contractor $contractor, UploadedFile $file, User $uploader, ?string $caption): Attachment
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'ფაილის ზომა აღემატება დასაშვებ ლიმიტს.',
            ]);
        }

        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $storagePath = sprintf('contractors/%s/%s.%s', $contractor->id, (string) Str::uuid(), $extension);

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
            'owner_type' => Contractor::class,
            'owner_id' => $contractor->id,
            'disk' => 'private',
            'storage_path' => $storagePath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
            'byte_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'status' => 'available',
            'uploaded_by_user_id' => $uploader->id,
            'caption' => $caption,
        ]);
    }
}
