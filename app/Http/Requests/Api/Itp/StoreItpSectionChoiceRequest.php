<?php

namespace App\Http\Requests\Api\Itp;

use App\Enums\ItpSectionChoice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The user's choice for a rewritten section: keep the new version or their original.
 */
class StoreItpSectionChoiceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'keep' => ['required', 'string', Rule::enum(ItpSectionChoice::class)],
        ];
    }
}
