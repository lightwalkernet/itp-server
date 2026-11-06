<?php

namespace App\Http\Requests\Api\Itp;

use App\Enums\ItpAddressStyle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItpIntakeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'address_style' => ['required', Rule::enum(ItpAddressStyle::class)],
            'q1_dedication' => ['required', 'string'],
            'q2_challenge' => ['required', 'string'],
            'q3_old_story' => ['required', 'string'],
            'q3_phrase' => ['nullable', 'string'],
            'q3_protective' => ['nullable', 'string'],
            'q4_vision' => ['required', 'string'],
            'q4_scene' => ['nullable', 'string'],
            'q5_soulful' => ['required', 'string'],
            'avoid' => ['nullable', 'string'],
        ];
    }
}
