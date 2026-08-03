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
        Schema::table('users', function (Blueprint $table) {
            // A single boolean rather than a roles/permissions table: right now there
            // is exactly one privileged actor (the catalog owner doing capture and
            // approval) and every other user is a public collector. A roles table
            // would be speculative structure with one row in it. If a second tier
            // ever appears (trusted contributors who can submit but not approve),
            // that's the point to introduce roles — not before.
            $table->boolean('is_admin')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
