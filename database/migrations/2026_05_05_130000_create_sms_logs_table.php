<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('provider')->default('thaibulksms');
            $table->string('sms_type');
            $table->string('dedupe_key')->default('default');
            $table->string('recipient', 20)->nullable();
            $table->text('message');
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'provider', 'sms_type', 'dedupe_key'], 'sms_logs_booking_provider_type_key_unique');
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
