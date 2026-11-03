<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings the support account changes in the app (the SMS account), by name. Secrets are stored encrypted.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('name', 60)->primary();
            $table->text('value');
            $table->string('updated_by', 32)->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
