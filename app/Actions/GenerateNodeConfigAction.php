<?php

namespace App\Actions;

use App\Models\ProviderNode;

/**
 * 根据 provider_nodes 生成「节点侧可复制的 Hysteria 2 服务端配置」。
 *
 * 安全：节点密钥默认**脱敏**输出（否则一键复制的明文密钥会经剪贴板 / 浏览器历史 / 截图泄漏）。
 * 只有当服务商显式展开「显示明文密钥」时，$revealSecret = true，才输出真实密钥。
 */
class GenerateNodeConfigAction
{
    public function execute(ProviderNode $node, bool $revealSecret = false): string
    {
        $secret = $node->authSecret();
        $auth = $revealSecret
            ? (string) $secret
            : $node->maskedAuthSecret();

        $port = $node->port ?: 443;
        $sni = $node->sni() ?: 'example.com';
        $user = $node->node_code;

        $lines = [
            '# ============================================================',
            '# Hysteria 2 服务端配置（由平台服务商后台生成）',
            '# 服务商: '.($node->provider?->name ?? '-'),
            '# 节点  : '.($node->name ?: $node->node_code).' / '.($node->region ?: '-'),
            '# 生成时间(UTC): '.now()->toDateTimeString(),
            '# ============================================================',
            'listen: :'.$port,
            '',
            'tls:',
            '  sni: '.$sni,
            '  cert: /etc/hysteria/'.$node->node_code.'.crt',
            '  key: /etc/hysteria/'.$node->node_code.'.key',
            '',
            'auth:',
            '  type: userpass',
            '  userpass:',
            '    '.$user.': "'.$auth.'"',
            '',
            'bandwidth:',
            '  up: '.($node->capacity_mbps ? $node->capacity_mbps.' mbps' : '1 gbps'),
            '  down: '.($node->capacity_mbps ? $node->capacity_mbps.' mbps' : '1 gbps'),
            '',
            'masquerade:',
            '  type: proxy',
            '  proxy:',
            '    url: https://news.ycombinator.com/',
            '    rewriteHost: true',
            '',
            '# 平台上报接口（节点侧按此地址上报流量与心跳）',
            'platform:',
            '  api: '.($node->provider?->api_endpoint ?? 'https://api.example.com'),
            '  node_code: '.$node->node_code,
            '  provider_code: '.($node->provider?->code ?? '-'),
        ];

        return implode(PHP_EOL, $lines);
    }
}
