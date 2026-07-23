<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:32', 'unique:books,isbn'],
            'description' => ['nullable', 'string', 'max:5000'],
            'published_year' => ['nullable', 'integer', 'min:1000', 'max:' . now()->year],
            'total_copies' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
