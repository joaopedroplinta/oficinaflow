<?php

namespace Tests\Feature\Concurrency;

use App\Actions\WorkOrders\AddAttachment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** O limite de 20 anexos por ordem vale mesmo com dois envios simultâneos (o lock da ordem serializa). */
class AttachmentLimitConcurrencyTest extends ConcurrencyTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAX+XDSwAAAABJRU5ErkJggg==';

    public function test_two_simultaneous_uploads_at_19_attachments_admit_only_one(): void
    {
        Storage::fake('local');
        $order = WorkOrder::factory()->create();
        $user = User::factory()->create();
        for ($i = 0; $i < 19; $i++) {
            WorkOrderAttachment::create(['work_order_id' => $order->id, 'uploaded_by' => $user->id, 'path' => "p/{$i}", 'original_name' => "{$i}.png", 'mime_type' => 'image/png', 'size_bytes' => 1]);
        }
        $file = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($file, base64_decode(self::PNG));

        $result = $this->whileInFlight(
            fn () => app(AddAttachment::class)->handle($order, new UploadedFile($file, 'a.png', 'image/png', null, true), $user),
            'attach', ['order_id' => $order->id, 'actor_id' => $user->id, 'file' => $file],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'ATTACHMENT_LIMIT'], $result);
        $this->assertSame(20, DB::table('work_order_attachments')->where('work_order_id', $order->id)->count());
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'attachment_added')->count());
        unlink($file);
    }
}
