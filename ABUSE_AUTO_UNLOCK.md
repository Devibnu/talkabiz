# Abuse Auto-Unlock System

## 📋 Overview

The Abuse Auto-Unlock System automatically monitors and releases temporarily suspended users when their cooldown period expires and their abuse score has improved. This system runs daily as a scheduled task and provides complete audit logging of all actions.

## ✨ Features

- ✅ **Automatic Monitoring**: Daily checks of all temporarily suspended users
- ✅ **Cooldown Tracking**: Validates cooldown period completion
- ✅ **Score Verification**: Ensures score has dropped below threshold
- ✅ **Score Improvement Check**: Confirms score has decreased since suspension
- ✅ **Automatic Unlocking**: Auto-unlocks eligible users without manual intervention
- ✅ **Approval Status Update**: Updates approval status (auto-approved or pending)
- ✅ **Complete Audit Logging**: Logs all checks and unlock actions
- ✅ **Manual Override**: Supports manual testing and specific user checks
- ✅ **Dry Run Mode**: Preview changes before applying them

## 🗄️ Database Schema

### New Fields in `abuse_scores` Table

```sql
suspended_at                    TIMESTAMP NULL
suspension_type                 ENUM('none', 'temporary', 'permanent') DEFAULT 'none'
suspension_cooldown_days        INT NULL
approval_status                 ENUM('none', 'pending', 'approved', 'rejected', 'auto_approved') DEFAULT 'none'
approval_status_changed_at      TIMESTAMP NULL
approval_changed_by             BIGINT UNSIGNED NULL
```

**Indexes:**
- `suspension_type`
- `approval_status`
- `(is_suspended, suspension_type)`

## ⚙️ Configuration

Configuration is located in `config/abuse.php` under the `suspension_cooldown` section:

```php
'suspension_cooldown' => [
    'enabled' => true,                          // Enable/disable feature
    'default_temp_suspension_days' => 7,        // Default cooldown period
    'auto_unlock_enabled' => true,              // Enable automatic unlocking
    'auto_unlock_score_threshold' => 30,        // Score must be below this
    'require_score_improvement' => true,        // Score must have decreased
    'min_cooldown_days' => 3,                   // Minimum cooldown
    'max_cooldown_days' => 30,                  // Maximum cooldown
    'check_frequency' => 'daily',               // Check frequency
    'approval_on_unlock' => false,              // Require approval after unlock
    'notify_on_unlock' => true,                 // Send notification when unlocked
    'log_all_checks' => true,                   // Log every check
]
```

## 🔄 How It Works

### 1. Daily Schedule

The system runs automatically every day at **03:30 AM** via Laravel scheduler:

```php
$schedule->command('abuse:check-suspended')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->name('abuse-check-suspended-users');
```

**Note:** Runs 30 minutes after the `abuse:decay` command to ensure scores are updated first.

### 2. Eligibility Criteria

A user is eligible for auto-unlock when **ALL** of the following conditions are met:

1. ✅ **Temporarily Suspended**: `is_suspended = true` AND `suspension_type = 'temporary'`
2. ✅ **Cooldown Expired**: Current date >= `suspended_at + suspension_cooldown_days`
3. ✅ **Score Below Threshold**: `current_score < auto_unlock_score_threshold` (default: 30)
4. ✅ **Score Improved**: `current_score < metadata['score_at_suspension']` (if required)

### 3. Unlock Process

When a user meets all criteria:

1. **Update Database**:
   ```php
   is_suspended = false
   suspension_type = 'none'
   approval_status = 'auto_approved' (or 'pending' if approval required)
   approval_status_changed_at = now()
   ```

2. **Update Metadata**:
   ```php
   metadata['auto_unlocked_at'] = timestamp
   metadata['score_at_unlock'] = current_score
   metadata['cooldown_completed'] = true
   ```

3. **Log Action** (to `storage/logs/laravel.log`):
   ```
   [INFO] User auto-unlocked after cooldown
   - klien_id: 123
   - old_score: 85.00
   - current_score: 25.00
   - suspended_at: 2026-02-03
   - cooldown_days: 7
   ```

4. **Send Notification** (if enabled):
   - Email/SMS notification to user
   - Dashboard notification

## 🖥️ Command Usage

### Basic Usage

```bash
# Check all temporarily suspended users
php artisan abuse:check-suspended

# Dry run mode (preview without applying changes)
php artisan abuse:check-suspended --dry-run

# Check specific user
php artisan abuse:check-suspended --klien=123

# Force (skip confirmations)
php artisan abuse:check-suspended --force
```

### Command Output Example

```
🔍 Checking temporarily suspended users...

Found 5 temporarily suspended user(s)

 5/5 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

📊 Summary:
+-------------------------+-------+
| Metric                  | Count |
+-------------------------+-------+
| Total Checked           | 5     |
| ✅ Auto-Unlocked        | 2     |
| ⏳ Cooldown Pending     | 1     |
| ⚠️  Score Too High       | 1     |
| 📉 No Score Improvement | 1     |
| ❌ Errors               | 0     |
+-------------------------+-------+

✅ Successfully unlocked 2 user(s)
```

## 📊 Result Categories

| Category | Description | Action |
|----------|-------------|--------|
| **Auto-Unlocked** | User met all criteria and was unlocked | ✅ Unlocked |
| **Cooldown Pending** | Cooldown period not yet expired | ⏳ Wait |
| **Score Too High** | Score still above threshold | ⚠️ Wait for decay |
| **No Improvement** | Score hasn't decreased since suspension | 📉 Wait for events |
| **Errors** | Exception during processing | ❌ Check logs |

## 🔍 Model Helper Methods

### AbuseScore Model

```php
// Check suspension type
$abuseScore->isTemporarilySuspended();  // bool
$abuseScore->isPermanentlySuspended();  // bool

// Check cooldown status
$abuseScore->hasCooldownEnded();        // bool
$abuseScore->cooldownDaysRemaining();   // int|null

// Check unlock eligibility
$abuseScore->canAutoUnlock($threshold); // bool

// Check approval status
$abuseScore->isApprovalPending();       // bool
$abuseScore->isApproved();              // bool
```

## 📝 Usage Examples

### Example 1: Temporarily Suspend a User

```php
use App\Models\AbuseScore;
use App\Services\AbuseScoringService;

$abuseScoringService = app(AbuseScoringService::class);
$klienId = 123;

// Get or create abuse score
$abuseScore = AbuseScore::firstOrCreate(
    ['klien_id' => $klienId],
    ['current_score' => 0]
);

// Temporarily suspend with 7-day cooldown
$abuseScore->update([
    'is_suspended' => true,
    'suspended_at' => now(),
    'suspension_type' => AbuseScore::SUSPENSION_TEMPORARY,
    'suspension_cooldown_days' => 7,
    'approval_status' => AbuseScore::APPROVAL_NONE,
    'metadata' => array_merge($abuseScore->metadata ?? [], [
        'score_at_suspension' => $abuseScore->current_score,
        'suspension_reason' => 'Multiple abuse violations',
    ]),
]);

Log::info("User temporarily suspended", [
    'klien_id' => $klienId,
    'score' => $abuseScore->current_score,
    'cooldown_days' => 7,
]);
```

### Example 2: Permanently Suspend a User

```php
$abuseScore->update([
    'is_suspended' => true,
    'suspended_at' => now(),
    'suspension_type' => AbuseScore::SUSPENSION_PERMANENT,
    'approval_status' => AbuseScore::APPROVAL_REJECTED,
    'metadata' => array_merge($abuseScore->metadata ?? [], [
        'suspension_reason' => 'Critical violation - manual review required',
    ]),
]);
```

### Example 3: Check if User Can Be Unlocked

```php
$abuseScore = AbuseScore::where('klien_id', 123)->first();
$threshold = config('abuse.suspension_cooldown.auto_unlock_score_threshold');

if ($abuseScore->canAutoUnlock($threshold)) {
    echo "✅ User is eligible for auto-unlock\n";
    echo "   - Cooldown ended: " . $abuseScore->hasCooldownEnded() . "\n";
    echo "   - Score: {$abuseScore->current_score} < {$threshold}\n";
    echo "   - Days remaining: " . $abuseScore->cooldownDaysRemaining() . "\n";
} else {
    echo "❌ User is NOT eligible for auto-unlock\n";
    
    if (!$abuseScore->hasCooldownEnded()) {
        echo "   Reason: Cooldown pending ({$abuseScore->cooldownDaysRemaining()} days)\n";
    } elseif ($abuseScore->current_score >= $threshold) {
        echo "   Reason: Score too high ({$abuseScore->current_score} >= {$threshold})\n";
    }
}
```

### Example 4: Manually Unlock a User

```php
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    $abuseScore->update([
        'is_suspended' => false,
        'suspension_type' => AbuseScore::SUSPENSION_NONE,
        'approval_status' => AbuseScore::APPROVAL_APPROVED,
        'approval_status_changed_at' => now(),
        'approval_changed_by' => auth()->id(),
        'metadata' => array_merge($abuseScore->metadata ?? [], [
            'manually_unlocked_at' => now()->toIso8601String(),
            'unlocked_by' => auth()->user()->name,
        ]),
    ]);
    
    Log::info("User manually unlocked", [
        'klien_id' => $abuseScore->klien_id,
        'unlocked_by' => auth()->id(),
    ]);
    
    DB::commit();
    echo "✅ User unlocked successfully\n";
} catch (Exception $e) {
    DB::rollBack();
    Log::error("Failed to unlock user", ['error' => $e->getMessage()]);
}
```

## 📊 Monitoring & Logging

### Log Entries

All checks and actions are logged to Laravel's daily log:

**Successful Unlock:**
```
[2026-02-11 03:30:15] production.INFO: User auto-unlocked after cooldown {
    "klien_id": 123,
    "klien_name": "ABC Company",
    "old_score": 85.00,
    "current_score": 25.00,
    "old_level": "critical",
    "new_level": "low",
    "suspended_at": "2026-02-03 10:15:00",
    "cooldown_days": 7,
    "score_threshold": 30,
    "approval_required": false
}
```

**Check (if log_all_checks enabled):**
```
[2026-02-11 03:30:15] production.INFO: Suspension check: score_too_high {
    "klien_id": 456,
    "current_score": 45.00,
    "cooldown_days_remaining": 0,
    "dry_run": false
}
```

**Error:**
```
[2026-02-11 03:30:15] production.ERROR: Failed to auto-unlock user {
    "klien_id": 789,
    "error": "Database connection lost",
    "trace": "..."
}
```

### Database Queries for Monitoring

**Find users pending auto-unlock:**
```sql
SELECT 
    k.nama_perusahaan,
    a.current_score,
    a.suspended_at,
    a.suspension_cooldown_days,
    DATEDIFF(NOW(), a.suspended_at) as days_suspended,
    a.suspension_cooldown_days - DATEDIFF(NOW(), a.suspended_at) as days_remaining
FROM abuse_scores a
JOIN klien k ON a.klien_id = k.id
WHERE a.is_suspended = 1
  AND a.suspension_type = 'temporary'
  AND a.suspended_at IS NOT NULL
  AND a.suspension_cooldown_days IS NOT NULL
ORDER BY days_remaining ASC;
```

**Count users by status:**
```sql
SELECT 
    CASE
        WHEN DATEDIFF(NOW(), suspended_at) >= suspension_cooldown_days 
             AND current_score < 30 THEN 'Ready to Unlock'
        WHEN DATEDIFF(NOW(), suspended_at) >= suspension_cooldown_days 
             AND current_score >= 30 THEN 'Score Too High'
        ELSE 'Cooldown Pending'
    END as status,
    COUNT(*) as count
FROM abuse_scores
WHERE is_suspended = 1
  AND suspension_type = 'temporary'
GROUP BY status;
```

## 🚨 Integration with Abuse Monitor Panel

The Owner Panel at `/owner/abuse-monitor` automatically shows temporary suspension status:

**UI Indicators:**
- 🔒 **Suspended (Temporary)** - Shows cooldown countdown
- 🔒 **Suspended (Permanent)** - No auto-unlock available
- ⏳ **Cooldown: X days remaining**
- ✅ **Eligible for Auto-Unlock** - Green badge when ready

**Manual Actions:**
- **Reset Score** - Manually reduce score and trigger unlock check
- **Approve** - Manually approve and unlock user
- **Extend Cooldown** - Add more days to suspension period

## ⚡ Performance Considerations

### Optimization Tips

1. **Index Usage**: Queries use composite indexes for fast lookups
2. **Batch Processing**: Progress bar tracks large batches
3. **Transaction Safety**: All updates wrapped in database transactions
4. **Overlap Prevention**: `withoutOverlapping()` prevents concurrent runs
5. **Selective Processing**: Use `--klien` for specific users

### Resource Usage

- **Memory**: ~10MB per 1000 users
- **Execution Time**: ~1-2 seconds per 1000 users
- **Database Load**: 1-2 queries per user
- **Log Size**: ~500 bytes per unlock action

## 🔧 Troubleshooting

### Issue: Users Not Being Unlocked

**Check:**
1. Is auto-unlock enabled?
   ```bash
   php artisan tinker
   config('abuse.suspension_cooldown.auto_unlock_enabled')
   ```

2. Is the cooldown period over?
   ```php
   $abuseScore->hasCooldownEnded()
   ```

3. Is the score below threshold?
   ```php
   $abuseScore->current_score < config('abuse.suspension_cooldown.auto_unlock_score_threshold')
   ```

4. Check logs:
   ```bash
   tail -f storage/logs/laravel.log | grep "auto-unlock"
   ```

### Issue: Command Not Running on Schedule

**Check:**
1. Laravel scheduler is running:
   ```bash
   crontab -l
   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
   ```

2. Verify command is scheduled:
   ```bash
   php artisan schedule:list | grep abuse
   ```

3. Test manually:
   ```bash
   php artisan abuse:check-suspended --dry-run
   ```

### Issue: Errors in Logs

**Common Causes:**
- Database connection issues
- Missing required fields in metadata
- Null values in cooldown calculation

**Solution:**
```bash
# Run with dry-run to preview
php artisan abuse:check-suspended --dry-run

# Check specific user
php artisan abuse:check-suspended --klien=123

# Review error logs
tail -n 100 storage/logs/laravel.log
```

## 🎯 Best Practices

### 1. Suspension Strategy

- ✅ **Use temporary suspensions** for first-time or minor violations
- ✅ **Set appropriate cooldown periods** (3-14 days based on severity)
- ✅ **Require approval for critical violations** instead of auto-unlock
- ✅ **Reserve permanent suspensions** for severe/repeated violations

### 2. Configuration Tuning

- ✅ **Score threshold**: Set based on your risk tolerance (20-50 recommended)
- ✅ **Cooldown period**: Balance between punishment and user experience
- ✅ **Require improvement**: Enable to prevent unlocking without progress
- ✅ **Approval on unlock**: Enable for high-risk accounts

### 3. Monitoring

- ✅ **Review logs daily** for unlock patterns
- ✅ **Track reoffense rates** after auto-unlock
- ✅ **Adjust thresholds** based on observed behavior
- ✅ **Alert on high unlock volumes** (may indicate system issue)

### 4. User Communication

- ✅ **Send suspension notifications** with cooldown info
- ✅ **Provide progress updates** during cooldown
- ✅ **Confirm unlock** with welcome-back message
- ✅ **Include prevention tips** to avoid future suspensions

## 📚 Related Documentation

- [ABUSE_SCORING_SYSTEM.md](./ABUSE_SCORING_SYSTEM.md) - Core abuse scoring system
- [ABUSE_MONITOR_PANEL.md](./ABUSE_MONITOR_PANEL.md) - Owner dashboard for monitoring
- [config/abuse.php](./config/abuse.php) - Complete configuration reference

## 🧪 Testing

Run the comprehensive test suite:

```bash
php test-auto-unlock.php
```

**Test Coverage:**
- ✅ Create temporarily suspended user
- ✅ Check cooldown status
- ✅ Verify auto-unlock eligibility
- ✅ Dry run mode
- ✅ Real unlock
- ✅ Verification of unlock
- ✅ High score rejection
- ✅ Cooldown pending rejection
- ✅ Schedule verification
- ✅ Configuration validation

## 🔐 Security Considerations

1. **Authorization**: Only automated system can auto-unlock (no user manipulation)
2. **Audit Trail**: All unlocks logged with timestamps and reasons
3. **Score Verification**: Multiple checks prevent premature unlocking
4. **Cooldown Enforcement**: Cannot bypass cooldown period
5. **Transaction Safety**: Rollback on any error prevents partial updates

## 📞 Support

For issues or questions:
- Check logs: `storage/logs/laravel.log`
- Run test: `php test-auto-unlock.php`
- Review config: `config/abuse.php`
- Manual command: `php artisan abuse:check-suspended --help`

---

**Last Updated:** February 11, 2026  
**Version:** 1.0.0  
**Status:** ✅ Production Ready
