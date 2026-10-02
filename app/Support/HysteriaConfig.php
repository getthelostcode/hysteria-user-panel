<?php

namespace App\Support;

use App\Models\Provider;
use App\Models\ProviderNode;

/**
 * Hysteria 2 连接配置（值对象）。
 *
 * 敏感字段（认证密钥）默认**脱敏**，只有用户主动点击「显示密钥」时才输出明文。
 */
final class HysteriaConfig
{
    public function __construct(
        public readonly ?Provider $provider,
        public readonly ?ProviderNode $node,
        public readonly ?string $externalUserId,
        public readonly ?string $secret,
        public readonly bool $revealed = false,
        public readonly ?string $sni = null,
        public readonly bool $insecure = false,
    ) {
    }

    /** 是否具备生成可用配置的条件 */
    public function isReady(): bool
    {
        return $this->node !== null && $this->externalUserId !== null && $this->secret !== null;
    }

    /** 服务端地址 host:port */
    public function server(): string
    {
        if (! $this->node) {
            return '未分配节点';
        }

        return $this->node->endpoint();
    }

    /** 认证串：external_user_id:secret（secret 未解密时脱敏） */
    public function auth(): string
    {
        if (! $this->externalUserId) {
            return '未开通';
        }

        return $this->externalUserId.':'.$this->maskedSecret();
    }

    /** 脱敏后的密钥：保留首 4 位与末 4 位 */
    public function maskedSecret(): string
    {
        if (blank($this->secret)) {
            return '******';
        }

        if ($this->revealed) {
            return $this->secret;
        }

        if (mb_strlen($this->secret) <= 8) {
            return str_repeat('*', mb_strlen($this->secret));
        }

        return mb_substr($this->secret, 0, 4).str_repeat('*', 8).mb_substr($this->secret, -4);
    }

    /** 节点名（用于配置备注） */
    public function nodeLabel(): string
    {
        if (! $this->node) {
            return '未分配';
        }

        return ($this->node->name ?: $this->node->node_code).' ('.$this->node->region.')';
    }

    /** 生成可复制的 Hysteria 2 客户端 YAML 配置 */
    public function toYaml(): string
    {
        $sni = $this->sni ?: ($this->node?->host ?? '');

        $lines = [
            '# Hysteria 2 客户端配置（由平台生成，密钥默认脱敏）',
            '# 服务商: '.($this->provider?->name ?? '未绑定'),
            '# 节点: '.$this->nodeLabel(),
            'server: '.$this->server(),
            'auth: '.$this->auth(),
            'tls:',
            '  sni: '.$sni,
            '  insecure: '.($this->insecure ? 'true' : 'false'),
            'bandwidth:',
            '  up: 20 mbps',
            '  down: 100 mbps',
            'socks5:',
            '  listen: 127.0.0.1:1080',
            'http:',
            '  listen: 127.0.0.1:8080',
        ];

        return implode(PHP_EOL, $lines);
    }

    /**
     * 生成 hysteria2:// 分享链接，方便一键导入。
     *
     * 安全：链接里的密钥同样走 maskedSecret() —— 只有在用户点了「显示明文密钥」
     * 之后，这条链接才是可用的；默认状态下复制到的是脱敏串，避免明文密钥
     * 通过 href / 剪贴板 / 浏览器历史泄漏。
     */
    public function toUri(): string
    {
        if (! $this->isReady()) {
            return '';
        }

        $query = http_build_query(array_filter([
            'sni' => $this->sni ?: $this->node?->host,
            'insecure' => $this->insecure ? '1' : '0',
        ]));

        return sprintf(
            'hysteria2://%s:%s@%s/?%s#%s',
            rawurlencode((string) $this->externalUserId),
            rawurlencode($this->maskedSecret()),
            $this->server(),
            $query,
            rawurlencode(($this->provider?->code ?? 'provider').'-'.($this->node?->node_code ?? 'node')),
        );
    }
}
