<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->decimal('starting_price', 12, 2);
            $table->decimal('reserve_price', 12, 2)->nullable();
            $table->decimal('current_price', 12, 2);
            $table->decimal('minimum_bid_increment', 12, 2);
            $table->json('increment_rules')->nullable();
            $table->unsignedInteger('anti_sniping_seconds')->default(120);
            $table->unsignedInteger('extension_seconds')->default(120);
            $table->unsignedInteger('max_extensions')->default(3);
            $table->unsignedInteger('extensions_count')->default(0);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default('draft')->index();
            $table->foreignId('leading_bidder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auctions');
    }
};
