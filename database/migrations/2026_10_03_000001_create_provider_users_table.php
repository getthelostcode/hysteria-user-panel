<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 服务商后台登录账号表 provider_users。
 *
 * 为什么必须新开一张表，而不是复用 users：
 *  1. 账号归属不同：users 是 VPN 用户；provider_users 是「服务商后台的操作员」，
 *     必须硬绑定到某个 provider，权限、数据隔离、登录入口全部不同。
 *  2. 架构师的 DDL（database/schema/hysteria_schema.sql）里没有这张表，
 *     且「不要把所有内容塞进 users」是硬要求，所以这里以**增量迁移**的方式补齐，
 *     不改动任何既有表（幂等：表已存在则直接跳过）。
 *  3. 一个服务商可以有多个操作员账号（运营/财务各一套），因此是 provider_id 一对多，
 *     不是 1:1。
 *
 * 命名口径：
 *  - 密码列用标准 password（Laravel 认证默认列名），不做 password_hash 映射，
 *    避免「写入口映射 + 哈希二次处理」这一类隐蔽问题；
 *  - 时间列统一 DATETIME(6)（微秒），与全库精度一致；
 *  - status 只有 active / disabled：disabled 的账号可以保留审计痕迹但无法登录。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provider_users')) {
            return;   // 幂等：已经建过就直接返回
        }

        Schema::create('provider_users', function (Blueprint $table) {
            $table->id();

            // 归属服务商：服务商被删除时账号一并回收
            $table->unsignedBigInteger('provider_id');
            $table->string('name', 128)->nullable()->comment('操作员姓名');
            $table->string('email', 190)->comment('登录邮箱');
            $table->string('password', 255);
            $table->enum('status', ['active', 'disabled'])->default('active');

            $table->string('remember_token', 100)->nullable();
            $table->dateTime('email_verified_at', 6)->nullable();
            $table->dateTime('last_login_at', 6)->nullable();

            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique('email', 'uk_pu_email');
            $table->index(['provider_id', 'status'], 'idx_pu_provider_status');

            $table->foreign('provider_id', 'fk_pu_provider')
                ->references('id')->on('providers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_users');
    }
};
