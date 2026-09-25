<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Device;
use App\Models\Part;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Varredura de permissões (PLANO §8): cada rota da API, para visitante, usuário inativo, técnico e atendimento.
 * A matriz é explícita e o teste falha se uma rota nova aparecer sem entrada aqui.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.attachments'));
    }

    private const BOTH = 'both';

    private const ADMIN = 'admin';

    /** "MÉTODO uri" => [quem pode, corpo]. Quem não pode recebe 403; quem pode nunca recebe 401/403. */
    private function matrix(): array
    {
        return [
            'GET api/v1/me' => [self::BOTH, []],
            'POST api/v1/logout' => [self::BOTH, []],

            'GET api/v1/customers' => [self::BOTH, []],
            'POST api/v1/customers' => [self::ADMIN, []],
            'GET api/v1/customers/{customer}' => [self::BOTH, []],
            'PATCH api/v1/customers/{customer}' => [self::ADMIN, []],
            'GET api/v1/customers/{customer}/devices' => [self::BOTH, []],
            'POST api/v1/customers/{customer}/devices' => [self::ADMIN, []],
            'PATCH api/v1/devices/{device}' => [self::ADMIN, []],

            'GET api/v1/parts' => [self::BOTH, []],
            'POST api/v1/parts' => [self::ADMIN, []],
            'GET api/v1/parts/{part}' => [self::BOTH, []],
            'PATCH api/v1/parts/{part}' => [self::ADMIN, []],
            'GET api/v1/parts/{part}/movements' => [self::ADMIN, []],
            'POST api/v1/parts/{part}/receipts' => [self::ADMIN, []],
            'GET api/v1/technicians' => [self::BOTH, []],

            'GET api/v1/work-orders' => [self::BOTH, []],
            'POST api/v1/work-orders' => [self::ADMIN, []],
            'GET api/v1/work-orders/{workOrder}' => [self::BOTH, []],
            'GET api/v1/work-orders/{workOrder}/events' => [self::BOTH, []],
            'GET api/v1/work-orders/{workOrder}/quotes' => [self::BOTH, []],
            'POST api/v1/work-orders/{workOrder}/start-diagnosis' => [self::BOTH, []],
            'PATCH api/v1/work-orders/{workOrder}/diagnosis' => [self::BOTH, []],
            'POST api/v1/work-orders/{workOrder}/quotes' => [self::ADMIN, []],
            'POST api/v1/work-orders/{workOrder}/quote-revisions' => [self::ADMIN, []],
            'POST api/v1/work-orders/{workOrder}/addenda' => [self::ADMIN, []],
            'POST api/v1/work-orders/{workOrder}/reservations' => [self::ADMIN, []],
            'POST api/v1/work-orders/{workOrder}/start-repair' => [self::BOTH, []],
            'POST api/v1/work-orders/{workOrder}/consumptions' => [self::BOTH, []],
            'POST api/v1/work-orders/{workOrder}/complete-repair' => [self::BOTH, []],
            'POST api/v1/work-orders/{workOrder}/deliver' => [self::ADMIN, []],
            'POST api/v1/work-orders/{workOrder}/cancel' => [self::ADMIN, []],
            'POST api/v1/work-orders/{workOrder}/returns' => [self::ADMIN, []],

            'GET api/v1/work-orders/{workOrder}/attachments' => [self::BOTH, []],
            'POST api/v1/work-orders/{workOrder}/attachments' => [self::BOTH, []],
            'GET api/v1/work-orders/{workOrder}/attachments/{attachment}' => [self::BOTH, []],

            'GET api/v1/work-orders/{workOrder}/pdf' => [self::BOTH, []],
            'GET api/v1/quotes/{quote}/pdf' => [self::BOTH, []],

            'PUT api/v1/quotes/{quote}/draft' => [self::ADMIN, []],
            'POST api/v1/quotes/{quote}/issue' => [self::ADMIN, []],
            'POST api/v1/quotes/{quote}/decision' => [self::ADMIN, []],
            'POST api/v1/quotes/{quote}/deliveries' => [self::ADMIN, []],
        ];
    }

    /** As rotas protegidas de /api/v1, no formato "MÉTODO uri". */
    private function protectedRoutes(): array
    {
        $keys = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || $route->uri() === 'api/v1/login') {
                continue;
            }
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $keys[] = (in_array('PATCH', $methods, true) ? 'PATCH' : $methods[0]).' '.$route->uri();
        }
        sort($keys);

        return $keys;
    }

    private function url(string $key): string
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();
        $quote = Quote::factory()->create(['work_order_id' => $order->id]);
        $order->forceFill(['current_quote_id' => $quote->id])->save();
        $device = Device::factory()->create();
        $path = "work-orders/{$order->id}/fixture.png";
        Storage::disk(config('filesystems.attachments'))->put($path, 'x');
        $attachment = WorkOrderAttachment::create([
            'work_order_id' => $order->id, 'uploaded_by' => User::factory()->create()->id, 'path' => $path,
            'original_name' => 'fixture.png', 'mime_type' => 'image/png', 'size_bytes' => 1,
        ]);
        $ids = [
            '{attachment}' => $attachment->id, '{customer}' => $device->customer_id, '{device}' => $device->id, '{part}' => Part::factory()->create()->id,
            '{workOrder}' => $order->id, '{quote}' => $quote->id,
        ];

        return '/'.strtr(explode(' ', $key, 2)[1], $ids);
    }

    private function statusOf(string $key, ?User $as): int
    {
        $client = $as ? $this->actingAs($as) : $this;

        return $client->json(explode(' ', $key, 2)[0], $this->url($key), $this->matrix()[$key][1])->getStatusCode();
    }

    /** Permitido = passou pela autorização e a API respondeu de forma controlada (nada de 401/403/404/5xx). */
    private function assertAllowed(int $status, string $who): void
    {
        $this->assertNotContains($status, [401, 403, 404], "{$who}: barrado ou recurso inexistente (recebeu {$status})");
        $this->assertLessThan(500, $status, "{$who}: erro de servidor (recebeu {$status})");
    }

    public function test_the_matrix_covers_exactly_the_routes_of_the_api(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys($this->matrix()),
            $this->protectedRoutes(),
            'Toda rota nova de /api/v1 precisa de uma linha na matriz de permissões (e de uma Policy).',
        );
    }

    public function test_every_protected_route_requires_a_session_and_an_active_user(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || $route->uri() === 'api/v1/login') {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, "{$route->uri()} sem auth:sanctum");
            $this->assertContains(EnsureUserIsActive::class, $middleware, "{$route->uri()} sem EnsureUserIsActive");
        }
    }

    public function test_the_only_public_api_route_is_login(): void
    {
        $public = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/') && ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                $public[] = $route->uri();
            }
        }

        $this->assertSame(['api/v1/login'], $public);
    }

    public function test_guests_get_401_on_every_route(): void
    {
        foreach (array_keys($this->matrix()) as $key) {
            $this->assertSame(401, $this->statusOf($key, null), "visitante em {$key}");
        }
    }

    public function test_inactive_users_get_401_on_every_route_even_with_a_valid_session(): void
    {
        foreach ([User::factory()->inactive()->create(), User::factory()->technician()->inactive()->create()] as $user) {
            foreach (array_keys($this->matrix()) as $key) {
                $this->assertSame(401, $this->statusOf($key, $user), "usuário inativo ({$user->role->value}) em {$key}");
            }
        }
    }

    public function test_the_technician_is_forbidden_exactly_where_the_plan_reserves_the_action_for_the_admin(): void
    {
        $tech = User::factory()->technician()->create();

        foreach ($this->matrix() as $key => [$who]) {
            $status = $this->statusOf($key, $tech);

            if ($who === self::ADMIN) {
                $this->assertSame(403, $status, "técnico deveria receber 403 em {$key}");
            } else {
                $this->assertAllowed($status, "técnico em {$key}");
            }
        }
    }

    public function test_the_admin_is_never_denied(): void
    {
        foreach (array_keys($this->matrix()) as $key) {
            $status = $this->statusOf($key, User::factory()->create());

            $this->assertAllowed($status, "atendimento em {$key}");
        }
    }

    public function test_denied_writes_leave_no_trace_in_the_audit_and_stock_tables_nor_in_the_order(): void
    {
        $tech = User::factory()->technician()->create();

        foreach ($this->matrix() as $key => [$who]) {
            if ($who !== self::ADMIN || str_starts_with($key, 'GET')) {
                continue;
            }
            $this->assertSame(403, $this->statusOf($key, $tech));

            // Os fixtures da chamada acabaram de ser criados: a ordem segue em diagnóstico e a peça intacta.
            $this->assertSame(WorkOrderStatus::Diagnosing, WorkOrder::latest('id')->first()->status, "{$key} mexeu na ordem");
            $part = Part::latest('id')->first();
            $this->assertSame([10, 0, true], [$part->on_hand, $part->reserved_quantity, $part->active], "{$key} mexeu na peça");
        }

        $this->assertSame(0, \DB::table('work_order_events')->count());
        $this->assertSame(0, \DB::table('inventory_movements')->count());
        $this->assertSame(0, \DB::table('quote_decisions')->count());
        $this->assertSame(0, \DB::table('part_reservations')->count());
        $this->assertSame(0, \DB::table('quote_items')->count());
    }
}
