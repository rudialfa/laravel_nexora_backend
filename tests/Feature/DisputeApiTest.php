<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\Order;
use App\Services\DisputeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SiapkanSengketa;
use Tests\TestCase;

/**
 * Menguji komplain dari sisi pembeli dan seller lewat HTTP.
 */
class DisputeApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanSengketa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturSengketa();
    }

    public function test_endpoint_komplain_butuh_login_akun_aktif_dan_peran_yang_sesuai(): void
    {
        $u = $this->uuidAsing();

        $pembeli = [['GET', '/api/v1/disputes'], ['GET', "/api/v1/disputes/{$u}"], ['POST', "/api/v1/orders/{$u}/dispute"]];
        $seller  = [['GET', '/api/v1/seller/disputes'], ['GET', "/api/v1/seller/disputes/{$u}"], ['POST', "/api/v1/seller/disputes/{$u}/respond"]];

        foreach (array_merge($pembeli, $seller) as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif] = $this->pembeli('suspended');
        [, $biasa]    = $this->pembeli();

        foreach (array_merge($pembeli, $seller) as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
        }

        foreach ($seller as [$method, $uri]) {
            $this->kirim($method, $uri, [], $biasa)->assertStatus(403);
        }
    }

    public function test_pengajuan_komplain_menolak_masukan_tidak_valid(): void
    {
        [, $token, $o] = $this->pesananSampai();

        $dasar = ['reason_code' => 'damaged', 'description' => 'Sepatu sobek pada bagian samping'];

        $kasus = [
            [[], ['reason_code', 'description']],
            [['reason_code' => 'rusak berat'] + $dasar, 'reason_code'],
            [['description' => 'pendek'] + $dasar, 'description'],
            [['description' => str_repeat('a', 1001)] + $dasar, 'description'],
            [$dasar + ['requested_amount' => 0], 'requested_amount'],
            [$dasar + ['requested_amount' => 'abc'], 'requested_amount'],
            [$dasar + ['photos' => $this->fotoUji(6)], 'photos'],
            [$dasar + ['photos' => [UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf')]], 'photos.0'],
            [$dasar + ['photos' => [UploadedFile::fake()->create('gambar.svg', 10, 'image/svg+xml')]], 'photos.0'],
        ];

        foreach ($kasus as [$data, $kolom]) {
            Cache::flush();

            $this->kirimForm("/api/v1/orders/{$o->uuid}/dispute", $data, $token)
                ->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors($kolom);
        }

        $this->assertSame(0, Dispute::count());
    }

    public function test_pembeli_mengajukan_komplain_dengan_foto_yang_diproses_ulang(): void
    {
        [$user, $token, $o] = $this->pesananSampai();
        $user->forceFill(['name' => 'Budi Santoso'])->save();

        $res = $this->ajukan($o, $token, ['requested_amount' => 100000], [
            UploadedFile::fake()->image('a.jpg', 800, 600),
            UploadedFile::fake()->image('besar.png', 3000, 2000),
        ])
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Komplain berhasil diajukan.'])
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.reason_code', 'damaged')
            ->assertJsonPath('data.requested_amount', 100000)
            ->assertJsonPath('data.approved_amount', null)
            ->assertJsonPath('data.order.number', $o->number)
            ->assertJsonPath('data.order.grand_total', 210000)
            ->assertJsonPath('data.store.name', $o->store->name)
            ->assertJsonPath('data.seller_response', null)
            ->assertJsonPath('data.can_respond', false)
            ->assertJsonPath('data.refunds', [])
            ->assertJsonCount(2, 'data.evidences')
            ->assertJsonPath('data.evidences.0.by', 'buyer')
            ->assertJsonMissingPath('data.bearer')
            ->assertJsonMissingPath('data.shortfall')
            ->assertJsonMissingPath('data.buyer');

        $this->assertStringNotContainsString($user->email, $res->getContent());

        $d = Dispute::first();

        $this->assertSame(1, Dispute::count());
        $this->assertSame($d->uuid, $res->json('data.id'));
        $this->assertSame([$user->id, $o->store_id, 'complaint', 'open'], [(int) $d->user_id, (int) $d->store_id, $d->type, $d->status]);
        $this->assertSame(2, DisputeEvidence::where('dispute_id', $d->id)->count());

        // Foto disimpan sebagai JPEG baru dan dikecilkan.
        foreach (DisputeEvidence::where('dispute_id', $d->id)->get() as $e) {
            $this->assertTrue(Storage::disk('public')->exists($e->path));
            $this->assertSame('image/jpeg', $e->mime_type);

            $info = getimagesizefromstring(Storage::disk('public')->get($e->path));

            $this->assertSame(IMAGETYPE_JPEG, $info[2]);
            $this->assertLessThanOrEqual(1600, max($info[0], $info[1]));
        }

        // Tanpa foto dan tanpa jumlah yang diminta juga boleh.
        [$u2, $t2, $o2] = $this->pesananSampai();

        $this->ajukan($o2, $t2)->assertStatus(201)->assertJsonCount(0, 'data.evidences')->assertJsonPath('data.requested_amount', null);
    }

    public function test_komplain_ditolak_untuk_pesanan_belum_sampai_lewat_batas_ganda_atau_milik_orang_lain(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();

        // Dibayar tetapi belum sampai.
        $belum = $this->lunas($this->checkout($user));

        $this->ajukan($belum, $token)->assertStatus(422)->assertJsonPath('errors.order.0', DisputeService::PESAN_BELUM_SAMPAI);

        // Sampai 8 hari lalu: lewat batas tujuh hari.
        $lama = $this->sampaiUntuk($user);
        $lama->forceFill(['delivered_at' => now()->subDays(8)])->save();

        $this->ajukan($lama, $token)->assertStatus(422)->assertJsonPath('errors.order.0', DisputeService::PESAN_LEWAT_BATAS);

        // Sampai 6 hari lalu: masih dalam batas, tetapi jumlah yang diminta tidak boleh melebihi total pesanan.
        $ok = $this->sampaiUntuk($user);
        $ok->forceFill(['delivered_at' => now()->subDays(6)])->save();

        $this->ajukan($ok, $token, ['requested_amount' => 999999])
            ->assertStatus(422)->assertJsonPath('errors.requested_amount.0', 'Jumlah yang diminta melebihi total pesanan.');

        $this->ajukan($ok, $token)->assertStatus(201);
        $this->ajukan($ok, $token)->assertStatus(422)->assertJsonPath('errors.order.0', DisputeService::PESAN_SUDAH_ADA);

        // Pesanan orang lain dan yang tidak ada: 404.
        $this->ajukan($ok, $tokenLain)->assertStatus(404);
        $this->kirimForm('/api/v1/orders/' . $this->uuidAsing() . '/dispute', ['reason_code' => 'damaged', 'description' => 'Sepatu sobek pada bagian samping'], $token)->assertStatus(404);

        // Pesanan yang sudah dikonfirmasi pembeli masih boleh dikomplain dalam batas waktu.
        $selesai = $this->sampaiUntuk($user);
        $selesai->transitionTo(Order::STATUS_COMPLETED, Order::ACTOR_BUYER);

        $this->ajukan($selesai->fresh(), $token)->assertStatus(201);

        $this->assertSame(2, Dispute::count());
    }

    public function test_pembeli_melihat_daftar_dan_detail_komplaknya_sendiri(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();

        $o1 = $this->sampaiUntuk($user);
        $o2 = $this->sampaiUntuk($user);

        $id1 = $this->ajukan($o1, $token)->assertStatus(201)->json('data.id');
        $id2 = $this->ajukan($o2, $token, ['reason_code' => 'wrong_item'])->assertStatus(201)->json('data.id');

        $res = $this->kirim('GET', '/api/v1/disputes', [], $token)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$id2, $id1], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame([2, 'wrong_item'], [$res->json('meta.total'), $res->json('data.0.reason_code')]);

        $this->assertCount(2, $this->kirim('GET', '/api/v1/disputes?status=open', [], $token)->json('data'));
        $this->assertCount(0, $this->kirim('GET', '/api/v1/disputes?status=approved', [], $token)->json('data'));
        $this->assertSame([$id1], collect($this->kirim('GET', '/api/v1/disputes?per_page=1&page=2', [], $token)->json('data'))->pluck('id')->all());
        $this->assertSame([$id1], collect($this->kirim('GET', '/api/v1/disputes?q=' . strtolower(substr($o1->number, -7)), [], $token)->json('data'))->pluck('id')->all());

        foreach (['?status=abc', '?per_page=100', '?per_page=0', '?page=0'] as $salah) {
            $this->kirim('GET', "/api/v1/disputes{$salah}", [], $token)->assertStatus(422);
        }

        $this->kirim('GET', "/api/v1/disputes/{$id1}", [], $token)
            ->assertOk()
            ->assertJsonPath('data.id', $id1)
            ->assertJsonPath('data.order.number', $o1->number)
            ->assertJsonPath('data.store.name', $o1->store->name)
            ->assertJsonPath('data.seller_response', null)
            ->assertJsonPath('data.admin_note', null);

        // Pembeli lain tidak melihat dan tidak bisa membuka.
        $this->assertSame([], $this->kirim('GET', '/api/v1/disputes', [], $tokenLain)->assertOk()->json('data'));
        $this->kirim('GET', "/api/v1/disputes/{$id1}", [], $tokenLain)->assertStatus(404);
        $this->kirim('GET', '/api/v1/disputes/' . $this->uuidAsing(), [], $token)->assertStatus(404);
        $this->kirim('GET', '/api/v1/disputes/abc', [], $token)->assertStatus(404);
    }

    public function test_seller_melihat_komplain_tokonya_dan_menanggapi_satu_kali(): void
    {
        [$user, $token, $o] = $this->pesananSampai();
        $user->forceFill(['name' => 'Budi Santoso'])->save();

        [, $tokenLain, $oLain] = $this->pesananSampai();

        $tokenSeller = $this->penjual($o);
        $tokenLainS  = $this->penjual($oLain);

        $id = $this->ajukan($o, $token, [], $this->fotoUji(1))->assertStatus(201)->json('data.id');

        // Daftar dan detail seller: nama pembeli tersamar, tanpa data pribadi.
        $res = $this->kirim('GET', '/api/v1/seller/disputes', [], $tokenSeller)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$id], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame('B*** S***', $res->json('data.0.buyer.name'));

        $this->kirim('GET', "/api/v1/seller/disputes/{$id}", [], $tokenSeller)
            ->assertOk()
            ->assertJsonPath('data.can_respond', true)
            ->assertJsonPath('data.seller_charged', 0)
            ->assertJsonPath('data.order.number', $o->number)
            ->assertJsonMissingPath('data.bearer');

        $this->assertStringNotContainsString($user->email, $res->getContent());

        // Seller lain tidak melihat dan tidak bisa menyentuh.
        $this->assertSame([], $this->kirim('GET', '/api/v1/seller/disputes', [], $tokenLainS)->assertOk()->json('data'));
        $this->kirim('GET', "/api/v1/seller/disputes/{$id}", [], $tokenLainS)->assertStatus(404);
        $this->kirimForm("/api/v1/seller/disputes/{$id}/respond", ['decision' => 'agree', 'response' => 'Kami setuju dengan komplain'], $tokenLainS)->assertStatus(404);

        // Masukan tidak valid.
        foreach ([[[], ['decision', 'response']], [['decision' => 'maybe', 'response' => 'Penjelasan yang cukup'], 'decision'], [['decision' => 'agree', 'response' => 'abc'], 'response']] as [$data, $kolom]) {
            $this->kirimForm("/api/v1/seller/disputes/{$id}/respond", $data, $tokenSeller)->assertStatus(422)->assertJsonValidationErrors($kolom);
        }

        // Menanggapi dengan foto.
        $this->kirimForm("/api/v1/seller/disputes/{$id}/respond", [
            'decision' => 'reject',
            'response' => 'Barang sudah dicek sebelum dikirim dalam kondisi baik',
            'photos'   => $this->fotoUji(1),
        ], $tokenSeller)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Tanggapan terkirim.'])
            ->assertJsonPath('data.seller_response.decision', 'reject')
            ->assertJsonPath('data.can_respond', false)
            ->assertJsonPath('data.seller_respond_until', null)
            ->assertJsonCount(2, 'data.evidences');

        $this->assertSame(['buyer', 'seller'], DisputeEvidence::orderBy('id')->pluck('note')->all());

        // Hanya satu tanggapan.
        $this->kirimForm("/api/v1/seller/disputes/{$id}/respond", ['decision' => 'agree', 'response' => 'Tanggapan yang kedua'], $tokenSeller)
            ->assertStatus(422)->assertJsonPath('errors.response.0', DisputeService::PESAN_SUDAH_MENANGGAPI);

        // Pembeli melihat tanggapan seller.
        $this->kirim('GET', "/api/v1/disputes/{$id}", [], $token)
            ->assertOk()
            ->assertJsonPath('data.seller_response.decision', 'reject')
            ->assertJsonPath('data.seller_response.text', 'Barang sudah dicek sebelum dikirim dalam kondisi baik');
    }
}
