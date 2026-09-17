<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\UploadEmployeeDocumentAction;
use App\Domain\Employees\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeePhotoRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeePhotoController extends Controller
{
    public function store(StoreEmployeePhotoRequest $request, Employee $employee, UploadEmployeeDocumentAction $action): RedirectResponse
    {
        $this->authorize('update', $employee);

        $attachment = $action->execute(
            $employee,
            $request->uploadedPhoto(),
            'Profile photo',
            $request->user(),
            'employee_photo',
        );
        $employee->update(['photo_attachment_id' => $attachment->id]);

        return to_route('employees.show', $employee)->with('toast', [
            'type' => 'success',
            'message' => 'პროფილის ფოტო განახლდა.',
        ]);
    }

    public function show(Employee $employee): StreamedResponse
    {
        $this->authorize('view', $employee);
        $attachment = $employee->photo()->where('classification', 'employee_photo')->firstOrFail();
        abort_unless($attachment->status === 'available', 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->storage_path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type],
        );
    }
}
