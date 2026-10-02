-- =============================================================================
-- Hysteria VPN 聚合平台 · 基础表结构（由 MySQL 数据库架构师设计）
-- 来源：hysteria-node-agent/sql/01..06（DDL 原样保留，未做任何改动）
-- 用法： mysql -h127.0.0.1 -P3306 -uroot hysteria < database/schema/hysteria_schema.sql
--       然后执行 php artisan migrate （只补充 Laravel 认证所需字段）
-- =============================================================================
-- =============================================================================
-- 01 · 用户与积分（Hyper Points）
-- 核心：用户表不存 current_provider_id；钱包只是缓存；所有 points 变动走账本
-- =============================================================================

-- -----------------------------------------------------------------------------
-- users · 用户表
-- 设计要点：刻意不设 current_provider_id 字段。
--           当前服务商 = user_provider_bindings 中 status='active' 的那一条。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  uuid            CHAR(36)        NOT NULL COMMENT '对外唯一标识(UUIDv4)',
  username        VARCHAR(64)     NOT NULL COMMENT '登录名',
  email           VARCHAR(190)    NULL     COMMENT '邮箱(唯一,可空)',
  phone           VARCHAR(32)     NULL     COMMENT '手机号(唯一,可空)',
  password_hash   VARCHAR(255)    NOT NULL COMMENT '密码哈希',
  status          ENUM('active','suspended','banned','deleted') NOT NULL DEFAULT 'active' COMMENT '账号状态',
  locale          VARCHAR(16)     NOT NULL DEFAULT 'zh-CN' COMMENT '语言/区域',
  registered_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '注册时间(UTC)',
  last_login_at   DATETIME(6)     NULL     COMMENT '最后登录(UTC)',
  created_at      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_uuid     (uuid),
  UNIQUE KEY uk_users_username (username),
  UNIQUE KEY uk_users_email    (email),   -- MySQL 唯一索引允许多个 NULL，可空列照常唯一
  UNIQUE KEY uk_users_phone    (phone),
  KEY idx_users_status (status, registered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC COMMENT='用户表';

-- -----------------------------------------------------------------------------
-- user_points_wallets · 用户 points 钱包（缓存层，权威值来自 user_points_ledger）
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_points_wallets (
  user_id          BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  balance          DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '可用余额(缓存,权威=ledger聚合)',
  frozen           DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '冻结(预扣)',
  total_recharged  DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '累计充值',
  total_consumed   DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '累计消费',
  last_ledger_id   BIGINT UNSIGNED NULL COMMENT '最后一条账本分录(对账用)',
  version          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '乐观锁版本',
  updated_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (user_id),
  CONSTRAINT fk_upw_user      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT ck_upw_balance   CHECK (balance >= 0),
  CONSTRAINT ck_upw_frozen    CHECK (frozen  >= 0),
  CONSTRAINT ck_upw_recharged CHECK (total_recharged >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户 points 钱包(缓存层)';

-- -----------------------------------------------------------------------------
-- points_packages · points 售卖套餐（法币计价）
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS points_packages (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(64)     NOT NULL COMMENT '套餐编码',
  name           VARCHAR(128)    NOT NULL COMMENT '套餐名',
  base_points    DECIMAL(30,8)   NOT NULL COMMENT '基础 points',
  bonus_points   DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '赠送 points',
  price_amount   DECIMAL(30,8)   NOT NULL COMMENT '法币售价',
  currency       CHAR(3)         NOT NULL DEFAULT 'USD' COMMENT 'ISO-4217',
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order     INT             NOT NULL DEFAULT 0,
  effective_from DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '上架时间(UTC)',
  effective_to   DATETIME(6)     NULL COMMENT '下架时间,NULL=长期;[from,to)',
  metadata       JSON            NULL COMMENT '扩展(限购/渠道等)',
  created_at     DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at     DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_pp_code (code),
  KEY idx_pp_status (status, sort_order),
  CONSTRAINT ck_pp_price  CHECK (price_amount > 0),
  CONSTRAINT ck_pp_points CHECK (base_points >= 0 AND bonus_points >= 0),
  CONSTRAINT ck_pp_win    CHECK (effective_to IS NULL OR effective_to > effective_from),
  CONSTRAINT ck_pp_meta   CHECK (metadata IS NULL OR JSON_VALID(metadata))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='points 售卖套餐';

-- -----------------------------------------------------------------------------
-- points_orders · 法币购买 points 订单
-- 关键唯一键：payment_channel + payment_ref ⇒ 同一支付流水不可能重复入账
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS points_orders (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no         VARCHAR(40)     NOT NULL COMMENT '业务单号',
  user_id          BIGINT UNSIGNED NOT NULL,
  package_id       BIGINT UNSIGNED NULL COMMENT '套餐(可空:自定义充值)',
  base_points      DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000,
  bonus_points     DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000,
  points_amount    DECIMAL(30,8)   NOT NULL COMMENT '本单到账总量=base+bonus',
  fiat_amount      DECIMAL(30,8)   NOT NULL COMMENT '实付法币金额',
  fiat_currency    CHAR(3)         NOT NULL COMMENT '法币币种',
  payment_method   VARCHAR(32)     NOT NULL COMMENT 'alipay/usdt/card...',
  payment_channel  VARCHAR(32)     NULL COMMENT '支付通道',
  payment_ref      VARCHAR(128)    NULL COMMENT '第三方支付流水号',
  status           ENUM('pending','paid','failed','cancelled','refunding','refunded') NOT NULL DEFAULT 'pending',
  idempotency_key  VARCHAR(128)    NULL COMMENT '下单幂等键',
  ledger_id        BIGINT UNSIGNED NULL COMMENT '入账的 user_points_ledger.id',
  raw_payload      JSON            NULL COMMENT '支付回调原文',
  expire_at        DATETIME(6)     NULL COMMENT '订单过期(UTC)',
  paid_at          DATETIME(6)     NULL,
  refunded_at      DATETIME(6)     NULL,
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_po_order_no (order_no),
  UNIQUE KEY uk_po_idem     (idempotency_key),
  UNIQUE KEY uk_po_pay_ref  (payment_channel, payment_ref),
  KEY idx_po_user (user_id, status, created_at),
  CONSTRAINT fk_po_user    FOREIGN KEY (user_id)    REFERENCES users(id),
  CONSTRAINT fk_po_package FOREIGN KEY (package_id) REFERENCES points_packages(id),
  CONSTRAINT ck_po_amount  CHECK (fiat_amount >= 0 AND points_amount >= 0),
  CONSTRAINT ck_po_sum     CHECK (points_amount = base_points + bonus_points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='法币购买 points 订单';

-- -----------------------------------------------------------------------------
-- user_points_ledger · 用户 points 账本（不可变；撤销走红冲 reversal_of_id）
-- 权威余额 = SUM(signed_amount)；钱包的 balance 只是它的缓存
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_points_ledger (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  biz_type         ENUM('recharge','bonus','usage','refund','adjust','expire','reversal') NOT NULL COMMENT '业务类型',
  direction        ENUM('credit','debit') NOT NULL COMMENT 'credit=加,debit=减',
  amount           DECIMAL(30,8)   NOT NULL COMMENT '金额,恒为正',
  signed_amount    DECIMAL(30,8)   GENERATED ALWAYS AS
                     (CASE WHEN direction = 'credit' THEN amount ELSE -amount END) STORED
                     COMMENT '有符号金额,SUM(signed_amount)即权威余额',
  balance_after    DECIMAL(30,8)   NOT NULL COMMENT '该分录后余额快照',
  biz_ref_type     VARCHAR(32)     NULL COMMENT 'usage_ledger/points_orders/...',
  biz_ref_id       BIGINT UNSIGNED NULL COMMENT '关联业务主键',
  idempotency_key  VARCHAR(128)    NULL COMMENT '幂等键,唯一',
  reversal_of_id   BIGINT UNSIGNED NULL COMMENT '红冲:指向被冲销的原分录',
  remark           VARCHAR(255)    NULL,
  metadata         JSON            NULL,
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '记账时间(UTC)',
  PRIMARY KEY (id),
  UNIQUE KEY uk_upl_idem (idempotency_key),
  UNIQUE KEY uk_upl_biz  (biz_type, biz_ref_type, biz_ref_id, user_id, direction),
  KEY idx_upl_user_time  (user_id, created_at),
  KEY idx_upl_ref        (biz_ref_type, biz_ref_id),
  KEY idx_upl_reversal   (reversal_of_id),
  CONSTRAINT fk_upl_user     FOREIGN KEY (user_id)        REFERENCES users(id),
  CONSTRAINT fk_upl_reversal FOREIGN KEY (reversal_of_id) REFERENCES user_points_ledger(id),
  CONSTRAINT ck_upl_amount  CHECK (amount > 0),
  CONSTRAINT ck_upl_balance CHECK (balance_after >= 0)
  -- 注意:不能写 CHECK (reversal_of_id <> id)，MySQL 禁止 CHECK 约束引用 AUTO_INCREMENT 列
  --       (ERROR 3818)，"红冲不能指向自己"由应用层校验。
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户 points 账本(不可变,红冲修正)';
-- =============================================================================
-- 02 · 服务商与节点（含版本化定价、版本化绑定）
-- =============================================================================

-- -----------------------------------------------------------------------------
-- providers · Hysteria 服务商
-- default_commission_rate 仅作展示；**计费一律以 provider_settlement_terms 为准**
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS providers (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                     VARCHAR(64)     NOT NULL COMMENT '服务商编码',
  name                     VARCHAR(128)    NOT NULL,
  contact_email            VARCHAR(190)    NULL,
  contact_telegram         VARCHAR(64)     NULL,
  status                   ENUM('pending','active','suspended','terminated') NOT NULL DEFAULT 'pending',
  api_endpoint             VARCHAR(255)    NULL COMMENT '上报/管理 API 基址',
  api_secret_encrypted     VARBINARY(512)  NULL COMMENT '服务商级 API 密钥(应用层加密存储)',
  default_commission_rate  DECIMAL(9,6)    NOT NULL DEFAULT 0.200000 COMMENT '默认抽成(展示用)',
  settlement_cycle         ENUM('weekly','biweekly','monthly','manual') NOT NULL DEFAULT 'monthly',
  metadata                 JSON            NULL,
  created_at               DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at               DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_providers_code (code),
  KEY idx_providers_status (status),
  CONSTRAINT ck_providers_comm CHECK (default_commission_rate >= 0 AND default_commission_rate < 1),
  CONSTRAINT ck_providers_meta CHECK (metadata IS NULL OR JSON_VALID(metadata))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Hysteria 服务商';

-- -----------------------------------------------------------------------------
-- provider_nodes · 服务商节点
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_nodes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id   BIGINT UNSIGNED NOT NULL,
  node_code     VARCHAR(64)     NOT NULL COMMENT '服务商侧节点标识(上报用)',
  name          VARCHAR(128)    NULL,
  region        VARCHAR(64)     NULL COMMENT '区域',
  country_code  CHAR(2)         NULL COMMENT 'ISO-3166-1 alpha2',
  host          VARCHAR(255)    NULL,
  port          SMALLINT UNSIGNED NULL,
  protocol      VARCHAR(32)     NOT NULL DEFAULT 'hysteria2',
  capacity_mbps INT UNSIGNED    NULL COMMENT '带宽上限',
  status        ENUM('active','maintenance','offline','disabled') NOT NULL DEFAULT 'active',
  tags          JSON            NULL COMMENT '标签数组',
  config        JSON            NULL COMMENT '节点扩展配置',
  last_seen_at  DATETIME(6)     NULL COMMENT '最后心跳(UTC)',
  created_at    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_pn_provider_code (provider_id, node_code),
  KEY idx_pn_status (status, provider_id),
  KEY idx_pn_lastseen (last_seen_at),
  CONSTRAINT fk_pn_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  CONSTRAINT ck_pn_tags CHECK (tags   IS NULL OR JSON_VALID(tags)),
  CONSTRAINT ck_pn_cfg  CHECK (config IS NULL OR JSON_VALID(config))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='服务商节点';

-- -----------------------------------------------------------------------------
-- provider_pricing_rules · 定价规则（版本化：改价只新增，绝不改旧记录）
--   node_id NULL    = 服务商级默认价
--   node_id 非 NULL = 节点覆盖价（优先级高于默认价）
--   生效区间 [effective_from, effective_to)，effective_to 为 NULL 表示长期
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_pricing_rules (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id       BIGINT UNSIGNED NOT NULL COMMENT '服务商',
  node_id           BIGINT UNSIGNED NULL COMMENT 'NULL=服务商级默认价;非空=节点覆盖价',
  points_per_gb     DECIMAL(30,8)   NOT NULL COMMENT '多少 Hyper Points = 1GB',
  upload_ratio      DECIMAL(10,6)   NOT NULL DEFAULT 1.000000 COMMENT '上行计费系数',
  download_ratio    DECIMAL(10,6)   NOT NULL DEFAULT 1.000000 COMMENT '下行计费系数',
  min_charge_points DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '单次计费最低扣费',
  priority          INT             NOT NULL DEFAULT 0 COMMENT '数值越大越优先',
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  effective_from    DATETIME(6)     NOT NULL COMMENT '生效起点(含),UTC',
  effective_to      DATETIME(6)     NULL COMMENT '失效终点(不含),NULL=长期',
  created_by        BIGINT UNSIGNED NULL COMMENT '操作人',
  remark            VARCHAR(255)    NULL,
  metadata          JSON            NULL,
  created_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_ppr_match_default (provider_id, status, effective_from, effective_to),
  KEY idx_ppr_match_node    (provider_id, node_id, status, effective_from, effective_to),
  KEY idx_ppr_priority      (provider_id, node_id, priority),
  CONSTRAINT fk_ppr_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ppr_node     FOREIGN KEY (node_id)     REFERENCES provider_nodes(id) ON DELETE CASCADE,
  CONSTRAINT ck_ppr_ppg   CHECK (points_per_gb >= 0),
  CONSTRAINT ck_ppr_up    CHECK (upload_ratio >= 0),
  CONSTRAINT ck_ppr_down  CHECK (download_ratio >= 0),
  CONSTRAINT ck_ppr_min   CHECK (min_charge_points >= 0),
  CONSTRAINT ck_ppr_win   CHECK (effective_to IS NULL OR effective_to > effective_from),
  CONSTRAINT ck_ppr_meta  CHECK (metadata IS NULL OR JSON_VALID(metadata))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='服务商定价规则(版本化,改价只增不改)';

-- -----------------------------------------------------------------------------
-- user_provider_bindings · 用户-服务商绑定（版本化 + 唯一 active）
--
-- MySQL 没有 PostgreSQL 的 EXCLUDE 排他约束，用「STORED 生成列 + 唯一索引」
-- 实现"部分唯一索引"效果：仅 status='active' 时生成列取 user_id，其余为 NULL，
-- 而唯一索引允许多个 NULL ⇒ 同一用户最多只能有 1 条 active 绑定。
--
-- 注意：active_binding_key 是生成列，INSERT/UPDATE 时**必须省略该列**，
--       显式写入会报 ERROR 3105。
-- 注意：关闭旧绑定(M->status='closed')后生成列变 NULL，唯一索引才不再拦截，
--       所以"先关旧、再插新"的顺序不能颠倒。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_provider_bindings (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                BIGINT UNSIGNED NOT NULL,
  provider_id            BIGINT UNSIGNED NOT NULL,
  external_user_id       VARCHAR(128)    NOT NULL COMMENT '服务商侧用户标识',
  auth_secret_encrypted  VARBINARY(512)  NOT NULL COMMENT '服务商侧鉴权密钥(应用层加密)',
  status                 ENUM('pending','active','suspended','closed') NOT NULL DEFAULT 'pending',
  active_binding_key     BIGINT UNSIGNED GENERATED ALWAYS AS
                           (CASE WHEN status = 'active' THEN user_id ELSE NULL END) STORED
                           COMMENT '唯一约束载体(生成列,禁止显式写入)',
  effective_from         DATETIME(6)     NOT NULL COMMENT '绑定生效起点(含),UTC',
  effective_to           DATETIME(6)     NULL COMMENT '绑定结束终点(不含),NULL=至今',
  switch_from_binding_id BIGINT UNSIGNED NULL COMMENT '从哪条绑定切换而来',
  metadata               JSON            NULL,
  created_at             DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at             DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_user_active_binding (active_binding_key) COMMENT '一用户同时仅一条 active 绑定',
  KEY idx_upb_user_time         (user_id, effective_from, effective_to),
  KEY idx_upb_provider_status   (provider_id, status, effective_from),
  KEY idx_upb_provider_external (provider_id, external_user_id),
  CONSTRAINT fk_upb_user     FOREIGN KEY (user_id)     REFERENCES users(id),
  CONSTRAINT fk_upb_provider FOREIGN KEY (provider_id) REFERENCES providers(id),
  CONSTRAINT fk_upb_prev     FOREIGN KEY (switch_from_binding_id) REFERENCES user_provider_bindings(id),
  CONSTRAINT ck_upb_win  CHECK (effective_to IS NULL OR effective_to > effective_from),
  CONSTRAINT ck_upb_open CHECK (status <> 'active' OR effective_to IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户-服务商绑定(版本化,唯一 active)';

-- -----------------------------------------------------------------------------
-- provider_switch_logs · 服务商切换审计
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_switch_logs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  from_binding_id  BIGINT UNSIGNED NULL COMMENT '旧绑定(首次绑定时为空)',
  to_binding_id    BIGINT UNSIGNED NOT NULL COMMENT '新绑定',
  from_provider_id BIGINT UNSIGNED NULL,
  to_provider_id   BIGINT UNSIGNED NOT NULL,
  effective_at     DATETIME(6)     NOT NULL COMMENT '切换生效时刻(UTC)',
  status           ENUM('success','failed','rolled_back') NOT NULL DEFAULT 'success',
  operator_type    ENUM('user','admin','system') NOT NULL DEFAULT 'user',
  operator_id      BIGINT UNSIGNED NULL,
  reason           VARCHAR(255)    NULL,
  detail           JSON            NULL COMMENT '请求/响应详情',
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_psl_user (user_id, effective_at),
  KEY idx_psl_provider (to_provider_id, effective_at),
  CONSTRAINT fk_psl_user      FOREIGN KEY (user_id)          REFERENCES users(id),
  CONSTRAINT fk_psl_from_bind FOREIGN KEY (from_binding_id)  REFERENCES user_provider_bindings(id),
  CONSTRAINT fk_psl_to_bind   FOREIGN KEY (to_binding_id)    REFERENCES user_provider_bindings(id),
  CONSTRAINT fk_psl_from_prov FOREIGN KEY (from_provider_id) REFERENCES providers(id),
  CONSTRAINT fk_psl_to_prov   FOREIGN KEY (to_provider_id)   REFERENCES providers(id),
  CONSTRAINT ck_psl_detail CHECK (detail IS NULL OR JSON_VALID(detail))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='服务商切换审计';
-- =============================================================================
-- 03 · 服务商 points（钱包 + 账本）
-- 放在 usage_ledger 之前：usage_ledger 需要外键引用 provider_points_ledger
-- =============================================================================

-- -----------------------------------------------------------------------------
-- provider_points_wallets · 服务商 points 钱包（缓存层）
-- balance = 累计应得 - 已结算（含冻结）
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_points_wallets (
  provider_id    BIGINT UNSIGNED NOT NULL,
  balance        DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '可用(累计赚取-已结算,缓存)',
  total_earned   DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '累计赚取',
  total_settled  DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '累计已结算',
  frozen         DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '结算冻结中',
  last_ledger_id BIGINT UNSIGNED NULL,
  version        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at     DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (provider_id),
  CONSTRAINT fk_ppw_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  CONSTRAINT ck_ppw_balance  CHECK (balance >= 0),
  CONSTRAINT ck_ppw_frozen   CHECK (frozen  >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='服务商 points 钱包(缓存层)';

-- -----------------------------------------------------------------------------
-- provider_points_ledger · 服务商 points 账本（不可变，红冲修正）
--   usage_earning : 每笔计费给服务商记应得（credit）
--   settlement    : 结算时把应得转出（debit）
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_points_ledger (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id     BIGINT UNSIGNED NOT NULL,
  biz_type        ENUM('usage_earning','settlement','adjust','reversal') NOT NULL COMMENT '赚取/结算转出/调整/红冲',
  direction       ENUM('credit','debit') NOT NULL,
  amount          DECIMAL(30,8)   NOT NULL COMMENT '恒为正',
  signed_amount   DECIMAL(30,8)   GENERATED ALWAYS AS
                    (CASE WHEN direction = 'credit' THEN amount ELSE -amount END) STORED,
  balance_after   DECIMAL(30,8)   NOT NULL COMMENT '该分录后余额快照',
  biz_ref_type    VARCHAR(32)     NULL COMMENT 'usage_ledger/provider_settlements',
  biz_ref_id      BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(128)    NULL,
  reversal_of_id  BIGINT UNSIGNED NULL,
  remark          VARCHAR(255)    NULL,
  metadata        JSON            NULL,
  created_at      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_ppl_idem (idempotency_key),
  UNIQUE KEY uk_ppl_biz  (biz_type, biz_ref_type, biz_ref_id, provider_id, direction),
  KEY idx_ppl_provider_time (provider_id, created_at),
  KEY idx_ppl_ref (biz_ref_type, biz_ref_id),
  CONSTRAINT fk_ppl_provider FOREIGN KEY (provider_id)     REFERENCES providers(id),
  CONSTRAINT fk_ppl_reversal FOREIGN KEY (reversal_of_id)  REFERENCES provider_points_ledger(id),
  CONSTRAINT ck_ppl_amount   CHECK (amount > 0),
  CONSTRAINT ck_ppl_balance  CHECK (balance_after >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='服务商 points 账本(不可变)';
-- =============================================================================
-- 04 · 流量（原始明细 · 全局幂等 · 小时聚合）
-- =============================================================================

-- -----------------------------------------------------------------------------
-- traffic_raw · 流量原始明细
--
-- 【MySQL 分区硬限制，务必理解】
--  1. 分区表**不能建外键**，别的表也不能外键引用分区表。
--     ⇒ provider_id / node_id / user_id / binding_id 全部由应用层保证，
--       并配合 09_reconciliation_queries.sql 的孤儿数据巡检兜底。
--  2. 分区表上**所有唯一键（含主键）必须包含分区列** occurred_at。
--     ⇒ UNIQUE(idempotency_key, occurred_at) 只在"分区内"唯一，
--       跨月重放的上报理论上可以重复插入 ⇒ 用 traffic_ingest_idempotency 兜底。
--  3. AUTO_INCREMENT 列必须是某个索引的首列 ⇒ PRIMARY KEY(id, occurred_at) 满足。
--  4. 分区裁剪要求 WHERE 直接比较分区列：occurred_at >= ? AND occurred_at < ?；
--     写成 WHERE DATE(occurred_at) = ? 会导致全分区扫描。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS traffic_raw (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id      BIGINT UNSIGNED NOT NULL,
  node_id          BIGINT UNSIGNED NOT NULL,
  user_id          BIGINT UNSIGNED NOT NULL,
  binding_id       BIGINT UNSIGNED NOT NULL COMMENT '按 occurred_at 解析出的绑定快照',
  session_id       VARCHAR(128)    NOT NULL COMMENT 'Hysteria 会话ID',
  external_user_id VARCHAR(128)    NULL COMMENT '服务商侧用户ID(原样保留)',
  occurred_at      DATETIME(6)     NOT NULL COMMENT '流量发生时间(UTC)=分区键',
  period_start     DATETIME(6)     NOT NULL COMMENT '本条覆盖窗口起点(UTC)',
  period_end       DATETIME(6)     NOT NULL COMMENT '本条覆盖窗口终点(UTC)',
  upload_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上行字节',
  download_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下行字节',
  total_bytes      BIGINT UNSIGNED GENERATED ALWAYS AS (upload_bytes + download_bytes) STORED COMMENT '总字节',
  idempotency_key  VARCHAR(128)    NOT NULL COMMENT '幂等键=SHA2(provider|node|binding|session|period_start|period_end)',
  source           ENUM('node_push','node_pull','reconcile','manual') NOT NULL DEFAULT 'node_push',
  raw_payload      JSON            NULL COMMENT '节点上报原文',
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id, occurred_at),
  UNIQUE KEY uk_tr_idem    (idempotency_key, occurred_at) COMMENT '分区内唯一,全局唯一见 traffic_ingest_idempotency',
  KEY idx_tr_provider_time (provider_id, occurred_at),
  KEY idx_tr_node_time     (node_id, occurred_at),
  KEY idx_tr_user_time     (user_id, occurred_at),
  KEY idx_tr_binding_time  (binding_id, occurred_at),
  KEY idx_tr_session       (session_id),
  CONSTRAINT ck_tr_win     CHECK (period_end > period_start),
  CONSTRAINT ck_tr_payload CHECK (raw_payload IS NULL OR JSON_VALID(raw_payload))
  -- 注意：无外键（分区表限制），见文件头说明
)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='流量原始明细(按月分区,无外键)'
PARTITION BY RANGE COLUMNS(occurred_at) (
  PARTITION p202601 VALUES LESS THAN ('2026-02-01 00:00:00'),
  PARTITION p202602 VALUES LESS THAN ('2026-03-01 00:00:00'),
  PARTITION p202603 VALUES LESS THAN ('2026-04-01 00:00:00'),
  PARTITION p202604 VALUES LESS THAN ('2026-05-01 00:00:00'),
  PARTITION p202605 VALUES LESS THAN ('2026-06-01 00:00:00'),
  PARTITION p202606 VALUES LESS THAN ('2026-07-01 00:00:00'),
  PARTITION p202607 VALUES LESS THAN ('2026-08-01 00:00:00'),
  PARTITION p202608 VALUES LESS THAN ('2026-09-01 00:00:00'),
  PARTITION p202609 VALUES LESS THAN ('2026-10-01 00:00:00'),
  PARTITION p202610 VALUES LESS THAN ('2026-11-01 00:00:00'),
  PARTITION p202611 VALUES LESS THAN ('2026-12-01 00:00:00'),
  PARTITION p202612 VALUES LESS THAN ('2027-01-01 00:00:00'),
  PARTITION pmax    VALUES LESS THAN (MAXVALUE)   -- 兜底，每月用 REORGANIZE 前滚
);

-- -----------------------------------------------------------------------------
-- traffic_ingest_idempotency · 上报全局幂等闸门（非分区小表）
-- 用法：先 INSERT IGNORE 本表；受影响行数=0 ⇒ 重复上报，直接丢弃。
--       这条路径同时解决"同一条上报跨月写入导致分区内唯一键失效"的问题。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS traffic_ingest_idempotency (
  idempotency_key VARCHAR(128)    NOT NULL,
  provider_id     BIGINT UNSIGNED NOT NULL,
  node_id         BIGINT UNSIGNED NOT NULL,
  traffic_raw_id  BIGINT UNSIGNED NULL COMMENT '成功落库后的明细ID',
  occurred_at     DATETIME(6)     NOT NULL,
  created_at      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (idempotency_key),
  KEY idx_tii_time (occurred_at),
  KEY idx_tii_provider (provider_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='流量上报全局幂等键(可定期清理90天前)';

-- -----------------------------------------------------------------------------
-- traffic_usage_hourly · 小时聚合桶
-- 唯一键 (binding_id, node_id, period_start) 是 UPSERT 落点，保证聚合幂等。
-- 本表非分区，保留外键；若写入 QPS 极高可去掉外键换性能（应用层保证）。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS traffic_usage_hourly (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  provider_id      BIGINT UNSIGNED NOT NULL,
  node_id          BIGINT UNSIGNED NOT NULL,
  binding_id       BIGINT UNSIGNED NOT NULL,
  period_start     DATETIME(6)     NOT NULL COMMENT '小时桶起点(UTC整点)',
  period_end       DATETIME(6)     NOT NULL COMMENT '小时桶终点=+1h',
  upload_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  download_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_bytes      BIGINT UNSIGNED GENERATED ALWAYS AS (upload_bytes + download_bytes) STORED,
  raw_record_count INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '聚合的原始条数(对账)',
  billed_status    ENUM('pending','billed','skipped','failed') NOT NULL DEFAULT 'pending',
  usage_ledger_id  BIGINT UNSIGNED NULL COMMENT '已生成的计费明细ID',
  billed_at        DATETIME(6)     NULL,
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_tuh_bucket (binding_id, node_id, period_start),
  KEY idx_tuh_user_time     (user_id, period_start),
  KEY idx_tuh_provider_time (provider_id, period_start),
  KEY idx_tuh_pending       (billed_status, period_end),
  CONSTRAINT fk_tuh_user     FOREIGN KEY (user_id)    REFERENCES users(id),
  CONSTRAINT fk_tuh_provider FOREIGN KEY (provider_id) REFERENCES providers(id),
  CONSTRAINT fk_tuh_node     FOREIGN KEY (node_id)    REFERENCES provider_nodes(id),
  CONSTRAINT fk_tuh_binding  FOREIGN KEY (binding_id) REFERENCES user_provider_bindings(id),
  CONSTRAINT ck_tuh_win      CHECK (period_end > period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='流量小时聚合桶';
-- =============================================================================
-- 05 · 计费明细 usage_ledger
-- 一条小时聚合桶 → 一条计费明细；所有费率都是"当时快照"，事后改价不影响历史账
-- =============================================================================

CREATE TABLE IF NOT EXISTS usage_ledger (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ledger_no                VARCHAR(48)     NOT NULL COMMENT '计费单号',
  user_id                  BIGINT UNSIGNED NOT NULL,
  provider_id              BIGINT UNSIGNED NOT NULL,
  node_id                  BIGINT UNSIGNED NOT NULL,
  binding_id               BIGINT UNSIGNED NOT NULL COMMENT '按流量发生时间命中的绑定',
  pricing_rule_id          BIGINT UNSIGNED NOT NULL COMMENT '按流量发生时间命中的定价规则',
  period_start             DATETIME(6)     NOT NULL COMMENT '计费周期起点(UTC)',
  period_end               DATETIME(6)     NOT NULL COMMENT '计费周期终点(UTC)',
  upload_bytes             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  download_bytes           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  billable_bytes           BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '计费字节=ROUND(上行*ur+下行*dr)',
  billable_gb              DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '计费GB=billable_bytes/1024^3',
  points_per_gb            DECIMAL(30,8)   NOT NULL COMMENT '计费时刻单价快照',
  upload_ratio             DECIMAL(10,6)   NOT NULL DEFAULT 1.000000 COMMENT '计费时刻上行系数快照',
  download_ratio           DECIMAL(10,6)   NOT NULL DEFAULT 1.000000 COMMENT '计费时刻下行系数快照',
  raw_points_amount        DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '按量原始值',
  user_points_amount       DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '实扣用户 points',
  platform_commission_rate DECIMAL(9,6)    NOT NULL DEFAULT 0.000000 COMMENT '计费时刻抽成快照',
  platform_points_amount   DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '平台抽成 points',
  provider_points_amount   DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '服务商应得=用户-平台',
  is_reversal              TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=红冲行',
  reversal_of_id           BIGINT UNSIGNED NULL COMMENT '被冲销的 usage_ledger.id',
  user_ledger_id           BIGINT UNSIGNED NULL COMMENT '对应 user_points_ledger.id',
  provider_ledger_id       BIGINT UNSIGNED NULL COMMENT '对应 provider_points_ledger.id',
  settlement_id            BIGINT UNSIGNED NULL COMMENT '已归属结算单(唯一结算由 settlement_items 保证)',
  status                   ENUM('pending','charged','skipped','failed','reversed') NOT NULL DEFAULT 'pending',
  idempotency_key          VARCHAR(128)    NOT NULL COMMENT '=SHA2(binding|node|period_start|period_end)',
  error_msg                VARCHAR(255)    NULL,
  metadata                 JSON            NULL,
  billed_at                DATETIME(6)     NULL,
  created_at               DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at               DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_ul_ledger_no (ledger_no),
  UNIQUE KEY uk_ul_idem      (idempotency_key),
  KEY idx_ul_user_time       (user_id, period_start),
  KEY idx_ul_provider_settle (provider_id, settlement_id, period_end),
  KEY idx_ul_status_time     (status, period_end),
  KEY idx_ul_binding         (binding_id, period_start),
  CONSTRAINT fk_ul_user     FOREIGN KEY (user_id)         REFERENCES users(id),
  CONSTRAINT fk_ul_provider FOREIGN KEY (provider_id)     REFERENCES providers(id),
  CONSTRAINT fk_ul_node     FOREIGN KEY (node_id)         REFERENCES provider_nodes(id),
  CONSTRAINT fk_ul_binding  FOREIGN KEY (binding_id)      REFERENCES user_provider_bindings(id),
  CONSTRAINT fk_ul_rule     FOREIGN KEY (pricing_rule_id) REFERENCES provider_pricing_rules(id),
  CONSTRAINT fk_ul_userled  FOREIGN KEY (user_ledger_id)  REFERENCES user_points_ledger(id),
  CONSTRAINT fk_ul_provled  FOREIGN KEY (provider_ledger_id) REFERENCES provider_points_ledger(id),
  CONSTRAINT ck_ul_win      CHECK (period_end > period_start),
  CONSTRAINT ck_ul_amounts  CHECK (is_reversal = 1 OR (user_points_amount >= 0 AND billable_gb >= 0)),
  CONSTRAINT ck_ul_split    CHECK (is_reversal = 1
                                   OR user_points_amount = platform_points_amount + provider_points_amount),
  CONSTRAINT ck_ul_meta     CHECK (metadata IS NULL OR JSON_VALID(metadata))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='流量计费明细(不可变,红冲修正)';
-- =============================================================================
-- 06 · 服务商结算（条款版本化 · 结算单 · 结算明细）
-- =============================================================================

-- -----------------------------------------------------------------------------
-- provider_settlement_terms · 结算条款（版本化：汇率 / 抽成 / 手续费 / 税 / 冻结期）
-- 计费与结算都必须"按时间点"取条款，不能用最新条款倒算历史账。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_settlement_terms (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id         BIGINT UNSIGNED NOT NULL,
  currency            CHAR(3)         NOT NULL DEFAULT 'CNY' COMMENT '结算法币',
  points_to_fiat_rate DECIMAL(30,8)   NOT NULL COMMENT '1 Point = X 法币',
  commission_rate     DECIMAL(9,6)    NOT NULL COMMENT '平台抽成比例 0~1',
  payout_fee_rate     DECIMAL(9,6)    NOT NULL DEFAULT 0.000000 COMMENT '打款手续费率',
  tax_rate            DECIMAL(9,6)    NOT NULL DEFAULT 0.000000 COMMENT '税率',
  min_payout_points   DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '起结门槛',
  hold_days           INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '流量发生到可结算的冻结天数',
  settlement_cycle    ENUM('weekly','biweekly','monthly','manual') NOT NULL DEFAULT 'monthly',
  priority            INT             NOT NULL DEFAULT 0 COMMENT '同刻多条款时取大',
  status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
  effective_from      DATETIME(6)     NOT NULL,
  effective_to        DATETIME(6)     NULL COMMENT '[from,to),NULL=长期',
  metadata            JSON            NULL,
  created_at          DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at          DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_pst_match (provider_id, status, effective_from, effective_to),
  CONSTRAINT fk_pst_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  CONSTRAINT ck_pst_rate CHECK (commission_rate >= 0 AND commission_rate < 1),
  CONSTRAINT ck_pst_fee  CHECK (payout_fee_rate >= 0 AND payout_fee_rate < 1 AND tax_rate >= 0 AND tax_rate < 1),
  CONSTRAINT ck_pst_fx   CHECK (points_to_fiat_rate > 0),
  CONSTRAINT ck_pst_win  CHECK (effective_to IS NULL OR effective_to > effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='服务商结算条款(版本化)';

-- -----------------------------------------------------------------------------
-- provider_settlements · 结算单
-- uk_ps_provider_period ⇒ 同一服务商同一区间只可能有一张结算单
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_settlements (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  settlement_no     VARCHAR(40)     NOT NULL COMMENT '结算单号',
  provider_id       BIGINT UNSIGNED NOT NULL,
  term_id           BIGINT UNSIGNED NULL COMMENT '采用的条款版本',
  period_start      DATETIME(6)     NOT NULL COMMENT '结算区间起点(UTC,含)',
  period_end        DATETIME(6)     NOT NULL COMMENT '结算区间终点(UTC,不含)',
  points_amount     DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '纳入结算的 points 毛额',
  commission_points DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '平台抽成(展示)',
  payout_fee_points DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '打款手续费',
  tax_points        DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '税费',
  net_points        DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '净 points',
  exchange_rate     DECIMAL(30,8)   NOT NULL COMMENT 'points→法币汇率快照',
  fiat_amount       DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000 COMMENT '应付法币',
  currency          CHAR(3)         NOT NULL,
  item_count        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '明细条数(对账)',
  status            ENUM('draft','pending','approved','paid','failed','cancelled') NOT NULL DEFAULT 'draft',
  payout_method     VARCHAR(32)     NULL,
  payout_ref        VARCHAR(128)    NULL COMMENT '打款流水号',
  metadata          JSON            NULL,
  requested_at      DATETIME(6)     NULL,
  approved_at       DATETIME(6)     NULL,
  paid_at           DATETIME(6)     NULL,
  created_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_ps_no              (settlement_no),
  UNIQUE KEY uk_ps_provider_period (provider_id, period_start, period_end),
  KEY idx_ps_status (status, period_end),
  CONSTRAINT fk_ps_provider FOREIGN KEY (provider_id) REFERENCES providers(id),
  CONSTRAINT fk_ps_term     FOREIGN KEY (term_id)     REFERENCES provider_settlement_terms(id),
  CONSTRAINT ck_ps_win    CHECK (period_end > period_start),
  CONSTRAINT ck_ps_amount CHECK (points_amount >= 0 AND net_points >= 0 AND fiat_amount >= 0),
  CONSTRAINT ck_ps_fx     CHECK (exchange_rate > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='服务商结算单';

-- -----------------------------------------------------------------------------
-- provider_settlement_items · 结算明细（usage 级）
-- uk_psi_usage ⇒ 一条 usage_ledger 只能被结算一次（防双结的关键）
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_settlement_items (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  settlement_id          BIGINT UNSIGNED NOT NULL,
  usage_ledger_id        BIGINT UNSIGNED NOT NULL,
  provider_id            BIGINT UNSIGNED NOT NULL,
  user_id                BIGINT UNSIGNED NOT NULL,
  node_id                BIGINT UNSIGNED NOT NULL,
  period_start           DATETIME(6)     NOT NULL,
  period_end             DATETIME(6)     NOT NULL,
  billable_gb            DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000,
  user_points_amount     DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000,
  platform_points_amount DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000,
  provider_points_amount DECIMAL(30,8)   NOT NULL DEFAULT 0.00000000,
  created_at             DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_psi_usage      (usage_ledger_id) COMMENT '一条计费明细只能被结算一次',
  KEY idx_psi_settlement (settlement_id, period_start),
  KEY idx_psi_provider   (provider_id, period_end),
  CONSTRAINT fk_psi_settlement FOREIGN KEY (settlement_id)   REFERENCES provider_settlements(id) ON DELETE CASCADE,
  CONSTRAINT fk_psi_usage      FOREIGN KEY (usage_ledger_id) REFERENCES usage_ledger(id),
  CONSTRAINT fk_psi_provider   FOREIGN KEY (provider_id)     REFERENCES providers(id),
  CONSTRAINT fk_psi_user       FOREIGN KEY (user_id)         REFERENCES users(id),
  CONSTRAINT fk_psi_node       FOREIGN KEY (node_id)         REFERENCES provider_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='结算明细(usage 级)';
