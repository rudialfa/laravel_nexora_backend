<?php

use App\Http\Controllers\Api\Admin\BrandController as AdminBrandController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\StoreApplicationController as AdminStoreApplicationController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicStoreController;
use App\Http\Controllers\Api\Seller\BankAccountController as SellerBankAccountController;
use App\Http\Controllers\Api\Seller\ProductController as SellerProductController;
use App\Http\Controllers\Api\Seller\ProductVariantController as SellerProductVariantController;
use App\Http\Controllers\Api\Seller\StoreController as SellerStoreController;
use App\Http\Controllers\Api\StoreApplicationController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes Nexora
|--------------------------------------------------------------------------
| Semua route di file ini otomatis berawalan /api, lalu kita tambah /v1.
| Contoh alamat lengkap: http://127.0.0.1:8000/api/v1/ping
*/

Route::prefix('v1')->group(function () {

    // Route publik: untuk mengecek API hidup.
    Route::get('/ping', function () {
        return ApiResponse::success([
            'app'  => config('app.name'),
            'time' => now()->toIso8601String(),
        ], 'Nexora API berjalan');
    });

    // ---- Katalog publik (tanpa login), maksimal 120 permintaan per menit per alamat IP ----
    Route::middleware('throttle:120,1,catalog')->group(function () {
        Route::get('/home', [HomeController::class, 'index']);

        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/categories/{slug}', [CategoryController::class, 'show'])->where('slug', '[a-z0-9\-]+');
        Route::get('/brands', [BrandController::class, 'index']);

        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{slug}', [ProductController::class, 'show'])->where('slug', '[a-z0-9\-]+');
        Route::get('/products/{slug}/related', [ProductController::class, 'related'])->where('slug', '[a-z0-9\-]+');

        Route::get('/stores/{slug}', [PublicStoreController::class, 'show'])->where('slug', '[a-z0-9\-]+');
    });

    // ---- Autentikasi ----
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

        // Lupa dan reset password (publik, dibatasi).
        Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:password');
        Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:password');

        // Verifikasi email. Tautan di email bertanda tangan digital (middleware "signed").
        Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->whereUuid('id')
            ->where('hash', '[a-f0-9]{40}')
            ->middleware('signed')
            ->name('verification.verify');

        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware(['auth:sanctum', 'active', 'throttle:verification']);

        // Logout boleh dilakukan siapa pun yang tokennya valid, termasuk akun yang sedang diblokir.
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    // ---- Route yang wajib login DAN akun aktif ----
    Route::middleware(['auth:sanctum', 'active'])->group(function () {

        // Profil
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [ProfileController::class, 'update']);
        Route::put('/me/password', [ProfileController::class, 'password'])
            ->middleware('throttle:5,1,password-change');
        Route::post('/me/avatar', [ProfileController::class, 'avatar']);
        Route::delete('/me/avatar', [ProfileController::class, 'destroyAvatar']);

        // Alamat pengiriman (hanya milik pengguna yang login)
        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);
        Route::get('/addresses/{address}', [AddressController::class, 'show'])->whereUuid('address');
        Route::patch('/addresses/{address}', [AddressController::class, 'update'])->whereUuid('address');
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->whereUuid('address');
        Route::post('/addresses/{address}/default', [AddressController::class, 'makeDefault'])->whereUuid('address');

        // Cek ongkos kirim (Biteship): maksimal 20 permintaan per menit per pengguna
        Route::prefix('shipping')->middleware('throttle:20,1,shipping-rates')->group(function () {
            Route::post('/rates', [ShippingController::class, 'rates']);
        });

        // Pencarian area pengiriman (Biteship): maksimal 30 permintaan per menit per pengguna
        Route::prefix('shipping')->middleware('throttle:30,1,shipping')->group(function () {
            Route::get('/areas', [ShippingController::class, 'areas']);
        });

        // Keranjang belanja: hanya milik pengguna yang login, maksimal 120 permintaan per menit
        Route::prefix('cart')->middleware('throttle:120,1,cart')->group(function () {
            Route::get('/', [CartController::class, 'index']);
            Route::delete('/', [CartController::class, 'clear']);
            Route::post('/items', [CartController::class, 'store']);
            Route::patch('/items/{variant}', [CartController::class, 'update'])->whereUuid('variant');
            Route::delete('/items/{variant}', [CartController::class, 'destroy'])->whereUuid('variant');
            Route::post('/select', [CartController::class, 'select']);
        });

        // Pengajuan toko (pembeli): maksimal 5 permintaan per jam per pengguna
        Route::get('/store-applications', [StoreApplicationController::class, 'index']);
        Route::post('/store-applications', [StoreApplicationController::class, 'store'])
            ->middleware('throttle:5,60,store-application');

        // ---- Khusus seller: hanya menyentuh toko milik sendiri ----
        // seller.store          : boleh melihat walau toko ditangguhkan/ditutup
        // seller.store:editable : hanya jika toko berstatus aktif (semua perubahan)
        Route::prefix('seller/store')->middleware('role:seller')->group(function () {
            Route::get('/', [SellerStoreController::class, 'show'])->middleware('seller.store');
            Route::patch('/', [SellerStoreController::class, 'update'])->middleware('seller.store:editable');

            Route::post('/images/{type}', [SellerStoreController::class, 'uploadImage'])
                ->where('type', 'logo|banner')
                ->middleware(['seller.store:editable', 'throttle:10,1,store-image']);
            Route::delete('/images/{type}', [SellerStoreController::class, 'removeImage'])
                ->where('type', 'logo|banner')
                ->middleware('seller.store:editable');

            Route::put('/operating-hours', [SellerStoreController::class, 'updateHours'])->middleware('seller.store:editable');
            Route::put('/couriers', [SellerStoreController::class, 'updateCouriers'])->middleware('seller.store:editable');

            Route::get('/bank-accounts', [SellerBankAccountController::class, 'index'])->middleware('seller.store');
            Route::post('/bank-accounts', [SellerBankAccountController::class, 'store'])->middleware('seller.store:editable');
            Route::delete('/bank-accounts/{account}', [SellerBankAccountController::class, 'destroy'])
                ->whereUuid('account')->middleware('seller.store:editable');
            Route::post('/bank-accounts/{account}/default', [SellerBankAccountController::class, 'makeDefault'])
                ->whereUuid('account')->middleware('seller.store:editable');
        });

        // ---- Khusus seller: produk milik tokonya sendiri ----
        Route::prefix('seller/products')->middleware('role:seller')->group(function () {
            Route::get('/', [SellerProductController::class, 'index'])->middleware('seller.store');
            Route::get('/{product}', [SellerProductController::class, 'show'])
                ->whereUuid('product')->middleware('seller.store');

            // Semua perubahan: toko harus aktif, maksimal 60 permintaan per menit per pengguna
            Route::middleware(['seller.store:editable', 'throttle:60,1,seller-catalog'])->group(function () {
                Route::post('/', [SellerProductController::class, 'store']);
                Route::patch('/{product}', [SellerProductController::class, 'update'])->whereUuid('product');
                Route::delete('/{product}', [SellerProductController::class, 'destroy'])->whereUuid('product');
                Route::post('/{product}/publish', [SellerProductController::class, 'publish'])->whereUuid('product');
                Route::post('/{product}/unpublish', [SellerProductController::class, 'unpublish'])->whereUuid('product');

                Route::post('/{product}/images', [SellerProductController::class, 'uploadImage'])
                    ->whereUuid('product')
                    ->middleware('throttle:20,1,product-image');
                Route::delete('/{product}/images/{image}', [SellerProductController::class, 'destroyImage'])
                    ->whereUuid(['product', 'image']);

                Route::post('/{product}/variants', [SellerProductVariantController::class, 'store'])
                    ->whereUuid('product');
                Route::patch('/{product}/variants/{variant}', [SellerProductVariantController::class, 'update'])
                    ->whereUuid(['product', 'variant']);
                Route::delete('/{product}/variants/{variant}', [SellerProductVariantController::class, 'destroy'])
                    ->whereUuid(['product', 'variant']);
                Route::post('/{product}/variants/{variant}/stock', [SellerProductVariantController::class, 'adjustStock'])
                    ->whereUuid(['product', 'variant']);
            });
        });

        // ---- Khusus admin ----
        Route::prefix('admin')->middleware('role:admin|super_admin')->group(function () {

            // Pengajuan toko
            Route::get('/store-applications', [AdminStoreApplicationController::class, 'index']);
            Route::get('/store-applications/{application}', [AdminStoreApplicationController::class, 'show'])
                ->whereUuid('application');
            Route::get('/store-applications/{application}/photos/{type}', [AdminStoreApplicationController::class, 'photo'])
                ->whereUuid('application')
                ->where('type', 'id|selfie');
            Route::post('/store-applications/{application}/approve', [AdminStoreApplicationController::class, 'approve'])
                ->whereUuid('application');
            Route::post('/store-applications/{application}/reject', [AdminStoreApplicationController::class, 'reject'])
                ->whereUuid('application');

            // Kategori
            Route::get('/categories', [AdminCategoryController::class, 'index']);
            Route::post('/categories', [AdminCategoryController::class, 'store']);
            Route::patch('/categories/{category}', [AdminCategoryController::class, 'update'])->whereUuid('category');
            Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->whereUuid('category');
            Route::post('/categories/{category}/images/{type}', [AdminCategoryController::class, 'uploadImage'])
                ->whereUuid('category')
                ->where('type', 'icon|image')
                ->middleware('throttle:20,1,catalog-image');
            Route::delete('/categories/{category}/images/{type}', [AdminCategoryController::class, 'removeImage'])
                ->whereUuid('category')
                ->where('type', 'icon|image');

            // Brand
            Route::get('/brands', [AdminBrandController::class, 'index']);
            Route::post('/brands', [AdminBrandController::class, 'store']);
            Route::patch('/brands/{brand}', [AdminBrandController::class, 'update'])->whereUuid('brand');
            Route::delete('/brands/{brand}', [AdminBrandController::class, 'destroy'])->whereUuid('brand');
            Route::post('/brands/{brand}/logo', [AdminBrandController::class, 'uploadLogo'])
                ->whereUuid('brand')
                ->middleware('throttle:20,1,catalog-image');
            Route::delete('/brands/{brand}/logo', [AdminBrandController::class, 'removeLogo'])->whereUuid('brand');
        });
    });

    // ---- Route khusus pengujian, tidak aktif di production ----
    if (! app()->environment('production')) {

        Route::post('/uji-validasi', function (Request $request) {
            $request->validate([
                'name' => ['required', 'string', 'min:3'],
            ]);

            return ApiResponse::success(null, 'Validasi lolos.');
        });

        Route::get('/uji-error', function () {
            throw new RuntimeException('Ini error uji coba.');
        });
    }
});
