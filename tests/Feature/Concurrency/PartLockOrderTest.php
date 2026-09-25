<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Inventory\ReserveParts;
use App\Actions\Inventory\StartRepair;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;

/**
 * Protocolo de locks (concorrencia.md): a ordem primeiro e as peças em ordem CRESCENTE de ID, em toda Action.
 * É isso que impede deadlock entre Actions que disputam as mesmas peças.
 *
 * Como provar sem depender de sorte: o teste segura o lock da peça de MENOR ID e dispara a Action. Se ela trava em
 * ordem crescente, fica esperando essa peça sem ter travado a de MAIOR ID; com a ordem invertida, já teria travado a
 * de maior ID antes de esperar a menor. `FOR UPDATE NOWAIT` na de maior ID, com o worker parado, diferencia os dois.
 */
class PartLockOrderTest extends ConcurrencyTestCase
{
    use BuildsApprovedOrders;

    /** @return array{0: Part, 1: Part} peça de menor ID, peça de maior ID */
    private function twoParts(): array
    {
        return [Part::factory()->create(['on_hand' => 10]), Part::factory()->create(['on_hand' => 10])];
    }

    /** Roda o worker enquanto o teste segura o lock da peça `$low`; confere que o worker não travou a peça `$high`. */
    private function assertAscending(string $command, array $args, Part $low, Part $high): array
    {
        $wasFree = null;

        $result = $this->whileInFlight(
            fn () => DB::select('select id from parts where id = ? for update', [$low->id]),
            $command, $args,
            function () use ($high, &$wasFree) {
                try {
                    DB::transaction(fn () => DB::select('select id from parts where id = ? for update nowait', [$high->id]));
                    $wasFree = true;
                } catch (QueryException) {
                    $wasFree = false;
                }
            },
        );

        $this->assertTrue($wasFree, 'O worker travou a peça de maior ID antes de esperar a de menor ID: os locks não estão em ordem crescente.');

        return $result;
    }

    public function test_reserve_parts_locks_parts_in_ascending_id_order_whatever_the_item_order(): void
    {
        [$low, $high] = $this->twoParts();
        $admin = User::factory()->create();
        // Os itens entram com a peça de MAIOR ID primeiro: quem travasse na ordem dos itens travaria a errada.
        $quote = $this->approvedOrder([[$high, 1], [$low, 1]]);

        $result = $this->assertAscending('reserve', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id], $low, $high);

        $this->assertSame(['result' => 'ok'], $result);
        $this->assertSame(2, PartReservation::count());
        $this->assertStockInvariant();
    }

    public function test_start_repair_locks_parts_in_ascending_id_order(): void
    {
        [$low, $high] = $this->twoParts();
        $admin = User::factory()->create();
        $quote = $this->approvedOrder([[$high, 1], [$low, 1]]);
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);

        $result = $this->assertAscending('start', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id], $low, $high);

        $this->assertSame(['result' => 'ok'], $result);
        $this->assertSame(2, PartReservation::where('status', 'consumed')->count());
        $this->assertStockInvariant();
    }

    public function test_release_reservations_locks_parts_in_ascending_id_order(): void
    {
        [$low, $high] = $this->twoParts();
        $admin = User::factory()->create();
        $quote = $this->approvedOrder([[$high, 1], [$low, 1]]);
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);

        $result = $this->assertAscending('cancel', ['order_id' => $quote->work_order_id, 'actor_id' => $admin->id], $low, $high);

        $this->assertSame(['result' => 'ok'], $result);
        $this->assertSame(2, PartReservation::where('status', 'released')->count());
        $this->assertStockInvariant();
    }

    public function test_cancelling_in_progress_locks_the_reserved_and_the_consumed_parts_in_one_ascending_pass(): void
    {
        // Consumida = menor ID, reservada (ativa) = maior ID: liberar e estornar em duas passadas travaria a maior
        // primeiro e a menor depois, o que cruza com outra ordem e dá deadlock.
        [$low, $high] = $this->twoParts();
        $admin = User::factory()->create();
        $quote = $this->approvedOrder([[$low, 1], [$high, 1]]);
        $order = WorkOrder::find($quote->work_order_id);
        $high->forceFill(['on_hand' => 0])->save();
        app(ReserveParts::class)->handle($order, $quote->id, $admin, true);                       // só a menor
        app(StartRepair::class)->handle($order, $quote->id, $admin, true);
        $high->forceFill(['on_hand' => 10])->save();
        app(ReserveParts::class)->handle($order, $quote->id, $admin, true);                       // a maior, ativa

        $result = $this->assertAscending('cancel', ['order_id' => $quote->work_order_id, 'actor_id' => $admin->id, 'parts' => [$low->id => ['action' => 'return', 'quantity' => 1]]], $low, $high);

        $this->assertSame(['result' => 'ok'], $result);
        $this->assertSame([10, 0], [$low->fresh()->on_hand, $low->fresh()->reserved_quantity]);
        $this->assertSame([10, 0], [$high->fresh()->on_hand, $high->fresh()->reserved_quantity]);
        $this->assertStockInvariant();
    }
}
