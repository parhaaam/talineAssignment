<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('scope', 64);
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->foreignId('deposit_id')->nullable()->constrained();
            $table->foreignId('transaction_id')->nullable()->constrained();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();

            // The database arbitrates concurrent duplicates: one insert wins, the rest re-read the winner.
            $table->unique(['user_id', 'scope', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
