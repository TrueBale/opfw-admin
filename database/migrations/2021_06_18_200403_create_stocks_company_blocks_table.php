<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateStocksCompanyBlocksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stocks_company_blocks', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('block_id')->nullable();
            $table->integer('company_id')->nullable();
            $table->integer('last_payment')->nullable();
            $table->integer('next_payment')->nullable();
            $table->integer('terminate')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('stocks_company_blocks');
    }
}
