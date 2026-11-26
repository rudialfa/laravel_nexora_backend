<?php

namespace Tests\Concerns;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\ReviewImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Bantuan bersama untuk tes ulasan dan tanya jawab. */
trait SiapkanUlasan
{
    use SiapkanKeuangan;

    protected function aturUlasan(): void
    {
        $this->aturPengiriman();
        Storage::fake('public');
    }

    /** Pesanan yang baru saja selesai milik pembeli. */
    protected function selesai(User $user): Order
    {
        $o = $this->lunas($this->checkout($user));
        $this->selesaikan($o);

        return $o->fresh();
    }

    protected function varianUuid(Order $o): string
    {
        return ProductVariant::find(OrderItem::where('order_id', $o->id)->value('variant_id'))->uuid;
    }

    protected function produkDari(Order $o): Product
    {
        return Product::find(OrderItem::where('order_id', $o->id)->value('product_id'));
    }

    /** Permintaan multipart (untuk unggah foto). */
    protected function kirimBerkas(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->post($uri, $data, ['Accept' => 'application/json']);
    }

    /** @return array<int, UploadedFile> */
    protected function foto(int $jumlah = 1): array
    {
        $daftar = [];

        for ($i = 1; $i <= $jumlah; $i++) {
            $daftar[] = UploadedFile::fake()->image("foto{$i}.jpg", 800, 600);
        }

        return $daftar;
    }

    /**
     * Ulasan produk langsung di database (tanpa API). Setiap ulasan butuh item pesanan sendiri,
     * jadi satu item baru disalin dari pesanan sumber. Opsi: komentar, anonim, status, foto, balasan.
     */
    protected function ulasanLangsung(Order $o, User $user, int $rating, array $opsi = []): ProductReview
    {
        $asal = OrderItem::where('order_id', $o->id)->first();
        $item = $asal->replicate();
        $item->save();

        $r = new ProductReview(['rating' => $rating, 'comment' => $opsi['komentar'] ?? "Ulasan {$rating}", 'is_anonymous' => $opsi['anonim'] ?? false]);
        $r->forceFill([
            'order_item_id' => $item->id,
            'user_id'       => $user->id,
            'product_id'    => $asal->product_id,
            'status'        => $opsi['status'] ?? 'published',
        ])->save();

        if (! empty($opsi['foto'])) {
            ReviewImage::create(['review_id' => $r->id, 'path' => "reviews/{$r->uuid}/1.jpg", 'sort_order' => 0]);
        }

        if (isset($opsi['balasan'])) {
            $r->forceFill(['seller_reply' => $opsi['balasan'], 'seller_replied_at' => now()])->save();
        }

        return $r->fresh();
    }

    /** Pertanyaan langsung di database. Opsi: status, jawaban (dijawab oleh pemilik toko produk). */
    protected function tanyaLangsung(Product $produk, User $user, string $teks, array $opsi = []): ProductQuestion
    {
        $q = new ProductQuestion(['question' => $teks]);
        $q->forceFill(['product_id' => $produk->id, 'user_id' => $user->id, 'status' => $opsi['status'] ?? 'published'])->save();

        if (isset($opsi['jawaban'])) {
            $q->answer($produk->store->owner, $opsi['jawaban']);
        }

        return $q->fresh();
    }
}
