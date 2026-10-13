<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('maximum_amount', 12, 2);
            $table->timestamps();
            $table->unique(['auction_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_bids');
    }
};
