<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invited users start with no password — they set their own the first time they
 * accept the invite. We keep a hashed, single-use invitation token plus who
 * invited them and when, so the accept link can be validated and expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('invitation_token', 64)->nullable()->index()->after('password');
            $table->timestamp('invited_at')->nullable()->after('invitation_token');
            $table->foreignId('invited_by')->nullable()->after('invited_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['invitation_token', 'invited_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
