<?php

namespace App\Http\Requests\Api\Itp;

use App\Models\ItpGeneration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A flag on one section (`{part}`). `segment_id`, when given, is the line the user pointed to: it
 * must be a current line of that section.
 */
class StoreItpSectionFlagRequest extends FormRequest
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
            'segment_id' => ['nullable', 'integer', Rule::in($this->sectionSegmentIds())],
        ];
    }

    /**
     * @return list<int>
     */
    protected function sectionSegmentIds(): array
    {
        /** @var ItpGeneration $generation */
        $generation = $this->route('generation');

        return $generation->attunement?->sectionSegments((int) $this->route('part'))->modelKeys() ?? [];
    }
}
