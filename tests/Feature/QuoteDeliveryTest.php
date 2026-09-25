<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Mail\QuoteMail;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

class QuoteDeliveryTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    private function issued(array $customer = []): Quote
    {
        $quote = $this->approvedOrder([]);
        $quote->workOrder->customer->forceFill($customer)->save();

        return $quote;
    }

    private function send(Quote $quote, string $channel, ?User $as = null)
    {
        return $this->actingAs($as ?? User::factory()->create())->postJson("/api/v1/quotes/{$quote->id}/deliveries", ['channel' => $channel]);
    }

    private function events(Quote $quote)
    {
        return $quote->workOrder->events()->where('type', 'quote_sent')->get();
    }

    public function test_email_sends_the_quote_pdf_to_the_customer_and_records_the_event(): void
    {
        Mail::fake();
        $quote = $this->issued(['name' => 'Maria Souza', 'email' => 'maria@example.com']);
        $admin = User::factory()->create();

        $this->send($quote, 'email', $admin)->assertCreated()
            ->assertJsonPath('data.channel', 'email')->assertJsonPath('data.channel_label', 'E-mail')
            ->assertJsonPath('data.recipient', 'maria@example.com')->assertJsonPath('data.url', null);

        Mail::assertSent(QuoteMail::class, fn (QuoteMail $m) => $m->hasTo('maria@example.com') && $m->quote->is($quote));
        Mail::assertSentCount(1);
        $event = $this->events($quote)->sole();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertEquals(['quote_id' => $quote->id, 'version' => $quote->version, 'channel' => 'email', 'channel_label' => 'E-mail', 'recipient' => 'maria@example.com'], $event->metadata);
    }

    public function test_the_email_really_carries_a_pdf_and_the_quote_summary(): void
    {
        config(['mail.default' => 'array']);
        $quote = $this->issued(['name' => 'Maria Souza', 'email' => 'maria@example.com']);

        $this->send($quote, 'email')->assertCreated();

        $raw = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->toString();
        $this->assertStringContainsString('To: maria@example.com', $raw);
        $this->assertStringContainsString('Subject: =?utf-8?', $raw); // "Orçamento…" com acento vai codificado
        $this->assertStringContainsString("orcamento-os-{$quote->workOrder->number}-v{$quote->version}.pdf", $raw);
        $this->assertStringContainsString('application/pdf', $raw);
        $this->assertStringContainsString('JVBERi0', str_replace(["\r", "\n"], '', $raw), 'o anexo é um PDF (base64 de %PDF-)');
    }

    public function test_email_without_a_registered_address_is_422_and_leaves_no_trace(): void
    {
        Mail::fake();
        $quote = $this->issued(['email' => null]);

        $this->send($quote, 'email')->assertUnprocessable()
            ->assertJsonPath('errors.channel.0', 'Este cliente não tem e-mail cadastrado. Cadastre o e-mail no cliente ou use o WhatsApp.');

        Mail::assertNothingSent();
        $this->assertCount(0, $this->events($quote));
    }

    public function test_a_mail_failure_rolls_the_event_back(): void
    {
        $quote = $this->issued(['email' => 'maria@example.com']);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP fora do ar'));

        $this->send($quote, 'email')->assertStatus(500);

        $this->assertCount(0, $this->events($quote), 'não pode constar como enviado o que não saiu');
    }

    public function test_whatsapp_returns_a_wa_me_link_with_the_text_and_never_sends_anything(): void
    {
        Mail::fake();
        $quote = $this->issued(['name' => 'Maria Souza', 'phone' => '(41) 99999-8888']);

        $url = $this->send($quote, 'whatsapp')->assertCreated()
            ->assertJsonPath('data.channel', 'whatsapp')->assertJsonPath('data.recipient', '5541999998888')
            ->json('data.url');

        $this->assertStringStartsWith('https://wa.me/5541999998888?text=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('Olá, Maria Souza!', $query['text']);
        $this->assertStringContainsString("nº {$quote->workOrder->number}", $query['text']);
        $this->assertStringContainsString('R$ 50,00', $query['text']);
        Mail::assertNothingSent();
        $this->assertSame('whatsapp', $this->events($quote)->sole()->metadata['channel']);
    }

    public function test_whatsapp_keeps_a_number_that_already_has_the_country_code_and_refuses_an_invalid_one(): void
    {
        $with55 = $this->issued(['phone' => '+55 (41) 3333-4444']);
        $this->send($with55, 'whatsapp')->assertCreated()->assertJsonPath('data.recipient', '554133334444');

        $bad = $this->issued(['phone' => '12345']);
        $this->send($bad, 'whatsapp')->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->assertCount(0, $this->events($bad));
    }

    public function test_only_an_issued_current_version_can_be_sent(): void
    {
        Mail::fake();
        $admin = User::factory()->create();

        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create(['diagnosis' => 'x']);
        $draft = Quote::factory()->create(['work_order_id' => $order->id]);
        $order->forceFill(['current_quote_id' => $draft->id])->save();
        $this->send($draft, 'email', $admin)->assertStatus(409)->assertJsonPath('code', 'QUOTE_NOT_ISSUED');

        $old = $this->issued(['email' => 'maria@example.com']);
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$old->work_order_id}/quote-revisions", ['quote_id' => $old->id])->assertCreated();
        $this->send($old, 'email', $admin)->assertStatus(409)->assertJsonPath('code', 'QUOTE_SUPERSEDED');

        Mail::assertNothingSent();
        $this->assertCount(0, $this->events($draft));
        $this->assertCount(0, $this->events($old));
    }

    public function test_the_technician_cannot_send_and_the_channel_is_validated(): void
    {
        Mail::fake();
        $quote = $this->issued(['email' => 'maria@example.com']);

        $this->send($quote, 'email', User::factory()->technician()->create())->assertForbidden();
        $this->send($quote, 'sms')->assertUnprocessable()->assertJsonPath('errors.channel.0', 'Escolha e-mail ou WhatsApp.');
        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$quote->id}/deliveries", [])
            ->assertUnprocessable()->assertJsonPath('errors.channel.0', 'Escolha o canal de envio.');

        Mail::assertNothingSent();
        $this->assertCount(0, $this->events($quote));
    }

    public function test_the_timeline_shows_the_event_with_its_channel(): void
    {
        $quote = $this->issued(['phone' => '(41) 99999-8888']);
        $admin = User::factory()->create();
        $this->send($quote, 'whatsapp', $admin)->assertCreated();

        $this->actingAs($admin)->getJson("/api/v1/work-orders/{$quote->work_order_id}/events")
            ->assertJsonFragment(['type_label' => 'Orçamento enviado']);
    }
}
