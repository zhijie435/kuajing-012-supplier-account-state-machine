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
