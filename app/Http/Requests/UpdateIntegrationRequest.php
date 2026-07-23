<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_url' => ['required', 'url', 'max:255'],
            // Blank means "keep the token you already have".
            'api_token' => ['nullable', 'string', 'max:500'],
            'default_mechanic_id' => ['nullable', 'integer'],
            'default_mechanic_name' => ['nullable', 'string', 'max:120'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
