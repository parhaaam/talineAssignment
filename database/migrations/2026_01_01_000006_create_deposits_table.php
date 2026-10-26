<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained();
            $table->foreignId('currency_id')->constrained();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained();
            $table->string('reference', 40)->unique();
            $table->string('provider', 32);
            $table->string('provider_authority', 64)->nullable();
            $table->string('provider_reference', 64)->nullable();
            $table->bigInteger('amount');
            $table->bigInteger('provider_amount')->nullable();
            $table->string('provider_currency', 8)->nullable();
            // DepositStatus: 1 = pending, 2 = verification_unknown, 3 = verified, 4 = confirmed, 5 = failed.
            $table->unsignedTinyInteger('status');
            $table->timestamp('provider_verified_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_authority']);
            $table->unique(['provider', 'provider_reference']);
            $table->index(['wallet_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE deposits ADD CONSTRAINT deposits_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
