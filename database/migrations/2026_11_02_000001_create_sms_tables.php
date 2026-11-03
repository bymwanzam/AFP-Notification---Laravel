<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each district's surveillance officer: who gets the SMS about its due and overdue AFP follow-ups.
        Schema::create('district_officers', function (Blueprint $table) {
            $table->id();
            $table->string('region', 40);
            $table->string('district_code', 8);
            $table->string('district', 80);
            $table->string('name', 80)->default('');
            $table->string('phone', 16);
            $table->string('updated_by', 32)->default('');
            $table->timestamps();
            $table->unique(['region', 'district_code']);
        });

        // Every SMS sent from the app: to whom, about which case, by whom, and what the provider said.
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->string('epid', 40)->index();
            $table->string('status_key', 10);
            $table->string('region', 40);
            $table->string('district', 80);
            $table->string('officer', 80)->default('');
            $table->string('phone', 16);
            $table->text('message');
            $table->string('sent_by', 32);
            $table->string('reference', 64)->default('');
            $table->string('status', 24);
            $table->string('error', 255)->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('district_officers');
        Schema::dropIfExists('sms_messages');
    }
};
