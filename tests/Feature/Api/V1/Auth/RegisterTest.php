<?php

use App\Models\User;

it('registra un usuario correctamente', function () {
    $res = $this->postJson('/v1/auth/register', [
        'name'                  => 'Juan Test',
        'email'                 => 'juan@test.com',
        'password'              => '12345678',
        'password_confirmation' => '12345678',
    ]);

    // Entrega 3 (BUG-01): el registro público deja la cuenta inactiva, sin rol y sin token.
    $res->assertStatus(201)
        ->assertJsonStructure([
            'status', 'message',
            'data' => ['user' => ['id', 'name', 'email', 'estado']],
        ])
        ->assertJsonMissingPath('data.token')
        ->assertJsonPath('data.user.estado', false)
        ->assertJson(['status' => true]);

    $this->assertDatabaseHas('users', ['email' => 'juan@test.com', 'estado' => false]);
});

it('falla si el email ya existe', function () {
    User::factory()->create(['email' => 'juan@test.com']);

    $res = $this->postJson('/v1/auth/register', [
        'name'                  => 'Juan Test',
        'email'                 => 'juan@test.com',
        'password'              => '12345678',
        'password_confirmation' => '12345678',
    ]);

    $res->assertStatus(422)
        ->assertJson(['status' => false]);
});

it('falla si faltan campos requeridos', function () {
    $res = $this->postJson('/v1/auth/register', []);

    $res->assertStatus(422)
        ->assertJsonStructure(['status', 'message', 'errors']);
});