<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('company', function (Blueprint $table) {
            // First drop any existing unique index on company_name if it exists
            if (Schema::hasIndex('company', 'company_company_name_unique')) {
                $table->dropUnique('company_company_name_unique');
            }

            // Add the composite unique index
            $table->unique(['company_name', 'industry_id']);
        });
    }

    public function down()
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropUnique(['company_name', 'industry_id']);
        });
    }
};
