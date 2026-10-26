<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained();
            $table->foreignId('account_id')->constrained();
            $table->bigInteger('amount');
            $table->timestamps();

            // A transaction touches an account at most once; also serves the transaction_id foreign key.
            $table->unique(['transaction_id', 'account_id']);
            // Wallet history: seek by account, newest transaction first.
            $table->index(['account_id', 'transaction_id']);
        });

        DB::statement('ALTER TABLE entries ADD CONSTRAINT entries_amount_non_zero CHECK (amount <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
