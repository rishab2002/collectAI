<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photos a scan was made from, kept so they can be cropped by hand.
 *
 * Until now a scan kept only the crop of each car the recognizer found, and
 * never the photo itself — deliberately, and it is written down as a privacy
 * rule. Keeping the photo is what the owner asked for, and it buys the one
 * thing that fixes the segmenter's misses: the packages it walks past can be
 * cut out by hand and scanned again, as many times as it takes, instead of
 * being lost.
 *
 * The promise around them does not change, and this table is what keeps it.
 * They live on the same private disk as the crops, are served only to the
 * collector who took them, are deleted with the scan, and go the same way as
 * an unconsented crop — when the scan is answered, or after the window at the
 * latest. Nothing here is published, and `/privacy` says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            // Which photo of the scan, from 1 — the same number
            // `scan_detections.photo` carries, so a detection says which
            // photo it came out of.
            $table->unsignedSmallInteger('number');
            $table->string('disk');
            // Null once deleted, exactly as a crop's path is: the row stays so
            // the detections that came from it still say where they came from.
            $table->string('path')->nullable();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();

            $table->unique(['scan_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_photos');
    }
};
