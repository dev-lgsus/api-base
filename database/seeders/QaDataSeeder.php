<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mock Data sintetica de los modulos Autenticacion y acceso (REQ-01 a REQ-05)
 * y Usuarios y roles (REQ-06 a REQ-12). Entrega 2 - Diseno e Implementacion QA.
 *
 * Datos ficticios: ninguna informacion personal real ni extracto de produccion.
 *
 * Resuelve el prerrequisito bloqueante de TC-USR-010: crea los seis perfiles que el
 * Master Test Plan declara in-scope con guard_name 'web', el guard predeterminado del
 * modelo User segun config/auth.php. RolesAndPermissionsSeeder solo siembra tres, y
 * POST /v1/roles los escribe con guard_name 'sanctum', que assignRole no encuentra.
 *
 *   php artisan migrate:fresh
 *   php artisan db:seed --class=QaDataSeeder
 */
class QaDataSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ---------------------------------------------------------------- permisos
        $permisos = [
            'users.index',
            'users.show',
            'users.update',
            'users.delete',
            'transferencias-externas.store',
            'cuentas.search',
        ];

        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        // ------------------------------------------------- roles (los 6 perfiles + 3 de frontera)
        $roles = [
            ['name' => 'admin', 'permissions' => ['users.index', 'users.show', 'users.update', 'users.delete', 'transferencias-externas.store', 'cuentas.search']],
            ['name' => 'gerente', 'permissions' => ['users.index', 'users.show', 'users.update', 'users.delete', 'transferencias-externas.store', 'cuentas.search']],
            ['name' => 'cajero', 'permissions' => ['users.index', 'users.show', 'users.update', 'users.delete', 'transferencias-externas.store', 'cuentas.search']],
            ['name' => 'servicio_al_cliente', 'permissions' => ['users.index', 'users.show', 'users.update', 'users.delete', 'transferencias-externas.store', 'cuentas.search']],
            ['name' => 'banco', 'permissions' => ['users.index', 'users.show', 'users.update', 'users.delete', 'transferencias-externas.store', 'cuentas.search']],
            ['name' => 'rol_vacio', 'permissions' => []],
            ['name' => 'rol_uno', 'permissions' => []],
            ['name' => 'rol_borrado', 'permissions' => []],
        ];

        foreach ($roles as $definicion) {
            $rol = Role::firstOrCreate([
                'name'       => $definicion['name'],
                'guard_name' => 'web',
            ]);
            $rol->syncPermissions($definicion['permissions']);
        }

        // ---------------------------------------------------------------- usuarios de escenario
        // [nombre, correo, contrasena, estado, roles, eliminado_logicamente]
        $usuarios = [
            ['Administrador', 'admin@derbanks.com', 'password', true, ['admin'], false],
            ['Gerente', 'gerente@derbanks.com', 'Admin123.', true, ['gerente'], false],
            ['Cajero', 'cajero@derbanks.com', 'Admin123.', true, ['cajero'], false],
            ['Servicio al Cliente', 'servicio_cliente@derbanks.com', 'Admin123.', true, ['servicio_al_cliente'], false],
            ['Pasarela URBANK', 'banco@derbanks.com', 'Clave12345', true, ['banco'], false],
            ['Sin Rol Asignado', 'sinrol@derbanks.com', 'Clave12345', true, [], false],
            ['Ernesto Inactivo', 'inactivo@derbanks.com', 'Clave12345', false, ['cajero'], false],
            ['Activo De Control', 'activo@derbanks.com', 'Clave12345', true, ['cajero'], false],
            ['Paula Multirol', 'multirol@derbanks.com', 'Clave12345', true, ['gerente', 'cajero'], false],
            ['Empleado A', 'empleado.a@derbanks.com', 'Clave12345', true, ['cajero'], false],
            ['Empleado B', 'empleado.b@derbanks.com', 'Clave12345', true, ['cajero'], false],
            ['Empleado C', 'empleado.c@derbanks.com', 'Clave12345', true, ['cajero'], false],
            ['Auto Reactiva', 'autoreactiva@derbanks.com', 'Clave12345', true, ['cajero'], false],
            ['Rodrigo Sesion', 'empleado@derbanks.com', 'Clave12345', true, ['cajero'], false],
            ['Agente Tickets', 'agente@derbanks.com', 'Clave12345', true, ['servicio_al_cliente'], false],
            ['Por Eliminar', 'a.eliminar@derbanks.com', 'Clave12345', true, ['cajero'], false],
            ['Unico Del Rol', 'unico.rol@derbanks.com', 'Clave12345', true, ['rol_uno'], false],
            ['Borrado Del Rol', 'borrado.rol@derbanks.com', 'Clave12345', true, ['rol_borrado'], true],
            ['Otro Empleado', 'otro.empleado@derbanks.com', 'Clave12345', true, ['cajero'], false],
        ];

        foreach ($usuarios as [$nombre, $correo, $clave, $estado, $rolesDelUsuario, $eliminado]) {
            $usuario = User::withTrashed()->firstOrNew(['email' => $correo]);
            $usuario->name     = $nombre;
            $usuario->password = Hash::make($clave);
            $usuario->estado   = $estado;
            $usuario->deleted_at = null;
            $usuario->save();

            // syncRoles reemplaza el conjunto; admite varios para el caso TC-USR-001.
            $usuario->syncRoles($rolesDelUsuario);

            if ($eliminado) {
                // Borrado logico DESPUES de asignar el rol: la fila de model_has_roles
                // permanece, que es justo la frontera que ejercita TC-USR-019.
                $usuario->delete();
            }
        }

        // ---------------------------------------------------------------- padron de volumen
        // 60 usuarios para ejercitar las fronteras de paginacion de TC-USR-005.
        // Uno de cada diez queda inactivo.
        $nombres   = ['Ana', 'Luis', 'Marta', 'Diego', 'Sofia', 'Pablo', 'Elena', 'Mario', 'Nadia', 'Oscar', 'Lucia', 'Hugo', 'Irene', 'Tomas', 'Rocio', 'Felipe', 'Alba', 'Ivan', 'Celia', 'Ruben'];
        $apellidos = ['Alvarez', 'Beltran', 'Cardona', 'Duarte', 'Escobar', 'Fuentes', 'Guzman', 'Herrera', 'Iriarte', 'Juarez', 'Lemus', 'Mejia', 'Narvaez', 'Ochoa', 'Pineda'];

        for ($i = 1; $i <= 60; $i++) {
            $usuario = User::firstOrNew([
                'email' => sprintf('qa.usuario%03d@derbanks.com', $i),
            ]);
            $usuario->name     = $nombres[($i - 1) % count($nombres)]
                               . ' ' . $apellidos[($i - 1) % count($apellidos)];
            $usuario->password = Hash::make('Clave12345');
            $usuario->estado   = ($i % 10 !== 0);
            $usuario->save();
            $usuario->syncRoles(['banco']);
        }

        $this->command->info('QaDataSeeder: ' . count($roles) . ' roles, ' . count($permisos) . ' permisos y '
            . (count($usuarios) + 60) . ' usuarios sinteticos.');
    }
}
