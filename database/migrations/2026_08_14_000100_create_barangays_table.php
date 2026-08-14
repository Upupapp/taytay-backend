<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barangays — the municipality's jurisdiction reference (ADR 0008).
 *
 * Foundation rather than domain: staff data scope, resident addresses, request routing and
 * every statutory report key off a barangay, so it must exist before any module that
 * references one. Taytay has five, and the list changes only by legislation.
 *
 * Personal-data classification: `public`. A barangay is published reference data and
 * describes no person.
 *
 * `psgc_code` is deliberately nullable: the authoritative PSA Philippine Standard
 * Geographic Code dataset has not been loaded (gap G-11), and a guessed code is worse than
 * an absent one because DSWD reporting keys off it. Unique when present, so a wrong code
 * cannot be entered twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barangays', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Stable slug used by clients and seeds, e.g. "brgy-san-juan".
            $table->string('code', 64)->unique();
            $table->string('name', 128);

            $table->string('psgc_code', 16)->nullable()->unique();

            $table->timestampsTz();

            $table->index('name', 'idx_barangays_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barangays');
    }
};
