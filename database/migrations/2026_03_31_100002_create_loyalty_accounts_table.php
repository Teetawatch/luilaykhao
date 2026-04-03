<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('points')->default(0);
            $table->integer('lifetime_points')->default(0);
            $table->enum('tier', ['regular', 'silver', 'gold'])->default('regular');
            $table->timestamps();
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['earn', 'redeem', 'expire', 'adjust']);
            $table->integer('points');
            $table->string('description');
            $table->nullableMorphs('reference'); // booking, reward redemption, etc.
            $table->integer('balance_after');
            $table->timestamps();
        });

        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['discount_percent', 'discount_fixed', 'free_item']);
            $table->integer('points_required');
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('stock')->nullable(); // null = unlimited
            $table->timestamps();
        });

        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained('loyalty_rewards')->cascadeOnDelete();
            $table->integer('points_used');
            $table->string('coupon_code', 20)->unique();
            $table->boolean('is_used')->default(false);
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_accounts');
    }
};
