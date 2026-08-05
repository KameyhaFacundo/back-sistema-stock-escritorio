<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Permiso;
use Illuminate\Console\Command;

class DarTodosPermisos extends Command
{
    protected $signature = 'permisos:dar-todos {userId}';
    protected $description = 'Asigna todos los permisos a un usuario';

    public function handle(): int
    {
        $user = User::find($this->argument('userId'));

        if (! $user) {
            $this->error('Usuario no encontrado.');

            return 1;
        }

        $todos = Permiso::pluck('id');
        $user->permisos()->sync($todos);

        $this->info("Se asignaron {$todos->count()} permisos al usuario #{$user->nro_usu} ({$user->des_usu}).");

        return 0;
    }
}
