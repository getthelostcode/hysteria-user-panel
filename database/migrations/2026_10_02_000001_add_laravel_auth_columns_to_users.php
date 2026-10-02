<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel 认证所需的补充字段。
 *
 * 基础表由「MySQL 数据库架构师」的 DDL 建立（见 database/schema/hysteria_schema.sql），
 * 其中 users 表使用 password_hash 作为密码列，但没有 Laravel 认证/会话所需的
 * remember_token 与 email_verified_at。本迁移只做「增量补充」，不重建任何既有表。
 *
 * 命名保持架构师的 points / password_hash 口径：
 *   - 不新增冗余的 password 列，改由 App\Models\User 覆盖 getAuthPassword() 指向 password_hash
 *   - 写 password 属性时通过 Attribute 自动落到 password_hash（见模型）
 *
 * 幂等：重复执行不会报错（先判断列是否存在）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'remember_token')) {
                // Laravel "记住我" 令牌（Auth::viaRemember），100 字符足够
                $table->string('remember_token', 100)->nullable()->after('password_hash');
            }

            if (! Schema::hasColumn('users', 'email_verified_at')) {
                // 邮箱验证时间（可选启用）。DATETIME(6) 与全库时间精度保持一致，统一 UTC
                $table->dateTime('email_verified_at', 6)->nullable()->after('remember_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['remember_token', 'email_verified_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
