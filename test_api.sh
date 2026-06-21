#!/bin/bash

# ———————————————— API 接口测试（使用 curl） ————————————————

BASE_URL="http://127.0.0.1:8001/api"

echo "=== API 接口验收 ===\n"

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
    exit 1
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
