<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmAssistantCheckoutRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'checkout_token' => ['required', 'uuid'],
        ];
    }
}
