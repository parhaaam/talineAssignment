<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('currency_id')->constrained();
            $table->bigInteger('balance')->default(0);
            $table->timestamps();

            // Also serves the user_id foreign key and wallet lookup by user+currency.
            $table->unique(['user_id', 'currency_id']);
        });

        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
