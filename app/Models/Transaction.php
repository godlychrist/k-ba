<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'datetime', 'date_from_email' => 'boolean'];
    }

    public function mailRecord(): BelongsTo
    {
        return $this->belongsTo(MailRecord::class);
    }
}
