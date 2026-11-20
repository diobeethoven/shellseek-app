<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per user request, updated as the request moves through
     * LLM -> parse -> Scope Enforcer -> gate -> execution.
     * All text is passed through SecretMasker before it is written.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->nullable()->unique();
            $table->text('instruction');
            $table->string('stage', 16);
            $table->string('outcome', 32)->index();

            // LLM
            $table->string('llm_model')->nullable();
            $table->text('llm_output')->nullable();
            $table->string('llm_failure', 64)->nullable();
            $table->unsignedInteger('llm_duration_ms')->nullable();

            // parsing
            $table->string('parse_failure', 64)->nullable();
            $table->text('parse_detail')->nullable();
            $table->text('tool')->nullable();
            $table->text('action')->nullable();
            $table->text('target')->nullable();
            $table->json('parameters')->nullable();

            // Scope Enforcer decision (no signature stored)
            $table->string('decision', 16)->nullable()->index();
            $table->string('risk_level', 16)->nullable();
            $table->boolean('execution_allowed')->nullable();
            $table->json('reasons')->nullable();
            $table->json('validation')->nullable();
            $table->string('policy_version', 64)->nullable();
            $table->string('origin', 16)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->unsignedBigInteger('authorization_issued_at')->nullable();
            $table->unsignedBigInteger('authorization_expires_at')->nullable();

            // execution gate
            $table->string('gate_refusal', 64)->nullable();

            // execution
            $table->string('execution_status', 32)->nullable();
            $table->integer('exit_code')->nullable();
            $table->json('argv')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->unsignedInteger('execution_duration_ms')->nullable();
            $table->text('execution_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
