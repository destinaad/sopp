<?php

namespace Tests\Unit;

use App\Exceptions\BridgeException;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\BridgeService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BridgeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function buatService(array $antrianRespons, ?array &$historiRequest = null): BridgeService
    {
        $mockHandler = new MockHandler($antrianRespons);
        $stack = HandlerStack::create($mockHandler);

        if ($historiRequest !== null) {
            $stack->push(Middleware::history($historiRequest));
        }

        $client = new Client(['handler' => $stack]);

        return new BridgeService($client, 'https://bridge.test/bridge.php', 'secret-key');
    }

    public function test_fetch_sukses_mengembalikan_data_dan_grand_total(): void
    {
        $service = $this->buatService([
            new Response(200, [], json_encode([
                'status' => 'ok',
                'data' => [['vendor_id' => 1, 'vendor_name' => 'Vendor A', 'total' => 100000]],
                'grand_total' => 100000,
            ])),
        ]);

        $hasil = $service->fetch('2026-08-14', '2026-08-14', 1);

        $this->assertSame(100000, $hasil['grand_total']);
        $this->assertCount(1, $hasil['data']);
    }

    public function test_status_error_dari_bridge_melempar_BridgeException(): void
    {
        $service = $this->buatService([
            new Response(200, [], json_encode(['status' => 'error', 'message' => 'Key tidak valid'])),
        ]);

        $this->expectException(BridgeException::class);
        $this->expectExceptionMessage('Key tidak valid');
        $service->fetch('2026-08-14', '2026-08-14', 1);
    }

    public function test_koneksi_gagal_melempar_BridgeException(): void
    {
        $mockHandler = new MockHandler([
            new ConnectException('Connection timed out', new Psr7Request('GET', 'test')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $service = new BridgeService($client, 'https://bridge.test/bridge.php', 'secret-key');

        $this->expectException(BridgeException::class);
        $service->fetch('2026-08-14', '2026-08-14', 1);
    }

    public function test_response_bukan_json_valid_melempar_BridgeException(): void
    {
        $service = $this->buatService([
            new Response(200, [], 'bukan json sama sekali <<<'),
        ]);

        $this->expectException(BridgeException::class);
        $service->fetch('2026-08-14', '2026-08-14', 1);
    }

    public function test_rentang_lebih_31_hari_dipecah_jadi_beberapa_request(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode(['status' => 'ok', 'data' => [], 'grand_total' => 100])),
            new Response(200, [], json_encode(['status' => 'ok', 'data' => [], 'grand_total' => 200])),
            new Response(200, [], json_encode(['status' => 'ok', 'data' => [], 'grand_total' => 50])),
        ]);
        $historiRequest = [];
        $stack = HandlerStack::create($mockHandler);
        $stack->push(Middleware::history($historiRequest));
        $client = new Client(['handler' => $stack]);
        $service = new BridgeService($client, 'https://bridge.test/bridge.php', 'secret-key');

        // 65 hari -> harus jadi 3 chunk: 31 + 31 + 3
        $hasil = $service->fetch('2026-01-01', '2026-03-06', 1);

        $this->assertSame(350, $hasil['grand_total']); // 100+200+50 tergabung
        $this->assertCount(3, $historiRequest);

        parse_str(parse_url((string) $historiRequest[0]['request']->getUri(), PHP_URL_QUERY), $q0);
        $this->assertSame('2026-01-01', $q0['tanggal_mulai']);
        $this->assertSame('2026-01-31', $q0['tanggal_selesai']); // pas 31 hari

        parse_str(parse_url((string) $historiRequest[2]['request']->getUri(), PHP_URL_QUERY), $q2);
        $this->assertSame('2026-03-06', $q2['tanggal_selesai']); // sisa chunk terakhir
    }

    public function test_vendor_baru_dicatat_ke_activity_log(): void
    {
        $actor = User::factory()->create();
        $service = $this->buatService([
            new Response(200, [], json_encode([
                'status' => 'ok',
                'data' => [['vendor_id' => 99, 'vendor_name' => 'Vendor Baru', 'total' => 1000]],
                'grand_total' => 1000,
            ])),
        ]);

        $service->fetch('2026-08-14', '2026-08-14', 1, $actor);

        $this->assertDatabaseHas('activity_logs', [
            'jenis_aksi' => 'vendor_baru_terdeteksi',
            'user_id' => $actor->id,
        ]);
    }

    public function test_vendor_yang_sudah_tercatat_tidak_dicatat_ulang(): void
    {
        $actor = User::factory()->create();
        ActivityLog::catat(actor: $actor, jenisAksi: 'vendor_baru_terdeteksi', detailPerubahan: ['vendor_id' => 99]);

        $service = $this->buatService([
            new Response(200, [], json_encode([
                'status' => 'ok',
                'data' => [['vendor_id' => 99, 'vendor_name' => 'Vendor Lama', 'total' => 1000]],
                'grand_total' => 1000,
            ])),
        ]);

        $service->fetch('2026-08-14', '2026-08-14', 1, $actor);

        $this->assertDatabaseCount('activity_logs', 1); // gak nambah baris baru
    }

    public function test_actor_null_tidak_mencatat_apapun(): void
    {
        $service = $this->buatService([
            new Response(200, [], json_encode([
                'status' => 'ok',
                'data' => [['vendor_id' => 99, 'vendor_name' => 'Vendor', 'total' => 1000]],
                'grand_total' => 1000,
            ])),
        ]);

        $service->fetch('2026-08-14', '2026-08-14', 1, null);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * Simulasi nulis activity_logs gagal (tabelnya sengaja diganti nama
     * biar query INSERT/SELECT ke situ error) -- fetch() harus TETAP
     * balikin data yang udah berhasil diambil dari bridge, TIDAK ikut
     * ngelempar exception cuma gara-gara langkah pencatatan FYI ini gagal.
     */
    public function test_gagal_mencatat_vendor_baru_tidak_menggagalkan_fetch(): void
    {
        $actor = User::factory()->create();
        Schema::rename('activity_logs', 'activity_logs_dimatikan_sementara');

        try {
            $service = $this->buatService([
                new Response(200, [], json_encode([
                    'status' => 'ok',
                    'data' => [['vendor_id' => 99, 'vendor_name' => 'Vendor Baru', 'total' => 1000]],
                    'grand_total' => 1000,
                ])),
            ]);

            $hasil = $service->fetch('2026-08-14', '2026-08-14', 1, $actor);

            // Data intinya tetap kebalikin utuh, walau pencatatan log di
            // baliknya gagal total.
            $this->assertSame(1000, $hasil['grand_total']);
            $this->assertCount(1, $hasil['data']);
        } finally {
            Schema::rename('activity_logs_dimatikan_sementara', 'activity_logs');
        }
    }


    public function test_key_dikirim_lewat_header_bukan_query_fetchSatuChunk(): void
    {
        $historiRequest = [];
        $service = $this->buatService([
            new Response(200, [], json_encode(['status' => 'ok', 'data' => [], 'grand_total' => 0])),
        ], $historiRequest);

        $service->fetch('2026-08-14', '2026-08-14', 1);

        $request = $historiRequest[0]['request'];

        $this->assertSame('secret-key', $request->getHeaderLine('X-Bridge-Key'));

        parse_str(parse_url((string) $request->getUri(), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('key', $query);
    }

    public function test_key_dikirim_lewat_header_bukan_query_fetchMasterLoket(): void
    {
        $historiRequest = [];
        $service = $this->buatService([
            new Response(200, [], json_encode(['status' => 'ok', 'data' => []])),
        ], $historiRequest);

        $service->fetchMasterLoket();

        $request = $historiRequest[0]['request'];

        $this->assertSame('secret-key', $request->getHeaderLine('X-Bridge-Key'));

        parse_str(parse_url((string) $request->getUri(), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('key', $query);
    }

    public function test_fetchDetailPerUser_kirim_mode_dan_header_yang_benar(): void
    {
        $historiRequest = [];
        $service = $this->buatService([
            new Response(200, [], json_encode([
                'status' => 'ok',
                'data' => [['user_id' => '7609063', 'user_name' => 'Contoh', 'total' => 1000]],
                'grand_total' => 1000,
            ])),
        ], $historiRequest);

        $hasil = $service->fetchDetailPerUser('2026-08-14', '2026-08-14', 1);

        $request = $historiRequest[0]['request'];

        $this->assertSame('secret-key', $request->getHeaderLine('X-Bridge-Key'));

        parse_str(parse_url((string) $request->getUri(), PHP_URL_QUERY), $query);
        $this->assertSame('detail_rekonsiliasi', $query['mode']);
        $this->assertArrayNotHasKey('key', $query);

        $this->assertSame(1000, $hasil['grand_total']);
    }
}
