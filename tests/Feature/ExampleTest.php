<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 根路径行为：本工程只有用户后台一个面板，访问 / 应直接跳到 /user。
 */
class ExampleTest extends TestCase
{
    public function test_根路径跳转到用户面板(): void
    {
        $this->get('/')->assertRedirect('/user');
    }
}
