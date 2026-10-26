<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            // NULL for system accounts; MySQL/PostgreSQL both allow many NULLs in a unique index.
            $table->foreignId('wallet_id')->nullable()->unique()->constrained();
            $table->foreignId('currency_id')->constrained();
            $table->string('code')->unique();
            $table->string('name');
            // AccountType: 1 = wallet, 2 = payment_clearing.
            $table->unsignedTinyInteger('type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
