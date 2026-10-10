<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceSnapshot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'observed_at' => 'datetime'];
    }

    public function mailRecord(): BelongsTo
    {
        return $this->belongsTo(MailRecord::class);
    }
}
