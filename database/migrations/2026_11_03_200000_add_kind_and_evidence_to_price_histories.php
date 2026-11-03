<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a price *is*, not just what it was.
 *
 * Three kinds of number answer "what is this car worth", and they must never be
 * confused on screen: what it actually **sold** for (eBay sold listings, via
 * eBay's Marketplace Insights API), what sellers are currently **asking** (eBay
 * active listings, via the Browse API) and the maker's **msrp** (list price, from
 * the admin's table). `kind` says which; a CHECK keeps it to those three.
 *
 * `sample_size` is how many sales or listings the figure summarises — a median
 * of one sale is a very different claim from a median of twelve — and `url` is
 * where a reader can see the evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_histories', function (Blueprint $table) {
            $table->string('kind')->default('sold')->after('currency');
            $table->unsignedSmallInteger('sample_size')->nullable()->after('kind');
            $table->string('url', 2048)->nullable()->after('source');
        });

        DB::statement("ALTER TABLE price_histories ADD CONSTRAINT price_histories_kind_check CHECK (kind IN ('sold', 'listing', 'msrp'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE price_histories DROP CONSTRAINT IF EXISTS price_histories_kind_check');

        Schema::table('price_histories', function (Blueprint $table) {
            $table->dropColumn(['kind', 'sample_size', 'url']);
        });
    }
};
