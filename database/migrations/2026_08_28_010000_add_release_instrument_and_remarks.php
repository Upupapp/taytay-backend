<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two things a disbursing officer types at the moment of handover.
 *
 * The staff console's release screen collects a **voucher or cheque reference** and free-text
 * **remarks**, and neither had anywhere to land: `confirmRelease` accepted only the acknowledgement
 * triple. Wiring that screen without these columns would have dropped the one identifier a
 * reconciliation is performed against — you cannot tie a payout back to a cheque number the system
 * never stored.
 *
 * `instrument_reference` is deliberately NOT an account code and nothing joins on it (`DL-89`).
 * It is what the office wrote on the voucher, held so the two records can be matched by a person.
 *
 * Both nullable: an in-kind release has no instrument, and remarks are optional everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->string('instrument_reference', 64)->nullable()->after('release_mode');
            $table->string('release_remarks', 255)->nullable()->after('instrument_reference');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn(['instrument_reference', 'release_remarks']);
        });
    }
};
