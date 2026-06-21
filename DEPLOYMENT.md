# 供应商账户状态机 - 部署文档

## 目录
1. [项目概述](#1-项目概述)
2. [系统架构](#2-系统架构)
3. [角色权限说明](#3-角色权限说明)
4. [环境变量配置](#4-环境变量配置)
5. [部署步骤](#5-部署步骤)
6. [验收命令](#6-验收命令)
7. [运维监控](#7-运维监控)
8. [故障排查](#8-故障排查)

---

## 1. 项目概述

### 1.1 业务背景
供应商结算账户状态机是在线课程教务系统的核心模块之一，负责管理供应商在平台上的结算账户全生命周期。系统重点沉淀两大核心能力：
- **结算账户审核**：提交 → 审核 → 通过/驳回 → 重新提交
- **账户冻结/解冻**：正常 ↔ 冻结，并可随时停用

### 1.2 状态流转图

```
draft ──submit──▶ pending_review ──approve──▶ active ──freeze──▶ frozen
       │                │                        │                  │
       │              reject                  disable            unfreeze
       │                ▼                        ▼                  │
       │             rejected ──resubmit──▶ pending_review        active
       │                │
       │              disable
       │                ▼
       └───────────▶ disabled ◀──disable── (任意非终态)
                        │
                      enable
                        ▼
                      draft
```

### 1.3 状态说明

| 状态 | 标识 | 分组 | 说明 |
|------|------|------|------|
| 待提交 | draft | review | 账户已建档，尚未提交审核。补全结算信息后可提交 |
| 待审核 | pending_review | review | 已提交审核，等待风控/结算复核通过或驳回 |
| 正常 | active | active | 审核通过，结算账户正常可用，可发起结算与冻结操作 |
| 已驳回 | rejected | review | 审核未通过。补正资料后可重新提交审核 |
| 已冻结 | frozen | frozen | 账户已被冻结，结算暂停。解冻后恢复正常 |
| 已停用 | disabled | terminal | 账户已停用（终态）。可重新启用回到待提交重新走流程 |

---

## 2. 系统架构

### 2.1 技术栈

| 层级 | 技术选型 | 说明 |
|------|----------|------|
| 前端 | Vue 3 + TypeScript + Vite + TailwindCSS | 单页应用，提供账户管理界面 |
| 后端 | PHP 8.1+ | 轻量级后端服务，提供 RESTful API |
| 数据库 | SQLite 3 | 嵌入式数据库，支持事务和外键 |
| 状态机引擎 | 自研 StateMachine | 有限状态机实现，支持守卫校验和回滚 |

### 2.2 目录结构

```
.
├── api/                          # 后端 PHP 代码
│   ├── AccountStateMachine.php   # 账户状态机定义
│   ├── AccountService.php        # 业务服务层（含权限控制）
│   ├── StateMachine.php          # 通用状态机引擎
│   ├── Database.php              # 数据库连接与迁移
│   ├── index.php                 # API 入口/路由控制器
│   ├── Response.php              # 统一响应格式
│   ├── seed.php                  # 数据初始化脚本
│   ├── test_state_machine.php    # 单元测试
│   └── data/                     # SQLite 数据目录
│       └── app.sqlite            # 数据库文件
├── src/                          # 前端 Vue 代码
│   ├── api/                      # API 客户端
│   ├── components/               # UI 组件
│   ├── composables/              # 组合式函数
│   ├── lib/                      # 工具库（状态元数据等）
│   ├── pages/                    # 页面组件
│   └── router/                   # 路由配置
├── DEPLOYMENT.md                 # 本文档
├── package.json                  # 前端依赖
└── vite.config.ts                # Vite 配置
```

### 2.3 API 接口列表

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/definition` | 获取状态机定义（含角色、事件权限） |
| GET | `/api/accounts` | 获取账户列表（支持 status、keyword 过滤） |
| POST | `/api/accounts` | 创建结算账户（初始为 draft） |
| GET | `/api/accounts/{id}` | 获取账户详情（支持 operator 参数过滤可用事件） |
| GET | `/api/accounts/{id}/history` | 获取账户操作历史 |
| POST | `/api/accounts/{id}/{event}` | 触发状态迁移事件 |

---

## 3. 角色权限说明

### 3.1 角色定义

| 角色标识 | 角色名称 | 前缀约定 | 主要职责 |
|----------|----------|----------|----------|
| supplier | 供应商运营 | `supplier_` / `sup_` | 提交审核资料、重新提交审核 |
| reviewer | 审核专员 | `reviewer_` / `rev_` | 审核通过、审核驳回 |
| risk | 风控专员 | `risk_` / `rsk_` | 冻结账户、解冻账户 |
| admin | 系统管理员 | `admin_` / `adm_` | 停用/启用账户、所有回滚操作、所有其他操作 |

### 3.2 事件权限矩阵

| 事件 | 说明 | supplier | reviewer | risk | admin |
|------|------|----------|----------|------|-------|
| submit | 提交审核 | ✅ | ❌ | ❌ | ✅ |
| resubmit | 重新提交 | ✅ | ❌ | ❌ | ✅ |
| approve | 审核通过 | ❌ | ✅ | ❌ | ✅ |
| reject | 审核驳回 | ❌ | ✅ | ❌ | ✅ |
| freeze | 冻结账户 | ❌ | ❌ | ✅ | ✅ |
| unfreeze | 解冻账户 | ❌ | ❌ | ✅ | ✅ |
| disable | 停用账户 | ❌ | ❌ | ❌ | ✅ |
| enable | 重新启用 | ❌ | ❌ | ❌ | ✅ |
| rollback_submit | 回滚提交 | ❌ | ❌ | ❌ | ✅ |
| rollback_approve | 回滚审核通过 | ❌ | ❌ | ❌ | ✅ |
| rollback_reject | 回滚审核驳回 | ❌ | ❌ | ❌ | ✅ |
| rollback_freeze | 回滚冻结 | ❌ | ❌ | ❌ | ✅ |

### 3.3 角色识别规则

系统通过操作人（operator）前缀自动识别角色：
- `supplier_*` 或 `sup_*` → 供应商运营
- `reviewer_*` 或 `rev_*` → 审核专员
- `risk_*` 或 `rsk_*` → 风控专员
- `admin_*` 或 `adm_*` 或 `ops-admin` → 系统管理员
- 其他前缀 → 抛出权限异常（HTTP 403）

---

## 4. 环境变量配置

### 4.1 前端环境变量

创建 `.env` 文件于项目根目录：

```bash
# .env

# ———————————————— 基础配置 ————————————————
VITE_APP_NAME=供应商账户管理系统
VITE_APP_ENV=development
VITE_APP_VERSION=1.0.0

# ———————————————— API 配置 ————————————————
# 后端 API 地址（开发环境使用 Vite 代理，生产环境需配置实际地址）
VITE_API_BASE_URL=/api
# 后端服务地址（用于直接调用）
VITE_BACKEND_HOST=127.0.0.1
VITE_BACKEND_PORT=8001

# ———————————————— 结算账户审核配置 ————————————————
# 审核是否需要银行账号（默认 true）
VITE_REVIEW_REQUIRE_ACCOUNT_NO=true
# 驳回是否必须填写原因（默认 true）
VITE_REVIEW_REQUIRE_REJECT_REASON=true
# 审核与冻结联动校验（默认 true）
VITE_REVIEW_FREEZE_LINK_CHECK=true
# 审核超时提醒时间（小时）
VITE_REVIEW_TIMEOUT_HOURS=24
# 自动审核开关（默认 false，生产环境建议关闭）
VITE_AUTO_APPROVE_ENABLED=false
# 自动审核白名单供应商（逗号分隔）
VITE_AUTO_APPROVE_WHITELIST=

# ———————————————— 账户冻结配置 ————————————————
# 冻结是否必须填写原因（默认 true）
VITE_FREEZE_REQUIRE_REASON=true
# 解冻是否必须填写原因（默认 true）
VITE_UNFREEZE_REQUIRE_REASON=true
# 冻结是否需要二次确认（默认 true）
VITE_FREEZE_CONFIRM_REQUIRED=true
# 自动解冻开关（默认 false）
VITE_AUTO_UNFREEZE_ENABLED=false
# 自动解冻天数（默认 30 天）
VITE_AUTO_UNFREEZE_DAYS=30
# 冻结通知接收人（逗号分隔）
VITE_FREEZE_NOTIFY_USERS=admin_01,risk_01

# ———————————————— 回滚操作配置 ————————————————
# 回滚是否必须填写原因（默认 true）
VITE_ROLLBACK_REQUIRE_REASON=true
# 回滚操作是否需要二次确认（默认 true）
VITE_ROLLBACK_CONFIRM_REQUIRED=true
# 允许回滚的时间窗口（小时，0 表示不限制）
VITE_ROLLBACK_TIME_WINDOW_HOURS=24

# ———————————————— 操作日志配置 ————————————————
# 是否记录详细操作日志（默认 true）
VITE_OPERATION_LOG_ENABLED=true
# 日志保留天数（默认 90 天）
VITE_LOG_RETENTION_DAYS=90

# ———————————————— 安全配置 ————————————————
# 是否启用操作人验证（默认 true）
VITE_OPERATOR_AUTH_ENABLED=true
# 操作人前缀列表（逗号分隔）
VITE_ALLOWED_OPERATOR_PREFIXES=supplier_,sup_,reviewer_,rev_,risk_,rsk_,admin_,adm_

# ———————————————— 页面配置 ————————————————
# 列表默认分页大小
VITE_DEFAULT_PAGE_SIZE=20
# 状态机图表是否默认展开
VITE_DIAGRAM_DEFAULT_EXPANDED=true
# 是否显示回滚按钮（默认 true）
VITE_SHOW_ROLLBACK_BUTTONS=true
```

### 4.2 后端环境变量

创建 `.env.php` 文件于 `api/` 目录：

```php
<?php
// api/.env.php

// ———————————————— 数据库配置 ————————————————
define('DB_PATH', __DIR__ . '/data/app.sqlite');
define('DB_JOURNAL_MODE', 'WAL');
define('DB_FOREIGN_KEYS', true);

// ———————————————— 结算账户审核配置 ————————————————
define('REVIEW_REQUIRE_ACCOUNT_NO', true);
define('REVIEW_REQUIRE_REJECT_REASON', true);
define('REVIEW_FREEZE_LINK_CHECK', true);
define('REVIEW_TIMEOUT_HOURS', 24);
define('AUTO_APPROVE_ENABLED', false);
define('AUTO_APPROVE_WHITELIST', '');

// ———————————————— 账户冻结配置 ————————————————
define('FREEZE_REQUIRE_REASON', true);
define('UNFREEZE_REQUIRE_REASON', true);
define('FREEZE_CONFIRM_REQUIRED', true);
define('AUTO_UNFREEZE_ENABLED', false);
define('AUTO_UNFREEZE_DAYS', 30);
define('FREEZE_NOTIFY_USERS', 'admin_01,risk_01');

// ———————————————— 回滚操作配置 ————————————————
define('ROLLBACK_REQUIRE_REASON', true);
define('ROLLBACK_CONFIRM_REQUIRED', true);
define('ROLLBACK_TIME_WINDOW_HOURS', 24);

// ———————————————— 安全配置 ————————————————
define('OPERATOR_AUTH_ENABLED', true);
define('ALLOWED_OPERATOR_PREFIXES', 'supplier_,sup_,reviewer_,rev_,risk_,rsk_,admin_,adm_');
define('CORS_ALLOW_ORIGIN', '*');

// ———————————————— 业务规则配置 ————————————————
// 审核与冻结联动：冻结状态下是否允许审核通过
define('LINK_RULE_APPROVE_WHEN_FROZEN', false);
// 审核与冻结联动：待审核状态下是否允许冻结
define('LINK_RULE_FREEZE_WHEN_PENDING', false);
// 回滚审核通过时是否检查冻结状态
define('LINK_RULE_ROLLBACK_APPROVE_CHECK_FREEZE', true);
```

### 4.3 环境变量加载

在 `api/Database.php` 开头添加环境变量加载逻辑：

```php
<?php

declare(strict_types=1);

// 加载环境变量
if (file_exists(__DIR__ . '/.env.php')) {
    require_once __DIR__ . '/.env.php';
}

// 定义默认值（当环境变量未设置时使用）
defined('DB_PATH') || define('DB_PATH', __DIR__ . '/data/app.sqlite');
defined('REVIEW_REQUIRE_ACCOUNT_NO') || define('REVIEW_REQUIRE_ACCOUNT_NO', true);
defined('REVIEW_REQUIRE_REJECT_REASON') || define('REVIEW_REQUIRE_REJECT_REASON', true);
defined('REVIEW_FREEZE_LINK_CHECK') || define('REVIEW_FREEZE_LINK_CHECK', true);
defined('FREEZE_REQUIRE_REASON') || define('FREEZE_REQUIRE_REASON', true);
defined('UNFREEZE_REQUIRE_REASON') || define('UNFREEZE_REQUIRE_REASON', true);
defined('ROLLBACK_REQUIRE_REASON') || define('ROLLBACK_REQUIRE_REASON', true);
defined('OPERATOR_AUTH_ENABLED') || define('OPERATOR_AUTH_ENABLED', true);
defined('LINK_RULE_APPROVE_WHEN_FROZEN') || define('LINK_RULE_APPROVE_WHEN_FROZEN', false);
defined('LINK_RULE_FREEZE_WHEN_PENDING') || define('LINK_RULE_FREEZE_WHEN_PENDING', false);
defined('LINK_RULE_ROLLBACK_APPROVE_CHECK_FREEZE') || define('LINK_RULE_ROLLBACK_APPROVE_CHECK_FREEZE', true);
```

---

## 5. 部署步骤

### 5.1 系统要求

- **操作系统**：Linux / macOS / Windows
- **PHP**：8.1 或更高版本（需开启 PDO_SQLITE 扩展）
- **Node.js**：18 或更高版本
- **npm** / **pnpm** / **yarn**：任意包管理器
- **SQLite**：3.7 或更高版本（PHP 内置）

### 5.2 部署前检查

```bash
# 检查 PHP 版本
php -v

# 检查 PHP 扩展
php -m | grep pdo_sqlite

# 检查 Node.js 版本
node -v

# 检查 npm 版本
npm -v
```

### 5.3 开发环境部署

#### 步骤 1：克隆项目并安装依赖

```bash
# 进入项目目录
cd /path/to/project

# 安装前端依赖
npm install
```

#### 步骤 2：配置环境变量

```bash
# 复制并编辑前端环境变量
cp .env.example .env
# 根据实际情况修改 .env 文件

# 复制并编辑后端环境变量
cp api/.env.example.php api/.env.php
# 根据实际情况修改 api/.env.php 文件
```

#### 步骤 3：初始化数据库

```bash
# 执行数据库初始化脚本（自动创建表结构）
php api/seed.php
```

#### 步骤 4：启动后端服务

```bash
# 方式一：使用 PHP 内置服务器（推荐开发环境）
php -S 127.0.0.1:8001 -t api api/index.php

# 方式二：使用 nohup 后台运行
nohup php -S 127.0.0.1:8001 -t api api/index.php > api.log 2>&1 &
```

#### 步骤 5：启动前端开发服务器

```bash
# 启动 Vite 开发服务器（默认端口 5173）
npm run dev

# 或指定端口
npm run dev -- --port 3000
```

#### 步骤 6：访问应用

打开浏览器访问：`http://localhost:5173`

### 5.4 生产环境部署

#### 步骤 1：构建前端

```bash
# 安装依赖（生产模式）
npm ci

# 构建生产版本
npm run build

# 构建产物将输出到 dist/ 目录
```

#### 步骤 2：配置 Web 服务器（以 Nginx 为例）

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/project/dist;

    # 前端静态文件
    location / {
        try_files $uri $uri/ /index.html;
    }

    # API 代理到 PHP 后端
    location /api {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME /path/to/project/api/index.php;
        include fastcgi_params;

        # 或者代理到 PHP 内置服务器
        # proxy_pass http://127.0.0.1:8001;
        # proxy_set_header Host $host;
        # proxy_set_header X-Real-IP $remote_addr;
    }

    # 安全头
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";
}
```

#### 步骤 3：配置 PHP-FPM（可选）

```ini
; /etc/php/8.1/fpm/pool.d/account-state-machine.conf
[account-state-machine]
user = www-data
group = www-data
listen = /run/php/php8.1-fpm-account.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 2
pm.max_spare_servers = 8
php_admin_value[error_log] = /var/log/php/account-state-machine-error.log
php_admin_flag[log_errors] = on
```

#### 步骤 4：配置 Supervisor（可选，用于管理后端进程）

```ini
; /etc/supervisor/conf.d/account-state-machine.conf
[program:account-state-machine]
command=/usr/bin/php -S 127.0.0.1:8001 -t /path/to/project/api /path/to/project/api/index.php
directory=/path/to/project
user=www-data
autostart=true
autorestart=true
stderr_logfile=/var/log/account-state-machine.err.log
stdout_logfile=/var/log/account-state-machine.out.log
```

```bash
# 更新 Supervisor 配置
supervisorctl reread
supervisorctl update
supervisorctl start account-state-machine
```

### 5.5 数据库备份与恢复

```bash
# 备份数据库
sqlite3 api/data/app.sqlite ".backup backup/$(date +%Y%m%d_%H%M%S).sqlite"

# 恢复数据库
sqlite3 api/data/app.sqlite ".restore backup/20240101_120000.sqlite"

# 导出为 SQL
sqlite3 api/data/app.sqlite .dump > backup/$(date +%Y%m%d_%H%M%S).sql

# 从 SQL 恢复
sqlite3 api/data/app.sqlite < backup/20240101_120000.sql
```

---

## 6. 验收命令

### 6.1 单元测试

```bash
# ———————————————— 运行完整单元测试套件 ————————————————
php api/test_state_machine.php

# 预期输出：
# ============================================================
# 供应商账户状态机 - 单元测试
# ============================================================
#
# 1. 状态机定义测试
#   ✓ 所有状态已正确定义
#   ✓ 状态标签正确
#   ✓ 终态定义正确 - disabled 是终态
#   ✓ 状态分组正确
#
# 2. 结算账户审核流程 - 正常路径
#   ✓ draft → submit → pending_review 正常流转
#   ✓ pending_review → approve → active 正常流转
#   ✓ 审核完整闭环: draft → pending → active
#
# ... (更多测试用例)
#
# ============================================================
# 测试结果: 通过 N, 失败 0
# ============================================================
```

### 6.2 结算账户审核流程验收

```bash
# ———————————————— 测试脚本：审核流程验收 ————————————————
# 创建测试脚本 test_review_flow.php

<?php
require_once __DIR__ . '/api/AccountService.php';

$service = new AccountService();

echo "=== 结算账户审核流程验收 ===\n\n";

// 1. 创建账户
echo "1. 创建账户... ";
$acc = $service->create([
    'supplier_name' => '验收测试供应商',
    'account_name' => '验收测试账户',
    'account_no' => '6225881012345678',
    'bank_name' => '招商银行',
    'bank_branch' => '杭州分行',
]);
echo "✓ 账户ID: {$acc['id']}, 状态: {$acc['status']}\n";

// 2. 提交审核（供应商操作）
echo "2. 提交审核（supplier_01）... ";
$acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
echo "✓ 状态: {$acc['status']}, 提交时间: {$acc['submitted_at_text']}\n";

// 3. 审核通过（审核专员操作）
echo "3. 审核通过（reviewer_01）... ";
$acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
echo "✓ 状态: {$acc['status']}, 审核时间: {$acc['reviewed_at_text']}\n";

// 4. 验证历史记录
echo "4. 验证历史记录... ";
$history = $service->history($acc['id']);
echo "✓ 历史记录数: " . count($history) . "\n";

echo "\n=== 结算账户审核流程验收通过 ===\n";
```

```bash
# 运行审核流程验收
php test_review_flow.php
```

### 6.3 账户冻结/解冻流程验收

```bash
# ———————————————— 测试脚本：冻结解冻流程验收 ————————————————
# 创建测试脚本 test_freeze_flow.php

<?php
require_once __DIR__ . '/api/AccountService.php';

$service = new AccountService();

echo "=== 账户冻结/解冻流程验收 ===\n\n";

// 1. 创建并激活账户
echo "1. 创建并激活账户... ";
$acc = $service->create([
    'supplier_name' => '冻结测试供应商',
    'account_name' => '冻结测试账户',
    'account_no' => '6225881087654321',
    'bank_name' => '工商银行',
    'bank_branch' => '北京分行',
]);
$acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
$acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
echo "✓ 账户ID: {$acc['id']}, 状态: {$acc['status']}\n";

// 2. 冻结账户（风控专员操作）
echo "2. 冻结账户（risk_01）... ";
$acc = $service->trigger($acc['id'], 'freeze', [
    'operator' => 'risk_01',
    'reason' => '账户存在异常大额交易，需核实'
]);
echo "✓ 状态: {$acc['status']}\n";
echo "  冻结时间: {$acc['frozen_at_text']}\n";
echo "  冻结原因: {$acc['freeze_reason']}\n";

// 3. 验证冻结状态下无法审核通过（联动校验）
echo "3. 验证冻结状态下无法审核通过... ";
try {
    $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    echo "✗ 应该被拒绝但成功了\n";
} catch (StateTransitionException $e) {
    echo "✓ 正确拒绝: {$e->getMessage()}\n";
}

// 4. 解冻账户（风控专员操作）
echo "4. 解冻账户（risk_01）... ";
$acc = $service->trigger($acc['id'], 'unfreeze', [
    'operator' => 'risk_01',
    'reason' => '核查无误，解除冻结'
]);
echo "✓ 状态: {$acc['status']}\n";
echo "  解冻后 frozen_at: " . ($acc['frozen_at'] ? '未清空' : '已清空') . "\n";

echo "\n=== 账户冻结/解冻流程验收通过 ===\n";
```

```bash
# 运行冻结解冻流程验收
php test_freeze_flow.php
```

### 6.4 审核与冻结联动校验验收

```bash
# ———————————————— 测试脚本：联动校验验收 ————————————————
# 创建测试脚本 test_link_check.php

<?php
require_once __DIR__ . '/api/AccountService.php';

$service = new AccountService();

echo "=== 审核与冻结联动校验验收 ===\n\n";

// 测试场景1：待审核状态下不允许冻结
echo "场景1：待审核状态下不允许冻结\n";
$acc1 = $service->create([
    'supplier_name' => '联动测试供应商1',
    'account_name' => '联动测试账户1',
    'account_no' => '6225881011112222',
    'bank_name' => '建设银行',
]);
$acc1 = $service->trigger($acc1['id'], 'submit', ['operator' => 'supplier_01']);
echo "  状态: {$acc1['status']}, submitted_at: {$acc1['submitted_at_text']}\n";
try {
    $service->trigger($acc1['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '测试冻结'
    ]);
    echo "  ✗ 应该被拒绝但成功了\n";
} catch (StateTransitionException $e) {
    echo "  ✓ 正确拒绝: {$e->getMessage()}\n";
}

// 测试场景2：冻结状态下不允许审核通过
echo "\n场景2：冻结状态下不允许审核通过\n";
$acc2 = $service->create([
    'supplier_name' => '联动测试供应商2',
    'account_name' => '联动测试账户2',
    'account_no' => '6225881033334444',
    'bank_name' => '农业银行',
]);
$acc2 = $service->trigger($acc2['id'], 'submit', ['operator' => 'supplier_01']);
$acc2 = $service->trigger($acc2['id'], 'approve', ['operator' => 'reviewer_01']);
$acc2 = $service->trigger($acc2['id'], 'freeze', [
    'operator' => 'risk_01',
    'reason' => '异常交易'
]);
// 回滚审核通过回到待审核
$acc2 = $service->trigger($acc2['id'], 'rollback_freeze', [
    'operator' => 'admin_01',
    'reason' => '回滚冻结用于测试'
]);
$acc2 = $service->trigger($acc2['id'], 'rollback_approve', [
    'operator' => 'admin_01',
    'reason' => '回滚审核通过用于测试'
]);
// 手动设置冻结标记
$stmt = Database::pdo()->prepare("UPDATE accounts SET frozen_at = ? WHERE id = ?");
$stmt->execute([time(), $acc2['id']]);
echo "  状态: {$acc2['status']}, 已手动设置 frozen_at\n";
try {
    $service->trigger($acc2['id'], 'approve', ['operator' => 'reviewer_01']);
    echo "  ✗ 应该被拒绝但成功了\n";
} catch (StateTransitionException $e) {
    echo "  ✓ 正确拒绝: {$e->getMessage()}\n";
}

// 测试场景3：先审核通过再冻结 - 正常流程
echo "\n场景3：先审核通过再冻结 - 正常流程\n";
$acc3 = $service->create([
    'supplier_name' => '联动测试供应商3',
    'account_name' => '联动测试账户3',
    'account_no' => '6225881055556666',
    'bank_name' => '中国银行',
]);
$acc3 = $service->trigger($acc3['id'], 'submit', ['operator' => 'supplier_01']);
echo "  提交后状态: {$acc3['status']}\n";
$acc3 = $service->trigger($acc3['id'], 'approve', ['operator' => 'reviewer_01']);
echo "  审核通过后状态: {$acc3['status']}\n";
$acc3 = $service->trigger($acc3['id'], 'freeze', [
    'operator' => 'risk_01',
    'reason' => '正常冻结'
]);
echo "  冻结后状态: {$acc3['status']}\n";
echo "  ✓ 正常流程通过\n";

echo "\n=== 审核与冻结联动校验验收通过 ===\n";
```

```bash
# 运行联动校验验收
php test_link_check.php
```

### 6.5 API 接口验收

```bash
# ———————————————— API 接口测试（使用 curl） ————————————————

# 确保后端服务已启动
# php -S 127.0.0.1:8001 -t api api/index.php

BASE_URL="http://127.0.0.1:8001/api"

echo "=== API 接口验收 ===\n\n"

# 1. 获取状态机定义
echo "1. 获取状态机定义... "
curl -s "$BASE_URL/definition" | python3 -m json.tool > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "✓ 成功"
    curl -s "$BASE_URL/definition" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(f'  状态数: {len(d[\"states\"])}')
print(f'  事件数: {len(d[\"events\"])}')
print(f'  角色数: {len(d[\"roles\"])}')
"
else
    echo "✗ 失败"
fi

# 2. 创建账户
echo -e "\n2. 创建账户... "
CREATE_RESPONSE=$(curl -s -X POST "$BASE_URL/accounts" \
    -H "Content-Type: application/json" \
    -d '{
        "supplier_name": "API测试供应商",
        "account_name": "API测试账户",
        "account_no": "6225881099998888",
        "bank_name": "交通银行",
        "bank_branch": "上海分行"
    }')
ACCOUNT_ID=$(echo $CREATE_RESPONSE | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(d['data']['id'])
")
echo "✓ 账户ID: $ACCOUNT_ID"

# 3. 获取账户列表
echo -e "\n3. 获取账户列表... "
curl -s "$BASE_URL/accounts?status=draft" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(f'✓ 账户数: {len(d[\"data\"])}')
"

# 4. 提交审核
echo -e "\n4. 提交审核... "
curl -s -X POST "$BASE_URL/accounts/$ACCOUNT_ID/submit" \
    -H "Content-Type: application/json" \
    -d '{"operator": "supplier_01"}' | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(f'✓ 状态: {d[\"data\"][\"status\"]}')
"

# 5. 审核通过
echo -e "\n5. 审核通过... "
curl -s -X POST "$BASE_URL/accounts/$ACCOUNT_ID/approve" \
    -H "Content-Type: application/json" \
    -d '{"operator": "reviewer_01"}' | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(f'✓ 状态: {d[\"data\"][\"status\"]}')
"

# 6. 冻结账户
echo -e "\n6. 冻结账户... "
curl -s -X POST "$BASE_URL/accounts/$ACCOUNT_ID/freeze" \
    -H "Content-Type: application/json" \
    -d '{"operator": "risk_01", "reason": "API测试冻结"}' | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(f'✓ 状态: {d[\"data\"][\"status\"]}')
print(f'  冻结原因: {d[\"data\"][\"freeze_reason\"]}')
"

# 7. 获取操作历史
echo -e "\n7. 获取操作历史... "
curl -s "$BASE_URL/accounts/$ACCOUNT_ID/history" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(f'✓ 历史记录数: {len(d[\"data\"])}')
"

echo -e "\n=== API 接口验收通过 ===\n"
```

```bash
# 运行 API 接口验收（保存为 test_api.sh 后执行）
chmod +x test_api.sh
./test_api.sh
```

### 6.6 前端构建验收

```bash
# ———————————————— 前端构建验收 ————————————————

# 1. TypeScript 类型检查
echo "1. TypeScript 类型检查... "
npm run check
if [ $? -eq 0 ]; then
    echo "✓ 类型检查通过"
else
    echo "✗ 类型检查失败"
    exit 1
fi

# 2. 代码风格检查（如果配置了 ESLint）
echo -e "\n2. 代码风格检查... "
npm run lint
if [ $? -eq 0 ]; then
    echo "✓ 代码风格检查通过"
else
    echo "✗ 代码风格检查失败"
    exit 1
fi

# 3. 生产构建
echo -e "\n3. 生产构建... "
npm run build
if [ $? -eq 0 ]; then
    echo "✓ 构建成功"
    # 检查构建产物
    echo "  dist/ 目录大小: $(du -sh dist | cut -f1)"
    echo "  index.html: $(ls -la dist/index.html | awk '{print $5}') bytes"
    echo "  JS 文件数: $(find dist/assets -name "*.js" | wc -l)"
    echo "  CSS 文件数: $(find dist/assets -name "*.css" | wc -l)"
else
    echo "✗ 构建失败"
    exit 1
fi

# 4. 预览构建结果（可选）
echo -e "\n4. 启动预览服务器（端口 4173）... "
npm run preview -- --port 4173 > /dev/null 2>&1 &
PREVIEW_PID=$!
sleep 2
if curl -s http://localhost:4173 > /dev/null; then
    echo "✓ 预览服务器启动成功"
    curl -s -o /dev/null -w "  HTTP 状态码: %{http_code}\n" http://localhost:4173
    kill $PREVIEW_PID
else
    echo "✗ 预览服务器启动失败"
    kill $PREVIEW_PID 2>/dev/null
    exit 1
fi

echo -e "\n=== 前端构建验收通过 ===\n"
```

### 6.7 一键验收脚本

创建完整的一键验收脚本 `run_acceptance.sh`：

```bash
#!/bin/bash
# run_acceptance.sh - 一键验收脚本

set -e

echo "============================================================"
echo "  供应商账户状态机 - 一键验收"
echo "============================================================"
echo ""

# 检查后端服务是否运行
echo "[1/6] 检查后端服务..."
if curl -s http://127.0.0.1:8001/api/definition > /dev/null 2>&1; then
    echo "  ✓ 后端服务运行正常"
else
    echo "  后端服务未启动，正在启动..."
    nohup php -S 127.0.0.1:8001 -t api api/index.php > /tmp/api.log 2>&1 &
    API_PID=$!
    sleep 2
    if curl -s http://127.0.0.1:8001/api/definition > /dev/null 2>&1; then
        echo "  ✓ 后端服务启动成功 (PID: $API_PID)"
    else
        echo "  ✗ 后端服务启动失败"
        exit 1
    fi
fi

# 运行单元测试
echo -e "\n[2/6] 运行单元测试..."
php api/test_state_machine.php
if [ $? -ne 0 ]; then
    echo "  ✗ 单元测试失败"
    exit 1
fi

# 审核流程验收
echo -e "\n[3/6] 结算账户审核流程验收..."
php test_review_flow.php
if [ $? -ne 0 ]; then
    echo "  ✗ 审核流程验收失败"
    exit 1
fi

# 冻结解冻流程验收
echo -e "\n[4/6] 账户冻结/解冻流程验收..."
php test_freeze_flow.php
if [ $? -ne 0 ]; then
    echo "  ✗ 冻结解冻流程验收失败"
    exit 1
fi

# 联动校验验收
echo -e "\n[5/6] 审核与冻结联动校验验收..."
php test_link_check.php
if [ $? -ne 0 ]; then
    echo "  ✗ 联动校验验收失败"
    exit 1
fi

# API 接口验收
echo -e "\n[6/6] API 接口验收..."
bash test_api.sh
if [ $? -ne 0 ]; then
    echo "  ✗ API 接口验收失败"
    exit 1
fi

echo ""
echo "============================================================"
echo "  所有验收通过 ✅"
echo "============================================================"

# 清理临时启动的后端服务
if [ -n "$API_PID" ]; then
    kill $API_PID 2>/dev/null
    echo "  已停止临时后端服务 (PID: $API_PID)"
fi
```

```bash
# 赋予执行权限并运行
chmod +x run_acceptance.sh
./run_acceptance.sh
```

---

## 7. 运维监控

### 7.1 关键指标监控

| 指标 | 说明 | 告警阈值 |
|------|------|----------|
| 账户总数 | 系统中的结算账户总数 | - |
| 待审核账户数 | 状态为 pending_review 的账户数 | > 100 或 24小时未处理 |
| 已冻结账户数 | 状态为 frozen 的账户数 | > 50 |
| 审核通过率 | 审核通过数 / 审核总数 | < 80% |
| 平均审核时长 | 从提交到审核完成的平均时间 | > 24 小时 |
| API 错误率 | HTTP 4xx/5xx 请求占比 | > 5% |
| API 响应时间 | 平均响应时间 | > 500ms |

### 7.2 日志配置

#### PHP 错误日志

在 `php.ini` 中配置：

```ini
log_errors = On
error_log = /var/log/php/account-state-machine-error.log
error_reporting = E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED
```

#### 应用日志

系统自动记录状态变更历史到 `state_transitions` 表，包含：
- 操作事件
- 起始状态和目标状态
- 操作人
- 操作原因
- 操作时间
- 扩展元数据

#### 日志查询

```sql
-- 查询最近24小时的操作记录
SELECT
    id,
    account_id,
    event,
    from_status,
    to_status,
    operator,
    reason,
    datetime(created_at, 'unixepoch', 'localtime') as created_at
FROM state_transitions
WHERE created_at >= strftime('%s', 'now', '-24 hours')
ORDER BY created_at DESC
LIMIT 100;

-- 按操作人统计操作次数
SELECT
    operator,
    event,
    COUNT(*) as count
FROM state_transitions
WHERE created_at >= strftime('%s', 'now', '-7 days')
GROUP BY operator, event
ORDER BY count DESC;

-- 查询冻结/解冻操作记录
SELECT
    a.supplier_name,
    st.event,
    st.reason,
    st.operator,
    datetime(st.created_at, 'unixepoch', 'localtime') as created_at
FROM state_transitions st
JOIN accounts a ON st.account_id = a.id
WHERE st.event IN ('freeze', 'unfreeze', 'rollback_freeze')
ORDER BY st.created_at DESC
LIMIT 50;
```

### 7.3 定时任务配置

使用 crontab 配置定时任务：

```bash
# 编辑 crontab
crontab -e
```

添加以下任务：

```bash
# ———————————————— 供应商账户状态机定时任务 ————————————————

# 每分钟检查 API 服务健康状态
* * * * * /path/to/project/scripts/health_check.sh >> /var/log/account-state-machine/health.log 2>&1

# 每小时统计审核超时账户
0 * * * * /usr/bin/php /path/to/project/scripts/check_review_timeout.php >> /var/log/account-state-machine/timeout.log 2>&1

# 每天凌晨清理过期日志
0 0 * * * /usr/bin/php /path/to/project/scripts/cleanup_logs.php >> /var/log/account-state-machine/cleanup.log 2>&1

# 每天凌晨备份数据库
0 2 * * * /usr/bin/sqlite3 /path/to/project/api/data/app.sqlite ".backup /path/to/backup/$(date +\%Y\%m\%d).sqlite" >> /var/log/account-state-machine/backup.log 2>&1

# 每周一凌晨生成周报
0 3 * * 1 /usr/bin/php /path/to/project/scripts/generate_weekly_report.php >> /var/log/account-state-machine/report.log 2>&1

# 自动解冻检查（如果启用了自动解冻）
0 4 * * * /usr/bin/php /path/to/project/scripts/auto_unfreeze.php >> /var/log/account-state-machine/auto_unfreeze.log 2>&1
```

### 7.4 健康检查脚本

创建 `scripts/health_check.sh`：

```bash
#!/bin/bash

API_URL="http://127.0.0.1:8001/api/definition"
LOG_FILE="/var/log/account-state-machine/health.log"

# 检查 API 服务
if curl -s -f "$API_URL" > /dev/null 2>&1; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] API 服务正常" >> "$LOG_FILE"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] API 服务异常，正在重启..." >> "$LOG_FILE"
    # 重启服务
    pkill -f "php -S 127.0.0.1:8001" || true
    sleep 1
    nohup php -S 127.0.0.1:8001 -t /path/to/project/api /path/to/project/api/index.php >> "$LOG_FILE" 2>&1 &
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] API 服务已重启" >> "$LOG_FILE"
fi

# 检查数据库文件
DB_FILE="/path/to/project/api/data/app.sqlite"
if [ -f "$DB_FILE" ]; then
    DB_SIZE=$(du -h "$DB_FILE" | cut -f1)
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] 数据库正常，大小: $DB_SIZE" >> "$LOG_FILE"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] 数据库文件不存在: $DB_FILE" >> "$LOG_FILE"
    # 发送告警...
fi
```

---

## 8. 故障排查

### 8.1 常见问题

#### 问题 1：后端服务无法启动

**现象**：执行 `php -S ...` 后无法访问 API

**排查步骤**：
```bash
# 检查端口是否被占用
lsof -i :8001
netstat -tlnp | grep 8001

# 检查 PHP 错误日志
tail -f /var/log/php/errors.log

# 直接运行 PHP 查看错误
php api/index.php

# 检查文件权限
ls -la api/index.php
ls -la api/data/
```

**解决方案**：
- 端口被占用：修改端口号 `php -S 127.0.0.1:8002 ...`
- 权限问题：`chmod -R 755 api/ && chown -R www-data:www-data api/`
- 数据库目录权限：`chmod -R 777 api/data/`（开发环境）

#### 问题 2：数据库错误："SQLSTATE[HY000]: General error: 14 unable to open database file"

**现象**：API 返回数据库打开失败

**排查步骤**：
```bash
# 检查数据库文件和目录权限
ls -la api/data/
ls -la api/data/app.sqlite

# 检查 SELinux 状态（Linux）
sestatus

# 检查 PHP 进程用户
ps aux | grep "php -S"
```

**解决方案**：
```bash
# 设置正确的权限
chmod 755 api/data
chmod 666 api/data/app.sqlite

# 或者修改 PHP 运行用户
php -S 127.0.0.1:8001 -t api api/index.php -c php.ini
```

#### 问题 3：前端无法调用 API，出现 CORS 错误

**现象**：浏览器控制台显示 "Access-Control-Allow-Origin" 错误

**排查步骤**：
```bash
# 检查后端响应头
curl -I http://127.0.0.1:8001/api/definition

# 检查 Vite 代理配置
cat vite.config.ts
```

**解决方案**：
- 确保 `api/index.php` 中的 CORS 头已正确设置
- 开发环境使用 Vite 代理，确保 `vite.config.ts` 中的代理目标正确
- 生产环境配置 Nginx 的 CORS 头

#### 问题 4：状态迁移失败，提示 "状态不允许的迁移"

**现象**：触发事件时返回状态迁移不允许

**排查步骤**：
```bash
# 查看账户当前状态
curl "http://127.0.0.1:8001/api/accounts/{id}?operator=admin_01"

# 查看状态机定义
curl "http://127.0.0.1:8001/api/definition"

# 查看操作历史
curl "http://127.0.0.1:8001/api/accounts/{id}/history"
```

**常见原因**：
1. 当前状态不支持该事件（例如 draft 状态不能直接 freeze）
2. 操作人角色无权执行该事件
3. 守卫校验失败（如缺少银行账号、缺少原因等）
4. 审核与冻结联动校验触发（冻结状态下无法审核通过）

#### 问题 5：审核通过时提示 "账户当前存在冻结记录"

**现象**：审核通过时返回联动校验错误

**排查步骤**：
```sql
-- 查询账户冻结状态
SELECT id, supplier_name, status, frozen_at, freeze_reason
FROM accounts WHERE id = {account_id};

-- 查询冻结历史
SELECT event, from_status, to_status, operator, reason, created_at
FROM state_transitions
WHERE account_id = {account_id} AND event IN ('freeze', 'unfreeze', 'rollback_freeze')
ORDER BY id DESC;
```

**解决方案**：
1. 先解冻账户：`POST /api/accounts/{id}/unfreeze`
2. 或使用回滚冻结：`POST /api/accounts/{id}/rollback_freeze`（需 admin 角色）
3. 确认冻结记录已清除后再执行审核通过

#### 问题 6：冻结账户时提示 "账户正处于待审核流程中"

**现象**：待审核状态下执行冻结返回错误

**排查步骤**：
```sql
-- 查询账户审核状态
SELECT id, supplier_name, status, submitted_at, reviewed_at
FROM accounts WHERE id = {account_id};
```

**解决方案**：
1. 先完成审核流程（通过或驳回）
2. 审核通过后再执行冻结
3. 或使用 admin 角色先回滚提交，再执行冻结

#### 问题 7：权限错误："当前角色无权执行"

**现象**：返回 HTTP 403 权限错误

**排查步骤**：
```bash
# 检查操作人前缀
# supplier_* / sup_ → 供应商运营
# reviewer_* / rev_ → 审核专员
# risk_* / rsk_ → 风控专员
# admin_* / adm_ → 系统管理员

# 查看事件权限矩阵
curl "http://127.0.0.1:8001/api/definition" | python3 -c "
import sys, json
d = json.load(sys.stdin)
for event, roles in d['event_permissions'].items():
    print(f'{event}: {roles}')
"
```

**解决方案**：
- 使用正确前缀的操作人账号
- 联系管理员授权
- 检查环境变量 `OPERATOR_AUTH_ENABLED` 是否为 true

### 8.2 数据修复

当数据出现异常时，可使用以下 SQL 进行修复：

```sql
-- 场景1：手动修正账户状态（慎用！）
UPDATE accounts
SET status = 'active',
    reviewed_at = strftime('%s', 'now'),
    frozen_at = NULL,
    updated_at = strftime('%s', 'now')
WHERE id = {account_id};

-- 场景2：清除异常冻结标记
UPDATE accounts
SET frozen_at = NULL,
    freeze_reason = NULL,
    updated_at = strftime('%s', 'now')
WHERE id = {account_id};

-- 场景3：清除待审核状态（回滚到 draft）
UPDATE accounts
SET status = 'draft',
    submitted_at = NULL,
    review_reason = NULL,
    updated_at = strftime('%s', 'now')
WHERE id = {account_id};

-- 场景4：修复重复的历史记录（谨慎操作）
-- 先查询重复记录
SELECT account_id, event, created_at, COUNT(*) as cnt
FROM state_transitions
GROUP BY account_id, event, created_at
HAVING cnt > 1;

-- 删除重复记录（保留 ID 最大的）
DELETE FROM state_transitions
WHERE id NOT IN (
    SELECT MAX(id)
    FROM state_transitions
    GROUP BY account_id, event, created_at
);
```

### 8.3 紧急联系

如遇到无法解决的问题，请联系：
- 技术负责人：[联系方式]
- 业务负责人：[联系方式]
- 运维负责人：[联系方式]

---

## 附录

### A. 状态机事件完整列表

| 事件标识 | 事件名称 | 起始状态 | 目标状态 | 前置条件 |
|----------|----------|----------|----------|----------|
| submit | 提交审核 | draft | pending_review | 必须填写银行账号 |
| approve | 审核通过 | pending_review | active | 必须有银行账号，无冻结记录 |
| reject | 审核驳回 | pending_review | rejected | 必须填写驳回原因 |
| resubmit | 重新提交 | rejected | pending_review | 必须填写银行账号 |
| freeze | 冻结账户 | active | frozen | 必须填写冻结原因，非待审核状态 |
| unfreeze | 解冻账户 | frozen | active | 必须填写解冻原因 |
| disable | 停用账户 | 任意非终态 | disabled | 仅 admin 可操作 |
| enable | 重新启用 | disabled | draft | 仅 admin 可操作 |
| rollback_submit | 回滚提交 | pending_review | draft | 必须填写回滚原因 |
| rollback_approve | 回滚审核通过 | active | pending_review | 必须填写回滚原因，无冻结记录 |
| rollback_reject | 回滚审核驳回 | rejected | pending_review | 必须填写回滚原因 |
| rollback_freeze | 回滚冻结 | frozen | active | 必须填写回滚原因 |

### B. HTTP 状态码说明

| 状态码 | 说明 | 场景 |
|--------|------|------|
| 200 | 成功 | GET 请求成功、POST/PUT 操作成功 |
| 201 | 已创建 | POST 创建资源成功 |
| 204 | 无内容 | OPTIONS 请求 |
| 400 | 请求错误 | 参数格式错误、未知事件 |
| 403 | 禁止访问 | 角色权限不足 |
| 404 | 资源不存在 | 账户不存在、路径错误 |
| 422 | 不可处理实体 | 状态迁移不允许、守卫校验失败 |
| 500 | 服务器错误 | 数据库错误、代码异常 |

### C. 变更日志

| 版本 | 日期 | 说明 | 变更人 |
|------|------|------|--------|
| 1.0.0 | 2024-01-01 | 初始版本，包含完整部署文档 | - |
| 1.1.0 | 2024-01-15 | 补充审核与冻结环境变量配置、验收命令 | - |

---

**文档版本**：1.1.0
**最后更新**：2024-01-15
**维护者**：技术团队
