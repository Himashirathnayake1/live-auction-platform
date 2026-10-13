<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('maximum_amount', 12, 2)->nullable();
            $table->string('type')->default('manual');
            $table->string('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique(['auction_id', 'idempotency_key']);
            $table->index(['auction_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};
