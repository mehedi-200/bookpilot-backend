<?php

namespace App\Http\Requests;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    Booking::STATUS_CONFIRMED,
                    Booking::STATUS_COMPLETED,
                    Booking::STATUS_CANCELLED,
                ]),
            ],
            'cancel_reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
