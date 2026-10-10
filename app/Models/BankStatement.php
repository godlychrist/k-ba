<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatement extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['summary' => 'encrypted:array', 'extracted_text' => 'encrypted', 'confirmed' => 'boolean', 'closing_date' => 'date:Y-m-d', 'closing_balance' => 'decimal:2'];
    }
}
