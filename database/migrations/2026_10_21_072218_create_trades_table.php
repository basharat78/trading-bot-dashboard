<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\table;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
$table->string('external_id', 64)->unique();
$table->foreignId('account_id')->constrained();
$table->string('symbol', 20);
$table->string('side', 4);
$table->decimal('volume', 12, 4);
$table->decimal('entry_price', 18, 5);
$table->decimal('exit_price', 18, 5)->nullable();
$table->decimal('sl', 18, 5)->nullable();
$table->decimal('tp', 18, 5)->nullable();
$table->decimal('pnl', 18, 2)->nullable();
$table->timestamp('opened_at');
$table->timestamp('closed_at')->nullable();
$table->string('status', 20)->index();
$table->foreignId('setup_id')->nullable()->constrained();
$table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
