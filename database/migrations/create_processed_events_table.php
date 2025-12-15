<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('processed_events', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->primary();
            $table->string('event_type', 100);
            $table->string('service', 50);
            $table->timestamp('processed_at');
            $table->index(['processed_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('processed_events');
    }
};

