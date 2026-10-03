<?php

namespace App\Actions;

use App\Models\ProviderNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 新建服务商节点。
 *
 * 业务规则：
 *  1. provider_id **必须来自当前登录服务商**（调用方负责传对），Action 内部再校验一次，
 *     绝不只依赖前端；
 *  2. host + port 不允许重复（同一个服务商内），避免同一入口被记两次导致流量对不上；
 *  3. 节点密钥在服务端生成并**加密存储**（provider_nodes 没有独立密钥列，
 *     密文放在 config JSON 的 auth_secret_encrypted），明文只在创建成功那一刻返回一次给页面。
 */
class CreateNodeAction
{
    /**
     * @param  array{name?:string, region?:string, country_code?:string, host:string, port:int,
     *               capacity_mbps?:int, protocol?:string, node_code?:string, tags?:array, config?:array}  $data
     * @param  int|null  $operatorId  操作人（服务商后台账号 id），写进 config 便于追责
     */
    public function execute(int $providerId, array $data, ?int $operatorId = null): array
    {
        $host = trim((string) $data['host']);
        $port = (int) $data['port'];

        if ($port < 1 || $port > 65535) {
            throw ValidationException::withMessages(['port' => '端口必须在 1~65535 之间。']);
        }

        // host + port 去重：同一服务商下不允许出现重复入口
        $duplicated = ProviderNode::query()
            ->ofProvider($providerId)
            ->where('host', $host)
            ->where('port', $port)
            ->exists();

        if ($duplicated) {
            throw ValidationException::withMessages([
                'host' => "节点地址 {$host}:{$port} 已存在，请勿重复添加。",
            ]);
        }

        return DB::transaction(function () use ($providerId, $data, $host, $port, $operatorId) {
            $secret = Str::random(32);

            $config = $data['config'] ?? [];
            $config['created_by'] = $operatorId;

            $node = new ProviderNode([
                'provider_id' => $providerId,
                'node_code' => $this->makeNodeCode($data['node_code'] ?? null, $data['name'] ?? null),
                'name' => $data['name'] ?? null,
                'region' => $data['region'] ?? null,
                'country_code' => $data['country_code'] ?? null,
                'host' => $host,
                'port' => $port,
                'protocol' => $data['protocol'] ?? 'hysteria2',
                'capacity_mbps' => $data['capacity_mbps'] ?? null,
                'status' => 'active',
                'tags' => $data['tags'] ?? null,
                'config' => $config,
            ]);

            // 密钥加密入库（明文只在返回值里出现一次）
            $node->setAuthSecret($secret);
            $node->save();

            return ['node' => $node, 'secret' => $secret];
        });
    }

    /** 生成节点编码：显式传入优先；否则由名称 slug 化，冲突时补 4 位随机后缀 */
    protected function makeNodeCode(?string $given, ?string $name): string
    {
        $code = $given ?: Str::slug((string) $name, '-');

        if (blank($code)) {
            $code = 'node';
        }

        $code = Str::limit($code, 48, '');

        while (ProviderNode::query()->where('node_code', $code)->exists()) {
            $code = Str::limit($code, 48, '').'-'.Str::lower(Str::random(4));
        }

        return $code;
    }
}
