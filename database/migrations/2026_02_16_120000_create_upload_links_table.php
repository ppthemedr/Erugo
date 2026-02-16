<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // creator
            $table->foreignId('guest_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('name'); // descriptive name, e.g. "Klant X project files"
            $table->string('token', 64)->unique(); // opaque random token
            $table->integer('max_uses')->default(0); // 0 = unlimited
            $table->integer('use_count')->default(0);
            $table->timestamp('expires_at');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_links');
    }
};
