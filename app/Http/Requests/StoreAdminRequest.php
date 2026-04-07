<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Handled by our RoleMiddleware in routes
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users')->whereNull('deleted_at')],
            'password' => ['required', 'string', 'min:8'],
            // 'username' -> For simplicity, Laravel auth traditionally defaults to email as username. We can add username later if they strictly require it over email. Email is safer.
        ];
    }
}
