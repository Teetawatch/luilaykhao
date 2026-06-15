<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Personal invite code shared with friends; set lazily on first use.
            $table->string('referral_code', 16)->nullable()->unique()->after('avatar');
            // The user who referred this account (set once at registration).
            $table->foreignId('referred_by')->nullable()->after('referral_code')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            // A user can only ever be referred once.
            $table->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'rewarded'])->default('pending');
            // The referred friend's first paid booking that unlocked the reward.
            $table->foreignId('qualifying_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->integer('referrer_points')->default(0);
            $table->integer('referee_points')->default(0);
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropColumn('referral_code');
        });
    }
};
