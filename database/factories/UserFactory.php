<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 用户工厂（适配架构师 DDL：没有 name 列，密码列是 password_hash）。
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $username = Str::lower($this->faker->unique()->userName());

        return [
            'uuid' => (string) Str::uuid(),
            'username' => Str::limit($username, 24, ''),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => null,
            'password_hash' => bcrypt('password'),   // 默认密码 password
            'status' => 'active',
            'locale' => 'zh-CN',
            'registered_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
