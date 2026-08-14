<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives Sanctum's token table a public identifier (ADR 0008 §1).
 *
 * A person managing their own sessions needs to name one to revoke it, and Sanctum keys
 * tokens by autoincrement id. Handing those out would expose a sequential key and let a
 * caller infer how many tokens the system has ever issued — which conventions §6 forbids.
 *
 * Nullable rather than NOT NULL: this is an additive change to an existing table
 * (expand → migrate → contract, Article 6). Every token created from now on gets a UUID
 * from the model hook registered in IdentityServiceProvider; any pre-existing row simply
 * has none and cannot be addressed by UUID, which is correct — it can still be revoked by
 * "revoke all".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid()->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
