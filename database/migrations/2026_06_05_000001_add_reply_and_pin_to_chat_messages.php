<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // Reply/quote — points at the message being replied to.
            $table->foreignId('reply_to_id')->nullable()->after('user_id')
                ->constrained('chat_messages')->nullOnDelete();

            // Pinned announcement — staff/admin pin one message per room.
            $table->timestamp('pinned_at')->nullable()->after('image_path');
            $table->foreignId('pinned_by_id')->nullable()->after('pinned_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['schedule_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropConstrainedForeignId('pinned_by_id');
            $table->dropColumn('pinned_at');
        });
    }
};
