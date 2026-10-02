<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // user 面板注册了独立 Vite 主题，渲染时会去读 public/build/manifest.json。
        // 测试环境不应该依赖前端构建产物（CI 里常常只跑 PHP 侧），
        // 因此统一替换成 Vite 的假实现；构建产物本身由 `npm run build` 单独验收。
        $this->withoutVite();
    }
}
