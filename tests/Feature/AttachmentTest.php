<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Models\WorkOrderEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAX+XDSwAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    /** Arquivo real em disco: o `UploadedFile::fake()` deduz o tipo pelo nome, e aqui o tipo tem que vir do conteúdo. */
    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function png(string $name = 'foto.png'): UploadedFile
    {
        return $this->file($name, base64_decode(self::PNG));
    }

    private function pdf(string $name = 'laudo.pdf'): UploadedFile
    {
        return $this->file($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function upload(User $user, WorkOrder $order, UploadedFile $file)
    {
        return $this->actingAs($user)->post("/api/v1/work-orders/{$order->id}/attachments", ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_technician_and_admin_attach_images_and_pdfs_with_event_and_random_private_name(): void
    {
        $order = WorkOrder::factory()->create();
        $tech = User::factory()->technician()->create(['name' => 'Tiago']);

        $this->upload($tech, $order, $this->png('Tela quebrada.PNG'))->assertCreated()
            ->assertJsonPath('data.original_name', 'Tela quebrada.PNG')
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonPath('data.is_image', true)
            ->assertJsonPath('data.uploader.name', 'Tiago');
        $this->upload(User::factory()->create(), $order, $this->pdf())->assertCreated()->assertJsonPath('data.is_image', false);

        $rows = WorkOrderAttachment::orderBy('id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertStringStartsWith("work-orders/{$order->id}/", $row->path);
            $this->assertStringNotContainsString('Tela', $row->path, 'o nome no disco é aleatório');
            Storage::disk('local')->assertExists($row->path);
        }
        $this->assertSame(2, $order->events()->where('type', 'attachment_added')->count());
        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$order->id}/events")
            ->assertJsonPath('data.0.type_label', 'Anexo adicionado');
    }

    public function test_rejects_other_types_even_when_the_name_lies_and_files_over_10_mb(): void
    {
        $order = WorkOrder::factory()->create();
        $admin = User::factory()->create();

        $this->upload($admin, $order, $this->file('foto.png', '<html><script>alert(1)</script></html>'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($admin, $order, $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($admin, $order, $this->file('grande.pdf', "%PDF-1.4\n".str_repeat('a', 10241 * 1024)))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/attachments", [])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Escolha um arquivo.');

        $this->assertSame(0, WorkOrderAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_21st_attachment_is_refused_with_409_and_nothing_is_stored(): void
    {
        $order = WorkOrder::factory()->create();
        $admin = User::factory()->create();
        for ($i = 0; $i < WorkOrderAttachment::MAX_PER_ORDER; $i++) {
            $this->upload($admin, $order, $this->png("f{$i}.png"))->assertCreated();
        }

        $this->upload($admin, $order, $this->png())->assertStatus(409)->assertJsonPath('code', 'ATTACHMENT_LIMIT');

        $this->assertSame(20, WorkOrderAttachment::count());
        $this->assertCount(20, Storage::disk('local')->allFiles());
        $this->assertSame(20, $order->events()->where('type', 'attachment_added')->count());
    }

    public function test_a_failure_after_storing_the_file_leaves_no_orphan_file_and_no_row(): void
    {
        $order = WorkOrder::factory()->create();
        WorkOrderEvent::creating(fn () => throw new RuntimeException('boom'));

        $this->upload(User::factory()->create(), $order, $this->png())->assertStatus(500);

        $this->assertSame(0, WorkOrderAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_limit_is_per_order(): void
    {
        $admin = User::factory()->create();
        $full = WorkOrder::factory()->create();
        $other = WorkOrder::factory()->create();
        for ($i = 0; $i < 20; $i++) {
            $this->upload($admin, $full, $this->png())->assertCreated();
        }

        $this->upload($admin, $other, $this->png())->assertCreated();
    }

    public function test_download_is_authorized_serves_the_original_content_and_is_never_public(): void
    {
        $order = WorkOrder::factory()->create();
        $tech = User::factory()->technician()->create();
        $id = $this->upload($tech, $order, $this->png())->json('data.id');
        $attachment = WorkOrderAttachment::findOrFail($id);

        $res = $this->actingAs($tech)->get("/api/v1/work-orders/{$order->id}/attachments/{$id}");
        $res->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(base64_decode(self::PNG), $res->streamedContent());

        $this->assertSame([], Storage::disk('public')->allFiles(), 'nada vai para o disco público');
        // Sem sessão, nem o caminho no disco nem a rota devolvem o arquivo.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/work-orders/{$order->id}/attachments/{$id}")->assertUnauthorized();
        // Adivinhar o caminho no disco não devolve o arquivo (cai no shell do SPA ou em 403/404, nunca nos bytes).
        $this->assertStringNotContainsString(base64_decode(self::PNG), $this->get('/storage/'.$attachment->path)->getContent());
    }

    public function test_an_attachment_is_only_reachable_through_its_own_order(): void
    {
        $mine = WorkOrder::factory()->create();
        $other = WorkOrder::factory()->create();
        $tech = User::factory()->technician()->create();
        $id = $this->upload($tech, $mine, $this->png())->json('data.id');

        $this->actingAs($tech)->get("/api/v1/work-orders/{$other->id}/attachments/{$id}")->assertNotFound();
        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$other->id}/attachments")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_returns_the_attachments_in_order_with_the_download_url(): void
    {
        $order = WorkOrder::factory()->create();
        $tech = User::factory()->technician()->create();
        $first = $this->upload($tech, $order, $this->png('a.png'))->json('data.id');
        $this->upload($tech, $order, $this->pdf('b.pdf'));

        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$order->id}/attachments")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.original_name', 'a.png')
            ->assertJsonPath('data.0.url', "/api/v1/work-orders/{$order->id}/attachments/{$first}")
            ->assertJsonPath('data.1.original_name', 'b.pdf');
    }

    public function test_the_record_cannot_be_updated_or_deleted_in_the_database(): void
    {
        $order = WorkOrder::factory()->create();
        $id = $this->upload(User::factory()->create(), $order, $this->png())->json('data.id');

        foreach (['delete from work_order_attachments', "update work_order_attachments set original_name = 'x'"] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("{$sql} deveria ser recusado");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame('foto.png', WorkOrderAttachment::findOrFail($id)->original_name);
    }

    public function test_the_database_refuses_a_disallowed_type_or_size(): void
    {
        $order = WorkOrder::factory()->create();
        $user = User::factory()->create();
        $row = ['work_order_id' => $order->id, 'uploaded_by' => $user->id, 'original_name' => 'x', 'mime_type' => 'image/png', 'size_bytes' => 10];

        foreach ([['mime_type' => 'text/html'], ['size_bytes' => 10485761], ['size_bytes' => 0]] as $i => $bad) {
            try {
                DB::transaction(fn () => DB::table('work_order_attachments')->insert(array_merge($row, ['path' => "p/{$i}"], $bad)));
                $this->fail('a constraint deveria recusar '.json_encode($bad));
            } catch (QueryException $e) {
                $this->assertStringContainsString('check constraint', $e->getMessage());
            }
        }
    }
}
