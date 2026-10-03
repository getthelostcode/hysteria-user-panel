<?php

namespace App\Actions;

use App\Models\ProviderNode;

/**
 * 测试节点连通性（TCP 探测 host:port）。
 *
 * 只做一次 TCP 握手，不做 TLS/协议层面的探测：
 *   - 握手成功 = 端口可达（节点进程即使没起来，也能区分「网络不通」和「服务没起」）；
 *   - 超时时间默认 3 秒，避免面板请求被卡死；
 *   - 结果写回 provider_nodes.config['last_probe']，方便列表页展示历史探测结论。
 */
class TestNodeConnectivityAction
{
    /**
     * @return array{ok: bool, latency_ms: int|null, message: string, probed_at: string}
     */
    public function execute(ProviderNode $node, float $timeout = 3.0): array
    {
        $host = (string) $node->host;
        $port = (int) $node->port;

        if ($host === '' || $port <= 0) {
            $result = [
                'ok' => false,
                'latency_ms' => null,
                'message' => '节点未配置 host / port，无法探测。',
                'probed_at' => now()->toIso8601String(),
            ];
        } else {
            $start = microtime(true);
            $errno = 0;
            $errstr = '';

            // 短超时 + 静默：探测失败是业务常态，不应该往日志里刷 Warning
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
            $latency = (int) round((microtime(true) - $start) * 1000);

            if (is_resource($socket)) {
                fclose($socket);
                $result = [
                    'ok' => true,
                    'latency_ms' => $latency,
                    'message' => "TCP 握手成功，耗时 {$latency}ms。",
                    'probed_at' => now()->toIso8601String(),
                ];
            } else {
                $result = [
                    'ok' => false,
                    'latency_ms' => null,
                    'message' => trim(sprintf('TCP 握手失败：%s (%d)', $errstr ?: '无法连接', $errno)),
                    'probed_at' => now()->toIso8601String(),
                ];
            }
        }

        // 记录最后一次探测结论（config 是 JSON 列；不改变业务字段）
        $config = $node->config ?? [];
        $config['last_probe'] = $result;
        $node->config = $config;
        $node->save();

        return $result;
    }
}
