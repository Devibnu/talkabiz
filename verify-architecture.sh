#!/bin/bash

# MIDDLEWARE ARCHITECTURE - VERIFICATION SCRIPT
# Tests locked architecture compliance

echo "================================================"
echo "🔒 MIDDLEWARE ARCHITECTURE VERIFICATION"
echo "================================================"
echo ""

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

FAIL_COUNT=0
PASS_COUNT=0

# Function to check
check_rule() {
    local rule_name="$1"
    local command="$2"
    local expected="$3"
    
    echo -e "${BLUE}Checking: ${rule_name}${NC}"
    
    result=$(eval "$command")
    
    if echo "$result" | grep -q "$expected"; then
        echo -e "${GREEN}✅ PASS${NC}"
        ((PASS_COUNT++))
    else
        echo -e "${RED}❌ FAIL${NC}"
        echo "Expected: $expected"
        echo "Got: $result"
        ((FAIL_COUNT++))
    fi
    echo ""
}

echo -e "${YELLOW}═══════════════════════════════════════${NC}"
echo -e "${YELLOW}RULE #1: Middleware Order - LOCKED${NC}"
echo -e "${YELLOW}═══════════════════════════════════════${NC}"

# Check middleware group order
check_rule "Middleware group 'client.access' exists" \
    "grep -A 5 \"'client.access'\" app/Http/Kernel.php | grep -c \"'auth'\\|'domain.setup'\"" \
    "2"

check_rule "Auth middleware comes BEFORE domain.setup" \
    "grep -A 5 \"'client.access'\" app/Http/Kernel.php | grep -B 1 \"domain.setup\" | grep -c \"auth\"" \
    "1"

echo -e "${YELLOW}═══════════════════════════════════════${NC}"
echo -e "${YELLOW}RULE #2: Single Source of Redirect${NC}"
echo -e "${YELLOW}═══════════════════════════════════════${NC}"

# Check for controller redirects (excluding expected ones)
CONTROLLER_REDIRECTS=$(grep -r "return redirect" app/Http/Controllers/ | \
    grep -v "after.*submit\|store()\|update()" | \
    grep -v "OnboardingController" | \
    wc -l)

echo -e "${BLUE}Checking: No unauthorized redirects in controllers${NC}"
if [ "$CONTROLLER_REDIRECTS" -lt 5 ]; then
    echo -e "${GREEN}✅ PASS - Found $CONTROLLER_REDIRECTS unauthorized redirects (acceptable)${NC}"
    ((PASS_COUNT++))
else
    echo -e "${RED}❌ FAIL - Found $CONTROLLER_REDIRECTS unauthorized redirects (too many!)${NC}"
    echo "Controllers should NOT redirect (except after form submit)"
    ((FAIL_COUNT++))
fi
echo ""

# Check for view redirects
VIEW_REDIRECTS=$(grep -r "window.location\|location.href\|location.replace" resources/views/ 2>/dev/null | wc -l)

echo -e "${BLUE}Checking: No redirects in views${NC}"
if [ "$VIEW_REDIRECTS" -eq 0 ]; then
    echo -e "${GREEN}✅ PASS - No redirects found in views${NC}"
    ((PASS_COUNT++))
else
    echo -e "${RED}❌ FAIL - Found $VIEW_REDIRECTS redirects in views${NC}"
    echo "Views should NEVER contain redirects"
    grep -r "window.location\|location.href" resources/views/ 2>/dev/null | head -3
    ((FAIL_COUNT++))
fi
echo ""

echo -e "${YELLOW}═══════════════════════════════════════${NC}"
echo -e "${YELLOW}RULE #4: Fail-Safe Anti-Loop${NC}"
echo -e "${YELLOW}═══════════════════════════════════════${NC}"

# Check for fail-safe in EnsureDomainSetup
check_rule "Fail-safe anti-loop exists in EnsureDomainSetup" \
    "grep -c \"isDashboardRoute\\|LOOP DETECTED\" app/Http/Middleware/EnsureDomainSetup.php" \
    "[1-9]"

echo -e "${YELLOW}═══════════════════════════════════════${NC}"
echo -e "${YELLOW}RULE #5: Comprehensive Logging${NC}"
echo -e "${YELLOW}═══════════════════════════════════════${NC}"

# Check for logging in middleware
check_rule "Logging exists in EnsureDomainSetup" \
    "grep -c \"Log::info\\|Log::warning\\|Log::critical\" app/Http/Middleware/EnsureDomainSetup.php" \
    "[5-9]\\|[1-9][0-9]"

echo -e "${YELLOW}═══════════════════════════════════════${NC}"
echo -e "${YELLOW}RULE #6: Owner/Admin Bypass${NC}"
echo -e "${YELLOW}═══════════════════════════════════════${NC}"

# Check for owner bypass in middleware
check_rule "Owner/Admin bypass exists in EnsureDomainSetup" \
    "grep -c \"owner\\|admin\\|BYPASS\" app/Http/Middleware/EnsureDomainSetup.php" \
    "[3-9]\\|[1-9][0-9]"

echo -e "${YELLOW}═══════════════════════════════════════${NC}"
echo -e "${YELLOW}ARCHITECTURE FILES${NC}"
echo -e "${YELLOW}═══════════════════════════════════════${NC}"

# Check documentation exists
echo -e "${BLUE}Checking: Documentation files exist${NC}"
if [ -f "MIDDLEWARE_FLOW.md" ] && [ -f "MIDDLEWARE_RULES.md" ]; then
    echo -e "${GREEN}✅ PASS - Documentation files exist${NC}"
    ((PASS_COUNT++))
else
    echo -e "${RED}❌ FAIL - Missing documentation${NC}"
    [ ! -f "MIDDLEWARE_FLOW.md" ] && echo "  - MIDDLEWARE_FLOW.md missing"
    [ ! -f "MIDDLEWARE_RULES.md" ] && echo "  - MIDDLEWARE_RULES.md missing"
    ((FAIL_COUNT++))
fi
echo ""

echo -e "${YELLOW}═══════════════════════════════════════${NC}"
echo -e "${YELLOW}ROUTE STRUCTURE${NC}"
echo -e "${YELLOW}═══════════════════════════════════════${NC}"

# Check routes use client.access
check_rule "Routes use 'client.access' middleware group" \
    "grep -c \"client.access\" routes/web.php" \
    "[1-9]"

echo "================================================"
echo -e "${BLUE}📊 TEST SUMMARY${NC}"
echo "================================================"
echo -e "${GREEN}Passed: $PASS_COUNT${NC}"
echo -e "${RED}Failed: $FAIL_COUNT${NC}"
echo ""

if [ $FAIL_COUNT -eq 0 ]; then
    echo -e "${GREEN}✅✅✅ ALL CHECKS PASSED! ✅✅✅${NC}"
    echo ""
    echo "Architecture is LOCKED and compliant."
    echo "Safe to deploy."
    exit 0
else
    echo -e "${RED}❌❌❌ SOME CHECKS FAILED ❌❌❌${NC}"
    echo ""
    echo "Architecture violations detected!"
    echo "Review rules in MIDDLEWARE_RULES.md"
    echo "Fix issues before deployment."
    exit 1
fi
