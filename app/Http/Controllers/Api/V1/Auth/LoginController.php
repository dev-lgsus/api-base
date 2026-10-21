<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Helpers\ApiResponse;

class LoginController extends Controller
{
    /**
     * @OA\Post(
     *     path="/auth/login",
     *     summary="Iniciar sesión",
     *     description="Autentica al usuario con email y contraseña. Retorna un token Sanctum para usar en endpoints protegidos.",
     *     operationId="authLogin",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email",    type="string", format="email",    example="admin@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Login exitoso.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status",  type="boolean", example=true),
     *             @OA\Property(property="message", type="string",  example="Login exitoso."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="user",
     *                     type="object",
     *                     @OA\Property(property="id",          type="integer", example=1),
     *                     @OA\Property(property="name",        type="string",  example="Admin"),
     *                     @OA\Property(property="email",       type="string",  example="admin@example.com"),
     *                     @OA\Property(property="estado",      type="boolean", example=true),
     *                     @OA\Property(property="roles",       type="object"),
     *                     @OA\Property(property="permissions", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="created_at",  type="string", format="date-time"),
     *                     @OA\Property(property="updated_at",  type="string", format="date-time")
     *                 ),
     *                 @OA\Property(property="token", type="string", example="1|abc123xyz...")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Credenciales incorrectas.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status",  type="boolean", example=false),
     *             @OA\Property(property="message", type="string",  example="Credenciales incorrectas.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Error de validación.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status",  type="boolean", example=false),
     *             @OA\Property(property="message", type="string",  example="Error de validación."),
     *             @OA\Property(property="errors",  type="object")
     *         )
     *     )
     *      @OA\Response(
     *         response=403,
     *         description="Usuario inactivo.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status",  type="boolean", example=false),
     *             @OA\Property(property="message", type="string",  example="Usuario inactivo. Contacta al administrador.")
     *         )
     *     )
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        if (!Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            return ApiResponse::error(
                message: 'Credenciales incorrectas.',
                status: 401
            );
        }

        if (!Auth::user()->estado) {
            return ApiResponse::error(
                message: 'Usuario inactivo. Contacta al administrador.',
                status: 403
            );
        }

        /** @var User $user */
        $user = Auth::user();

        $user->tokens()->where('name', 'auth_token')->delete();

        // (BUG-13) El token lleva la marca de inicio de la sesión para que la renovación
        // pueda aplicar una duración máxima absoluta (config sanctum.max_session).
        $token = $user->createToken(
            name: 'auth_token',
            abilities: ['*', 'sesion_inicio:' . now()->timestamp],
            expiresAt: now()->addMinutes(config('sanctum.expiration'))
        );

        return ApiResponse::success(
            data: [
                'user'    => new UserResource($user),
                'token'   => $token->plainTextToken,
            ],
            message: 'Login exitoso.'
        );
    }
}
