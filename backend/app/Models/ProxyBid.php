<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProxyBid extends Model
{
    protected $fillable = ['auction_id', 'user_id', 'maximum_amount'];

    protected function casts(): array
    {
        return ['maximum_amount' => 'decimal:2'];
    }
}
