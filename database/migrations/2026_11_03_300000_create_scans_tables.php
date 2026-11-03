<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A collector's scans: photos of their cars, what the recognizer found in
 * each, and what the collector said about it.
 *
 * Separate from submissions on purpose. A submission is an admin proposing
 * catalog records; a scan is a collector adding existing cars to their own
 * collection, and it never creates or changes a catalog row.
 *
 * Each confirmed detection is also a labelled example — this crop is that
 * variation — which is the training data a learned recognizer needs. It is
 * kept only when the collector said so (`consent`); otherwise the crops are
 * deleted once the scan is finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('consent')->default(false);
            // auto | pile | single — what the collector said the photos show.
            $table->string('layout', 8)->default('auto');
            $table->unsignedSmallInteger('photos')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('scan_detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('photo');      // which photo of the scan, from 1
            $table->unsignedSmallInteger('number');     // which car in that photo, from 1
            $table->string('disk')->nullable();
            $table->string('crop_path')->nullable();     // null once deleted
            $table->jsonb('box');
            $table->string('colour_family', 16)->nullable();
            $table->jsonb('reading');                    // text, brand, year, codes
            $table->jsonb('match')->nullable();          // the recognizer's answer
            $table->boolean('confident')->default(false);
            $table->foreignId('casting_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('confidence', 4, 3)->default(0);
            // pending | added | rejected
            $table->string('decision', 16)->default('pending');
            $table->foreignId('variation_id')->nullable()->constrained()->nullOnDelete();
            // True when the collector named the car themselves rather than
            // picking one the recognizer offered — the most valuable labels.
            $table->boolean('labelled_by_hand')->default(false);
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['scan_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_detections');
        Schema::dropIfExists('scans');
    }
};
