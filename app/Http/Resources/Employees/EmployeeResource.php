<?php

namespace App\Http\Resources\Employees;

use App\Domain\Employees\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * spec section 5: "პირადი ნომერი საჭიროების შემთხვევაში შეზღუდული წვდომით."
 * `personal_id_number` is included ONLY when the requesting user passes
 * EmployeePolicy::viewPersonalId — this is the one place that decrypts and
 * serializes it, so no other code path can leak it into a response by
 * accident. `personal_id_number_masked` is always present (last 2 digits
 * only) so the UI can show "…something exists" without ever fetching the
 * real value for an unauthorized viewer.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;
        $user = $request->user();

        $canViewPersonalId = $user !== null && $user->can('viewPersonalId', $employee);

        return [
            'id' => $employee->id,
            'internal_code' => $employee->internal_code,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'full_name' => trim("{$employee->first_name} {$employee->last_name}"),
            'phone' => $employee->phone,
            'position' => $employee->position,
            'photo_url' => $employee->photo_attachment_id !== null
                ? route('employees.photo.show', $employee)
                : null,
            'profession_skills' => $employee->profession_skills ?? [],
            'status' => $employee->status,
            'team' => $this->whenLoaded('team', fn () => $employee->team === null ? null : [
                'id' => $employee->team->id,
                'name' => $employee->team->name,
            ]),
            'supervisor' => $this->whenLoaded('supervisor', fn () => $employee->supervisor === null ? null : [
                'id' => $employee->supervisor->id,
                'full_name' => trim("{$employee->supervisor->first_name} {$employee->supervisor->last_name}"),
            ]),
            'emergency_contact_name' => $employee->emergency_contact_name,
            'emergency_contact_phone' => $employee->emergency_contact_phone,
            'has_login' => $employee->user_id !== null,
            'personal_id_number' => $canViewPersonalId ? $employee->personal_id_number_encrypted : null,
            'personal_id_number_visible' => $canViewPersonalId,
            'created_at' => $employee->created_at?->toIso8601String(),
        ];
    }
}
