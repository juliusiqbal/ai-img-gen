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
        Schema::table('templates', function (Blueprint $table) {
            $table->string('project_name')->nullable()->after('category_id');
            $table->text('generation_prompt')->nullable()->after('prompt_used');
            $table->json('design_preferences')->nullable()->after('generation_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['project_name', 'generation_prompt', 'design_preferences']);
        });
    }
};
