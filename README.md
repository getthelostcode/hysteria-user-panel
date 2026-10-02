# Hysteria VPN 聚合平台 · 用户后台（Laravel 11 + Filament v3）

平台只发行 **Hyper Points**：用户用法币买 Points，按 Hysteria 实际流量扣 Points，
Points 在服务商之间按「多少 Points = 1GB」的定价结算。本工程只实现**普通用户后台**。

> 平台运营后台、服务商后台 **不在本工程范围内**。

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
# 打开 http://127.0.0.1:8088/user
```

**演示账号**（`db:seed` 创建）

| 邮箱 | 密码 | 数据 |
|---|---|---|
| `demo@example.com` | `password` | 6500 Points 充值、30 天流量、已切过一次服务商、109 条账单 |
| `demo2@example.com` | `password` | 干净账号（验证数据隔离） |

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

php artisan test        # 39 个用例
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
| 主题与品牌 | 独立主题仅注册在 user 面板；品牌名/Logo/favicon/语义色/暗黑模式；登录注册重置页中文品牌文案；状态徽章文字+图标+颜色三件套；Points 千分位格式化 |

真实 HTTP 冒烟（非测试环境）：

```bash
curl -I http://127.0.0.1:8088/          # 302 → /user
curl -I http://127.0.0.1:8088/user      # 302 → /user/login
curl -s  http://127.0.0.1:8088/user/login | grep -c Hysteria
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

---

## 7. 独立主题（Theme）与品牌

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
| 注册/买积分/切换服务商/看流量/看账单 | ✓ 39 个用例全绿（见 §5） |

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
- ❌ 服务商后台
- ⏭ 真实支付通道：`PurchasePointsAction` 目前是**模拟支付**（`payment_channel=sandbox`），
  接真实通道时把「模拟支付成功」换成带签名校验的回调，幂等键保持不变即可
- ⏭ 计费任务生产化：提供 `php artisan hysteria:bill`（调试用，逻辑与线上一致），上线挂调度器/队列
