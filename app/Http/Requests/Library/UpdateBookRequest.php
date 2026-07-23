<?php

namespace App\Http\Requests\Library;

use App\Models\Book;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Book $book */
        $book = $this->route('book');

        return [
            'title' => ['required', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:32', Rule::unique('books', 'isbn')->ignore($book)],
            'description' => ['nullable', 'string', 'max:5000'],
            'published_year' => ['nullable', 'integer', 'min:1000', 'max:'.now()->year],
            'total_copies' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
