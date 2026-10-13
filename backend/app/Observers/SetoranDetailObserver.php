<?php

namespace App\Observers;

use App\Models\SetoranDetail;

/**
 * Satu-satunya tempat entries_updated_at boleh disentuh. SENGAJA
 * TIDAK boleh dibump oleh SnapshotService (refresh snapshot) ATAU
 * simpanDraft()/submit() (dua-duanya cuma ubah kolom status, bukan
 * isi entries) -- cuma 3 event di sini yang boleh nyentuh kolom ini.
 */
class SetoranDetailObserver
{
    public function created(SetoranDetail $entry): void
    {
        $entry->setoranHarian->touch('entries_updated_at');
    }

    public function updated(SetoranDetail $entry): void
    {
        $entry->setoranHarian->touch('entries_updated_at');
    }

    public function deleted(SetoranDetail $entry): void
    {
        $entry->setoranHarian->touch('entries_updated_at');
    }
}