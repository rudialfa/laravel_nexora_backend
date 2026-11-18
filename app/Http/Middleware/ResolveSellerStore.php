<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mencari toko milik pengguna yang login dan menyimpannya di $request->attributes['store'].
 * Seller hanya pernah bisa menyentuh tokonya sendiri karena tidak ada ID toko di alamat.
 *
 * Pemakaian di route:
 *   seller.store            : boleh melihat walau toko ditangguhkan atau ditutup
 *   seller.store:editable   : hanya boleh jika status toko "active" (untuk semua perubahan)
 */
class ResolveSellerStore
{
    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        $user  = $request->user();
        $store = $user ? Store::query()->where('owner_id', $user->id)->first() : null;

        if ($store === null) {
            return ApiResponse::error('Toko Anda tidak ditemukan.', 404);
        }

        if (in_array('editable', $flags, true) && $store->status !== 'active') {
            return ApiResponse::error(
                'Toko Anda sedang tidak aktif (ditangguhkan atau ditutup), sehingga pengaturannya tidak dapat diubah. Hubungi dukungan Nexora.',
                403
            );
        }

        $request->attributes->set('store', $store);

        return $next($request);
    }
}
