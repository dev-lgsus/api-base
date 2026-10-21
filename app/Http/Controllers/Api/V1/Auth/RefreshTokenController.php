<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Http\Helpers\ApiResponse;

class RefreshTokenController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user   = $request->user();
        $actual = $user->currentAccessToken();

        // (BUG-13) Un usuario desactivado no puede renovar: se revoca su token.
        // Se lee el estado persistido: el modelo en memoria puede no traer la columna cargada.
        if (! User::whereKey($user->getKey())->value('estado')) {
            $user->tokens()->delete();

            return ApiResponse::error(
                message: 'Usuario inactivo. Contacta al administrador.',
                status: 403
            );
        }

        // (BUG-13) Duración máxima absoluta de la sesión, contada desde el login original.
        $inicio = $this->inicioDeSesion($actual);
        $limite = $inicio->copy()->addMinutes(config('sanctum.max_session'));

        if (now()->greaterThanOrEqualTo($limite)) {
            $actual->delete();

            return ApiResponse::error(
                message: 'La sesión alcanzó su duración máxima. Inicia sesión nuevamente.',
                status: 401
            );
        }

        $actual->delete();

        $token = $user->createToken(
            name: 'auth_token',
            abilities: ['*', 'sesion_inicio:' . $inicio->timestamp],
            expiresAt: now()->addMinutes(config('sanctum.expiration'))->min($limite)
        );

        return ApiResponse::success(
            data: [
                'user'    => new UserResource($user),
                'token'   => $token->plainTextToken,
            ],
            message: 'Token renovado exitosamente.'
        );
    }

    private function inicioDeSesion($token): Carbon
    {
        $abilities = is_array($token->abilities ?? null) ? $token->abilities : [];

        foreach ($abilities as $ability) {
            if (str_starts_with($ability, 'sesion_inicio:')) {
                // Con la zona de la app: createFromTimestamp devuelve UTC y el tope quedaba corrido.
                return Carbon::createFromTimestamp((int) substr($ability, 14), config('app.timezone'));
            }
        }

        // Tokens emitidos antes de esta corrección: se toma su fecha de creación. Un token
        // no persistido (sin created_at) cuenta como una sesión que empieza ahora.
        return $token->created_at ? Carbon::parse($token->created_at) : now();
    }
}
