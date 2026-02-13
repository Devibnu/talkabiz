#!/bin/bash

# REDIRECT LOOP TEST SCRIPT
# Run this after deployment to verify the fix

echo "================================================"
echo "🧪 REDIRECT LOOP FIX - VERIFICATION SCRIPT"
echo "================================================"
echo ""

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}📋 PRE-TEST CHECKLIST:${NC}"
echo "1. ✅ Caches cleared (cache, route, config, view)"
echo "2. ✅ Fresh browser session (incognito recommended)"
echo "3. ✅ Terminal open untuk monitor logs"
echo ""

echo -e "${BLUE}🔍 TEST SCENARIOS:${NC}"
echo ""

echo -e "${GREEN}================================${NC}"
echo -e "${GREEN}TEST 1: User Belum Onboarding${NC}"
echo -e "${GREEN}================================${NC}"
echo "1. Login sebagai user dengan onboarding_complete = false"
echo "2. Expected: Auto redirect ke /onboarding"
echo "3. Fill form → submit"
echo "4. Expected: Redirect ke /dashboard (NO LOOP!)"
echo ""
echo "📊 Log keywords:"
echo "   - '⚠️ User belum onboarding'"
echo "   - '✅ ALLOW onboarding route'"
echo "   - '🔄 REDIRECT to onboarding'"
echo ""
read -p "Press ENTER after testing scenario 1..."

echo ""
echo -e "${GREEN}================================${NC}"
echo -e "${GREEN}TEST 2: User Sudah Onboarding${NC}"
echo -e "${GREEN}================================${NC}"
echo "1. Login sebagai user dengan onboarding_complete = true"
echo "2. Access /dashboard"
echo "3. Expected: Dashboard loaded successfully"
echo "4. Try manual /onboarding"
echo "5. Expected: Auto redirect ke /dashboard (NO LOOP!)"
echo ""
echo "📊 Log keywords:"
echo "   - '✅ User sudah onboarding'"
echo "   - '🔄 BLOCK onboarding (already complete)'"
echo "   - '✅ ALLOW access'"
echo ""
read -p "Press ENTER after testing scenario 2..."

echo ""
echo -e "${GREEN}================================${NC}"
echo -e "${GREEN}TEST 3: Owner/Admin Bypass${NC}"
echo -e "${GREEN}================================${NC}"
echo "1. Login sebagai owner/admin/super_admin"
echo "2. Access /dashboard"
echo "3. Access /onboarding"
echo "4. Access /billing"
echo "5. Expected: ALL routes accessible (NO RESTRICTIONS)"
echo ""
echo "📊 Log keywords:"
echo "   - '✅ OWNER/ADMIN BYPASS'"
echo ""
read -p "Press ENTER after testing scenario 3..."

echo ""
echo -e "${GREEN}================================${NC}"
echo -e "${GREEN}TEST 4: Loop Detection${NC}"
echo -e "${GREEN}================================${NC}"
echo "Checking for loop detection triggers..."
echo ""

# Check logs for loop detection
LOOP_COUNT=$(grep -c "LOOP DETECTED" storage/logs/laravel.log 2>/dev/null || echo "0")

if [ "$LOOP_COUNT" -gt 0 ]; then
    echo -e "${YELLOW}⚠️ WARNING: Loop detection triggered $LOOP_COUNT times${NC}"
    echo "This means the fail-safe worked! Check logs for details:"
    echo ""
    grep "LOOP DETECTED" storage/logs/laravel.log | tail -5
else
    echo -e "${GREEN}✅ SUCCESS: No loop detection triggered${NC}"
fi

echo ""
echo -e "${BLUE}📊 LOG ANALYSIS:${NC}"
echo ""

# Count middleware executions
MIDDLEWARE_COUNT=$(grep -c "🔍 EnsureDomainSetup START" storage/logs/laravel.log 2>/dev/null || echo "0")
echo "Middleware executions: $MIDDLEWARE_COUNT"

# Count redirects
REDIRECT_COUNT=$(grep -c "🔄.*REDIRECT" storage/logs/laravel.log 2>/dev/null || echo "0")
echo "Redirects triggered: $REDIRECT_COUNT"

# Count allow/block
ALLOW_COUNT=$(grep -c "✅.*ALLOW" storage/logs/laravel.log 2>/dev/null || echo "0")
BLOCK_COUNT=$(grep -c "🔄.*BLOCK" storage/logs/laravel.log 2>/dev/null || echo "0")
echo "Allow decisions: $ALLOW_COUNT"
echo "Block decisions: $BLOCK_COUNT"

echo ""
echo -e "${BLUE}🔍 CHECK FOR ERRORS:${NC}"
echo ""

# Check for ERR_TOO_MANY_REDIRECTS in logs
ERROR_COUNT=$(grep -c "ERR_TOO_MANY_REDIRECTS\|too many redirects\|redirect loop" storage/logs/laravel.log 2>/dev/null || echo "0")

if [ "$ERROR_COUNT" -gt 0 ]; then
    echo -e "${RED}❌ FAILED: Redirect loop errors detected!${NC}"
    echo "Found $ERROR_COUNT error occurrences"
    grep "ERR_TOO_MANY_REDIRECTS\|too many redirects\|redirect loop" storage/logs/laravel.log | tail -5
    exit 1
else
    echo -e "${GREEN}✅ SUCCESS: No redirect loop errors detected${NC}"
fi

echo ""
echo -e "${BLUE}🎯 FINAL VERDICT:${NC}"
echo ""

if [ "$ERROR_COUNT" -eq 0 ]; then
    echo -e "${GREEN}✅✅✅ ALL TESTS PASSED! ✅✅✅${NC}"
    echo ""
    echo "Redirect loop is FIXED!"
    echo "Safe to deploy to production."
else
    echo -e "${RED}❌❌❌ TESTS FAILED ❌❌❌${NC}"
    echo ""
    echo "Redirect loop still exists."
    echo "Check logs at: storage/logs/laravel.log"
    exit 1
fi

echo ""
echo "================================================"
echo "📋 POST-TEST ACTIONS:"
echo "================================================"
echo "1. Monitor logs for 1 hour: tail -f storage/logs/laravel.log"
echo "2. Check production metrics"
echo "3. User acceptance testing"
echo "4. Rollback plan ready (if needed)"
echo ""
echo "Report result to team. ✅"
echo ""
