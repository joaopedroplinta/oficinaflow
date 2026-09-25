<?php

/**
 * Processo-filho dos testes de concorrência: abre a própria conexão com o PostgreSQL, roda uma Action
 * e imprime o resultado em JSON. Uso: php worker.php <revise|decide|issue|receive|reserve|start|complete|deliver|cancel|attach|send|return> <json com os argumentos>
 */

use App\Actions\Inventory\ReceivePart;
use App\Actions\Inventory\ReserveParts;
use App\Actions\Inventory\StartRepair;
use App\Actions\Quotes\IssueQuote;
use App\Actions\Quotes\RecordQuoteDecision;
use App\Actions\Quotes\ReviseQuote;
use App\Actions\Quotes\SendQuote;
use App\Actions\WorkOrders\AddAttachment;
use App\Actions\WorkOrders\CancelWorkOrder;
use App\Actions\WorkOrders\CompleteRepair;
use App\Actions\WorkOrders\DeliverWorkOrder;
use App\Actions\WorkOrders\OpenReturn;
use App\Enums\DeliveryChannel;
use App\Enums\WorkOrderKind;
use App\Exceptions\DomainConflict;
use App\Models\Part;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $command, $json] = $argv;
$args = json_decode($json, true);
$actor = User::findOrFail($args['actor_id']);

try {
    match ($command) {
        'revise' => $app->make(ReviseQuote::class)->handle(WorkOrder::findOrFail($args['order_id']), $args['quote_id'], $actor),
        'decide' => $app->make(RecordQuoteDecision::class)->handle(Quote::findOrFail($args['quote_id']), $args['data'], $actor),
        'issue' => $app->make(IssueQuote::class)->handle(Quote::findOrFail($args['quote_id']), $actor),
        'reserve' => $app->make(ReserveParts::class)->handle(WorkOrder::findOrFail($args['order_id']), $args['quote_id'], $actor),
        'start' => $app->make(StartRepair::class)->handle(WorkOrder::findOrFail($args['order_id']), $args['quote_id'], $actor),
        'cancel' => $app->make(CancelWorkOrder::class)->handle(WorkOrder::findOrFail($args['order_id']), 'Cancelado pelo worker', $actor),
        'complete' => $app->make(CompleteRepair::class)->handle(WorkOrder::findOrFail($args['order_id']), 'Trabalho do worker', $actor),
        'deliver' => $app->make(DeliverWorkOrder::class)->handle(WorkOrder::findOrFail($args['order_id']), $actor),
        'send' => $app->make(SendQuote::class)->handle(Quote::findOrFail($args['quote_id']), DeliveryChannel::Whatsapp, $actor),
        'return' => $app->make(OpenReturn::class)->handle(WorkOrder::findOrFail($args['order_id']), WorkOrderKind::from($args['kind']), ['reported_problem' => 'Worker', 'intake_condition' => 'Worker', 'accessories' => 'Worker'], $actor),
        'attach' => $app->make(AddAttachment::class)->handle(WorkOrder::findOrFail($args['order_id']), new UploadedFile($args['file'], 'worker.png', 'image/png', null, true), $actor),
        'receive' => $app->make(ReceivePart::class)->handle(Part::findOrFail($args['part_id']), $args['quantity'], null, $actor),
    };
    echo json_encode(['result' => 'ok']);
} catch (DomainConflict $e) {
    echo json_encode(['result' => 'conflict', 'code' => $e->errorCode()]);
} catch (ValidationException $e) {
    echo json_encode(['result' => 'validation', 'fields' => array_keys($e->errors())]);
}
