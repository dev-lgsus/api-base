<?php

namespace App\Http\Requests\Api\V1\User;

use App\Http\Helpers\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->user()->id;

        return [
            'name'   => ['sometimes', 'string', 'max:255'],
            'email'  => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($id)],
            // (BUG-06) 'estado' ya no es editable desde el perfil propio: sólo el
            // administrador lo cambia por PUT /users/{id}.
            // (BUG-17) Cambio de contraseña propio, con la contraseña actual y confirmación.
            'password'         => ['sometimes', 'string', 'min:8', 'confirmed'],
            'current_password' => ['required_with:password', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error(
                message: 'Error de validación.',
                errors: $validator->errors()->toArray(),
                status: 422
            )
        );
    }
}
