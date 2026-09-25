<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Device;
use App\Models\Part;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Nenhuma mensagem de validação pode chegar ao usuário como chave crua (`validation.required_if`) ou em inglês. */
class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function assertPortuguese($response): void
    {
        $response->assertUnprocessable();
        $messages = collect($response->json('errors'))->flatten();
        $this->assertNotEmpty($messages);
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('validation.', $message, "chave de tradução crua: {$message}");
            $this->assertDoesNotMatchRegularExpression('/\b(field is required|must be|is invalid|The selected)\b/i', $message, "mensagem em inglês: {$message}");
        }
    }

    public function test_every_validation_rule_in_use_has_a_portuguese_message(): void
    {
        $admin = User::factory()->create();
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();
        $quote = Quote::factory()->create(['work_order_id' => $order->id]);
        $order->forceFill(['current_quote_id' => $quote->id])->save();
        $part = Part::factory()->create();

        $cases = [
            // required_if / prohibited_if / distinct / present / integer / min / max / exists
            ['put', "/api/v1/quotes/{$quote->id}/draft", ['items' => [['kind' => 'part', 'quantity' => 1], ['kind' => 'service', 'quantity' => 0]]]],
            ['put', "/api/v1/quotes/{$quote->id}/draft", ['items' => [['kind' => 'part', 'part_id' => $part->id, 'quantity' => 1], ['kind' => 'part', 'part_id' => $part->id, 'quantity' => 1]]]],
            ['put', "/api/v1/quotes/{$quote->id}/draft", ['items' => [['kind' => 'service', 'part_id' => $part->id, 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => 1]]]],
            ['put', "/api/v1/quotes/{$quote->id}/draft", []],
            // enum / date / before_or_equal / regex
            ['post', "/api/v1/quotes/{$quote->id}/decision", ['decision' => 'talvez', 'customer_name' => 'x', 'channel' => 'pombo']],
            ['post', "/api/v1/quotes/{$quote->id}/decision", ['decision' => 'approved', 'customer_name' => 'x', 'channel' => 'phone', 'decided_at' => 'ontem']],
            ['post', "/api/v1/quotes/{$quote->id}/decision", ['decision' => 'approved', 'customer_name' => 'x', 'channel' => 'phone', 'decided_at' => now()->addDay()->toIso8601String()]],
            ['get', '/api/v1/work-orders?status=qualquer&number=abc', []],
            ['post', '/api/v1/parts', ['sku' => '', 'name' => '', 'sale_price_cents' => -1]],
            ['post', "/api/v1/parts/{$part->id}/receipts", ['quantity' => 0]],
            ['post', '/api/v1/customers', ['name' => '', 'phone' => 'abc']],
            ['post', '/api/v1/work-orders', ['customer_id' => 999, 'device_id' => 999]],
            ['patch', "/api/v1/work-orders/{$order->id}/diagnosis", ['assigned_technician_id' => 999]],
            ['post', "/api/v1/work-orders/{$order->id}/cancel", []],
            ['post', "/api/v1/work-orders/{$order->id}/complete-repair", []],
            ['post', "/api/v1/work-orders/{$order->id}/reservations", []],
            ['post', "/api/v1/customers/{$this->deviceOwner()}/devices", []],
        ];

        foreach ($cases as [$method, $uri, $body]) {
            $this->assertPortuguese($this->actingAs($admin)->json($method, $uri, $body));
        }
    }

    private function deviceOwner(): int
    {
        return Device::factory()->create()->customer_id;
    }
}
