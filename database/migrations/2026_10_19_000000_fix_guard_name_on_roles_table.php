<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Entrega 3 (BUG-08): POST /v1/roles escribía guard_name='sanctum' y esos roles no se
 * podían asignar (assignRole busca en 'web'). El controlador ya se corrigió; esta
 * migración repara los roles creados antes de la corrección.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabla = config('permission.table_names.roles', 'roles');

        $heredados = DB::table($tabla)->where('guard_name', 'sanctum')->pluck('name');
        $yaEnWeb = DB::table($tabla)->where('guard_name', 'web')->whereIn('name', $heredados)->pluck('name');

        // Si ya existe el mismo nombre en 'web' se deja el heredado intacto para no violar
        // el índice único (name, guard_name); queda para revisión manual del administrador.
        DB::table($tabla)
            ->where('guard_name', 'sanctum')
            ->whereNotIn('name', $yaEnWeb)
            ->update(['guard_name' => 'web']);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Irreversible a propósito: devolver los roles a 'sanctum' reintroduce el defecto.
    }
};
