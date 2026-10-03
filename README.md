# Hysteria VPN 聚合平台 · 用户后台 + 服务商后台（Laravel 11 + Filament v3）

平台只发行 **Hyper Points**：用户用法币买 Points，按 Hysteria 实际流量扣 Points，
Points 在服务商之间按「多少 Points = 1GB」的定价结算。

本工程实现**两个独立 Filament 面板**：

| 面板 | panel id / 路径 | 认证 | 数据范围 |
|---|---|---|---|
| 用户后台 | `user` · `/user` | `web` guard（`users` 表） | 只能看自己的钱包 / 账单 / 流量 / 绑定 |
| 服务商后台 | `provider` · `/provider` | `provider` guard（`provider_users` 表） | 只能看自己服务商的节点 / 定价 / 流量 / 收益 / 结算 |

> 平台运营后台 **不在本工程范围内**。

**相关仓库**

| 仓库 | 作用 |
|---|---|
| [`hysteria-node-agent`](https://github.com/getthelostcode/hysteria-node-agent) | 节点侧客户端（拉踢人列表 + 上报流量）；其 `sql/` 目录是本平台**数据库 DDL 的权威来源** |
| [`hysteria-server`](https://github.com/getthelostcode/hysteria-server) | Hysteria 2 服务端 + 流量采集 |

本仓库 `database/schema/hysteria_schema.sql` 即从 `hysteria-node-agent/sql/01..06` 原样搬运，
只增不改；Laravel 侧的增量字段写在 `database/migrations/` 里。

---

## 1. 技术栈与版本

| 组件 | 版本 | 说明 |
|---|---|---|
| PHP | **8.2**（实测 8.2.33） | 需要 `pdo_mysql` `bcmath` `intl` `redis` `zip` |
| Laravel | **11.x** | 配置走 `bootstrap/`，无 `Http/Middleware` 骨架 |
| Filament | **v3.3.55** | Panel id = `user`，路径 `/user` |
| MySQL | **8.0.43**（架构师 DDL，`utf8mb4_0900_ai_ci` + `DATETIME(6)`） | |
| Redis | 7.x（session / cache / queue） | 需要 `phpredis` 扩展 |
| 前端 | Filament 自带 UI（Livewire 3 + Alpine + Tailwind）+ Blade | 不引入 Vue/React；user 面板有独立主题（见 §7） |
| Node / npm | Node 18+ / npm 9+（实测 18.20.4 / 9.2.0） | 仅用于 `npm run build` 编译主题；构建非 PHP 运行必需 |

> **分支说明**：`master` = Laravel 11.57.0（按技术栈要求）。
> 分支 `chore/laravel-12` = Laravel 12.69.3（修复 4 条安全公告，零代码改动，测试同样全绿），
> 详见 [§8 安全公告](#8-安全公告务必处理)。

---

## 2. 安装与启动

```bash
composer install
cp .env.example .env && php artisan key:generate

# 建库：先跑「架构师 DDL」，再跑 Laravel 补充迁移
mysql -uroot -e "CREATE DATABASE hysteria DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -uroot hysteria < database/schema/hysteria_schema.sql   # 19 张基础表
php artisan migrate                                          # 只补 remember_token / email_verified_at

php artisan db:seed                                          # 演示数据
php artisan serve --port=8088                                # 8000 端口可能被其它服务占用
# 用户后台：   http://127.0.0.1:8088/user
# 服务商后台： http://127.0.0.1:8088/provider
```

**演示账号**（`db:seed` 创建）

| 面板 | 邮箱 | 密码 | 数据 |
|---|---|---|---|
| 用户 | `demo@example.com` | `password` | 6500 Points 充值、30 天流量、已切过一次服务商、109 条账单 |
| 用户 | `demo2@example.com` | `password` | 干净账号（验证数据隔离） |
| 服务商 | `suyun-ops@example.com` | `password` | 速云 Hysteria（2 节点 / 4 条定价 / 3 天冻结期条款） |
| 服务商 | `xinglian-ops@example.com` | `password` | 星链加速（1 节点 / 高优先级节点价 / 7 天冻结期） |

---

## 3. 数据库

基础表结构由 MySQL 架构师设计，**本工程不重建**，只做增量补充：

```
database/schema/hysteria_schema.sql                          19 张基础表（架构师 DDL 原样搬运）
database/migrations/2026_10_02_000001_add_laravel_auth_columns_to_users.php
    users 补齐 Laravel 认证所需字段：remember_token、email_verified_at
    密码列沿用 password_hash，不新增冗余的 password 列
    （映射由 App\Models\User::getAuthPassword() + password 属性写入口完成）
```

| 表 | 关键语义 |
|---|---|
| `users` | **不存 current_provider_id**；当前服务商由 `user_provider_bindings` 的 active 记录推导 |
| `user_provider_bindings` | `active_binding_key` 生成列 + `uk_user_active_binding` 保证「一人一 active」；区间 `[effective_from, effective_to)` |
| `provider_pricing_rules` | 版本化定价：`node_id=NULL` 服务商默认价，非空为节点覆盖价；`priority`/`effective_from` 决定优先级 |
| `traffic_usage_hourly` | 小时聚合桶，唯一键 `(binding_id, node_id, period_start)`，UPSERT 幂等 |
| `usage_ledger` | 计费明细，**费率快照**（points_per_gb / 系数 / 抽成比例），红冲修正 |
| `user_points_ledger` / `provider_points_ledger` | 不可变账本（`signed_amount` 生成列），钱包只是缓存 |

---

## 4. 目录结构（本工程新增部分）

```
app/
├── Actions/                           # 业务动作：Resource/Page 一律调它，不直接写库
│   ├── PurchasePointsAction.php           下单 → 模拟支付 → 加钱包 → 写账本（事务）
│   ├── SwitchProviderAction.php           事务内：锁绑定 → 关旧 → 建新 → 写审计
│   └── GenerateHysteriaConfigAction.php   生成 Hysteria 2 客户端配置（默认脱敏）
├── Services/
│   ├── PricingResolver.php                按「流量发生时间」解析绑定 / 定价 / 结算条款
│   ├── UsageBillingService.php            计费：逐桶幂等，用户扣费 + 服务商记账 + 账单
│   └── UsageStatistics.php                用量与消费统计（Widget / 图表数据源）
├── Support/
│   ├── Decimal.php                        bcmath 定点运算（DECIMAL(30,8)，禁 float）
│   ├── Bytes.php                          1 GB = 1024³ bytes
│   ├── DbTime.php                         DATETIME(6) 微秒精度助手
│   └── HysteriaConfig.php                 连接配置值对象（脱敏 / YAML / 分享链接）
├── Models/                            15 个 Eloquent 模型 + Concerns/HasMicrosecondTimestamps
├── Policies/                          6 个策略（只读 + 归属校验）
└── Filament/User/
    ├── Pages/
    │   ├── Dashboard.php                  概览（登录后落地页）
    │   ├── MyWallet.php                   我的积分（含「钱包 vs 账本」一致性自检）
    │   ├── SwitchProvider.php             切换服务商（卡片式）
    │   ├── MyConnection.php               我的连接（脱敏 / 显示明文 / 复制）
    │   ├── Auth/Register.php              自定义注册页（多一个 username）
    │   └── Auth/EditProfile.php           自定义资料页（users 表没有 name 列）
    ├── Resources/                     7 个 Resource（全只读，或只暴露一个业务 Action）
    │   ├── PointsLedgerResource           积分流水（类型/方向/时间筛选）
    │   ├── PointsPackageResource          购买积分（行内「购买」Action）
    │   ├── PointsOrderResource            我的订单
    │   ├── ProviderResource               服务商列表（节点与定价模态框 + 切换）
    │   ├── UserProviderBindingResource    我的绑定（当前 + 历史）
    │   ├── TrafficUsageResource           流量用量（页头挂消费统计 + 趋势图）
    │   └── UsageLedgerResource            计费明细
    └── Widgets/
        ├── WalletStatsOverview.php        余额 / 本月流量 / 本月消费 / 当前服务商
        ├── TrafficTrendChart.php          最近 7/30/90 天流量趋势
        ├── RecentPointsLedgerTable.php    最近 10 条流水
        └── ConsumptionStatsWidget.php     消费统计（流量页页头）
```

### 服务商后台（`app/Filament/Provider/`）

```
app/
├── Actions/                          # 服务商侧写操作（面板只调 Action，不直接写库）
│   ├── CreateNodeAction.php              建节点 + 生成加密密钥 + host:port 去重
│   ├── RegenerateNodeKeyAction.php       轮换节点密钥（旧密钥立即失效）
│   ├── ToggleNodeStatusAction.php        启用/维护/停用/下线（归属二次校验）
│   ├── TestNodeConnectivityAction.php    TCP 探测并写回 config.last_probe
│   ├── UpdatePricingAction.php           改价 = 新增版本 + 截断旧规则 + 区间冲突校验
│   ├── RequestSettlementAction.php       汇总 → 校验门槛/余额 → 结算单+明细+账本+钱包
│   └── GenerateNodeConfigAction.php      生成 Hysteria 2 服务端配置（默认脱敏）
├── Services/ProviderUsageStatistics.php  服务商维度统计（卡片 / 图表 / 排行）
└── Filament/Provider/
    ├── ProviderPanelTheme.php             服务商面板品牌与语义色（primary=Teal）
    ├── Concerns/ScopedToProvider.php      provider_id 强制隔离基座（第二道防线）
    ├── Pages/
    │   ├── Dashboard.php                  概览（登录后落地页）
    │   ├── TopUsers.php                   用户流量排行 Top 20（7/30/90 天）
    │   └── Settings/{ManageProviderProfile,ManageApiCredentials,ChangePassword}.php
    ├── Resources/                     9 个 Resource
    │   ├── ProviderNodeResource           节点列表 / 详情 / 新增 / 编辑 + 探测/换钥/状态
    │   ├── ProviderPricingRuleResource    定价规则（可新增，不可改删；未生效可作废）
    │   ├── TrafficUsageResource           流量用量（小时桶，只读）
    │   ├── TrafficRawResource             流量原始明细（分区表，默认只查最近 7 天）
    │   ├── ProviderUserBindingResource    我的用户（只读）
    │   ├── ProviderPointsLedgerResource   Points 流水（只读）
    │   ├── UsageLedgerResource            计费明细（只读，展示费率快照）
    │   ├── ProviderSettlementTermResource 结算条款（只读 + 申请调整）
    │   └── ProviderSettlementResource     结算记录（发起结算 + 明细只读）
    └── Widgets/
        ├── ProviderStatsOverview.php      今日/本月流量、本月应得、余额、在线节点
        ├── TrafficTrendChart.php          最近 7/30/90 天流量趋势
        ├── EarningsTrendChart.php         最近 7/30/90 天 Points 收益趋势
        ├── RecentProviderLedgerTable.php  最近 10 条 Points 流水
        └── RecentUsageLedgerTable.php     最近 10 条计费明细
```

服务商后台导航分组：**概览** / **节点管理** / **定价管理** / **流量与用户** / **收益** / **结算** / **设置**。

导航分组：**资产**（我的积分 / 积分流水 / 购买积分 / 我的订单）、
**服务**（服务商列表 / 切换服务商 / 我的绑定 / 我的连接）、
**用量**（流量用量 / 计费明细）、**设置**（个人资料）。

---

## 5. 验收测试

```bash
# 一次性准备测试库（结构与生产一致）
mysql -uroot -e "CREATE DATABASE hysteria_test DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -uroot hysteria_test < database/schema/hysteria_schema.sql
DB_DATABASE=hysteria_test php artisan migrate

php artisan test        # 73 个用例（用户后台 39 + 服务商后台 26 + 服务商主题 8）
```

| 验收项 | 用例 |
|---|---|
| 用户能注册 | 访客被重定向登录页；注册成功并自动建钱包；用户名重复被拒；停用用户不能进面板 |
| 买积分 | 界面「购买」Action → 订单 paid + 余额 +1000 + 账本 credit；赠送点数计入总量；下架套餐被拒 |
| 切换服务商 | 旧绑定 closed / 新绑定 active / 只有 1 条 active / 写审计；重复切换被拒；**绕过 Action 直插第二条 active 会被唯一索引拒绝** |
| 看流量 | 流量用量页展示 2.00 GB；计费明细页展示单价与扣费 |
| 看账单 | 三方金额恒等式；节点价优先命中；幂等重跑不重复扣费；余额不足不透支；红冲回补双方账本 |
| 数据隔离 | 流水 / 订单只看到自己的；Policy 禁止创建账本、流量、绑定、订单 |
| 跨服务商归属 | **切换后旧流量仍按旧服务商与其历史价格计费** |
| 服务商后台 | 独立 guard 真登录 / 跨租户被拒 / 只能看到自己的节点、账单、流水 / 只读约束 / 建节点加密密钥 / 改价只增不改 / 发起结算与防重复结算（见 §10） |
| 主题与品牌 | 独立主题仅注册在 user 面板；品牌名/Logo/favicon/语义色/暗黑模式；登录注册重置页中文品牌文案；状态徽章文字+图标+颜色三件套；Points 千分位格式化 |

真实 HTTP 冒烟（非测试环境）：

```bash
curl -I http://127.0.0.1:8088/            # 302 → /user
curl -I http://127.0.0.1:8088/user        # 302 → /user/login
curl -s  http://127.0.0.1:8088/user/login | grep -c Hysteria
curl -I http://127.0.0.1:8088/provider    # 302 → /provider/login
curl -s  http://127.0.0.1:8088/provider/login | grep -c '服务商后台'
```

---

## 6. 必须知道的实现细节（真实踩坑记录）

1. **时间精度必须到微秒。**
   架构师 DDL 用 `DATETIME(6)`，而 Laravel 有两处会截断微秒：Eloquent 写入侧
   （`$dateFormat='Y-m-d H:i:s'`）与查询绑定侧（`MySqlGrammar::$dateFormat`）。
   截断会让 `effective_from <= t < effective_to` 这类左闭右开比较在同一秒内判错 ——
   现象是「刚切换完服务商，新流量找不到绑定、计费失败」。
   解决：`App\Models\Concerns\HasMicrosecondTimestamps`（全部模型）+ `AppServiceProvider`
   给 MySQL 连接换上微秒精度语法器 + `App\Support\DbTime::sql()` 显式格式化。

2. **唯一 active 绑定是库层保证的，不是代码自觉。**
   `active_binding_key` 生成列（仅 active 时取 user_id）+ 唯一索引。
   所以**必须先关旧绑定再插新绑定**：关闭后生成列变 NULL，唯一索引才放行。
   该生成列绝不能进 `fillable`（生成列不可写，否则 ERROR 3105）。

3. **同一服务商重复切换由 Action 拒绝**，不会写入脏数据后靠索引报错。

4. **users 表没有 `name` 列**：Filament 顶部用户名会取 `$user->name` 得到 null 并抛类型错误，
   必须实现 `Filament\Models\Contracts\HasName::getFilamentName()`。

5. **邮箱验证默认关闭**：只实现 `MustVerifyEmail` 而不开 `->emailVerification()`，
   注册会去找不存在的 `email-verification.verify` 路由而 500。开启方式见 `App\Models\User` 注释。

6. **分享链接也脱敏**：`hysteria2://` 里的密钥默认为脱敏串，只有点了「显示明文密钥」才是可用链接，
   避免明文密钥出现在 `href` / 剪贴板 / 浏览器历史。

7. **账本是权威、钱包是缓存**：「我的积分」页同时展示余额与「按账本聚合的权威余额」，
   不一致时红色告警 —— 这是第一时间发现「有人绕过账本写钱」的探针。

8. **自定义 SQL 语法器必须显式注入连接（本工程修掉的隐藏 bug）。**
   `Illuminate\Database\Grammar` **没有构造函数**，`$this->connection` 只能靠
   `setConnection()` 注入。历史写法 `new class($connection) extends MySqlGrammar {...}`
   里的构造参数会被**静默忽略**，语法器的连接为 null，于是
   `escape()` 抛 `The database driver's grammar implementation does not support escaping values.`
   —— 平时看不出来，一旦有查询报错（Laravel 用 `substituteBindingsIntoRawSql()` 拼错误信息）
   或调用 `toRawSql()`，真实异常就会被这个次生异常盖掉，排查现场全毁。
   正确写法见 `AppServiceProvider::useMicrosecondPrecisionForMysqlBindings()`：构造后用 `setConnection($connection)`。

---

## 7. 独立主题（Theme）与品牌（用户中心 / 服务商后台各一份）

两个面板各有一份**独立主题**：`resources/css/filament/user/theme.css` 与
`resources/css/filament/provider/theme.css`，各自编译成一份产物，互不加载
（`test_注册了独立主题_且不在其它面板生效` / `test_注册了独立主题_且与用户中心各自一份` 守着这件事）。
用 Filament v3 原生主题机制，**不修改 `vendor/filament` 任何文件**：

```bash
php artisan make:filament-theme user     # 生成主题骨架（本仓库已生成并定制）
npm install                              # 若环境有 NODE_ENV=production，用 npm install --include=dev
npm run build                            # 产出 public/build/manifest.json + theme-*.css
php artisan optimize:clear
```

**三处接线（缺一不可）**

| 位置 | 内容 |
|---|---|
| `vite.config.js` | `input` 增加 `resources/css/filament/user/theme.css` |
| `app/Providers/Filament/UserPanelProvider.php` | `->viteTheme('resources/css/filament/user/theme.css')` |
| `resources/css/filament/user/tailwind.config.js` | `content` 包含本面板 PHP/Blade 与 `vendor/filament/**/*.blade.php` |

**主题文件做了什么**（`theme.css`）：

- 顶部只 `@import` Filament 官方主题 + `@config`，**不覆盖 vendor**；
- 设计令牌：`--hv-radius-card` / `--hv-shadow-card`（亮暗两套，暗色阴影更实）；
- 卡片统一圆角阴影：`.fi-section` / `.fi-wi-stats-overview-stat` / `.fi-ta-ctn` / `.fi-wi-chart` / `.fi-wi-table`；
- 表格横向滚动：`.fi-ta-table { min-width: 640px }` + `.fi-ta-content` 触控滚动；
- 自有组件类：`.hv-grid`（移动端单列 → md 起分列）、`.hv-code`（连接配置代码块）、`.hv-metric` / `.hv-metric-label` / `.hv-metric-unit`；
- 移动端媒体查询（≤640px）：统计卡、区块、工具栏收紧，图表高度 220px。

**品牌与配色**（集中在 `app/Filament/User/UserPanelTheme.php`，换资源只改这一处）

| 项 | 值 |
|---|---|
| brandName | `Hysteria VPN 用户中心` |
| brandLogo / darkModeBrandLogo | `/images/brand/user-logo.svg` · `/images/brand/user-logo-dark.svg`（占位 SVG，已落地可直接 200） |
| favicon | `/images/brand/user-favicon.svg` |
| 语义色 | `primary=Indigo` `success=Emerald` `warning=Amber` `danger=Rose` `info=Sky` `gray=Slate` |
| 暗黑模式 | `->darkMode(isForced: false)`，用户菜单内切换（`fi-theme-switcher`） |
| 中文品牌文案 | 登录/注册/重置密码页通过 `AUTH_*_FORM_BEFORE` RenderHook 注入 `filament.user.auth.tagline`，不覆盖官方页面 |

**主题隔离**：`viteTheme` 挂在 panel 上，只有 user 面板会加载该 CSS；平台后台、服务商后台各自有独立 Panel 与主题，互不影响（测试 `test_注册了独立主题_且不在其它面板生效` 断言了这一点）。

### 服务商后台主题（跟用户中心刻意区分）

服务商后台动的是**钱和节点**，用户中心动的是自己的流量。两者功能相似、后果完全不同，
所以视觉上必须一眼可分，而不是"同一个后台换个入口"：

| 项 | 用户中心 | 服务商后台 |
|---|---|---|
| 主色 | `primary=Indigo` | `primary=Teal` |
| 圆角 | `--hv-radius-card: 1rem` | `0.75rem`（服务商侧信息密度更高） |
| 统计卡 | 常规卡片 | 左侧一条主色竖条（`--hv-accent-bar`） |
| 表格 | `min-width: 640px` | `min-width: 720px`（金额/费率快照/状态列更多） |
| 数字 | 等宽 | 等宽 + `.hv-amount`（右对齐不换行） |
| 专属组件 | `.hv-code` / `.hv-grid` / `.hv-metric*` | 同左 + `.hv-ops-badge`（顶栏标识） |
| 构建产物 | `theme-D6ehCEXT.css` 112.85 kB | `theme-DZ4iLoAc.css` 112.90 kB |

顶栏常驻「服务商后台」标识：`ProviderPanelProvider` 用

```php
->renderHook(PanelsRenderHook::TOPBAR_START, fn (): View => view('filament.provider.topbar-badge'))
```

注入 `resources/views/filament/provider/topbar-badge.blade.php`（样式 `.hv-ops-badge`，小屏只留图标）。
它的作用是"防误操作"：服务商后台也有一堆看起来很熟的卡片和表格，若不标明场景，
很容易在"管收益"的界面里按用户中心的心智去点。

> **加第二个面板主题的两个必改点**（漏了会出现"样式突然缺失"）：
> 1. `vite.config.js` 的 `input` 要加新主题入口（否则 `viteTheme` 指向的文件不在 manifest 里，页面直接报错）；
> 2. 新主题目录要有配套 `tailwind.config.js`，`content` 覆盖 `./app/Filament/Provider/**/*.php`、
>    `./resources/views/filament/provider/**/*.blade.php` 与 `./vendor/filament/**/*.blade.php`
>    —— 自定义 Blade 里写的 Tailwind 类只有在 content 里才会被编译（本次的 `max-h-96`、`bg-danger-50` 就属于这类）。
> 最后 `npm run build`，并核对 manifest 里两份产物文件名不同、且互相不含对方的专属类。

**构建产物实测**

```
public/build/manifest.json                 0.44 kB
public/build/assets/theme-D6ehCEXT.css   112.85 kB   ← 自定义主题（含 Filament 全量 + 我们的令牌）
public/build/assets/app-*.css             60.50 kB
public/build/assets/app-*.js              51.52 kB
✓ built in 4.40s
```

产物中可直接验证：`--hv-radius-card`、`hv-code`、`hv-grid`、231 条 `.dark` 规则、`640px` 媒体查询、以及我们 Blade 里用到的 `md:grid-cols-3` 等工具类都已生成。

**验收命令与结果**

```bash
php artisan make:filament-theme user   # ✓ 主题骨架已生成
npm install --include=dev              # ✓ 149 packages
npm run build                          # ✓ vite v6.4.3，0 error
php artisan optimize:clear             # ✓
```

> **构建环境两个坑（都踩过）**
> 1. 本机 `NODE_ENV=production`，npm 会**静默跳过 devDependencies**（表现为 `up to date in 2s` 却只有 8 个目录）——必须加 `--include=dev`。
> 2. `make:filament-theme` 会写入 `postcss-nesting@^14`，它要求 **Node ≥ 20.19**，在 Node 18 上会报 `EBADENGINE`。
>    已把它锁到 `postcss-nesting@^13.0`（`engines: >=18`），干净重装后 **0 条 EBADENGINE**，且主题产物字节级一致（同 hash `theme-D6ehCEXT.css`，112.85 kB）。

| 验收项 | 结果 |
|---|---|
| `/user/login` 自定义品牌名 + Logo + 中文登录页 | ✓ `Hysteria VPN 用户中心` ×3、logo/favicon 链接、`Hyper Points` 文案、邮箱/密码中文 |
| 登录后 `/user` 使用自定义主色 | ✓ `--primary-500` 已注入（测试 `test_登录后的仪表盘使用自定义主色并带暗黑模式开关`） |
| 暗黑模式切换正常 | ✓ `fi-theme-switcher` 存在、`hasDarkMode()=true`、产物含 231 条 `.dark` 规则 |
| 移动端侧边栏与表格 | ✓ `sidebarCollapsibleOnDesktop()` + 表格 `min-width:640px` 横向滚动 + `hv-grid` 单列堆叠 |
| `npm run build` 无错误 | ✓ exit 0 |
| user 面板主题不影响其它面板 | ✓ 主题仅注册在 user panel（有测试断言） |
| 注册/买积分/切换服务商/看流量/看账单 | ✓ 73 个用例全绿（见 §5） |

---

## 8. 安全公告（务必处理）

`composer audit` 对 **laravel/framework v11.57.0** 报出 4 条安全公告，且 **Laravel 11 已 EOL**，
11.x 分支没有补丁版本（11.57.0 就是 11.x 的最后一版）：

| CVE / 公告 | 标题 | 影响范围 | 本工程实际暴露面 |
|---|---|---|---|
| CVE-2026-48019 | CRLF injection in default email rule | `>=11.0.0,<12.0.0` | 我们用了 `->email()` 规则；仅在开启邮箱验证并发信时可被利用 |
| CVE-2026-102279 | XSS in Debug Page Information | `<12.69.0` | 需 `APP_DEBUG=true` 且触发异常页；生产应关闭 |
| GHSA-crmm-hgp2-wgrp | Temporary Signed URL Path Confusion | `<12.61.1` | 本工程不使用签名 URL，不适用 |
| GHSA-5vg9-5847-vvmq | CRLF injection in default email rule（同一问题） | `>=11.0.0,<12.0.0` | 同上 |

**已验证的修复路径**（分支 `chore/laravel-12`，零代码改动）：

```bash
composer audit          # 升级前：Found 4 security vulnerability advisories
git checkout chore/laravel-12
php artisan test        # 29 passed (125 assertions)
composer audit          # 升级后：No security vulnerability advisories found
```

`master` 保持 Laravel 11 以满足技术栈要求；要采纳修复，执行：

```bash
git checkout master && git merge chore/laravel-12 && composer install
```

（Filament v3.3.55 的约束是 `illuminate/* ^10.45|^11.0|^12.0|^13.0`，直升 12/13 都是官方支持范围。）

---

## 9. 未包含 / 后续

- ❌ 平台运营后台（服务商审核、定价管理、抽成配置、对账）
- ⏭ 真实支付通道：`PurchasePointsAction` 目前是**模拟支付**（`payment_channel=sandbox`），
  接真实通道时把「模拟支付成功」换成带签名校验的回调，幂等键保持不变即可
- ⏭ 计费任务生产化：提供 `php artisan hysteria:bill`（调试用，逻辑与线上一致），上线挂调度器/队列
- ⏭ 服务商后台的密码重置 / 邮箱验证：当前只注册了 `->login()`（面板内可改密码）；
  要开邮件能力需先建 `password_reset_tokens` 表并在 `config/auth.php` 配 `passwords.provider_users`

---

## 10. 服务商后台（panel id = `provider`，路径 `/provider`）

### 10.1 认证与多租户：两道锁

| 层 | 做法 | 拦住的攻击 |
|---|---|---|
| 认证 | 独立 guard `provider` → `provider_users` 表（`config/auth.php`）；`ProviderPanelProvider::authGuard('provider')` | 用户端凭证无法进服务商后台；两个面板可在同一浏览器同时登录（session 键按 guard 隔离） |
| 租户 | `->tenant(Provider::class, slugAttribute: 'code')`；`ProviderUser implements HasTenants`，`canAccessTenant()` 只认 `provider_id` 相等 | 改 URL 里的 `{tenant}` 去访问别家 → 403/404 |

`provider_users` 是**增量迁移**新建的表（架构师 DDL 里没有），字段：`id / provider_id / name / email / password / status / remember_token / last_login_at + DATETIME(6) 时间列`，
外键 `provider_id → providers(id) ON DELETE CASCADE`。密码列用标准 `password`（不做列名映射），
哈希统一在 Action/Seeder 里 `Hash::make`，模型上**不挂 hashed cast** —— 避免 Filament 的 password 字段
`dehydrateStateUsing(Hash::make)` 再哈希一次导致「永远登录不上」（有测试 `test_修改密码页面会正确哈希并拒绝错误的当前密码` 守着）。

### 10.2 数据隔离：三层，缺一不可

1. **Filament tenancy**：模型有 `provider()` 关系 ⇒ Resource 查询自动收窄；
2. **`ScopedToProvider` trait**：`getEloquentQuery()` 显式 `where provider_id = 当前租户`，
   没有租户上下文时返回 `whereRaw('1 = 0')`（出错宁可「看不到」，绝不返回全量）；
3. **Policy**：单条记录再判归属（`view/update`）；表单里的节点下拉、用户搜索也全部按 `provider_id` 过滤。

**归属校验在 Action 内部再来一次**：`ToggleNodeStatusAction` / `RegenerateNodeKeyAction` / `UpdatePricingAction`
都用 `ofProvider($providerId)->whereKey($id)` 取记录，拿别人的 id 过来只会得到「节点不存在或不属于当前服务商」。

### 10.3 面板能力一览

| 分组 | 页面 | 写权限 |
|---|---|---|
| 概览 | Dashboard：今日/本月流量、本月应得 Points、余额、在线节点数 + 30 天流量趋势 + 收益趋势 + 最近流水/账单 | — |
| 节点管理 | 节点列表 / 详情 / 新增 / 编辑；行内动作：测试连通性、重新生成密钥、启用/维护/停用/下线、查看（明文）节点配置 | 可增可改，**不可删**（节点承载历史账单） |
| 定价管理 | 定价规则列表 + 新增定价（改价） | 只能**新增版本**；不可编辑/删除；未生效的可作废 |
| 流量与用户 | 流量用量、流量原始明细、我的用户、用户流量排行 Top 20 | 全只读 |
| 收益 | 我的 Points（卡片）、Points 流水、计费明细 | 全只读 |
| 结算 | 结算条款（只读 + 申请调整）、结算记录（发起结算 + 明细） | 可发起结算，创建后不可改 |
| 设置 | 服务商资料、API 凭证（脱敏 + 重新生成）、修改密码 | 只能改自己的资料字段 |

### 10.4 四个关键业务规则

1. **改价只增不改**：`UpdatePricingAction` 在事务里锁住该 provider+node 的规则，
   校验左闭右开区间：新规则起点不能早于既有规则（会出负区间）、有终点时不能切进既有规则内部（会挖洞）；
   通过后**新增一条**并把被覆盖的旧规则 `effective_to` 截断到新起点 —— 旧记录的价格字段一个字节都不改，
   所以历史账单永远能凭 `usage_ledger.pricing_rule_id` 回溯当时的价格。
2. **结算不重复抽成**：`usage_ledger.provider_points_amount = user_points_amount - platform_points_amount`，
   抽成在**计费那一刻**已按「流量发生时刻」的条款快照扣掉了。所以结算时
   `points_amount = Σ provider_points_amount`（毛额），`commission_points = Σ platform_points_amount`（仅展示），
   `net_points = points_amount - 打款手续费 - 税费`，`fiat_amount = net_points × points_exchange_rate`。
   如果再按 `commission_rate` 抽一次就是抽两遍。
3. **结算防双结**：候选明细必须 `status=charged AND is_reversal=0 AND settlement_id IS NULL AND period_end <= now - hold_days`，
   行锁 + `uk_psi_usage(usage_ledger_id)` + `uk_ps_provider_period` 三道保险；
   一次事务里完成「汇总 → 写结算单 → 写明细 → 回写 `usage_ledger.settlement_id` → 账本 debit → 扣钱包」。
4. **密钥全链路加密**：节点密钥（`provider_nodes.config.auth_secret_encrypted`）、
   服务商 API 密钥（`providers.api_secret_encrypted`）都用 `Crypt` 加密存储；
   页面永远只显示「首4位+8个星号+末4位」，明文只在「新建 / 重新生成」那一刻弹一次通知。

### 10.5 独立主题与顶栏标识

服务商后台有**自己的一份 vite 主题**（`resources/css/filament/provider/theme.css`，
产物 `theme-DZ4iLoAc.css`），与用户中心那份完全独立：主色 Teal、圆角更小、
统计卡带主色竖条、表格 `min-width: 720px`（详见 §7）。
`ProviderPanelProvider` 还通过 `PanelsRenderHook::TOPBAR_START` 在顶栏常驻一个
「服务商后台」标识（`.hv-ops-badge`）—— 防的是"在管收益的界面里用用户中心的心智去点"。

### 10.6 服务商后台验收

```bash
php artisan test tests/Feature/ProviderPanelTest.php       # 26 个用例（业务与隔离）
php artisan test tests/Feature/ProviderPanelThemeTest.php  # 8 个用例（主题 / 品牌 / 双面板不串台）
```

| 验收项 | 用例要点 |
|---|---|
| 能登录 | 邮箱密码走 Filament 登录页真登录（独立 guard）；密码错误被拒；`/provider` 302 → `/provider/login`；登录后 302 → `/provider/{code}` |
| 能加节点 | `CreateNodeAction` 生成密钥且密文落库（明文不落库）；同服务商 host:port 重复被拒；不同服务商允许同地址 |
| 能改定价 | 新增即截断旧规则且旧价不变；区间落在既有规则内部被拒；未来时间排期生效且当前仍命中旧价 |
| 能看流量/收益 | 19 个页面全部 200；节点列表/计费明细只显示自己的记录（Livewire 表格断言） |
| 能发起结算 | 结算单/明细/账本/钱包四件套 + `settlement_id` 回写；未达起结门槛被拒；冻结期内被拒；同区间不能重复结算 |
| 数据隔离 | 跨租户 403/404；别人的节点详情 404；Policy 断言「账本/计费/流量/绑定一律不可写」 |
| 主题与品牌 | provider 面板注册独立主题（与 user 不同文件）；品牌/语义色/暗黑模式；顶栏「服务商后台」标识只在 provider 出现；两份构建产物互不含对方的专属类 |
| 停用账号 | `canAccessPanel()=false` → 403 |
