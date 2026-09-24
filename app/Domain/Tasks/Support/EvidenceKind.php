<?php

namespace App\Domain\Tasks\Support;

use App\Domain\Shared\Models\Attachment;

/**
 * 03-Construction-Task-Manager-Spec-KA.md §11 / TM-04: a task's minimum
 * PHOTO requirement must be counted in photos. Uploads legitimately accept
 * PDFs too (drawings, certificates, delivery notes), and counting "any
 * attachment" let a single PDF satisfy „საჭიროა მინიმუმ 2 ფოტო" — the
 * evidence rule then guarantees nothing about whether anyone actually
 * photographed the work.
 *
 * Classification is by the server-recorded MIME type, never by the
 * client-supplied filename or the free-text `classification` tag, both of
 * which the submitter controls. `config('tasks.evidence.pdf_satisfies_photo_requirement')`
 * exists because §11 allows a deployment to state that a PDF counts — but it
 * has to be stated, so the default is false.
 */
class EvidenceKind
{
    public static function isPhoto(Attachment $attachment): bool
    {
        $mime = strtolower(trim((string) $attachment->mime_type));

        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        return $mime === 'application/pdf'
            && (bool) config('tasks.evidence.pdf_satisfies_photo_requirement', false);
    }
}
