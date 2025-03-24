<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('industry', function (Blueprint $table) {
            $table->unique('industry_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('industry', function (Blueprint $table) {
            $table->dropUnique('industry_industry_name_unique');
        });
    }
};
