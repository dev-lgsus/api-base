<?php

/**
 * Entrega 3 - Ciclo 2 (re-test y regresion) del Integrante 1 (Lider de QA).
 * Los mismos 34 casos del ciclo 1 (TC-AUT-001..015, TC-USR-001..019), ahora con las
 * aserciones del COMPORTAMIENTO CORRECTO que pide cada requerimiento. Cada defecto
 * critico o alto corregido (BUG-01..BUG-20) queda cubierto por al menos una prueba,
 * como exige la regla de regresion del MTP (8.9).
 *
 * Evidencia: storage/qa/evidencia_e3_ciclo2.jsonl (una linea por caso).
 * Corre sobre SQLite en memoria (phpunit.xml); ningun caso depende de bloqueo de filas.
 */

use App\Models\AsignacionTicket;
use App\Models\Cliente;
use App\Models\Cuentas;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

function ev2(string $caso, mixed $observado): void
{
    $dir = storage_path('qa');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir.'/evidencia_e3_ciclo2.jsonl', json_encode([
        'caso' => $caso, 'observado' => $observado, 'ts' => now()->toIso8601String(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
}

beforeEach(function () {
    // El API se consume sin cookie de sesion: Sanctum resuelve solo por Bearer.
    config(['sanctum.guard' => []]);

    // Roles y permisos tal como los siembra el seeder corregido (BUG-10).
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->audit = new class(app(AuditLogService::class)) extends AuditLogService {
        public array $registros = [];
        public function __construct($base) {}
        public function registrar(array $atributos): ?\App\Models\Mongo\AuditLog
        {
            $this->registros[] = $atributos;
            return null;
        }
    };
    $this->app->instance(AuditLogService::class, $this->audit);

    $this->crear = function (string $email, ?string $rol, bool $estado = true, string $pwd = 'Clave12345'): User {
        $u = User::forceCreate(['name' => $email, 'email' => $email, 'password' => Hash::make($pwd), 'estado' => $estado]);
        if ($rol) {
            $u->assignRole($rol);
        }
        return $u;
    };

    $this->cuenta = function (string $numero = '1234567890', float $saldo = 1000): Cuentas {
        $cli = Cliente::forceCreate([
            'nombres' => 'Cliente', 'apellidos' => 'Sintetico '.$numero, 'dpi' => 'DPI'.$numero,
            'direccion' => 'Zona 1', 'telefono' => '55550000', 'correo_electronico' => $numero.'@cliente.test', 'estado' => true,
        ]);
        return Cuentas::forceCreate([
            'id_cliente' => $cli->id, 'numero_cuenta' => $numero, 'saldo' => $saldo, 'saldo_disponible' => $saldo,
            'tipo_cuenta' => 'monetaria', 'moneda' => 'Q', 'estado' => true, 'fecha_apertura' => now(),
        ]);
    };

    $this->login = function (string $email, string $pwd = 'Clave12345') {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->app['session']->flush();
        $this->flushHeaders();
        return $this->postJson('/v1/auth/login', ['email' => $email, 'password' => $pwd]);
    };

    $this->bearer = function (string $token) {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->flushHeaders();
        return $this->withHeader('Authorization', 'Bearer '.$token);
    };

    $this->como = function (User $u) {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        Sanctum::actingAs($u->fresh());
    };

    $this->transferencia = fn (int $cuentaId, string $codigo) => [
        'tipo' => 'entrante', 'moneda' => 'Q', 'monto' => 500.00, 'banco_externo' => 'urbank',
        'cuenta_externa' => '1234567890', 'codigo_confirmacion' => $codigo, 'id_cuenta_destino' => $cuentaId,
    ];
});

// ======================================================= AUTENTICACION Y ACCESO

it('TC-AUT-001: el registro publico crea un usuario inactivo, sin rol y sin token (BUG-01, BUG-19)', function () {
    $res = $this->postJson('/v1/auth/register', ['name' => 'Nuevo Empleado', 'email' => 'nuevo.empleado@derbanks.com',
        'password' => 'Clave12345', 'password_confirmation' => 'Clave12345']);
    $u = User::where('email', 'nuevo.empleado@derbanks.com')->first();
    $login = ($this->login)('nuevo.empleado@derbanks.com');
    ev2('TC-AUT-001', ['http' => $res->status(), 'message' => $res->json('message'), 'data.user.estado' => $res->json('data.user.estado'),
        'db.estado' => $u->estado, 'roles' => $res->json('data.user.roles'), 'trae_token' => $res->json('data.token') !== null,
        'login' => [$login->status(), $login->json('message')]]);
    $res->assertStatus(201)->assertJsonPath('data.user.estado', false)->assertJsonPath('data.user.roles', []);
    expect($res->json('data.token'))->toBeNull();
    expect($u->estado)->toBeFalse();
    $login->assertStatus(403);
});

it('TC-AUT-002: registro rechazado por correo duplicado y sin confirmacion', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $antes = User::count();
    $a = $this->postJson('/v1/auth/register', ['name' => 'D', 'email' => 'admin@derbanks.com', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345']);
    $b = $this->postJson('/v1/auth/register', ['name' => 'S', 'email' => 'sin.confirmacion@derbanks.com', 'password' => 'Clave12345']);
    ev2('TC-AUT-002', ['A' => [$a->status(), $a->json('errors')], 'B' => [$b->status(), $b->json('errors')], 'filas_nuevas' => User::count() - $antes]);
    $a->assertStatus(422)->assertJsonValidationErrors('email');
    $b->assertStatus(422)->assertJsonValidationErrors('password');
    expect(User::count())->toBe($antes);
});

it('TC-AUT-003: limites de longitud de contrasena 7/8 en el registro', function () {
    $r = [];
    foreach (['A' => ['limite7@derbanks.com', 'Clave12'], 'B' => ['limite8@derbanks.com', 'Clave123'], 'C' => ['debil@derbanks.com', '12345678']] as $k => [$m, $p]) {
        $r[$k] = $this->postJson('/v1/auth/register', ['name' => $k, 'email' => $m, 'password' => $p, 'password_confirmation' => $p])->status();
    }
    ev2('TC-AUT-003', $r);
    expect($r)->toBe(['A' => 422, 'B' => 201, 'C' => 201]);
});

it('TC-AUT-004: un usuario sin rol ya no acredita dinero; banco si, y queda auditado (BUG-02)', function () {
    $cuenta = ($this->cuenta)('1234567890', 1000);
    $sinRol = ($this->crear)('externo@anon.test', null);
    $tSin = ($this->login)('externo@anon.test')->json('data.token');
    $pago = ($this->bearer)($tSin)->postJson('/v1/transferencias-externas', ($this->transferencia)($cuenta->id, 'TRX-R01-0001'));
    $busq = ($this->bearer)($tSin)->getJson('/v1/cuentas/search/1234567890');
    $saldoTrasIntento = (float) Cuentas::find($cuenta->id)->saldo;

    ($this->crear)('banco@derbanks.com', 'banco');
    $tBanco = ($this->login)('banco@derbanks.com')->json('data.token');
    $pagoBanco = ($this->bearer)($tBanco)->postJson('/v1/transferencias-externas', ($this->transferencia)($cuenta->id, 'TRX-R01-0002'));
    $busqBanco = ($this->bearer)($tBanco)->getJson('/v1/cuentas/search/1234567890');
    $this->app['auth']->forgetGuards();
    $this->flushHeaders();
    $sinToken = $this->postJson('/v1/transferencias-externas', [])->status();
    $auditado = collect($this->audit->registros)->contains(fn ($x) => str_contains((string) data_get($x, 'http.ruta'), 'transferencias-externas'));

    ev2('TC-AUT-004', ['sin_rol.acreditacion' => $pago->status(), 'sin_rol.search' => $busq->status(), 'saldo_tras_intento' => $saldoTrasIntento,
        'banco.acreditacion' => $pagoBanco->status(), 'banco.search' => $busqBanco->status(),
        'saldo_final' => (float) Cuentas::find($cuenta->id)->saldo, 'sin_token' => $sinToken, 'registrado_en_bitacora' => $auditado]);
    expect($pago->status())->toBe(403);
    expect($busq->status())->toBe(403);
    expect($saldoTrasIntento)->toBe(1000.0);
    expect($pagoBanco->status())->toBe(201);
    expect($busqBanco->status())->toBe(200);
    expect($sinToken)->toBe(401);
    expect($auditado)->toBeTrue();
});

it('TC-AUT-005: el registro ya no expone el registro del token; login devuelve cadena (BUG-15)', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $reg = $this->postJson('/v1/auth/register', ['name' => 'C', 'email' => 'contrato.token@derbanks.com', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345']);
    $log = ($this->login)('admin@derbanks.com', 'password');
    ev2('TC-AUT-005', ['register.data_claves' => array_keys($reg->json('data')), 'login.token_tipo' => gettype($log->json('data.token'))]);
    expect(array_keys($reg->json('data')))->toBe(['user']);
    expect($log->json('data.token'))->toBeString();
});

it('TC-AUT-006: login exitoso con token de 8 horas, lista de roles y permisos propios del rol', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    ($this->crear)('gerente@derbanks.com', 'gerente', true, 'Admin123.');
    $t0 = now();
    $a = ($this->login)('admin@derbanks.com', 'password');
    $tok = DB::table('personal_access_tokens')->latest('id')->first();
    $g = ($this->login)('gerente@derbanks.com', 'Admin123.');
    ev2('TC-AUT-006', ['admin' => [$a->status(), $a->json('data.user.roles'), count($a->json('data.user.permissions'))],
        'vigencia_min' => round($t0->diffInMinutes(\Carbon\Carbon::parse($tok->expires_at))), 'gerente.permissions' => $g->json('data.user.permissions')]);
    $a->assertStatus(200)->assertJsonPath('data.user.roles.0.name', 'admin');
    expect(count($a->json('data.user.permissions')))->toBe(6);
    expect(round($t0->diffInMinutes(\Carbon\Carbon::parse($tok->expires_at))))->toEqual(480);
    $g->assertStatus(200);
    expect($g->json('data.user.permissions'))->toBe([]);
});

it('TC-AUT-007: login con correo mal formado responde 422 y no 500 (BUG-12)', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $a = ($this->login)('admin@derbanks.com', 'incorrecta');
    $b = ($this->login)('noexiste@derbanks.com', 'password');
    $c = ($this->login)('admin-derbanks.com', 'password');
    ev2('TC-AUT-007', ['A' => [$a->status(), $a->json('message')], 'B' => [$b->status(), $b->json('message')], 'C' => [$c->status(), $c->json('errors.email')]]);
    $a->assertStatus(401);
    $b->assertStatus(401);
    expect($a->json('message'))->toBe($b->json('message'));
    $c->assertStatus(422)->assertJsonPath('message', 'Error de validación.');
    expect($c->json('errors.email'))->toBeArray();
});

it('TC-AUT-008: el segundo login revoca el token del primero', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $t1 = ($this->login)('admin@derbanks.com', 'password')->json('data.token');
    $t2 = ($this->login)('admin@derbanks.com', 'password')->json('data.token');
    $r1 = ($this->bearer)($t1)->getJson('/v1/users/me')->status();
    $r2 = ($this->bearer)($t2)->getJson('/v1/users/me')->status();
    ev2('TC-AUT-008', ['tokenA' => $r1, 'tokenB' => $r2]);
    expect([$r1, $r2])->toBe([401, 200]);
});

it('TC-AUT-009: login de usuario inactivo responde 403 y no emite token', function () {
    ($this->crear)('inactivo@derbanks.com', 'cajero', false);
    ($this->crear)('activo@derbanks.com', 'cajero');
    $a = ($this->login)('inactivo@derbanks.com');
    $b = ($this->login)('inactivo@derbanks.com', 'incorrecta');
    $c = ($this->login)('activo@derbanks.com');
    ev2('TC-AUT-009', ['inactivo' => $a->status(), 'clave_erronea' => $b->status(), 'activo' => $c->status()]);
    expect([$a->status(), $b->status(), $c->status()])->toBe([403, 401, 200]);
});

it('TC-AUT-010: desactivar a un usuario revoca su sesion activa (BUG-07)', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $emp = ($this->crear)('empleado@derbanks.com', 'cajero');
    $cuenta = ($this->cuenta)('5550001', 100);
    $tokEmp = ($this->login)('empleado@derbanks.com')->json('data.token');
    $tokAdm = ($this->login)('admin@derbanks.com', 'password')->json('data.token');
    $des = ($this->bearer)($tokAdm)->putJson('/v1/users/'.$emp->id, ['estado' => false]);
    $me = ($this->bearer)($tokEmp)->getJson('/v1/users/me')->status();
    $dep = ($this->bearer)($tokEmp)->postJson('/v1/transacciones', ['tipo_transaccion' => 'deposito', 'moneda' => 'Q', 'monto' => 50, 'id_cuenta_destino' => $cuenta->id])->status();
    $relog = ($this->login)('empleado@derbanks.com')->status();
    ev2('TC-AUT-010', ['desactivar' => $des->status(), 'me' => $me, 'deposito' => $dep, 'saldo' => (float) Cuentas::find($cuenta->id)->saldo,
        'relogin' => $relog, 'tokens_empleado' => $emp->tokens()->count()]);
    $des->assertStatus(200);
    expect([$me, $dep, $relog])->toBe([401, 401, 403]);
    expect((float) Cuentas::find($cuenta->id)->saldo)->toBe(100.0);
    expect($emp->tokens()->count())->toBe(0);
});

it('TC-AUT-011: logout revoca el token y queda auditado', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $t = ($this->login)('admin@derbanks.com', 'password')->json('data.token');
    $out = ($this->bearer)($t)->postJson('/v1/auth/logout')->status();
    $reuso = ($this->bearer)($t)->getJson('/v1/users/me')->status();
    $log = collect($this->audit->registros)->firstWhere('accion', 'auth.logout');
    ev2('TC-AUT-011', ['logout' => $out, 'reuso' => $reuso, 'bitacora' => $log['accion'] ?? null]);
    expect([$out, $reuso])->toBe([200, 401]);
    expect($log)->not->toBeNull();
});

it('TC-AUT-012: la renovacion respeta una duracion maxima de 12 h y rechaza usuarios inactivos (BUG-13)', function () {
    $u = ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $inicio = now()->copy();
    $t = ($this->login)('admin@derbanks.com', 'password')->json('data.token');
    $this->travelTo($inicio->copy()->addHours(7));
    $r1 = ($this->bearer)($t)->postJson('/v1/auth/refresh');
    $t = $r1->json('data.token');
    $exp = \Carbon\Carbon::parse(DB::table('personal_access_tokens')->latest('id')->value('expires_at'));
    $this->travelTo($inicio->copy()->addHours(11));
    $r2 = ($this->bearer)($t)->postJson('/v1/auth/refresh');
    $t = $r2->json('data.token');
    $this->travelTo($inicio->copy()->addHours(12)->addMinute());
    $r3 = ($this->bearer)($t)->postJson('/v1/auth/refresh')->status();

    $this->travelBack();
    $u->tokens()->delete();
    $t2 = ($this->login)('admin@derbanks.com', 'password')->json('data.token');
    $u->forceFill(['estado' => false])->save();
    $inactivo = ($this->bearer)($t2)->postJson('/v1/auth/refresh')->status();

    ev2('TC-AUT-012', ['refresh_7h' => $r1->status(), 'refresh_11h' => $r2->status(), 'vence_a_las_h' => round($inicio->diffInMinutes($exp) / 60, 2),
        'refresh_12h01' => $r3, 'refresh_usuario_inactivo' => $inactivo]);
    expect([$r1->status(), $r2->status(), $r3])->toBe([200, 200, 401]);
    expect(round($inicio->diffInMinutes($exp)))->toEqual(720);
    expect($inactivo)->toBe(403);
});

it('TC-AUT-013: valores limite de vigencia del token (479 / 480 / 481 min)', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $t = ($this->login)('admin@derbanks.com', 'password')->json('data.token');
    $inicio = now()->copy();
    $obs = [];
    foreach ([479, 480, 481] as $min) {
        $this->travelTo($inicio->copy()->addMinutes($min));
        $obs[$min] = ($this->bearer)($t)->getJson('/v1/users/me')->status();
    }
    ev2('TC-AUT-013', $obs);
    expect($obs)->toBe([479 => 200, 480 => 401, 481 => 401]);
});

it('TC-AUT-014: limites del throttle (10/11 en auth, 60/61 autenticada)', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $a = [];
    for ($i = 1; $i <= 11; $i++) {
        $a[$i] = ($this->login)('admin@derbanks.com', 'incorrecta')->status();
    }
    ($this->como)(($this->crear)('limite@derbanks.com', 'cajero'));
    $b = [];
    for ($i = 1; $i <= 61; $i++) {
        $b[$i] = $this->getJson('/v1/users/me')->status();
    }
    $this->travel(61)->seconds();
    $tras = $this->getJson('/v1/users/me')->status();
    ev2('TC-AUT-014', ['auth.10' => $a[10], 'auth.11' => $a[11], 'api.60' => $b[60], 'api.61' => $b[61], 'tras_ventana' => $tras]);
    expect([$a[10], $a[11], $b[60], $b[61], $tras])->toBe([401, 429, 200, 429, 200]);
});

it('TC-AUT-015: el limite tambien cuenta por cuenta y no castiga a otros usuarios de la IP (BUG-14)', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    ($this->crear)('otro.empleado@derbanks.com', 'cajero');
    $desde = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    for ($i = 1; $i <= 10; $i++) {
        $desde('10.0.0.1')->postJson('/v1/auth/login', ['email' => 'admin@derbanks.com', 'password' => 'incorrecta']);
    }
    $ip1 = $desde('10.0.0.1')->postJson('/v1/auth/login', ['email' => 'admin@derbanks.com', 'password' => 'incorrecta'])->status();
    $ip2 = $desde('10.0.0.2')->postJson('/v1/auth/login', ['email' => 'admin@derbanks.com', 'password' => 'incorrecta'])->status();
    $legit = $desde('10.0.0.1')->postJson('/v1/auth/login', ['email' => 'otro.empleado@derbanks.com', 'password' => 'Clave12345'])->status();
    ev2('TC-AUT-015', ['IP_1.intento11' => $ip1, 'IP_2.misma_cuenta' => $ip2, 'legitimo_misma_IP' => $legit]);
    expect([$ip1, $ip2, $legit])->toBe([429, 429, 200]);
});

// ======================================================= USUARIOS Y ROLES

it('TC-USR-001: el perfil propio devuelve todos los roles del usuario (BUG-16)', function () {
    $u = ($this->crear)('multirol@derbanks.com', null);
    $u->syncRoles(['gerente', 'cajero']);
    ($this->como)($u);
    $res = $this->getJson('/v1/users/me');
    ev2('TC-USR-001', ['http' => $res->status(), 'claves' => array_keys($res->json('data')), 'roles' => $res->json('data.roles')]);
    $res->assertStatus(200);
    expect($res->json('data'))->not->toHaveKey('password');
    expect(collect($res->json('data.roles'))->pluck('name')->sort()->values()->all())->toBe(['cajero', 'gerente']);
});

it('TC-USR-002: el empleado cambia su propia contrasena con la actual y confirmacion (BUG-17)', function () {
    $u = ($this->crear)('empleado.a@derbanks.com', 'cajero');
    ($this->como)($u);
    $sinActual = $this->putJson('/v1/users/me', ['password' => 'NuevaClave999', 'password_confirmation' => 'NuevaClave999']);
    $actualMala = $this->putJson('/v1/users/me', ['password' => 'NuevaClave999', 'password_confirmation' => 'NuevaClave999', 'current_password' => 'otra']);
    $ok = $this->putJson('/v1/users/me', ['name' => 'Empleado A Actualizado', 'password' => 'NuevaClave999',
        'password_confirmation' => 'NuevaClave999', 'current_password' => 'Clave12345']);
    $nueva = ($this->login)('empleado.a@derbanks.com', 'NuevaClave999')->status();
    $vieja = ($this->login)('empleado.a@derbanks.com', 'Clave12345')->status();
    ev2('TC-USR-002', ['sin_actual' => $sinActual->status(), 'actual_incorrecta' => $actualMala->status(), 'cambio' => $ok->status(),
        'login_nueva' => $nueva, 'login_vieja' => $vieja]);
    expect([$sinActual->status(), $actualMala->status(), $ok->status(), $nueva, $vieja])->toBe([422, 422, 200, 200, 401]);
});

it('TC-USR-003: un usuario no puede cambiar su propio estado (BUG-06)', function () {
    $u = ($this->crear)('autoreactiva@derbanks.com', 'cajero');
    $t = ($this->login)('autoreactiva@derbanks.com')->json('data.token');
    $u->forceFill(['estado' => false])->save();          // desactivacion hecha fuera de la API
    $put = ($this->bearer)($t)->putJson('/v1/users/me', ['estado' => true]);
    $relog = ($this->login)('autoreactiva@derbanks.com')->status();
    ev2('TC-USR-003', ['put' => $put->status(), 'db.estado' => $u->fresh()->estado, 'relogin' => $relog]);
    expect($u->fresh()->estado)->toBeFalse();
    expect($relog)->toBe(403);
});

it('TC-USR-004: perfil propio con correo ajeno, propio e invalido', function () {
    $a = ($this->crear)('empleado.a@derbanks.com', 'cajero');
    ($this->crear)('empleado.b@derbanks.com', 'cajero');
    ($this->como)($a);
    $r = [$this->putJson('/v1/users/me', ['email' => 'empleado.b@derbanks.com'])->status(),
          $this->putJson('/v1/users/me', ['email' => 'empleado.a@derbanks.com'])->status(),
          $this->putJson('/v1/users/me', ['email' => 'empleado.a@'])->status()];
    ev2('TC-USR-004', $r);
    expect($r)->toBe([422, 200, 422]);
});

it('TC-USR-005: paginacion fija en 15 y /users/all responde 404 (BUG-20)', function () {
    ($this->como)(($this->crear)('admin.pag@derbanks.com', 'admin'));
    for ($i = 1; $i <= 20; $i++) {
        ($this->crear)(sprintf('qa.usuario%03d@derbanks.com', $i), 'banco');
    }
    $n = fn ($q) => count($this->getJson('/v1/users'.$q)->json('data.users') ?? []);
    $obs = ['(sin)' => $n(''), 'page=2' => $n('?page=2'), 'per_page=100' => $n('?per_page=100'), 'page=999' => $n('?page=999'),
            '/users/all' => $this->getJson('/v1/users/all')->status(), '/roles/abc' => $this->getJson('/v1/roles/abc')->status()];
    ev2('TC-USR-005', $obs);
    expect($obs)->toBe(['(sin)' => 15, 'page=2' => 6, 'per_page=100' => 15, 'page=999' => 0, '/users/all' => 404, '/roles/abc' => 404]);
});

it('TC-USR-006: matriz de autorizacion rol x endpoint sin discrepancias (BUG-02, BUG-03)', function () {
    $cuenta = ($this->cuenta)('7770001', 1000);
    $perfiles = ['admin', 'gerente', 'cajero', 'servicio_al_cliente', 'banco', null];
    $permitidos = [
        'GET /v1/users' => ['admin'], 'GET /v1/roles' => ['admin'],
        'GET /v1/dashboard/summary' => ['admin', 'gerente'], 'GET /v1/reportes/cuentas' => ['admin', 'gerente'],
        'GET /v1/transferencias-externas' => ['admin', 'gerente'], 'GET /v1/transacciones' => ['admin', 'gerente', 'cajero'],
        'GET /v1/clientes' => ['admin', 'gerente', 'servicio_al_cliente'], 'GET /v1/cuentas' => ['admin', 'gerente', 'servicio_al_cliente'],
        'GET /v1/tickets' => ['admin', 'gerente', 'servicio_al_cliente'],
        'GET /v1/cuentas/search/{n}' => ['admin', 'banco'], 'POST /v1/transferencias-externas' => ['admin', 'banco'],
    ];
    $llamar = [
        'GET /v1/users' => fn () => $this->getJson('/v1/users'),
        'GET /v1/roles' => fn () => $this->getJson('/v1/roles'),
        'GET /v1/dashboard/summary' => fn () => $this->getJson('/v1/dashboard/summary'),
        'GET /v1/reportes/cuentas' => fn () => $this->getJson('/v1/reportes/cuentas'),
        'GET /v1/transferencias-externas' => fn () => $this->getJson('/v1/transferencias-externas'),
        'GET /v1/transacciones' => fn () => $this->getJson('/v1/transacciones'),
        'GET /v1/clientes' => fn () => $this->getJson('/v1/clientes'),
        'GET /v1/cuentas' => fn () => $this->getJson('/v1/cuentas'),
        'GET /v1/tickets' => fn () => $this->getJson('/v1/tickets'),
        'GET /v1/cuentas/search/{n}' => fn () => $this->getJson('/v1/cuentas/search/7770001'),
        'POST /v1/transferencias-externas' => fn () => $this->postJson('/v1/transferencias-externas', ($this->transferencia)($cuenta->id, 'MTZ-'.uniqid())),
    ];
    $matriz = [];
    $discrepancias = [];
    foreach ($perfiles as $rol) {
        $etq = $rol ?? 'sin_rol';
        ($this->como)(($this->crear)($etq.'.mtz@derbanks.com', $rol));
        foreach ($llamar as $nombre => $fn) {
            $cod = $fn()->status();
            $matriz[$nombre][$etq] = $cod;
            if (($cod < 400) !== in_array($etq, $permitidos[$nombre], true)) {
                $discrepancias[] = "$nombre [$etq] = $cod";
            }
        }
    }
    $this->app['auth']->forgetGuards();
    $this->flushHeaders();
    $sinToken = $this->getJson('/v1/users')->status();
    ev2('TC-USR-006', ['matriz' => $matriz, 'discrepancias' => $discrepancias, 'sin_token' => $sinToken]);
    expect($discrepancias)->toBe([]);
    expect($sinToken)->toBe(401);
});

it('TC-USR-007: el portal filtra rutas y menu por rol (BUG-05)', function () {
    $base = realpath(base_path('../fontend-erp/src'));
    $mw = file_get_contents($base.'/middleware.ts');
    $sidebar = file_get_contents($base.'/components/layout/Sidebar.tsx');
    $permisos = file_get_contents($base.'/lib/permisos.ts');
    $obs = [
        'middleware_valida_rol' => str_contains($mw, 'puedeAcceder(ruta, roles)'),
        'sidebar_lee_sesion' => str_contains($sidebar, 'useSession()'),
        'sidebar_filtra_menu' => str_contains($sidebar, 'filtrarMenu(NAV_ITEMS'),
        'usuarios_solo_admin' => str_contains($permisos, '{ prefijo: "/usuarios", roles: ["admin"] }'),
        'auditoria_solo_admin' => str_contains($permisos, '{ prefijo: "/auditoria", roles: ["admin"] }'),
        'mantenimiento_solo_admin' => str_contains($permisos, '{ prefijo: "/mantenimiento", roles: ["admin"] }'),
        'pagina_acceso_denegado' => is_file($base.'/app/acceso-denegado/page.tsx'),
    ];
    ev2('TC-USR-007', $obs);
    expect(array_unique(array_values($obs)))->toBe([true]);
});

it('TC-USR-008: alta administrativa con rol existente y estado por omision', function () {
    ($this->como)(($this->crear)('admin.alta@derbanks.com', 'admin'));
    $a = $this->postJson('/v1/users', ['name' => 'Cajero Nuevo', 'email' => 'cajero.nuevo@derbanks.com', 'password' => 'Clave12345', 'role' => 'cajero']);
    $b = $this->postJson('/v1/users', ['name' => 'Cajero Inactivo', 'email' => 'cajero.inactivo@derbanks.com', 'password' => 'Clave12345', 'role' => 'cajero', 'estado' => false]);
    $la = ($this->login)('cajero.nuevo@derbanks.com')->status();
    $lb = ($this->login)('cajero.inactivo@derbanks.com')->status();
    ev2('TC-USR-008', ['A' => [$a->status(), $a->json('data.estado'), $a->json('data.roles')], 'B' => [$b->status(), $b->json('data.estado')], 'login' => [$la, $lb]]);
    $a->assertStatus(201)->assertJsonPath('data.estado', true)->assertJsonPath('data.roles.0.name', 'cajero');
    $b->assertStatus(201)->assertJsonPath('data.estado', false);
    expect([$la, $lb])->toBe([200, 403]);
});

it('TC-USR-009: alta administrativa rechazada por datos invalidos', function () {
    ($this->como)(($this->crear)('admin@derbanks.com', 'admin', true, 'password'));
    $p1 = $this->postJson('/v1/users', ['name' => 'I', 'email' => 'admin@derbanks.com', 'password' => 'Clave12', 'role' => 'supervisor_general']);
    $p3 = $this->postJson('/v1/users', ['name' => 'F', 'email' => 'frontera8@derbanks.com', 'password' => 'Clave123', 'role' => 'cajero'])->status();
    $p4 = $this->postJson('/v1/users', ['name' => 'S', 'email' => 'sinrol.alta@derbanks.com', 'password' => 'Clave12345'])->status();
    $p5 = $this->postJson('/v1/users', ['name' => 'U', 'email' => 'rol.usuario@derbanks.com', 'password' => 'Clave12345', 'role' => 'usuario'])->status();
    ev2('TC-USR-009', ['p1' => [$p1->status(), array_keys($p1->json('errors') ?? [])], 'p3' => $p3, 'p4' => $p4, 'p5' => $p5]);
    $p1->assertStatus(422);
    expect(array_keys($p1->json('errors')))->toEqualCanonicalizing(['email', 'password', 'role']);
    expect([$p3, $p4, $p5])->toBe([201, 422, 422]);
});

it('TC-USR-010: un rol creado por la API se puede asignar y el alta es atomica (BUG-08)', function () {
    ($this->como)(($this->crear)('admin.guard@derbanks.com', 'admin'));
    $r = $this->postJson('/v1/roles', ['name' => 'supervisor']);
    $alta = $this->postJson('/v1/users', ['name' => 'Supervisor Uno', 'email' => 'supervisor.uno@derbanks.com', 'password' => 'Clave12345', 'role' => 'supervisor']);
    $ctrl = $this->postJson('/v1/users', ['name' => 'Control', 'email' => 'control@derbanks.com', 'password' => 'Clave12345', 'role' => 'cajero']);
    $sync = $this->putJson('/v1/users/'.$ctrl->json('data.id'), ['role' => 'supervisor'])->status();

    // Atomicidad: un rol heredado con guard 'sanctum' (creado antes de la correccion) hace
    // fallar assignRole; el usuario ya no debe quedar creado y sin rol.
    Role::create(['name' => 'heredado', 'guard_name' => 'sanctum']);
    $falla = $this->postJson('/v1/users', ['name' => 'Huerfano', 'email' => 'huerfano@derbanks.com', 'password' => 'Clave12345', 'role' => 'heredado'])->status();
    $huerfano = User::where('email', 'huerfano@derbanks.com')->exists();

    // La migracion de datos repara los roles heredados y el alta vuelve a funcionar.
    (require database_path('migrations/2026_10_19_000000_fix_guard_name_on_roles_table.php'))->up();
    $trasMigracion = $this->postJson('/v1/users', ['name' => 'Reparado', 'email' => 'reparado@derbanks.com', 'password' => 'Clave12345', 'role' => 'heredado'])->status();
    ev2('TC-USR-010', ['crear_rol' => [$r->status(), Role::where('name', 'supervisor')->value('guard_name')],
        'alta_con_rol_nuevo' => [$alta->status(), $alta->json('data.roles')], 'syncRoles' => $sync,
        'alta_rol_heredado' => $falla, 'usuario_huerfano_creado' => $huerfano,
        'guard_heredado_tras_migracion' => Role::where('name', 'heredado')->value('guard_name'), 'alta_tras_migracion' => $trasMigracion]);
    expect($trasMigracion)->toBe(201);
    $r->assertStatus(201);
    expect(Role::where('name', 'supervisor')->value('guard_name'))->toBe('web');
    $alta->assertStatus(201)->assertJsonPath('data.roles.0.name', 'supervisor');
    expect($sync)->toBe(200);
    expect(User::where('email', 'huerfano@derbanks.com')->exists())->toBeFalse();
});

it('TC-USR-011: la actualizacion reemplaza el rol y aplica campos por separado', function () {
    ($this->como)(($this->crear)('admin.upd@derbanks.com', 'admin'));
    $c = ($this->crear)('empleado.c@derbanks.com', 'cajero');
    $this->putJson('/v1/users/'.$c->id, ['name' => 'Empleado C Renombrado'])->assertStatus(200);
    $this->putJson('/v1/users/'.$c->id, ['role' => 'gerente'])->assertStatus(200);
    $filas = DB::table('model_has_roles')->where('model_id', $c->id)->count();
    $this->putJson('/v1/users/'.$c->id, ['password' => 'OtraClave999'])->assertStatus(200);
    $login = ($this->login)('empleado.c@derbanks.com', 'OtraClave999')->status();
    ($this->como)(User::where('email', 'admin.upd@derbanks.com')->first());
    $p6 = $this->putJson('/v1/users/999999', ['name' => 'X'])->status();
    ev2('TC-USR-011', ['nombre' => $c->fresh()->name, 'filas_rol' => $filas, 'login_clave_nueva' => $login, 'inexistente' => $p6]);
    expect([$filas, $login, $p6])->toBe([1, 200, 404]);
});

it('TC-USR-012: un cajero ya no administra usuarios ni puede escalar a admin (BUG-03, BUG-04)', function () {
    $admin = ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $cajero = ($this->crear)('cajero.escala@derbanks.com', 'cajero');
    ($this->como)($cajero);
    $lista = $this->getJson('/v1/users')->status();
    $escala = $this->putJson('/v1/users/'.$cajero->id, ['role' => 'admin'])->status();
    $degrada = $this->putJson('/v1/users/'.$admin->id, ['role' => 'cajero'])->status();
    ($this->como)($admin);
    $autoDegrada = $this->putJson('/v1/users/'.$admin->id, ['role' => 'cajero']);
    ev2('TC-USR-012', ['cajero_lista' => $lista, 'cajero_autoasigna_admin' => $escala, 'cajero_degrada_admin' => $degrada,
        'ultimo_admin_se_degrada' => [$autoDegrada->status(), $autoDegrada->json('message')], 'admin_conserva_rol' => $admin->fresh()->hasRole('admin')]);
    expect([$lista, $escala, $degrada, $autoDegrada->status()])->toBe([403, 403, 403, 422]);
    expect($cajero->fresh()->hasRole('admin'))->toBeFalse();
    expect($admin->fresh()->hasRole('admin'))->toBeTrue();
});

it('TC-USR-013: la eliminacion logica corta la sesion y no deja tokens huerfanos', function () {
    ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $v = ($this->crear)('victima@derbanks.com', 'cajero');
    $tv = ($this->login)('victima@derbanks.com')->json('data.token');
    $ta = ($this->login)('admin@derbanks.com', 'password')->json('data.token');
    $del = ($this->bearer)($ta)->deleteJson('/v1/users/'.$v->id)->status();
    $sesion = ($this->bearer)($tv)->getJson('/v1/users/me')->status();
    $show = ($this->bearer)($ta)->getJson('/v1/users/'.$v->id)->status();
    $huerfanos = DB::table('personal_access_tokens')->where('tokenable_id', $v->id)->count();
    ev2('TC-USR-013', ['delete' => $del, 'sesion_victima' => $sesion, 'show' => $show, 'tokens_huerfanos' => $huerfanos]);
    expect([$del, $sesion, $show, $huerfanos])->toBe([200, 401, 404, 0]);
});

it('TC-USR-014: la eliminacion libera los tickets y protege al administrador (BUG-18)', function () {
    $admin = ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $agente = ($this->crear)('agente@derbanks.com', 'servicio_al_cliente');
    $cuenta = ($this->cuenta)('8880001', 10);
    $ticket = Ticket::forceCreate(['codigo_ticket' => 'TCK-QA-0001', 'id_cliente' => $cuenta->id_cliente, 'id_tipo_ticket' => 'reclamo',
        'id_estado_ticket' => 'asignado', 'asunto' => 'Reclamo QA', 'creado_por' => $admin->id]);
    AsignacionTicket::forceCreate(['id_ticket' => $ticket->id_ticket, 'id_usuario_asignado' => $agente->id, 'id_usuario_asigna' => $admin->id, 'estado_asignacion' => 'activa']);
    ($this->como)($admin);
    $del = $this->deleteJson('/v1/users/'.$agente->id)->status();
    $asig = DB::table('asignaciones_ticket')->where('id_ticket', $ticket->id_ticket)->value('estado_asignacion');
    $estadoTicket = DB::table('Tickets')->where('id_ticket', $ticket->id_ticket)->value('id_estado_ticket');
    $auto = $this->deleteJson('/v1/users/'.$admin->id);
    ev2('TC-USR-014', ['delete_agente' => $del, 'asignacion' => $asig, 'ticket_estado' => $estadoTicket,
        'autoeliminacion' => [$auto->status(), $auto->json('message')], 'admins_activos' => User::role('admin')->count()]);
    expect([$del, $asig, $estadoTicket, $auto->status()])->toBe([200, 'finalizada', 'abierto', 422]);
    expect(User::role('admin')->count())->toBe(1);
});

it('TC-USR-015: CRUD del catalogo de roles reservado al administrador', function () {
    ($this->como)(($this->crear)('admin.rol@derbanks.com', 'admin'));
    $c = $this->postJson('/v1/roles', ['name' => 'auditor_interno']);
    $id = $c->json('data.id');
    $obs = ['store' => $c->status(), 'index' => $this->getJson('/v1/roles')->status(), 'all' => $this->getJson('/v1/roles/all')->status(),
            'update' => $this->putJson('/v1/roles/'.$id, ['name' => 'auditor_general'])->status(),
            'duplicado' => $this->putJson('/v1/roles/'.$id, ['name' => 'admin'])->status()];
    ($this->como)(($this->crear)('cajero.rol@derbanks.com', 'cajero'));
    $obs['cajero_crea_rol'] = $this->postJson('/v1/roles', ['name' => 'rol_de_cajero'])->status();
    ev2('TC-USR-015', $obs);
    expect($obs)->toBe(['store' => 201, 'index' => 200, 'all' => 200, 'update' => 200, 'duplicado' => 422, 'cajero_crea_rol' => 403]);
});

it('TC-USR-016: los roles del sistema no se pueden renombrar ni eliminar (BUG-09)', function () {
    $admin = ($this->crear)('admin@derbanks.com', 'admin', true, 'password');
    $rolAdmin = Role::where('name', 'admin')->first();
    ($this->como)($admin);
    $ren = $this->putJson('/v1/roles/'.$rolAdmin->id, ['name' => 'administrador']);
    $del = $this->deleteJson('/v1/roles/'.$rolAdmin->id)->status();
    ($this->como)($admin);
    $acceso = $this->getJson('/v1/users')->status();
    ev2('TC-USR-016', ['renombrar' => [$ren->status(), $ren->json('message')], 'eliminar' => $del, 'nombre' => $rolAdmin->fresh()->name, 'admin_users' => $acceso]);
    expect([$ren->status(), $del, $acceso])->toBe([422, 422, 200]);
    expect($rolAdmin->fresh()->name)->toBe('admin');
});

it('TC-USR-017: los permisos gobiernan las rutas que los declaran (BUG-10)', function () {
    $cuenta = ($this->cuenta)('9990001', 0);
    $obs = [];
    foreach (['admin', 'gerente', 'cajero', 'servicio_al_cliente', 'banco'] as $rol) {
        $obs['permisos'][$rol] = Role::where('name', $rol)->first()->permissions()->pluck('name')->sort()->values()->all();
    }
    $v1 = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'v1/'));
    $obs['rutas_con_permission'] = $v1->filter(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_contains($m, 'permission:')))->count();
    // Un admin sin el permiso users.index ya no lista usuarios: el permiso si gobierna.
    $adminSinPermiso = ($this->crear)('admin.sinperm@derbanks.com', 'admin');
    Role::where('name', 'admin')->first()->revokePermissionTo('users.index');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    ($this->como)($adminSinPermiso);
    $obs['admin_sin_users.index'] = $this->getJson('/v1/users')->status();
    ($this->como)(($this->crear)('sin.permisos@derbanks.com', null));
    $obs['sin_permisos_transferencia'] = $this->postJson('/v1/transferencias-externas', ($this->transferencia)($cuenta->id, 'PERM-0001'))->status();
    ev2('TC-USR-017', $obs);
    expect(count($obs['permisos']['admin']))->toBe(6);
    expect($obs['permisos']['banco'])->toBe(['cuentas.search', 'transferencias-externas.store']);
    expect($obs['permisos']['cajero'])->toBe([]);
    expect($obs['rutas_con_permission'])->toBe(7);
    expect($obs['admin_sin_users.index'])->toBe(403);
    expect($obs['sin_permisos_transferencia'])->toBe(403);
});

it('TC-USR-018: eliminar un rol con usuarios activos responde 409', function () {
    ($this->como)(($this->crear)('admin.del@derbanks.com', 'admin'));
    $rol = Role::create(['name' => 'supervisor_qa', 'guard_name' => 'web']);
    ($this->crear)('supervisor1@derbanks.com', 'supervisor_qa');
    $res = $this->deleteJson('/v1/roles/'.$rol->id);
    ev2('TC-USR-018', ['http' => $res->status(), 'message' => $res->json('message'), 'deleted_at' => $rol->fresh()->deleted_at]);
    $res->assertStatus(409);
    expect($rol->fresh()->deleted_at)->toBeNull();
});

it('TC-USR-019: la guarda de eliminacion de rol cuenta tambien a usuarios eliminados (BUG-11)', function () {
    ($this->como)(($this->crear)('admin.roles@derbanks.com', 'admin'));
    $vacio = Role::create(['name' => 'rol_vacio', 'guard_name' => 'web']);
    $uno = Role::create(['name' => 'rol_uno', 'guard_name' => 'web']);
    $borrado = Role::create(['name' => 'rol_borrado', 'guard_name' => 'web']);
    ($this->crear)('unico.rol@derbanks.com', 'rol_uno');
    $aBorrar = ($this->crear)('borrado.rol@derbanks.com', 'rol_borrado');
    $r0 = $this->deleteJson('/v1/roles/'.$vacio->id)->status();
    $r1 = $this->deleteJson('/v1/roles/'.$uno->id)->status();
    $aBorrar->delete();
    $r2 = $this->deleteJson('/v1/roles/'.$borrado->id)->status();
    $aBorrar->restore();
    ev2('TC-USR-019', ['0_usuarios' => $r0, '1_activo' => $r1, '1_eliminado' => $r2, 'rol_tras_restaurar' => $aBorrar->fresh()->hasRole('rol_borrado')]);
    expect([$r0, $r1, $r2])->toBe([200, 409, 409]);
    expect($aBorrar->fresh()->hasRole('rol_borrado'))->toBeTrue();
});
