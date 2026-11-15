<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Menguji format respons dan penanganan error API.
 * Tes ini tidak menyentuh database.
 */
class ApiResponseTest extends TestCase
{
    public function test_ping_mengembalikan_format_sukses(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['app', 'time']])
            ->assertJson(['success' => true]);
    }

    public function test_route_tidak_ada_mengembalikan_404_json(): void
    {
        $this->getJson('/api/v1/tidak-ada')
            ->assertNotFound()
            ->assertJson(['success' => false]);
    }

    public function test_metode_salah_mengembalikan_405_json(): void
    {
        $this->postJson('/api/v1/ping')
            ->assertStatus(405)
            ->assertJson(['success' => false]);
    }

    public function test_tanpa_token_mengembalikan_401_json(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJson(['success' => false]);
    }

    /**
     * Meniru kasus nyata: permintaan TANPA header Accept: application/json.
     * Menggunakan get() biasa, bukan getJson().
     */
    public function test_tanpa_token_dan_tanpa_header_accept_tetap_401_json(): void
    {
        $this->get('/api/v1/me')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Anda belum login atau token tidak valid.',
            ]);
    }

    public function test_validasi_gagal_mengembalikan_422_dengan_errors(): void
    {
        $this->postJson('/api/v1/uji-validasi', [])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['name']);
    }

    public function test_error_server_mengembalikan_500_json(): void
    {
        $this->getJson('/api/v1/uji-error')
            ->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Terjadi kesalahan pada server.',
            ]);
    }
}
