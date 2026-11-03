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
        // One region per account ("Ghana" for a national administrator); the support account is the superuser.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 32)->unique();
            $table->string('password');
            $table->boolean('is_superuser')->default(false);
            $table->string('full_name', 80)->default('');
            $table->string('region', 40)->default('');
            $table->string('cadre', 60)->default('');
            $table->string('photo', 120)->default('');
            $table->boolean('must_change')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        // Wrong passwords in a row for a username; after 5 it is locked for 5 minutes.
        Schema::create('login_locks', function (Blueprint $table) {
            $table->string('username', 150)->primary();
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('locked_until')->nullable();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

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
        Schema::dropIfExists('users');
        Schema::dropIfExists('login_locks');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
