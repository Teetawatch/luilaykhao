<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // broadcast_dispatches started life as a pure dedupe ledger (event type +
        // key). Once admins can compose their own pushes it also has to answer
        // "what did we send, to whom, and did anyone read it?" — so the message
        // itself, the audience and the delivery counters live here too.
        Schema::table('broadcast_dispatches', function (Blueprint $table) {
            $table->string('title')->nullable()->after('dedupe_key');
            $table->text('body')->nullable()->after('title');
            $table->json('data')->nullable()->after('body');
            // all | trip | schedule — null for the automatic broadcasts that
            // predate this column (they always went to everyone).
            $table->string('audience', 30)->nullable()->after('data');
            $table->unsignedBigInteger('audience_id')->nullable()->after('audience');
            $table->string('audience_label')->nullable()->after('audience_id');
            $table->unsignedInteger('recipients_count')->nullable()->after('audience_label');
            $table->foreignId('sent_by')->nullable()->after('recipients_count')
                ->constrained('users')->nullOnDelete();
        });

        // Links each delivered notification back to the blast that produced it,
        // so the read-rate on the admin page is an exact count rather than a
        // guess from timestamps.
        Schema::table('smart_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('broadcast_dispatch_id')->nullable()->after('data');
            $table->index('broadcast_dispatch_id');
        });
    }

    public function down(): void
    {
        Schema::table('broadcast_dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn([
                'title', 'body', 'data', 'audience', 'audience_id',
                'audience_label', 'recipients_count',
            ]);
        });

        Schema::table('smart_notifications', function (Blueprint $table) {
            $table->dropIndex(['broadcast_dispatch_id']);
            $table->dropColumn('broadcast_dispatch_id');
        });
    }
};
