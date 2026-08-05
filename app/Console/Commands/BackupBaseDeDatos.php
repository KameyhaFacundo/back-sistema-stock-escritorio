<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Backup diario de TODA la base (no confundir con "Configuración → exportar
 * datos", que es un export CSV por empresa a pedido del propio dueño). Este
 * corre solo, programado en Kernel::schedule(), y es la red de seguridad del
 * lado del servidor — sin esto, el único backup que existía dependía de que
 * un dueño de empresa se acuerde de tocar el botón de exportar.
 *
 * Soporta los dos motores que este proyecto puede usar: SQLite (instalación
 * local típica, un solo archivo — el caso común) y MySQL (si el local ya
 * tiene un servidor de base de datos propio). Elige la rama según
 * DB_CONNECTION. Requiere que el cron del host llame a
 * `php artisan schedule:run` cada minuto (requisito estándar de Laravel) —
 * sin ese cron, esto nunca se dispara solo por más que esté acá.
 */
class BackupBaseDeDatos extends Command
{
    protected $signature = 'backup:run {--dias-retencion=14 : Cuántos días de backups conservar}';

    protected $description = 'Genera un backup completo de la base de datos y borra los backups más viejos que el período de retención';

    public function handle(): int
    {
        $directorio = storage_path('app/backups');
        File::ensureDirectoryExists($directorio);

        $driver = config('database.default');
        $archivo = $driver === 'sqlite'
            ? $this->backupSqlite($directorio)
            : $this->backupMysql($directorio);

        if ($archivo === null) {
            return self::FAILURE;
        }

        $this->info("Backup generado: {$archivo}");

        $dias = (int) $this->option('dias-retencion');
        $borrados = 0;

        foreach (File::files($directorio) as $file) {
            if (now()->timestamp - $file->getMTime() > $dias * 86400) {
                File::delete($file->getPathname());
                $borrados++;
            }
        }

        if ($borrados > 0) {
            $this->info("Se borraron {$borrados} backups con más de {$dias} días.");
        }

        return self::SUCCESS;
    }

    /**
     * SQLite es un solo archivo: el backup es una copia + gzip, sin depender
     * de ninguna herramienta externa instalada en el sistema.
     */
    private function backupSqlite(string $directorio): ?string
    {
        $baseDatos = config('database.connections.sqlite.database');
        if (!File::exists($baseDatos)) {
            Log::error('Falló el backup automático: no se encontró el archivo de la base SQLite', ['path' => $baseDatos]);
            $this->error("No se encontró el archivo de la base SQLite en: {$baseDatos}");
            return null;
        }

        $archivo = $directorio . '/backup-' . now()->format('Y-m-d_His') . '.sqlite.gz';

        $origen  = fopen($baseDatos, 'rb');
        $destino = gzopen($archivo, 'wb9');
        while (!feof($origen)) {
            gzwrite($destino, fread($origen, 1024 * 1024));
        }
        fclose($origen);
        gzclose($destino);

        return $archivo;
    }

    /**
     * MySQL: mismo dump vía mysqldump que antes, para cuando este backend
     * corre contra un servidor MySQL propio en vez del SQLite local.
     */
    private function backupMysql(string $directorio): ?string
    {
        $conexion = config('database.connections.mysql');
        $archivo  = $directorio . '/backup-' . now()->format('Y-m-d_His') . '.sql.gz';

        $comando = sprintf(
            'mysqldump --host=%s --port=%s --user=%s %s | gzip > %s',
            escapeshellarg($conexion['host']),
            escapeshellarg((string) $conexion['port']),
            escapeshellarg($conexion['username']),
            escapeshellarg($conexion['database']),
            escapeshellarg($archivo)
        );

        // La contraseña va por variable de entorno (MYSQL_PWD), no como
        // argumento — un argumento de línea de comandos queda visible para
        // cualquiera que corra `ps` en el mismo servidor mientras el dump corre.
        $proceso = Process::fromShellCommandline($comando, null, ['MYSQL_PWD' => $conexion['password']]);
        $proceso->setTimeout(600);
        $proceso->run();

        if (!$proceso->isSuccessful()) {
            Log::error('Falló el backup automático de la base de datos', ['error' => $proceso->getErrorOutput()]);
            $this->error('Falló el backup: ' . $proceso->getErrorOutput());
            if (File::exists($archivo)) {
                File::delete($archivo);
            }
            return null;
        }

        return $archivo;
    }
}
