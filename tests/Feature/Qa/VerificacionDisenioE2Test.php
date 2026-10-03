<?php

/**
 * Verificacion de los resultados esperados declarados en los casos de prueba
 * del Integrante 1 (Entrega 2): modulos AUT (REQ-01..05) y USR (REQ-06..12).
 *
 * No prueba el sistema: prueba que el DISENO de los casos coincide con el codigo.
 * Corre sobre SQLite en memoria y cache en array, segun phpunit.xml.
 */

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $permisos = ['users.index', 'users.show', 'users.update', 'users.delete',
                 'transferencias-externas.store', 'cuentas.search'];
    foreach ($permisos as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    foreach (['admin', 'gerente', 'cajero', 'servicio_al_cliente', 'banco'] as $nombre) {
        Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web'])->syncPermissions($permisos);
    }

    $this->crear = function (string $email, ?string $rol, bool $estado = true): User {
        $u = User::forceCreate([
            'name' => $email, 'email' => $email,
            'password' => Hash::make('Clave12345'), 'estado' => $estado,
        ]);
        if ($rol) {
            $u->assignRole($rol);
        }
        return $u;
    };
});

// ---------------------------------------------------------------- TC-AUT-009
it('TC-AUT-009: el login de un usuario inactivo responde 403 con el mensaje de CA-08', function () {
    ($this->crear)('inactivo@derbanks.com', 'cajero', false);

    $this->postJson('/v1/auth/login', ['email' => 'inactivo@derbanks.com', 'password' => 'Clave12345'])
        ->assertStatus(403)
        ->assertJson(['status' => false, 'message' => 'Usuario inactivo. Contacta al administrador.']);

    expect(User::where('email', 'inactivo@derbanks.com')->first()->tokens()->count())->toBe(0);
});

it('TC-AUT-009: la contrasena se valida ANTES del estado', function () {
    ($this->crear)('inactivo2@derbanks.com', 'cajero', false);

    $this->postJson('/v1/auth/login', ['email' => 'inactivo2@derbanks.com', 'password' => 'incorrecta'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Credenciales incorrectas.']);
});

// ---------------------------------------------------------------- TC-AUT-001
it('TC-AUT-001: el registro publico crea un usuario activo y sin rol (R-02)', function () {
    $res = $this->postJson('/v1/auth/register', [
        'name' => 'Nuevo Empleado', 'email' => 'nuevo.empleado@derbanks.com',
        'password' => 'Clave12345', 'password_confirmation' => 'Clave12345',
    ])->assertStatus(201)->assertJson(['message' => 'Usuario registrado exitosamente.']);

    // DEFECTO DE CONTRATO: la respuesta trae estado=null aunque la base guarde 1,
    // porque el modelo no recarga el atributo despues del insert.
    $res->assertJsonPath('data.user.estado', null)
        ->assertJsonPath('data.user.roles', null)
        ->assertJsonPath('data.user.permissions', []);
    expect(User::where('email', 'nuevo.empleado@derbanks.com')->first()->estado)->toBeTrue();

    // No existe ningun rol de minimo privilegio al que el controlador pudiera recurrir.
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'banco', 'cajero', 'gerente', 'servicio_al_cliente']);
});

// ---------------------------------------------------------------- TC-USR-006
it('TC-USR-006: el grupo rotulado "Solo admin" admite CUATRO roles (R-03)', function () {
    $esperado = [
        'admin' => 200, 'gerente' => 200, 'cajero' => 200, 'servicio_al_cliente' => 200,
        'banco' => 403,
    ];

    foreach ($esperado as $rol => $codigo) {
        $u = ($this->crear)($rol.'@derbanks.com', $rol);
        Laravel\Sanctum\Sanctum::actingAs($u);
        $this->getJson('/v1/users')->assertStatus($codigo);
        $this->getJson('/v1/roles')->assertStatus($codigo);
        $this->app['auth']->forgetGuards();
    }

    // /v1/auditoria esta en el MISMO grupo, pero devuelve 500 sin MongoDB disponible:
    // se verifica el middleware declarado en lugar de la respuesta.
    $mw = collect(Route::getRoutes()->getByName('auditoria.index')->gatherMiddleware())
        ->map(fn ($m) => (string) $m);
    expect($mw->contains('role:admin|gerente|servicio_al_cliente|cajero'))->toBeTrue();

    // El usuario sin rol tampoco entra.
    Laravel\Sanctum\Sanctum::actingAs(($this->crear)('sinrol@derbanks.com', null));
    $this->getJson('/v1/users')->assertStatus(403);
});

it('TC-USR-006: delimitacion de los grupos G3, G4 y G5', function () {
    $matriz = [
        // rol                  dashboard  transacciones  clientes
        'admin'               => [200, 200, 200],
        'gerente'             => [200, 200, 200],
        'cajero'              => [403, 200, 403],
        'servicio_al_cliente' => [403, 403, 200],
        'banco'               => [403, 403, 403],
    ];

    foreach ($matriz as $rol => [$dash, $trx, $cli]) {
        Laravel\Sanctum\Sanctum::actingAs(($this->crear)($rol.'.g345@derbanks.com', $rol));
        expect($this->getJson('/v1/dashboard/summary')->status() === 403)->toBe($dash === 403);
        expect($this->getJson('/v1/transacciones')->status() === 403)->toBe($trx === 403);
        expect($this->getJson('/v1/clientes')->status() === 403)->toBe($cli === 403);
        $this->app['auth']->forgetGuards();
    }
});

it('TC-USR-006: sin token responde 401 con el envoltorio ApiResponse', function () {
    $this->getJson('/v1/users')
        ->assertStatus(401)
        ->assertJson(['status' => false, 'message' => 'No autenticado.']);
});

// ---------------------------------------------------------------- TC-USR-012
it('TC-USR-012: un cajero se asigna a si mismo el rol admin', function () {
    $cajero = ($this->crear)('cajero.escala@derbanks.com', 'cajero');
    Laravel\Sanctum\Sanctum::actingAs($cajero);

    $this->getJson('/v1/users')->assertStatus(200);
    $this->getJson('/v1/dashboard/summary')->assertStatus(403);

    $this->putJson('/v1/users/'.$cajero->id, ['role' => 'admin'])
        ->assertStatus(200)
        ->assertJsonPath('data.roles.name', 'admin');

    expect($cajero->fresh()->hasRole('admin'))->toBeTrue();

    $this->app['auth']->forgetGuards();
    Laravel\Sanctum\Sanctum::actingAs($cajero->fresh());
    $this->getJson('/v1/dashboard/summary')->assertStatus(200);
});

// ---------------------------------------------------------------- TC-USR-017
it('TC-USR-017: los cinco roles tienen los seis permisos y ninguna ruta los lee (R-04)', function () {
    foreach (['admin', 'gerente', 'cajero', 'servicio_al_cliente', 'banco'] as $rol) {
        expect(Role::where('name', $rol)->first()->permissions()->count())->toBe(6);
    }

    $v1 = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'v1/'));
    $conPermiso = $v1->filter(fn ($r) => collect($r->gatherMiddleware())
        ->contains(fn ($m) => is_string($m) && str_contains($m, 'permission:')));
    $conRol = $v1->filter(fn ($r) => collect($r->gatherMiddleware())
        ->contains(fn ($m) => is_string($m) && str_contains($m, 'role:')));

    expect($v1->count())->toBe(62);
    expect($conPermiso->count())->toBe(0);
    expect($conRol->count())->toBe(54);
});

// ---------------------------------------------------------------- TC-AUT-004
it('TC-AUT-004: las dos rutas de dinero no declaran rol ni permiso (R-01)', function () {
    foreach (['transferencias-externas.store', 'cuentas.search'] as $nombre) {
        $ruta = Route::getRoutes()->getByName($nombre);
        $mw = collect($ruta->gatherMiddleware())->map(fn ($m) => (string) $m);

        expect($mw->contains(fn ($m) => str_contains($m, 'RoleMiddleware')))->toBeFalse();
        expect($mw->contains(fn ($m) => str_contains($m, 'PermissionMiddleware')))->toBeFalse();
        expect($mw->contains(fn ($m) => str_contains($m, 'RegisterAuditLog')))->toBeFalse();
        expect($mw->contains('auth:sanctum'))->toBeTrue();
    }
});

// ---------------------------------------------------------------- TC-USR-003
it('TC-USR-003: un usuario desactivado se reactiva a si mismo desde su perfil', function () {
    $u = ($this->crear)('autoreactiva@derbanks.com', 'cajero', false);
    Laravel\Sanctum\Sanctum::actingAs($u);

    $this->putJson('/v1/users/me', ['estado' => true])
        ->assertStatus(200)
        ->assertJsonPath('data.estado', true);

    expect($u->fresh()->estado)->toBeTrue();
});

// ---------------------------------------------------------------- TC-USR-002
it('TC-USR-002: el perfil propio descarta el campo password en silencio', function () {
    $u = ($this->crear)('empleado.pwd@derbanks.com', 'cajero');
    Laravel\Sanctum\Sanctum::actingAs($u);

    $this->putJson('/v1/users/me', ['name' => 'Cambiado', 'password' => 'NuevaClave999'])
        ->assertStatus(200);

    expect($u->fresh()->name)->toBe('Cambiado');
    expect(Hash::check('Clave12345', $u->fresh()->password))->toBeTrue();
    expect(Hash::check('NuevaClave999', $u->fresh()->password))->toBeFalse();
});

// ---------------------------------------------------------------- TC-USR-010
it('TC-USR-010: un rol creado por POST /roles queda en otro guard y rompe el alta', function () {
    Laravel\Sanctum\Sanctum::actingAs(($this->crear)('admin.guard@derbanks.com', 'admin'));

    $this->postJson('/v1/roles', ['name' => 'supervisor'])->assertStatus(201);

    expect(Role::where('name', 'supervisor')->first()->guard_name)->toBe('sanctum');
    expect(Role::where('name', 'cajero')->first()->guard_name)->toBe('web');

    $res = $this->postJson('/v1/users', [
        'name' => 'Supervisor Uno', 'email' => 'supervisor.uno@derbanks.com',
        'password' => 'Clave12345', 'role' => 'supervisor',
    ]);

    $res->assertStatus(500);
    expect($res->json('exception'))->toBe(Spatie\Permission\Exceptions\RoleDoesNotExist::class);
    expect($res->json('message'))->toContain('There is no role named `supervisor` for guard `web`');

    // AGRAVANTE: store no usa transaccion, asi que el usuario queda creado y sin rol.
    $huerfano = User::where('email', 'supervisor.uno@derbanks.com')->first();
    expect($huerfano)->not->toBeNull();
    expect($huerfano->roles->count())->toBe(0);
    expect(DB::table('model_has_roles')->where('model_id', $huerfano->id)->count())->toBe(0);
});

// ---------------------------------------------------------------- TC-USR-019
it('TC-USR-019: la guarda de eliminacion no ve al usuario eliminado logicamente', function () {
    Laravel\Sanctum\Sanctum::actingAs(($this->crear)('admin.roles@derbanks.com', 'admin'));

    $vacio = Role::create(['name' => 'rol_vacio', 'guard_name' => 'web']);
    $uno = Role::create(['name' => 'rol_uno', 'guard_name' => 'web']);
    $borrado = Role::create(['name' => 'rol_borrado', 'guard_name' => 'web']);

    ($this->crear)('unico.rol@derbanks.com', 'rol_uno');
    $aBorrar = ($this->crear)('borrado.rol@derbanks.com', 'rol_borrado');

    // Frontera 0 usuarios -> se elimina
    $this->deleteJson('/v1/roles/'.$vacio->id)->assertStatus(200);
    expect($vacio->fresh()->deleted_at)->not->toBeNull();

    // Frontera 1 usuario activo -> se bloquea
    $this->deleteJson('/v1/roles/'.$uno->id)
        ->assertStatus(409)
        ->assertJson(['message' => 'No se puede eliminar el rol porque tiene usuarios asignados.']);

    // Frontera 1 usuario eliminado logicamente -> SE ELIMINA (el defecto)
    $aBorrar->delete();
    expect($borrado->users()->count())->toBe(0);
    expect(DB::table('model_has_roles')->where('role_id', $borrado->id)->count())->toBe(1);

    $this->deleteJson('/v1/roles/'.$borrado->id)->assertStatus(200);

    // Al restaurar al usuario queda SIN rol efectivo, que es lo que REQ-12 prohibe.
    $aBorrar->restore();
    expect($aBorrar->fresh()->roles->count())->toBe(0);
    expect($aBorrar->fresh()->hasRole('rol_borrado'))->toBeFalse();
});

// ---------------------------------------------------------------- TC-USR-005
it('TC-USR-005: per_page se ignora y el tamano de pagina es fijo en 15', function () {
    Laravel\Sanctum\Sanctum::actingAs(($this->crear)('admin.pag@derbanks.com', 'admin'));
    for ($i = 1; $i <= 20; $i++) {
        ($this->crear)(sprintf('qa.usuario%03d@derbanks.com', $i), 'banco');
    }

    foreach ([null, 0, 1, 100, 101] as $perPage) {
        $uri = '/v1/users'.($perPage === null ? '' : '?per_page='.$perPage);
        $res = $this->getJson($uri)->assertStatus(200);
        expect(count($res->json('data.users')))->toBe(15);
        expect($res->json('data.meta.per_page'))->toBe(15);
    }

    $res = $this->getJson('/v1/users?page=999')->assertStatus(200);
    expect($res->json('data.users'))->toBe([]);
});

// ---------------------------------------------------------------- TC-USR-001
it('TC-USR-001: UserResource trunca los roles al primero', function () {
    $u = ($this->crear)('multirol@derbanks.com', null);
    $u->syncRoles(['gerente', 'cajero']);
    Laravel\Sanctum\Sanctum::actingAs($u);

    expect($u->fresh()->roles->count())->toBe(2);

    $res = $this->getJson('/v1/users/me')->assertStatus(200);
    expect($res->json('data.roles'))->toBeArray();           // un objeto, no una lista
    expect($res->json('data.roles.name'))->not->toBeNull();
    expect($res->json('data.roles.0'))->toBeNull();          // no hay segundo elemento
});
