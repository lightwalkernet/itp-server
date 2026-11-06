<?php

namespace App\Http\Requests\Api\Content;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The sync pull's query parameters. Whether `type` is a global type is the controller's call:
 * any other type is a 404, not a validation error.
 */
class PullContentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string'],
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('sync.server.max_pull_limit')],
        ];
    }
}
