<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Quotes\RecordQuoteDecision;
use App\Actions\Quotes\ReviseQuote;
use App\Enums\QuoteStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Grupo 2 do PLANO §9 sob concorrência real: duas conexões ao PostgreSQL, uma delas parada no lock da
 * ordem enquanto a outra ainda não confirmou. Sem RefreshDatabase: os dados precisam estar commitados
 * para o processo-filho enxergá-los (por isso o TRUNCATE no início e no fim).
 */
class QuoteApprovalConcurrencyTest extends TestCase
{
    private const TABLES = 'users, customers, devices, work_orders, work_order_events, quotes, quote_items, quote_decisions, parts';

    private array $approval = ['decision' => 'approved', 'customer_name' => 'Maria Silva', 'channel' => 'phone'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('quote_decisions')) {
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

    /** Ordem aguardando aprovação com a V1 emitida. */
    private function awaitingApproval(): array
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::AwaitingApproval)->create(['diagnosis' => 'Fonte']);
        $v1 = Quote::factory()->create(['work_order_id' => $order->id, 'total_cents' => 8000]);
        $v1->items()->create(['kind' => 'service', 'description' => 'Mão de obra', 'quantity' => 1, 'unit_price_cents' => 8000, 'position' => 0]);
        $v1->forceFill(['status' => QuoteStatus::Issued, 'issued_at' => now()->subHour()])->save();
        $order->forceFill(['current_quote_id' => $v1->id])->save();

        return [$order, $v1, User::factory()->create()];
    }

    /**
     * Abre a transação de $inFlight (lock da ordem retido, nada confirmado), dispara o worker em outro
     * processo, espera ele travar no lock e só então confirma. Devolve o que o worker respondeu.
     */
    private function whileInFlight(callable $inFlight, string $command, array $args): array
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

    private function assertConsistent(WorkOrder $order): void
    {
        $order = $order->fresh();
        $live = Quote::where('work_order_id', $order->id)->where('status', '!=', QuoteStatus::Superseded->value)->get();

        $this->assertCount(1, $live, 'Deve haver exatamente uma versão não substituída.');
        $this->assertSame($live->first()->id, $order->current_quote_id);
    }

    public function test_two_revisions_of_the_same_version_create_only_one_new_version(): void
    {
        [$order, $v1, $admin] = $this->awaitingApproval();

        $result = $this->whileInFlight(
            fn () => app(ReviseQuote::class)->handle($order, $v1->id, $admin),
            'revise', ['order_id' => $order->id, 'quote_id' => $v1->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'QUOTE_SUPERSEDED'], $result);
        $this->assertSame(2, Quote::where('work_order_id', $order->id)->count());
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'quote_revised')->count());
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->fresh()->status);
        $this->assertConsistent($order);
    }

    public function test_approving_a_version_that_is_being_revised_is_refused_and_records_nothing(): void
    {
        [$order, $v1, $admin] = $this->awaitingApproval();

        $result = $this->whileInFlight(
            fn () => app(ReviseQuote::class)->handle($order, $v1->id, $admin),
            'decide', ['quote_id' => $v1->id, 'data' => $this->approval, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'QUOTE_SUPERSEDED'], $result);
        $this->assertSame(0, DB::table('quote_decisions')->count());
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->fresh()->status);
        $this->assertConsistent($order);
    }

    public function test_revising_a_version_that_is_being_approved_keeps_the_approval_in_the_history(): void
    {
        [$order, $v1, $admin] = $this->awaitingApproval();

        $result = $this->whileInFlight(
            fn () => app(RecordQuoteDecision::class)->handle($v1, $this->approval, $admin),
            'revise', ['order_id' => $order->id, 'quote_id' => $v1->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $v2 = Quote::where('work_order_id', $order->id)->where('version', 2)->sole();
        $this->assertDatabaseHas('quote_decisions', ['quote_id' => $v1->id, 'decision' => 'approved']);
        $this->assertDatabaseMissing('quote_decisions', ['quote_id' => $v2->id]);
        $this->assertSame(QuoteStatus::Superseded, $v1->fresh()->status);
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->fresh()->status, 'A V2 exige nova aprovação.');
        $this->assertSame(['quote_approved', 'quote_revised'], DB::table('work_order_events')->orderBy('id')->pluck('type')->all());
        $this->assertConsistent($order);
    }

    public function test_approving_and_rejecting_the_same_version_at_once_records_one_decision(): void
    {
        [$order, $v1, $admin] = $this->awaitingApproval();

        $result = $this->whileInFlight(
            fn () => app(RecordQuoteDecision::class)->handle($v1, $this->approval, $admin),
            'decide', ['quote_id' => $v1->id, 'data' => ['decision' => 'rejected'] + $this->approval, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame(1, DB::table('quote_decisions')->count());
        $this->assertDatabaseHas('quote_decisions', ['quote_id' => $v1->id, 'decision' => 'approved']);
        $this->assertSame(WorkOrderStatus::Approved, $order->fresh()->status);
        $this->assertSame(1, DB::table('work_order_events')->count());
        $this->assertConsistent($order);
    }
}
