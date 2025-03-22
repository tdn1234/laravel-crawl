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
        // Create job_detail table
        Schema::create('job_detail', function (Blueprint $table) {
            $table->id();
            $table->string('job_name');
            $table->string('location')->nullable();
            $table->date('open_date')->nullable();
            $table->foreignId('job_type_id');
            $table->foreignId('company_id');
            $table->enum('time_type', ['full_time', 'part_time']);
            $table->integer('number_of_applicants')->default(0);
            $table->string('job_description_link')->nullable();
            $table->timestamps();

            $table->foreign('job_type_id')->references('id')->on('jobs_types');
            $table->foreign('company_id')->references('id')->on('company');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_detail');        
    }
};