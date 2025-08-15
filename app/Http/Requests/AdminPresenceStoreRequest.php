<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminPresenceStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->user()->hasRole('admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'created_by_id' => [
                'required',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $user = \App\Models\User::find($value);
                    if ($user && !$user->hasRole('staff')) {
                        $fail('The selected employee must have staff role.');
                    }
                }
            ],
            'store_id' => 'required|exists:stores,id',
            'shift_store_id' => 'required|exists:shift_stores,id',
            'check_in' => 'required|date',
            'check_out' => 'nullable|date|after:check_in',
            'latitude_in' => 'required|numeric|between:-90,90',
            'longitude_in' => 'required|numeric|between:-180,180',
            'latitude_out' => 'nullable|numeric|between:-90,90',
            'longitude_out' => 'nullable|numeric|between:-180,180',
            'image_in' => 'nullable|string',
            'image_out' => 'nullable|string',
            'status' => 'required|integer|in:0,1,2',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'created_by_id.required' => 'Employee is required',
            'created_by_id.exists' => 'Selected employee does not exist',
            'store_id.required' => 'Store is required',
            'store_id.exists' => 'Selected store does not exist',
            'shift_store_id.required' => 'Shift is required',
            'shift_store_id.exists' => 'Selected shift does not exist',
            'check_in.required' => 'Check-in time is required',
            'check_in.date' => 'Check-in must be a valid date',
            'check_out.date' => 'Check-out must be a valid date',
            'check_out.after' => 'Check-out time must be after check-in time',
            'latitude_in.required' => 'Check-in latitude is required',
            'longitude_in.required' => 'Check-in longitude is required',
            'status.required' => 'Status is required',
            'status.in' => 'Status must be 0, 1, or 2',
        ];
    }
}