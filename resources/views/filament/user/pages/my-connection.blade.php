@php
    $config = $this->config();
@endphp

<x-filament-panels::page>
    @if (! $config->isReady())
        <x-filament::section>
            <div class="py-6 text-center">
                <div class="text-lg font-semibold">还没有可用的连接</div>
                <p class="mt-2 text-sm text-gray-500">
                    你需要先选择一家服务商，平台会为你开通账号并分配节点。
                </p>
                <div class="mt-4">
                    <x-filament::button
                        tag="a"
                        :href="\App\Filament\User\Pages\SwitchProvider::getUrl()"
                        icon="heroicon-m-arrows-right-left"
                    >
                        去选择服务商
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section heading="连接信息">
            <div class="hv-grid hv-grid-3">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">服务商</div>
                    <div class="mt-1 font-semibold">{{ $config->provider?->name }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">节点</div>
                    <div class="mt-1 font-semibold">{{ $config->nodeLabel() }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">服务器地址</div>
                    <div class="mt-1 font-mono font-semibold">{{ $config->server() }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">用户标识（external_user_id）</div>
                    <div class="mt-1 font-mono">{{ $config->externalUserId }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">认证密钥</div>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="font-mono">{{ $config->maskedSecret() }}</span>
                        <x-filament::badge :color="$this->revealSecret ? 'danger' : 'gray'">
                            {{ $this->revealSecret ? '明文' : '已脱敏' }}
                        </x-filament::badge>
                    </div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">TLS / SNI</div>
                    <div class="mt-1 font-mono">{{ $config->sni ?: '—' }}</div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-filament::button wire:click="toggleSecret" color="gray" icon="heroicon-m-eye">
                    {{ $this->revealSecret ? '隐藏密钥' : '显示明文密钥' }}
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section heading="客户端配置（可直接粘贴到 mihomo / sing-box / Hysteria 客户端）">
            <pre x-ref="cfg" class="hv-code">{{ $config->toYaml() }}</pre>

            <div class="mt-3 flex gap-2">
                <x-filament::button
                    x-data
                    x-on:click="navigator.clipboard.writeText($refs.cfg.innerText); $wire.notifyCopied('配置')"
                    icon="heroicon-m-clipboard"
                >
                    复制配置
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    color="gray"
                    icon="heroicon-m-link"
                    :href="$config->toUri()"
                    target="_blank"
                >
                    打开分享链接
                </x-filament::button>
            </div>

            <p class="mt-3 text-xs text-gray-400">
                提示：密钥属于敏感凭据，分享链接中含明文密钥，请勿转发给他人。
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
