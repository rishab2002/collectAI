<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a user owns.
 *
 * No unique on (user_id, variation_id): collectors hold duplicates of the same
 * variation in different conditions, and each entry carries its own purchase
 * price and acquisition date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('condition')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->char('purchase_currency', 3)->nullable();
            $table->date('acquired_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'variation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_items');
    }
};
