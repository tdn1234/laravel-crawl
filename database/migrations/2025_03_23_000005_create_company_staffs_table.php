<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        // Create company_staffs table
        Schema::create('company_staffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->string('full_name');
            $table->string('contact_link')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {        
        Schema::dropIfExists('company_staffs');     
    }
};