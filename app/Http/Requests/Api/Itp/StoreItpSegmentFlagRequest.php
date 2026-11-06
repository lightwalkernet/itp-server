<?php

namespace App\Http\Requests\Api\Itp;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreItpSegmentFlagRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_explanation' => ['required', 'string', 'max:2000'],
            'requested_change' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
