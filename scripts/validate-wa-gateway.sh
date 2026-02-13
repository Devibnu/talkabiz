#!/bin/bash

# ============================================================
# WhatsApp Gateway Validation Script
# Run this to verify everything is working
# ============================================================

echo "🔍 WhatsApp Gateway Validation"
echo "================================"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Track failures
FAILURES=0

# 1. Check Laravel .env
echo "1️⃣  Checking Laravel .env..."
if grep -q "WHATSAPP_GATEWAY_URL" .env 2>/dev/null; then
    echo -e "   ${GREEN}✓${NC} WHATSAPP_GATEWAY_URL configured"
else
    echo -e "   ${RED}✗${NC} WHATSAPP_GATEWAY_URL missing in .env"
    FAILURES=$((FAILURES + 1))
fi

if grep -q "WHATSAPP_GATEWAY_API_KEY" .env 2>/dev/null; then
    echo -e "   ${GREEN}✓${NC} WHATSAPP_GATEWAY_API_KEY configured"
else
    echo -e "   ${RED}✗${NC} WHATSAPP_GATEWAY_API_KEY missing in .env"
    FAILURES=$((FAILURES + 1))
fi

# 2. Check Gateway .env
echo ""
echo "2️⃣  Checking Gateway .env..."
if [ -f "wa-gateway/.env" ]; then
    echo -e "   ${GREEN}✓${NC} wa-gateway/.env exists"
else
    echo -e "   ${RED}✗${NC} wa-gateway/.env missing"
    FAILURES=$((FAILURES + 1))
fi

# 3. Check Gateway directories
echo ""
echo "3️⃣  Checking Gateway directories..."
if [ -d "wa-gateway/sessions" ]; then
    echo -e "   ${GREEN}✓${NC} sessions/ directory exists"
else
    echo -e "   ${RED}✗${NC} sessions/ directory missing"
    FAILURES=$((FAILURES + 1))
fi

if [ -d "wa-gateway/logs" ]; then
    echo -e "   ${GREEN}✓${NC} logs/ directory exists"
else
    echo -e "   ${RED}✗${NC} logs/ directory missing"
    FAILURES=$((FAILURES + 1))
fi

# 4. Check if node_modules exists
echo ""
echo "4️⃣  Checking Gateway dependencies..."
if [ -d "wa-gateway/node_modules" ]; then
    echo -e "   ${GREEN}✓${NC} node_modules installed"
else
    echo -e "   ${YELLOW}⚠${NC} node_modules not installed"
    echo "      Run: cd wa-gateway && npm install"
    FAILURES=$((FAILURES + 1))
fi

# 5. Check Gateway health
echo ""
echo "5️⃣  Checking Gateway health..."
GATEWAY_HEALTH=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:3001/health 2>/dev/null)
if [ "$GATEWAY_HEALTH" == "200" ]; then
    echo -e "   ${GREEN}✓${NC} Gateway running on port 3001"
else
    echo -e "   ${YELLOW}⚠${NC} Gateway not running"
    echo "      Run: cd wa-gateway && npm run dev"
    FAILURES=$((FAILURES + 1))
fi

# 6. Check Laravel routes
echo ""
echo "6️⃣  Checking Laravel routes..."
ROUTES=$(php artisan route:list --name=whatsapp 2>/dev/null | grep -c whatsapp)
if [ "$ROUTES" -gt "4" ]; then
    echo -e "   ${GREEN}✓${NC} WhatsApp routes registered ($ROUTES routes)"
else
    echo -e "   ${RED}✗${NC} WhatsApp routes missing"
    FAILURES=$((FAILURES + 1))
fi

# 7. Check webhook route
echo ""
echo "7️⃣  Checking webhook endpoint..."
WEBHOOK_ROUTE=$(php artisan route:list --name=api.whatsapp.webhook 2>/dev/null | grep -c webhook)
if [ "$WEBHOOK_ROUTE" -gt "0" ]; then
    echo -e "   ${GREEN}✓${NC} Webhook endpoint registered"
else
    echo -e "   ${RED}✗${NC} Webhook endpoint missing"
    FAILURES=$((FAILURES + 1))
fi

# Summary
echo ""
echo "================================"
if [ "$FAILURES" -eq "0" ]; then
    echo -e "${GREEN}✅ All checks passed!${NC}"
    echo ""
    echo "Next steps:"
    echo "1. Start gateway: cd wa-gateway && npm run dev"
    echo "2. Start Laravel: php artisan serve"
    echo "3. Go to /whatsapp and click 'Hubungkan WhatsApp'"
else
    echo -e "${RED}❌ $FAILURES check(s) failed${NC}"
    echo ""
    echo "Fix the issues above before testing."
fi

echo ""
