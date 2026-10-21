<?php

namespace App\Http\Controllers\Api\V1\Role;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\Api\V1\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Guard;

class RoleController extends Controller
{
    /**
     * Roles que routes/api.php y el portal referencian por nombre. Renombrarlos o
     * eliminarlos rompe la autorización (BUG-09), así que quedan protegidos.
     */
    private const ROLES_DEL_SISTEMA = ['admin', 'gerente', 'cajero', 'servicio_al_cliente', 'banco'];

    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->paginate(15);

        return ApiResponse::success(
            data: [
                'roles' => RoleResource::collection($roles->items()),
                'meta'  => [
                    'current_page' => $roles->currentPage(),
                    'last_page'    => $roles->lastPage(),
                    'per_page'     => $roles->perPage(),
                    'total'        => $roles->total(),
                ],
            ],
            message: 'Roles obtenidos correctamente.'
        );
    }

    public function indexAll(): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        return ApiResponse::success(
            data: RoleResource::collection($roles),
            message: 'Roles obtenidos correctamente.'
        );
    }

    public function show(int $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return ApiResponse::success(
            data: new RoleResource($role),
            message: 'Rol obtenido correctamente.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
        ]);

        // (BUG-08) Antes se escribía guard_name='sanctum' y assignRole (guard 'web') no lo
        // encontraba. Se usa el guard que Spatie resuelve para el modelo User ('web'), no
        // auth.defaults.guard, que dentro de una petición con Sanctum vale 'sanctum'.
        $role = Role::create(['name' => $validated['name'], 'guard_name' => Guard::getDefaultName(User::class)]);

        return ApiResponse::success(
            data: new RoleResource($role->load('permissions')),
            message: 'Rol creado correctamente.',
            status: 201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if (in_array($role->name, self::ROLES_DEL_SISTEMA, true)) {
            return ApiResponse::error(
                message: "El rol {$role->name} es un rol del sistema y no puede renombrarse.",
                status: 422
            );
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,' . $role->id],
        ]);

        $role->update(['name' => $validated['name']]);

        return ApiResponse::success(
            data: new RoleResource($role->fresh()->load('permissions')),
            message: 'Rol actualizado correctamente.'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if (in_array($role->name, self::ROLES_DEL_SISTEMA, true)) {
            return ApiResponse::error(
                message: "El rol {$role->name} es un rol del sistema y no puede eliminarse.",
                status: 422
            );
        }

        // (BUG-11) Se cuenta directamente en model_has_roles: la relación users() arrastra
        // el SoftDeletes de User y no veía a los usuarios eliminados lógicamente.
        $asignados = DB::table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.role_pivot_key') ?? 'role_id', $role->id)
            ->count();

        if ($asignados > 0) {
            return ApiResponse::error(
                message: 'No se puede eliminar el rol porque tiene usuarios asignados.',
                status: 409
            );
        }

        $role->delete();

        return ApiResponse::success(message: 'Rol eliminado correctamente.');
    }
}
