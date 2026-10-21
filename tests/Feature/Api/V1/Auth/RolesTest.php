<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// Entrega 3 (R-12): la prueba anterior esperaba un rol 'usuario' que no existe en el
// catálogo. Tras BUG-01 el registro público deja la cuenta sin rol y pendiente de aprobación.
it('no asigna ningún rol al registrarse', function () {
    $res = $this->postJson('/v1/auth/register', [
        'name'                  => 'Juan Test',
        'email'                 => 'juan@test.com',
        'password'              => '12345678',
        'password_confirmation' => '12345678',
    ]);

    $res->assertStatus(201)
        ->assertJsonPath('data.user.roles', []);
});

it('incluye roles en la respuesta del login', function () {
    $user = User::factory()->create([
        'email'    => 'juan@test.com',
        'password' => bcrypt('12345678'),
    ]);
    $user->assignRole('cajero');

    $res = $this->postJson('/v1/auth/login', [
        'email'    => 'juan@test.com',
        'password' => '12345678',
    ]);

    $res->assertStatus(200)
        ->assertJsonStructure(['data' => ['user' => ['roles', 'permissions']]])
        ->assertJsonPath('data.user.roles.0.name', 'cajero');
});

it('bloquea acceso a rutas de admin para usuario básico', function () {
    $user = User::factory()->create();
    $user->assignRole('cajero');
    Sanctum::actingAs($user);

    $res = $this->getJson('/v1/users');

    $res->assertStatus(403);
});

it('permite acceso a rutas de admin para admin', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    Sanctum::actingAs($user);

    $res = $this->getJson('/v1/users');

    $res->assertStatus(200);
});
