<?php

/**
 * Processo-filho dos testes de concorrência: abre a própria conexão com o PostgreSQL, roda uma Action
 * e imprime o resultado em JSON. Uso: php worker.php <revise|decide|issue|receive> <json com os argumentos>
 */

use App\Actions\Inventory\ReceivePart;
use App\Actions\Quotes\IssueQuote;
use App\Actions\Quotes\RecordQuoteDecision;
use App\Actions\Quotes\ReviseQuote;
use App\Exceptions\DomainConflict;
use App\Models\Part;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Contracts\Console\Kernel;
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
        'receive' => $app->make(ReceivePart::class)->handle(Part::findOrFail($args['part_id']), $args['quantity'], null, $actor),
    };
    echo json_encode(['result' => 'ok']);
} catch (DomainConflict $e) {
    echo json_encode(['result' => 'conflict', 'code' => $e->errorCode()]);
} catch (ValidationException $e) {
    echo json_encode(['result' => 'validation', 'fields' => array_keys($e->errors())]);
}
