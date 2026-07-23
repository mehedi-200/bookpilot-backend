<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => [
                'required',
                Rule::exists('services', 'id')->whereNull('deleted_at')->where('active', true),
            ],
            'starts_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],

            // Either an existing customer, or enough to find/create one by phone.
            'customer_id' => ['nullable', 'required_without:customer_phone', 'exists:customers,id'],
            'customer_name' => ['nullable', 'required_without:customer_id', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'required_without:customer_id', 'string', 'max:30', 'regex:/[0-9]/'],
            'customer_email' => ['nullable', 'email', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'service_id.exists' => 'Pick an active service.',
            'customer_id.required_without' => 'Choose a customer or enter their phone number.',
            'customer_name.required_without' => 'Enter the customer’s name.',
            'customer_phone.required_without' => 'Enter the customer’s phone number.',
        ];
    }
}
