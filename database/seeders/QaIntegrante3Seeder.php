<?php

namespace Database\Seeders;

use App\Models\AsignacionTicket;
use App\Models\Cliente;
use App\Models\Cuentas;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Siembra la data sintetica QA del modulo de Transferencias Externas y
 * Tickets (Integrante 3, Entrega 2). Requiere que RolesAndPermissionsSeeder
 * ya haya corrido (crea los roles "servicio_al_cliente" y "banco").
 *
 * Uso:
 *   php artisan db:seed                              # roles + admin base
 *   php artisan db:seed --class=QaIntegrante3Seeder   # data de este modulo
 *
 * Pensado para una base recien migrada (migrate:fresh); usa IDs explicitos,
 * asi que correrlo dos veces sobre la misma base falla por llaves unicas
 * (dpi, numero_cuenta, codigo_ticket, email) — eso es intencional.
 */
class QaIntegrante3Seeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/qa_integrante3_mock_data.json');
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($data['clientes'] as $row) {
            Cliente::forceCreate($row);
        }

        foreach ($data['usuarios'] as $row) {
            $rol = $row['rol'];
            unset($row['rol']);
            $user = User::forceCreate($row);
            $user->assignRole($rol);
        }

        foreach ($data['cuentas'] as $row) {
            Cuentas::forceCreate($row);
        }

        foreach ($data['tickets'] as $row) {
            Ticket::forceCreate($row);
        }

        foreach ($data['asignaciones_ticket'] as $row) {
            AsignacionTicket::forceCreate($row);
        }

        $this->command?->info(sprintf(
            'QA Integrante 3: %d clientes, %d usuarios, %d cuentas, %d tickets, %d asignaciones sembrados.',
            count($data['clientes']),
            count($data['usuarios']),
            count($data['cuentas']),
            count($data['tickets']),
            count($data['asignaciones_ticket']),
        ));
    }
}
