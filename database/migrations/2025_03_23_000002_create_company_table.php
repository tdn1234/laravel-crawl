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
        // Create company table
        Schema::create('company', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->foreignId('industry_id')->constrained('industry');
            $table->integer('employee_number')->nullable();
            $table->integer('open_jobs')->default(0);
            $table->timestamps();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {       
        Schema::dropIfExists('company');    
    }
};