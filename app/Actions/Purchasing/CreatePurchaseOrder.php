<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseOrder
{
    /**
     * Pedido de compra: fornecedor e itens (peça, quantidade e custo combinado). Não mexe em estoque; o pedido é
     * imutável depois de criado (para mudar, cancela e refaz).
     *
     * @param  array{supplier_id: int, notes?: string|null, items: list<array{part_id: int, quantity: int, unit_cost_cents: int}>}  $data
     */
    public function handle(array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            // A validação do request rodou antes; desativar entre um e outro não pode gerar pedido para inativo.
            if (! Supplier::whereKey($data['supplier_id'])->where('active', true)->sharedLock()->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'Fornecedor não encontrado ou inativo.']);
            }
            $partIds = collect($data['items'])->pluck('part_id')->sort()->values();
            $active = Part::whereIn('id', $partIds)->where('active', true)->orderBy('id')->sharedLock()->pluck('id');
            foreach (array_values($data['items']) as $n => $item) {
                if (! $active->contains($item['part_id'])) {
                    throw ValidationException::withMessages(["items.{$n}.part_id" => 'Peça não encontrada ou inativa.']);
                }
            }

            $order = new PurchaseOrder(['notes' => $data['notes'] ?? null]);
            $order->supplier_id = $data['supplier_id'];
            $order->status = PurchaseOrderStatus::Open;
            $order->created_by = $actor->id;
            $order->save();
            $order->items()->createMany($data['items']);

            // Relê para trazer o `number` gerado pelo banco.
            return $order->refresh();
        });
    }
}
