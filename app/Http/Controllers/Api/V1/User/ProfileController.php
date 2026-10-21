<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\User\StoreUserRequest;
use App\Http\Requests\Api\V1\User\UpdateProfileRequest;
use App\Http\Requests\Api\V1\User\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\AsignacionTicket;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show(UpdateProfileRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new UserResource($request->user()),
            message: 'Perfil obtenido correctamente.'
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // (BUG-17) La contraseña ya no se descarta en silencio: se cambia si la actual es correcta.
        if (isset($validated['password']) && ! Hash::check($validated['current_password'], $user->password)) {
            return ApiResponse::error(
                message: 'Error de validación.',
                errors: ['current_password' => ['La contraseña actual no es correcta.']],
                status: 422
            );
        }

        $user->update(collect($validated)->except(['current_password'])->toArray());

        return ApiResponse::success(
            data: new UserResource($user->fresh()),
            message: 'Perfil actualizado correctamente.'
        );
    }

    public function index(): JsonResponse
    {
        $users = User::with('roles')->paginate(15);

        return ApiResponse::success(
            data: [
                'users' => UserResource::collection($users->items()),
                'meta'  => [
                    'current_page' => $users->currentPage(),
                    'last_page'    => $users->lastPage(),
                    'per_page'     => $users->perPage(),
                    'total'        => $users->total(),
                ],
            ],
            message: 'Usuarios obtenidos correctamente.'
        );
    }

    public function showById(int $id): JsonResponse
    {
        $user = User::with('roles')->findOrFail($id);

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'Usuario obtenido correctamente.'
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // (BUG-08) Alta y asignación de rol en una sola transacción: si el rol no se puede
        // asignar, no queda un usuario creado y sin rol.
        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => $validated['password'],
                'estado'   => $validated['estado'] ?? true,
            ]);

            $user->assignRole($validated['role']);

            return $user;
        });

        return ApiResponse::success(
            data: new UserResource($user->load('roles')),
            message: 'Usuario creado correctamente.',
            status: 201
        );
    }

    public function updateById(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validated();

        // (BUG-04) Nunca puede quedar el sistema sin un administrador activo.
        $pierdeAdmin = (isset($validated['role']) && $validated['role'] !== 'admin')
            || (array_key_exists('estado', $validated) && ! $validated['estado']);

        if ($pierdeAdmin && $this->esUltimoAdminActivo($user)) {
            return ApiResponse::error(
                message: 'No se puede dejar el sistema sin un administrador activo.',
                status: 422
            );
        }

        DB::transaction(function () use ($user, $validated) {
            $user->update(collect($validated)->except('role')->toArray());

            if (isset($validated['role'])) {
                $user->syncRoles([$validated['role']]);
            }
        });

        // (BUG-07) Al desactivar a un usuario se cierran sus sesiones vivas.
        if (array_key_exists('estado', $validated) && ! $validated['estado']) {
            $user->tokens()->delete();
        }

        return ApiResponse::success(
            data: new UserResource($user->fresh()->load('roles')),
            message: 'Usuario actualizado correctamente.'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        // (BUG-18) El administrador no puede eliminarse a sí mismo ni eliminar al último admin.
        if ($user->id === auth()->id()) {
            return ApiResponse::error(message: 'No puedes eliminar tu propio usuario.', status: 422);
        }

        if ($this->esUltimoAdminActivo($user)) {
            return ApiResponse::error(
                message: 'No se puede dejar el sistema sin un administrador activo.',
                status: 422
            );
        }

        DB::transaction(function () use ($user) {
            // (BUG-18) Los tickets que atendía vuelven a la cola: se finaliza su asignación
            // activa y, si el ticket sólo estaba asignado, regresa a "abierto".
            $asignaciones = AsignacionTicket::where('id_usuario_asignado', $user->id)
                ->where('estado_asignacion', 'activa')->get();

            foreach ($asignaciones as $asignacion) {
                $asignacion->update(['estado_asignacion' => 'finalizada']);
                Ticket::where('id_ticket', $asignacion->id_ticket)
                    ->where('id_estado_ticket', 'asignado')
                    ->update(['id_estado_ticket' => 'abierto']);
            }

            // Sin tokens huérfanos que revivan si el usuario se restaura.
            $user->tokens()->delete();
            $user->delete();
        });

        return ApiResponse::success(message: 'Usuario eliminado correctamente.');
    }

    /**
     * Usuarios activos que pueden recibir un ticket (admin, gerente o servicio_al_cliente).
     * Lo consume atención al cliente en lugar de GET /users, reservado al administrador.
     */
    public function asignables(): JsonResponse
    {
        $users = User::with('roles')
            ->where('estado', true)
            ->role(['admin', 'gerente', 'servicio_al_cliente'])
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            data: UserResource::collection($users),
            message: 'Usuarios asignables obtenidos correctamente.'
        );
    }

    private function esUltimoAdminActivo(User $user): bool
    {
        if (! $user->estado || ! $user->hasRole('admin')) {
            return false;
        }

        return User::role('admin')->where('estado', true)->where('id', '!=', $user->id)->doesntExist();
    }
}
