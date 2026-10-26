<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 26)->unique();
            $table->foreignId('currency_id')->constrained();
            // TransactionType: 1 = transfer, 2 = deposit.
            $table->unsignedTinyInteger('type');
            // TransactionStatus: 1 = posted.
            $table->unsignedTinyInteger('status')->default(1);
            $table->bigInteger('amount');
            $table->timestamp('posted_at');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
