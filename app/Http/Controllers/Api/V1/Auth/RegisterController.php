<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Http\Helpers\ApiResponse;

class RegisterController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        // (BUG-01) El registro público ya no crea una cuenta operativa: el usuario nace
        // INACTIVO y SIN ROL, sin token, y queda pendiente de que un administrador le asigne
        // rol y lo active desde el mantenimiento de usuarios (opción "pendiente de aprobación"
        // de Q-02). Así un desconocido no obtiene ninguna sesión desde este endpoint.
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'estado' => false,
        ]);

        // (BUG-19) Se recarga el modelo para devolver el estado persistido, no un null.
        // (BUG-15) Ya no se devuelve el NewAccessToken completo: el registro no emite token.
        return ApiResponse::success(
            data: [
                'user' => new UserResource($user->fresh()),
            ],
            message: 'Registro recibido. La cuenta queda inactiva hasta que un administrador le asigne un rol y la active.',
            status: 201
        );
    }


}
