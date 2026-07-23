<?php

namespace App\Http\Requests\Library;

use App\Enums\LibraryRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(array_map(
                static fn (LibraryRole $role): string => $role->value,
                LibraryRole::cases(),
            ))],
        ];
    }
}
