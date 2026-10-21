<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RefreshTokenController;
use App\Http\Controllers\Api\V1\User\ProfileController;
use App\Http\Controllers\Api\V1\Role\RoleController;
use App\Http\Controllers\Api\V1\Cliente\ClienteController;
use App\Http\Controllers\Api\V1\Cuenta\CuentaController;
use App\Http\Controllers\Api\V1\Ticket\TicketController;
use App\Http\Controllers\Api\V1\Transaccion\TransaccionController;
use App\Http\Controllers\Api\V1\TransferenciaExterna\TransferenciaExternaController;
use App\Http\Controllers\Api\V1\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\Reporte\ReporteController;
use App\Http\Controllers\Api\V1\Auditoria\AuditoriaController;

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', RegisterController::class)->name('auth.register');
    Route::post('/login', LoginController::class)->name('auth.login');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', LogoutController::class)->name('auth.logout');
        Route::post('/refresh', RefreshTokenController::class)->name('auth.refresh');
    });

    Route::prefix('users')->group(function () {
        Route::get('me', [ProfileController::class, 'show'])->name('users.me');
        Route::put('me', [ProfileController::class, 'update'])->name('users.update');
    });
});

// Grupo 1: Solo admin — gestión de usuarios, roles y auditoría
// (BUG-03) Antes admitía admin|gerente|servicio_al_cliente|cajero. Ahora sólo admin,
// igual que el portal, y cada operación de usuarios exige además su permiso (BUG-10).
Route::middleware(['auth:sanctum', 'throttle:api', 'role:admin', 'audit'])->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('/',        [ProfileController::class, 'index'])->middleware('permission:users.index')->name('users.index');
        Route::post('/',       [ProfileController::class, 'store'])->middleware('permission:users.update')->name('users.store');
        Route::get('{id}',     [ProfileController::class, 'showById'])->middleware('permission:users.show')->whereNumber('id')->name('users.show');
        Route::put('{id}',     [ProfileController::class, 'updateById'])->middleware('permission:users.update')->whereNumber('id')->name('users.updateById');
        Route::delete('{id}',  [ProfileController::class, 'destroy'])->middleware('permission:users.delete')->whereNumber('id')->name('users.delete');
    });

    Route::prefix('roles')->group(function () {
        Route::get('/',        [RoleController::class, 'index'])->name('roles.index');
        Route::get('/all',     [RoleController::class, 'indexAll'])->name('roles.indexAll');
        Route::post('/',       [RoleController::class, 'store'])->name('roles.store');
        Route::get('{id}',     [RoleController::class, 'show'])->whereNumber('id')->name('roles.show');
        Route::put('{id}',     [RoleController::class, 'update'])->whereNumber('id')->name('roles.update');
        Route::delete('{id}',  [RoleController::class, 'destroy'])->whereNumber('id')->name('roles.destroy');
    });

    Route::prefix('auditoria')->group(function () {
        Route::get('/',                  [AuditoriaController::class, 'index'])->name('auditoria.index');
        Route::get('/resumen',           [AuditoriaController::class, 'resumen'])->name('auditoria.resumen');
        Route::get('/snapshots',         [AuditoriaController::class, 'snapshots'])->name('auditoria.snapshots');
        Route::get('/snapshots/{id}',    [AuditoriaController::class, 'snapshotShow'])->name('auditoria.snapshots.show');
        Route::get('/{id}',              [AuditoriaController::class, 'show'])->name('auditoria.show');
    });
});

// Grupo 2: Admin y gerente — dashboard, reportes y consulta de transferencias externas
Route::middleware(['auth:sanctum', 'throttle:api', 'role:admin|gerente', 'audit'])->group(function () {
    Route::prefix('dashboard')->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
    });

    Route::prefix('reportes')->group(function () {
        Route::get('/clientes',                       [ReporteController::class, 'clientesLista'])->name('reportes.clientes');
        Route::get('/estado-cuenta/{clienteId}',      [ReporteController::class, 'estadoCuenta'])->name('reportes.estadoCuenta');
        Route::get('/transacciones',                  [ReporteController::class, 'historialTransacciones'])->name('reportes.transacciones');
        Route::get('/cuentas',                        [ReporteController::class, 'listadoCuentas'])->name('reportes.cuentas');
        Route::get('/transferencias-externas',        [ReporteController::class, 'transferenciasExternas'])->name('reportes.transferenciasExternas');
        Route::get('/actividad-mensual',              [ReporteController::class, 'actividadMensual'])->name('reportes.actividadMensual');
    });

    Route::prefix('transferencias-externas')->group(function () {
        Route::get('/',        [TransferenciaExternaController::class, 'index'])->name('transferencias-externas.index');
        Route::get('/all',     [TransferenciaExternaController::class, 'indexAll'])->name('transferencias-externas.indexAll');
        Route::get('{id}',     [TransferenciaExternaController::class, 'show'])->name('transferencias-externas.show');
    });
});

// Grupo 3: Admin, gerente y cajero — transacciones
Route::middleware(['auth:sanctum', 'throttle:api', 'role:admin|gerente|cajero', 'audit'])->group(function () {
    Route::prefix('transacciones')->group(function () {
        Route::get('/',        [TransaccionController::class, 'index'])->name('transacciones.index');
        Route::get('/all',     [TransaccionController::class, 'indexAll'])->name('transacciones.indexAll');
        Route::post('/',       [TransaccionController::class, 'store'])->name('transacciones.store');
        Route::get('{id}',     [TransaccionController::class, 'show'])->name('transacciones.show');
    });
});

// Grupo 4: Admin, gerente y servicio_al_cliente — clientes, cuentas y tickets
Route::middleware(['auth:sanctum', 'throttle:api', 'role:admin|gerente|servicio_al_cliente', 'audit'])->group(function () {
    Route::prefix('clientes')->group(function () {
        Route::get('/',        [ClienteController::class, 'index'])->name('clientes.index');
        Route::get('/all',     [ClienteController::class, 'indexAll'])->name('clientes.indexAll');
        Route::post('/',       [ClienteController::class, 'store'])->name('clientes.store');
        Route::get('{id}',     [ClienteController::class, 'show'])->name('clientes.show');
        Route::put('{id}',     [ClienteController::class, 'update'])->name('clientes.update');
        Route::delete('{id}',  [ClienteController::class, 'destroy'])->name('clientes.destroy');
    });

    Route::prefix('cuentas')->group(function () {
        Route::get('/',        [CuentaController::class, 'index'])->name('cuentas.index');
        Route::get('/all',     [CuentaController::class, 'indexAll'])->name('cuentas.indexAll');
        Route::post('/',       [CuentaController::class, 'store'])->name('cuentas.store');
        Route::get('{id}',     [CuentaController::class, 'show'])->name('cuentas.show');
        Route::put('{id}',     [CuentaController::class, 'update'])->name('cuentas.update');
        Route::delete('{id}',  [CuentaController::class, 'destroy'])->name('cuentas.destroy');
    });

    Route::prefix('tickets')->group(function () {
        Route::get('/',        [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/all',     [TicketController::class, 'indexAll'])->name('tickets.indexAll');
        Route::get('/filter',  [TicketController::class, 'filter'])->name('tickets.filter');
        // Usuarios que pueden recibir un ticket: reemplaza el uso de GET /users desde
        // atención al cliente, que tras BUG-03 queda reservado al administrador.
        Route::get('/usuarios-asignables', [ProfileController::class, 'asignables'])->name('tickets.asignables');
        Route::get('/cliente/{clienteId}', [TicketController::class, 'getByCliente'])->name('tickets.byCliente');
        Route::post('/',       [TicketController::class, 'store'])->name('tickets.store');
        Route::get('{id}',     [TicketController::class, 'show'])->name('tickets.show');
        Route::put('{id}',     [TicketController::class, 'update'])->name('tickets.update');
        Route::post('{id}/asignar', [TicketController::class, 'assign'])->name('tickets.assign');
        Route::post('{id}/reasignar', [TicketController::class, 'reassign'])->name('tickets.reassign');
        Route::patch('{id}/estado', [TicketController::class, 'changeStatus'])->name('tickets.changeStatus');
        Route::post('{id}/cerrar', [TicketController::class, 'close'])->name('tickets.close');
        Route::delete('{id}',  [TicketController::class, 'destroy'])->name('tickets.destroy');
    });
});

// (BUG-02) Rutas consumidas por las entidades externas. Antes sólo exigían token:
// cualquier sesión, incluso sin rol, acreditaba dinero y enumeraba cuentas sin bitácora.
// Ahora exigen el permiso correspondiente (rol banco o admin). El POST queda auditado; el
// GET no, porque el middleware audit sólo registra métodos que modifican estado.
Route::middleware(['auth:sanctum', 'throttle:api', 'permission:cuentas.search', 'audit'])
    ->get('/cuentas/search/{numero_cuenta}', [CuentaController::class, 'searchAccount'])
    ->name('cuentas.search');

Route::middleware(['auth:sanctum', 'throttle:api', 'permission:transferencias-externas.store', 'audit'])
    ->post('/transferencias-externas', [TransferenciaExternaController::class, 'store'])
    ->name('transferencias-externas.store');
