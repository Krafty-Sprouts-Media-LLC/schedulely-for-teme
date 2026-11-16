<?php
/**
 * Filename: 2026_11_16_000001_create_schedulely_tables.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Creates Schedulely's four tables: the queue of drafts, the ledger of
 *              scheduled times, small named values, and the reorder log.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every table starts with the plugin's code and is listed under `owns` in teme-plugin.json.
     *
     * @since 0.1.0
     */
    public function up(): void
    {
        Schema::create('schedulely_queue', function (Blueprint $table): void {
            $table->string('item_id', 26)->primary();
            $table->string('type', 64);
            $table->text('title');
            $table->string('language', 35);
            $table->string('status', 16);
            $table->bigInteger('first_seen_at');
            $table->bigInteger('updated_at');
            $table->index(['type', 'status', 'first_seen_at'], 'schedulely_queue_pick');
        });

        Schema::create('schedulely_slots', function (Blueprint $table): void {
            $table->string('item_id', 26)->primary();
            $table->string('type', 64);
            $table->bigInteger('publish_at');
            $table->index(['type', 'publish_at'], 'schedulely_slots_time');
        });

        Schema::create('schedulely_state', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->text('value');
        });

        Schema::create('schedulely_reorder_log', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('created_at');
            $table->string('outcome', 16);
            $table->string('model', 120);
            $table->integer('item_count');
            $table->integer('http_code')->nullable();
            $table->integer('usage_tokens')->nullable();
            $table->string('error_code', 64)->default('');
            $table->text('error_message')->nullable();
            $table->text('assistant_excerpt')->nullable();
            $table->text('raw_excerpt')->nullable();
            $table->text('note')->nullable();
        });
    }

    /**
     * @since 0.1.0
     */
    public function down(): void
    {
        Schema::dropIfExists('schedulely_reorder_log');
        Schema::dropIfExists('schedulely_state');
        Schema::dropIfExists('schedulely_slots');
        Schema::dropIfExists('schedulely_queue');
    }
};
