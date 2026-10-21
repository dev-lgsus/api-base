<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpiar caché de permisos
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Crear permisos
        $permissions = [
            'users.index',
            'users.show',
            'users.update',
            'users.delete',
            'transferencias-externas.store',
            'cuentas.search',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // (BUG-10) Antes los cinco roles recibían los seis permisos y el modelo no gobernaba
        // nada. Ahora cada rol recibe sólo lo que usa y las rutas lo exigen con permission:.
        // Los demás módulos siguen gobernados por role: en routes/api.php.
        $porRol = [
            'admin'               => $permissions,
            'banco'               => ['transferencias-externas.store', 'cuentas.search'],
            'gerente'             => [],
            'cajero'              => [],
            'servicio_al_cliente' => [],
        ];

        foreach ($porRol as $rol => $permisosDelRol) {
            Role::firstOrCreate(['name' => $rol])->syncPermissions($permisosDelRol);
        }
    }
}
