<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts sign in with a username (no email: the lab is offline and
     * sends no mail) and there are no roles: every account is equal and is
     * activated on VM 1 with `php artisan shellseek:user-activate`.
     *
     * Existing rows get a username derived from the part of their email
     * before "@" (sanitised, unique), so existing accounts keep working.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 32)->nullable()->after('name');
        });

        $taken = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'email']) as $row) {
            $base = substr(preg_replace('/[^a-z0-9._-]/', '', strtolower(strstr((string) $row->email, '@', true) ?: '')), 0, 28);
            $base = strlen($base) >= 3 ? $base : 'user'.$row->id;
            $name = $base;
            for ($i = 2; isset($taken[$name]); $i++) {
                $name = substr($base, 0, 28).$i;
            }
            $taken[$name] = true;
            DB::table('users')->where('id', $row->id)->update(['username' => $name]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
            $table->dropUnique('users_email_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email', 'email_verified_at', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('role', 16)->default('analyst')->after('password');
        });
        DB::table('users')->update(['email' => DB::raw("username || '@localhost'")]);
        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
            $table->dropUnique(['username']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
