<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('image_path');
            $table->boolean('is_deleted')->default(false)->after('edited_at');
            // user_ids mentioned in the message — drives targeted "you were
            // mentioned" pushes and in-bubble highlighting.
            $table->json('mentions')->nullable()->after('is_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'is_deleted', 'mentions']);
        });
    }
};
