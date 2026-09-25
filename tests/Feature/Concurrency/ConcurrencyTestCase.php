<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Base dos testes com duas conexões reais ao PostgreSQL: uma delas parada no lock enquanto a outra ainda
 * não confirmou. Sem RefreshDatabase: os dados precisam estar commitados para o processo-filho enxergá-los
 * (por isso o TRUNCATE no início e no fim).
 */
abstract class ConcurrencyTestCase extends TestCase
{
    private const TABLES = 'users, customers, devices, work_orders, work_order_events, quotes, quote_items, quote_decisions, parts, inventory_movements, part_reservations';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('part_reservations')) {
            $this->artisan('migrate', ['--force' => true]);
        }
        DB::statement('TRUNCATE '.self::TABLES.' RESTART IDENTITY CASCADE');
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::statement('TRUNCATE '.self::TABLES.' RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    /**
     * Abre a transação de $inFlight (lock da ordem retido, nada confirmado), dispara o worker em outro
     * processo, espera ele travar no lock e só então confirma. Devolve o que o worker respondeu.
     * `$whileBlocked` roda com o worker parado no lock (e a transação do teste ainda aberta): serve para inspecionar
     * o que o worker já travou até ali.
     */
    protected function whileInFlight(callable $inFlight, string $command, array $args, ?callable $whileBlocked = null): array
    {
        DB::beginTransaction();
        $inFlight();

        $connection = config('database.connections.pgsql');
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', $command, json_encode($args)], null, [
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $connection['host'],
            'DB_PORT' => $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'],
        ]);
        $process->start();

        $this->waitUntilBlocked($process);
        if ($whileBlocked) {
            $whileBlocked();
        }
        DB::commit();
        $process->wait();

        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, 'Worker sem resposta: '.$process->getErrorOutput().$process->getOutput());

        return $result;
    }

    /**
     * O worker está esperando um lock quando o pg_stat_activity mostra outra sessão parada em Lock.
     * pg_stat_clear_snapshot(): dentro de uma transação o Postgres guarda em cache a primeira leitura das
     * views de estatística, então sem isso o segundo poll em diante nunca veria o waiter.
     */
    private function waitUntilBlocked(Process $process): void
    {
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) c from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()")->c;
            if ($waiting > 0) {
                return;
            }
            if (! $process->isRunning()) {
                throw new RuntimeException('O worker terminou sem bloquear no lock: '.$process->getErrorOutput().$process->getOutput());
            }
            usleep(50_000);
        }
        throw new RuntimeException('O worker não chegou a esperar o lock da ordem.');
    }
}
