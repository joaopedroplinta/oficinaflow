<?php

namespace Database\Seeders;

use App\Actions\Inventory\ReceivePart;
use App\Actions\Inventory\ReserveParts;
use App\Actions\Inventory\StartRepair;
use App\Actions\Quotes\CreateQuoteDraft;
use App\Actions\Quotes\IssueQuote;
use App\Actions\Quotes\RecordQuoteDecision;
use App\Actions\Quotes\ReviseQuote;
use App\Actions\Quotes\SaveQuoteDraft;
use App\Actions\WorkOrders\CompleteRepair;
use App\Actions\WorkOrders\OpenWorkOrder;
use App\Actions\WorkOrders\RecordDiagnosis;
use App\Actions\WorkOrders\StartDiagnosis;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Part;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Seeder;

/**
 * Quatro ordens de exemplo para a demonstração (PLANO.md §10), criadas pelas mesmas Actions da aplicação:
 * estoque, reservas, livro-razão e linha do tempo nascem consistentes, sem atalho no banco.
 * Sem factories nem Faker: precisa rodar em produção (`composer install --no-dev`).
 */
class DemoSeeder extends Seeder
{
    private User $admin;

    private User $tech;

    public function run(): void
    {
        $this->admin = User::where('email', 'admin@oficinaflow.test')->firstOrFail();
        $this->tech = User::where('email', 'tecnico@oficinaflow.test')->firstOrFail();

        $parts = $this->catalog();

        $this->orderInDiagnosis();
        $this->approvedWithoutStock($parts['tela']);
        $this->revisedQuote($parts['ssd'], $parts['ram']);
        $this->repairReady($parts['leitor'], $parts['cabo']);
    }

    /** Catálogo; todo saldo entra por ReceivePart (o livro-razão explica o estoque). */
    private function catalog(): array
    {
        $parts = [];
        foreach ([
            'fonte' => ['FONTE-500', 'Fonte 500W', 22000, 4],
            'cabo' => ['CABO-FORCA', 'Cabo de força', 1500, 20],
            'ssd' => ['SSD-480', 'SSD 480 GB', 31000, 3],
            'ram' => ['RAM-8GB', 'Memória RAM 8 GB', 18000, 5],
            'leitor' => ['LEITOR-OPT', 'Leitor óptico de console', 26000, 2],
            'tela' => ['TELA-A54', 'Tela Galaxy A54', 48000, 0], // sem saldo: o orçamento aprovado da ordem 2 fica aguardando peças
        ] as $key => [$sku, $name, $price, $stock]) {
            $part = Part::create(['sku' => $sku, 'name' => $name, 'sale_price_cents' => $price])->refresh();
            if ($stock > 0) {
                app(ReceivePart::class)->handle($part, $stock, 'Estoque inicial da demonstração', $this->admin);
            }
            $parts[$key] = $part;
        }

        return $parts;
    }

    private function open(string $customer, string $phone, array $device, string $problem, string $condition, string $accessories): WorkOrder
    {
        $c = Customer::create(['name' => $customer, 'phone' => $phone, 'email' => null]);
        $d = new Device($device);
        $d->customer_id = $c->id;
        $d->save();

        return app(OpenWorkOrder::class)->handle(['device_id' => $d->id, 'reported_problem' => $problem, 'intake_condition' => $condition, 'accessories' => $accessories], $this->admin);
    }

    private function diagnose(WorkOrder $order, string $text): WorkOrder
    {
        app(StartDiagnosis::class)->handle($order, $this->tech);
        app(RecordDiagnosis::class)->handle($order, ['diagnosis' => $text, 'assigned_technician_id' => $this->tech->id], $this->tech);

        return $order->fresh();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function issuedQuote(WorkOrder $order, array $items, string $notes): Quote
    {
        $quote = app(CreateQuoteDraft::class)->handle($order, $this->admin);
        app(SaveQuoteDraft::class)->handle($quote, ['notes' => $notes, 'items' => $items]);

        return $quote->fresh();
    }

    private function approve(Quote $quote, string $who, string $channel): void
    {
        app(IssueQuote::class)->handle($quote, $this->admin);
        app(RecordQuoteDecision::class)->handle($quote->fresh(), ['decision' => 'approved', 'customer_name' => $who, 'channel' => $channel], $this->admin);
    }

    /** 1. Em diagnóstico: só o técnico olhou, ainda sem orçamento. */
    private function orderInDiagnosis(): void
    {
        $order = $this->open('Maria Silva', '(41) 99999-1001', ['category' => 'Notebook', 'brand' => 'Dell', 'model' => 'Inspiron 15', 'serial_number' => 'DL15-88231'],
            'Não liga de jeito nenhum.', 'Arranhões na tampa, sem avarias na tela.', 'Carregador original');
        $this->diagnose($order, 'Sem sinal de carga. Suspeita de fonte queimada; falta confirmar com a fonte de bancada.');
    }

    /** 2. Orçamento aprovado, mas a tela ainda não chegou: aprovada e "aguardando peças". */
    private function approvedWithoutStock(Part $tela): void
    {
        $order = $this->open('João Pereira', '(41) 99999-1002', ['category' => 'Celular', 'brand' => 'Samsung', 'model' => 'Galaxy A54', 'serial_number' => null],
            'Tela trincada depois de uma queda.', 'Vidro trincado no canto superior; touch funcionando.', 'Capa transparente');
        $this->diagnose($order, 'Display danificado; placa e bateria sem defeito. Precisa trocar a tela.');
        $quote = $this->issuedQuote($order->fresh(), [
            ['kind' => 'part', 'part_id' => $tela->id, 'quantity' => 1],
            ['kind' => 'service', 'description' => 'Troca de tela e teste', 'quantity' => 1, 'unit_price_cents' => 9000],
        ], 'Garantia de 90 dias na peça.');
        $this->approve($quote, 'João Pereira', 'whatsapp');
    }

    /** 3. Orçamento revisado: a V1 foi aprovada e reservada; o cliente pediu mais memória, nasce a V2 e volta para aprovação. */
    private function revisedQuote(Part $ssd, Part $ram): void
    {
        $order = $this->open('Carla Souza', '(41) 99999-1003', ['category' => 'Notebook', 'brand' => 'Lenovo', 'model' => 'IdeaPad 3', 'serial_number' => 'LN3-55102'],
            'Muito lento, demora minutos para abrir.', 'Em bom estado.', 'Nenhum');
        $this->diagnose($order, 'HD mecânico com setores ruins. Recomendado trocar por SSD.');
        $v1 = $this->issuedQuote($order->fresh(), [
            ['kind' => 'part', 'part_id' => $ssd->id, 'quantity' => 1],
            ['kind' => 'service', 'description' => 'Instalação do SSD e clonagem do sistema', 'quantity' => 1, 'unit_price_cents' => 12000],
        ], 'Inclui formatação e backup dos arquivos.');
        $this->approve($v1, 'Carla Souza', 'phone');
        app(ReserveParts::class)->handle($order->fresh(), $v1->id, $this->admin);

        // A cliente pediu mais memória: a revisão libera a reserva, cria a V2 (mesmos itens) e exige nova aprovação.
        $v2 = app(ReviseQuote::class)->handle($order->fresh(), $v1->id, $this->admin);
        app(SaveQuoteDraft::class)->handle($v2, ['notes' => 'V2: SSD + upgrade de memória.', 'items' => [
            ['kind' => 'part', 'part_id' => $ssd->id, 'quantity' => 1],
            ['kind' => 'part', 'part_id' => $ram->id, 'quantity' => 1],
            ['kind' => 'service', 'description' => 'Instalação do SSD, memória e clonagem do sistema', 'quantity' => 1, 'unit_price_cents' => 14000],
        ]]);
        app(IssueQuote::class)->handle($v2->fresh(), $this->admin);
    }

    /** 4. Reparo pronto: aprovado, reservado, consumido e concluído; aguardando a retirada. */
    private function repairReady(Part $leitor, Part $cabo): void
    {
        $order = $this->open('Pedro Alves', '(41) 99999-1004', ['category' => 'Console', 'brand' => 'Sony', 'model' => 'PlayStation 4', 'serial_number' => 'PS4-77420'],
            'Não lê discos.', 'Carcaça com marcas de uso.', 'Fonte e um controle');
        $this->diagnose($order, 'Leitor óptico com a lente suja e a correia gasta; cabo de força com mau contato.');
        $quote = $this->issuedQuote($order->fresh(), [
            ['kind' => 'part', 'part_id' => $leitor->id, 'quantity' => 1],
            ['kind' => 'part', 'part_id' => $cabo->id, 'quantity' => 1],
            ['kind' => 'service', 'description' => 'Mão de obra e teste de leitura', 'quantity' => 1, 'unit_price_cents' => 8000],
        ], 'Garantia de 90 dias.');
        $this->approve($quote, 'Pedro Alves', 'in_person');
        app(ReserveParts::class)->handle($order->fresh(), $quote->id, $this->admin);
        app(StartRepair::class)->handle($order->fresh(), $quote->id, $this->tech);
        app(CompleteRepair::class)->handle($order->fresh(), 'Troca do leitor óptico e do cabo de força; teste de leitura de discos por 30 minutos sem falhas.', $this->tech);
    }
}
