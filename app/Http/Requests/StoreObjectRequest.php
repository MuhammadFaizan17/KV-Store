<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreObjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Custom validation: reject empty bodies and non-object payloads.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $rawBody = ltrim((string) $this->getContent());
            $decodedBody = $rawBody !== '' && $rawBody[0] === '{'
                ? json_decode($rawBody, false)
                : null;

            if (!is_object($decodedBody)) {
                $v->errors()->add('body', 'Request body must be a non-empty JSON object.');
                return;
            }

            foreach (get_object_vars($decodedBody) as $key => $value) {
                if (trim((string) $key) === '') {
                    $v->errors()->add('key', 'All keys must be non-empty strings.');
                }
                if (strlen((string) $key) > 255) {
                    $v->errors()->add('key', "Key '{$key}' exceeds 255 characters.");
                }
            }
        });
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.present' => 'Each key must have an associated value.',
        ];
    }
}
