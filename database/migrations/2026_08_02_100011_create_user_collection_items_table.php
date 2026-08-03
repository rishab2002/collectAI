<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained('variations')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('condition')->nullable();
            $table->date('acquired_at')->nullable();
            $table->timestamps();

            // One row per (user, variation); multiple copies are a quantity bump,
            // not separate rows.
            $table->unique(['user_id', 'variation_id']);
            $table->index('variation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_collection_items');
    }
};
