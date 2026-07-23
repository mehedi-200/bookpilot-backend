<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkingHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'size:7'],
            'days.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.is_closed' => ['required', 'boolean'],
            'days.*.open_time' => ['required_if:days.*.is_closed,false', 'nullable', 'date_format:H:i'],
            'days.*.close_time' => [
                'required_if:days.*.is_closed,false',
                'nullable',
                'date_format:H:i',
                'after:days.*.open_time',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'days.*.close_time.after' => 'Closing time must be after opening time.',
            'days.*.open_time.required_if' => 'Opening time is required when the day is open.',
            'days.*.close_time.required_if' => 'Closing time is required when the day is open.',
        ];
    }
}
