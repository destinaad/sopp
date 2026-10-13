<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\SetoranEntryController;
use App\Services\SnapshotService;
use Illuminate\Database\QueryException;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Test terisolasi buat method adalahRaceConditionUniqueViolation() --
 * gak perlu beneran manipulasi race-condition di DB asli, cukup
 * konstruksi QueryException dengan kode+pesan yang mirip skenario
 * nyata (MySQL & SQLite), termasuk kasus FK violation yang kebetulan
 * numpang SQLSTATE 23000 yang sama.
 */
class SetoranEntryRaceConditionDetectionTest extends TestCase
{
    private function panggil(QueryException $e): bool
    {
        // Controller sekarang butuh SnapshotService di constructor --
        // method yang ditest di sini sama sekali gak menyentuhnya, jadi
        // mock kosong cukup, gak perlu di-setup expectation apapun.
        $controller = new SetoranEntryController($this->createMock(SnapshotService::class));

        $method = new ReflectionMethod($controller, 'adalahRaceConditionUniqueViolation');
        $method->setAccessible(true);

        return $method->invoke($controller, $e);
    }

    /**
     * $kode sengaja bertipe string (bukan int) -- SQLSTATE asli
     * (mis. 'HY000') non-numeric, gak bisa dipaksa jadi int. PHP tetap
     * otomatis nyamain '23000' (string) == 23000 (int) di sisi
     * controller karena perbandingannya loose (!=), jadi tipe string
     * di sini tetap merepresentasikan kondisi nyata dengan akurat.
     */
    private function buatQueryException(string $pesanAsli, string $kode = '23000'): QueryException
    {
        $previous = new PDOException($pesanAsli, 0);
        // PDOException::$code diisi manual -- constructor bawaan PHP
        // memaksa $code jadi int, padahal SQLSTATE asli (mis. 'HY000')
        // non-numeric. Set langsung ke property lewat reflection biar
        // representasinya presisi ke kondisi nyata.
        $refCode = new \ReflectionProperty($previous, 'code');
        $refCode->setAccessible(true);
        $refCode->setValue($previous, $kode);

        return new QueryException('mysql', 'insert into `setoran_harian` ...', [], $previous);
    }

    public function test_duplicate_entry_mysql_dianggap_race_condition(): void
    {
        $e = $this->buatQueryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-1-2026-08-14' for key 'unique_slot_guard'"
        );

        $this->assertTrue($this->panggil($e));
    }

    public function test_unique_constraint_failed_sqlite_dianggap_race_condition(): void
    {
        $e = $this->buatQueryException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: setoran_harian.unique_slot_guard'
        );

        $this->assertTrue($this->panggil($e));
    }

    /** Ini kasus yang mau ditutup fix -- FK violation JANGAN ketuker dianggap race-condition walau kode error-nya sama-sama 23000. */
    public function test_foreign_key_violation_mysql_tidak_dianggap_race_condition(): void
    {
        $e = $this->buatQueryException(
            'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`sopp`.`setoran_harian`, CONSTRAINT `setoran_harian_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`))'
        );

        $this->assertFalse($this->panggil($e));
    }

    public function test_foreign_key_violation_sqlite_tidak_dianggap_race_condition(): void
    {
        $e = $this->buatQueryException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed'
        );

        $this->assertFalse($this->panggil($e));
    }

    public function test_error_lain_yang_gak_relevan_tidak_dianggap_race_condition(): void
    {
        $e = $this->buatQueryException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded', 'HY000');

        $this->assertFalse($this->panggil($e));
    }
}