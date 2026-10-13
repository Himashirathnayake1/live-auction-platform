<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuctionEvent extends Model
{
    protected $fillable = ['auction_id', 'user_id', 'type', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
