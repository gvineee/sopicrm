<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\UploadEmployeeDocumentAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Models\Attachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeDocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    public function store(StoreEmployeeDocumentRequest $request, Employee $employee, UploadEmployeeDocumentAction $action): RedirectResponse
    {
        $this->authorize('manageDocuments', $employee);
        $action->execute(
            $employee,
            $request->uploadedFile(),
            $request->validatedNullableString('caption'),
            $request->user(),
        );

        return to_route('employees.show', $employee)->with('toast', [
            'type' => 'success',
            'message' => 'დოკუმენტი აიტვირთა.',
        ]);
    }

    public function download(Employee $employee, Attachment $attachment): StreamedResponse
    {
        $this->authorize('viewDocuments', $employee);
        abort_unless(
            $attachment->owner_type === $employee->getMorphClass()
            && $attachment->owner_id === $employee->id
            && $attachment->classification === 'employee_document'
            && $attachment->status === 'available',
            404,
        );

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->disk)->download(
            $attachment->storage_path,
            $attachment->original_filename,
        );
    }
}
