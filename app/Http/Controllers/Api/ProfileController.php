<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profile) {}

    /** PATCH /api/v1/me */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profile->updateProfile($request->user(), $request->validated(), $request);

        return ApiResponse::success(new UserResource($user), 'Profil berhasil diperbarui.');
    }

    /** PUT /api/v1/me/password */
    public function password(ChangePasswordRequest $request): JsonResponse
    {
        $this->profile->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
            $request
        );

        return ApiResponse::success(null, 'Password berhasil diubah. Perangkat lain telah dikeluarkan.');
    }

    /** POST /api/v1/me/avatar (multipart, field "avatar") */
    public function avatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $this->profile->replaceAvatar($request->user(), $request->file('avatar'), $request);

        return ApiResponse::success(new UserResource($user), 'Foto profil berhasil diperbarui.');
    }

    /** DELETE /api/v1/me/avatar */
    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $this->profile->removeAvatar($request->user(), $request);

        return ApiResponse::success(new UserResource($user), 'Foto profil dihapus.');
    }
}
