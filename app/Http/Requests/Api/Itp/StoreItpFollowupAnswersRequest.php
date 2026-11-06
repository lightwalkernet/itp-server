<?php

namespace App\Http\Requests\Api\Itp;

use App\Models\ItpGeneration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItpFollowupAnswersRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request. Each answer must be to one of this
     * generation's questions.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var ItpGeneration $generation */
        $generation = $this->route('generation');

        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*' => ['array'],
            'answers.*.followup_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('itp_followups', 'id')->where('generation_id', $generation->id),
            ],
            'answers.*.answer_text' => ['nullable', 'string'],
        ];
    }
}
