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
        /*
         * Laravel's `users` and `password_reset_tokens` tables are deliberately NOT
         * created.
         *
         * Identity owns authentication in this system (TAB 05): `accounts` supersedes
         * `users` and `password_resets` supersedes `password_reset_tokens`. Creating the
         * scaffolding tables as well would leave two account stores and two reset
         * mechanisms in one database — the duplicate source of truth that CLAUDE.md
         * Article 6 and ADR 0008 §10 exist to prevent, and the kind that ends with half
         * the code reading the wrong one.
         *
         * Editing this migration rather than adding a drop is safe here and only here:
         * it has never run anywhere but local and disposable databases, and the project
         * has no deployment. Once anything is deployed, ADR 0008 §14 applies and the
         * change would have to be a new forward migration.
         *
         * `sessions` stays: the framework's session store is unrelated to API
         * authentication, which uses bearer tokens (ADR 0005).
         */
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
