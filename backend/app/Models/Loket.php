<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Loket extends Model
{
    protected $table = 'lokets';
    protected $primaryKey = 'loket_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'loket_id',
        'nama_loket',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}