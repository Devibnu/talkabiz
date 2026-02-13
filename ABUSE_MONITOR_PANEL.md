# Abuse Monitor Panel - Owner Dashboard

## Overview

**Abuse Monitor Panel** adalah dashboard khusus untuk Owner/Super Admin untuk memonitor, mengelola, dan mengambil tindakan terhadap akun klien yang memiliki perilaku abuse. Panel ini terintegrasi dengan **Abuse Scoring System** yang secara otomatis mendeteksi dan menilai perilaku abuse.

## URL Access

```
/owner/abuse-monitor
```

**Required Role:** `owner` atau `super_admin`

---

## Features

### 1. **Dashboard Statistics**
Menampilkan ringkasan real-time:
- **Total Tracked**: Jumlah total akun yang dipantau
- **None/Low/Medium/High/Critical**: Distribusi abuse level
- **Suspended Accounts**: Jumlah akun yang di-suspend
- **Requires Action**: Akun yang memerlukan tindakan manual
- **Recent Events (24h)**: Event abuse dalam 24 jam terakhir

### 2. **Filter & Sort**
- **Filter by Level**: All, Critical, High, Medium
- **Sort Options**:
  - Score (High to Low)
  - Score (Low to High)
  - Recent Activity
  - Oldest Activity

### 3. **Klien List Table**
Menampilkan semua klien dengan informasi:
- Nama klien & email
- Business type
- **Current Score** (nilai abuse)
- **Abuse Level** (none/low/medium/high/critical)
- **Policy Action** (none/throttle/require approval/suspend)
- **Last Event** (waktu event terakhir)
- **Action Buttons**:
  - 👁️ View Detail
  - 🔄 Reset Score
  - 🚫 Suspend / ✅ Approve

### 4. **Detail Modal**
Menampilkan informasi lengkap untuk klien tertentu:
- **Score Information**:
  - Current Score
  - Abuse Level
  - Policy Action
  - Status (Active/Suspended)
  - Last Event timestamp
  - Days Since Last Event
  - Notes
- **Event Statistics**:
  - Total Events
  - Last 24h Events
  - Last 7 Days Events
- **Recent Events Table**:
  - Time
  - Event Type (signal_type)
  - Severity
  - Points Added
  - Description

### 5. **Admin Actions** (dengan logging)

#### a. Reset Score
- Mereset abuse score ke 0
- Level menjadi "none"
- **Required**: Reason (wajib diisi)
- **Logged**: Ya

#### b. Suspend Klien
- Manual suspend akun
- Status klien menjadi "suspended"
- Policy action menjadi "suspend"
- **Required**: Reason (wajib diisi)
- **Logged**: Ya

#### c. Approve/Reactivate Klien
- Menghapus status suspend
- Policy action dikembalikan sesuai abuse level
- Status klien menjadi "aktif"
- **Optional**: Reason
- **Logged**: Ya

---

## Routes

```php
// List/Dashboard
Route::get('owner/abuse-monitor', [AbuseMonitorController::class, 'index'])
    ->name('abuse-monitor.index');

// Detail klien (AJAX)
Route::get('owner/abuse-monitor/{id}', [AbuseMonitorController::class, 'show'])
    ->name('abuse-monitor.show');

// Reset score
Route::post('owner/abuse-monitor/{id}/reset', [AbuseMonitorController::class, 'resetScore'])
    ->name('abuse-monitor.reset');

// Suspend klien
Route::post('owner/abuse-monitor/{id}/suspend', [AbuseMonitorController::class, 'suspendKlien'])
    ->name('abuse-monitor.suspend');

// Approve/reactivate klien
Route::post('owner/abuse-monitor/{id}/approve', [AbuseMonitorController::class, 'approveKlien'])
    ->name('abuse-monitor.approve');
```

---

## Controller Methods

### `index(Request $request)`
**Purpose**: Menampilkan dashboard dengan daftar klien dan statistik

**Parameters**:
- `level` (query): Filter by level (all, none, low, medium, high, critical)
- `search` (query): Search by klien name

**Returns**: View `abuse-monitor.index`

**Data**:
```php
[
    'abuseScores' => Paginator, // Paginated abuse scores with relations
    'stats' => array,           // Statistics from AbuseScoringService
    'recentHighRiskEvents' => Collection, // Recent high/critical events
    'level' => string,          // Current filter
    'search' => string|null,    // Search query
]
```

---

### `show($klienId)`
**Purpose**: Mendapatkan detail abuse score untuk klien tertentu (AJAX)

**Parameters**:
- `klienId` (route): ID klien

**Returns**: JSON

**Success Response** (200):
```json
{
  "success": true,
  "data": {
    "score": {
      "klien_name": "Toko Sejahtera",
      "user_email": "user@example.com",
      "business_type": "Toko",
      "current_score": 42.00,
      "abuse_level": "medium",
      "abuse_level_label": "Medium Risk",
      "policy_action": "throttle",
      "policy_action_label": "Rate Limited",
      "is_suspended": false,
      "badge_color": "warning",
      "last_event_at": "10 Feb 2026 14:30",
      "days_since_last_event": 1,
      "notes": "..."
    },
    "events": [
      {
        "id": 1,
        "signal_type": "excessive_messages",
        "severity": "medium",
        "abuse_points": 15,
        "description": "...",
        "detected_at": "2026-02-10 14:30:00",
        "evidence": {...}
      }
    ],
    "event_stats": {
      "total": 15,
      "last_24h": 3,
      "last_7d": 8
    }
  }
}
```

**Error Response** (404):
```json
{
  "success": false,
  "message": "No abuse score found"
}
```

---

### `resetScore(Request $request, $klienId)`
**Purpose**: Reset abuse score ke 0

**Parameters**:
- `klienId` (route): ID klien
- `reason` (body): Alasan reset (required, max 500 chars)

**Validation**:
```php
[
    'reason' => 'required|string|max:500'
]
```

**Process**:
1. Get abuse score
2. Call `AbuseScoringService::resetScore()`
3. Log action to Laravel log

**Log Format**:
```php
[
    'action' => 'reset_score',
    'klien_id' => 1,
    'klien_name' => 'Toko Sejahtera',
    'old_score' => 42.00,
    'old_level' => 'medium',
    'new_score' => 0,
    'new_level' => 'none',
    'reason' => 'Approved by owner',
    'admin_id' => 1,
    'admin_name' => 'Admin Name',
    'timestamp' => '2026-02-11 10:00:00',
    'ip' => '127.0.0.1'
]
```

**Success Response** (200):
```json
{
  "success": true,
  "message": "Abuse score reset successfully"
}
```

**Error Response** (400/500):
```json
{
  "success": false,
  "message": "Failed to reset abuse score"
}
```

---

### `suspendKlien(Request $request, $klienId)`
**Purpose**: Suspend klien secara manual

**Parameters**:
- `klienId` (route): ID klien
- `reason` (body): Alasan suspend (required, max 500 chars)

**Validation**:
```php
[
    'reason' => 'required|string|max:500'
]
```

**Process**:
1. Get abuse score
2. **Update abuse_scores table**:
   - `is_suspended = true`
   - `policy_action = 'suspend'`
   - `notes = reason`
3. **Update klien table**:
   - `status = 'suspended'` (if currently 'aktif')
4. Log action

**Transaction**: ✅ Yes (rollback on error)

**Log Format**:
```php
[
    'action' => 'suspend',
    'klien_id' => 1,
    'klien_name' => 'Toko Sejahtera',
    'abuse_score' => 85.00,
    'abuse_level' => 'high',
    'reason' => 'Multiple policy violations',
    'admin_id' => 1,
    'admin_name' => 'Admin Name',
    'timestamp' => '2026-02-11 10:00:00',
    'ip' => '127.0.0.1'
]
```

**Success Response** (200):
```json
{
  "success": true,
  "message": "Klien suspended successfully"
}
```

---

### `approveKlien(Request $request, $klienId)`
**Purpose**: Approve/reactivate klien (unsuspend)

**Parameters**:
- `klienId` (route): ID klien
- `reason` (body): Alasan approve (optional, max 500 chars)

**Validation**:
```php
[
    'reason' => 'nullable|string|max:500'
]
```

**Process**:
1. Get abuse score
2. **Determine new policy** based on current abuse level:
   - Uses `config('abuse.policy_actions.{level}')`
   - Example: medium → throttle, high → require_approval
3. **Update abuse_scores table**:
   - `is_suspended = false`
   - `policy_action = new_policy`
   - `notes = reason`
4. **Update klien table**:
   - `status = 'aktif'` (if currently 'suspended')
5. Log action

**Transaction**: ✅ Yes (rollback on error)

**Success Response** (200):
```json
{
  "success": true,
  "message": "Klien approved/reactivated successfully"
}
```

---

## Frontend (Blade + JavaScript)

### View Structure

**File**: `resources/views/abuse-monitor/index.blade.php`

**Layout**: `layouts.user_type.auth`

**Components**:
1. **Page Header**
2. **Statistics Cards** (6 cards)
3. **Additional Stats** (3 cards)
4. **Main Content**:
   - Filter buttons
   - Sort dropdown
   - Data table
   - Pagination
5. **Modals**:
   - Detail Modal
   - Action Modal

### JavaScript Functionality

**Dependencies**: jQuery, SweetAlert2

#### 1. View Detail (`.btn-view-detail`)
```javascript
$('.btn-view-detail').on('click', function() {
    const scoreId = $(this).data('score-id');
    
    // Show modal
    $('#detailModal').modal('show');
    
    // AJAX call to get details
    $.ajax({
        url: `/owner/abuse-monitor/${scoreId}`,
        method: 'GET',
        success: function(response) {
            // Render score info
            // Render event statistics
            // Render event table
        }
    });
});
```

#### 2. Reset Score (`.btn-reset-score`)
```javascript
$('.btn-reset-score').on('click', function() {
    const scoreId = $(this).data('score-id');
    const klienName = $(this).data('klien-name');
    
    // Show action modal
    $('#actionModalTitle').text('Reset Abuse Score');
    $('#actionType').val('reset');
    $('#actionScoreId').val(scoreId);
    $('#actionWarning').text('You are about to reset...');
    $('#actionModal').modal('show');
});
```

#### 3. Suspend (`.btn-suspend`)
```javascript
$('.btn-suspend').on('click', function() {
    const scoreId = $(this).data('score-id');
    const klienName = $(this).data('klien-name');
    
    $('#actionModalTitle').text('Suspend Klien');
    $('#actionType').val('suspend');
    $('#actionModal').modal('show');
});
```

#### 4. Approve (`.btn-approve`)
```javascript
$('.btn-approve').on('click', function() {
    const scoreId = $(this).data('score-id');
    
    $('#actionModalTitle').text('Approve/Reactivate Klien');
    $('#actionType').val('approve');
    $('#actionModal').modal('show');
});
```

#### 5. Confirm Action (`#btnConfirmAction`)
```javascript
$('#btnConfirmAction').on('click', function() {
    const actionType = $('#actionType').val();
    const scoreId = $('#actionScoreId').val();
    const reason = $('#actionReason').val().trim();
    
    // Validate reason
    if ((actionType === 'reset' || actionType === 'suspend') && !reason) {
        // Show error
        return;
    }
    
    // AJAX call
    $.ajax({
        url: `/owner/abuse-monitor/${scoreId}/${actionType}`,
        method: 'POST',
        data: { reason: reason },
        success: function(response) {
            // Show success message
            // Reload page
        }
    });
});
```

---

## Database Impact

### Tables Modified

1. **`abuse_scores`**:
   - `is_suspended`: Updated on suspend/approve
   - `policy_action`: Updated on suspend/approve
   - `current_score`: Reset to 0 on reset
   - `abuse_level`: Set to 'none' on reset
   - `notes`: Updated with reason
   - `last_event_at`: Reset to NULL on reset
   - `metadata`: Updated on all actions

2. **`klien`**:
   - `status`: Changed between 'aktif' ↔ 'suspended'

### Logging

**Channel**: `daily` (Laravel default)

**Location**: `storage/logs/laravel-{date}.log`

**Format**:
```
[2026-02-11 10:00:00] local.INFO: Abuse Monitor Action {"action":"reset_score","klien_id":1,...}
```

---

## Integration with Abuse Scoring System

### Service Methods Used

```php
// Get statistics
$stats = $abuseService->getStatistics();
// Returns: ['total_tracked', 'by_level', 'suspended', 'requires_action', 'recent_events_24h']

// Get score with caching
$score = $abuseService->getScore($klienId);
// Returns: AbuseScore model or null

// Reset score
$success = $abuseService->resetScore($klienId, $reason);
// Returns: boolean
```

### Events Flow

```
User Action → Controller Method → Database Update → Log Action → Response
```

**Example: Suspend Flow**
```
1. Owner clicks "Suspend" button
2. Modal shows with reason field
3. Owner fills reason and confirms
4. AJAX POST to /owner/abuse-monitor/{id}/suspend
5. suspendKlien() method executed
6. Transaction begins
7. Update abuse_scores (is_suspended=true, policy_action='suspend')
8. Update klien (status='suspended')
9. Log action to Laravel log
10. Transaction commits
11. JSON response sent
12. Frontend shows success message
13. Page reloads
```

---

## Testing

### Test Script

**File**: `test-abuse-monitor.php`

**Run**:
```bash
php test-abuse-monitor.php
```

**Tests**:
1. ✅ Routes Registration (5 routes)
2. ✅ Controller Existence & Methods
3. ✅ View Existence & Components
4. ✅ Service Integration
5. ✅ Database Query Test
6. ✅ Action Methods Test
7. ✅ Authorization Check
8. ✅ Model Helper Methods
9. ✅ Filter & Sort Functionality
10. ✅ Log Integration

**Expected Result**: 10/10 tests passed ✅

---

## Usage Examples

### 1. View All High-Risk Klien

**URL**: `/owner/abuse-monitor?level=high`

**Result**: Shows only klien with abuse_level = 'high'

### 2. View Klien Details

```javascript
// Click "View Detail" button
// Modal shows:
// - Current score: 85.00
// - Level: High Risk
// - Policy: Require Approval
// - 15 recent events
// - Statistics: 25 total events, 5 in last 24h
```

### 3. Reset Score

```javascript
// Click "Reset Score" button
// Modal shows
// Fill reason: "Verified false-positive detection"
// Confirm
// Success: Score reset to 0, level to "none"
```

### 4. Suspend Abusive Klien

```javascript
// Click "Suspend" button
// Modal shows
// Fill reason: "Repeated spam violations"
// Confirm
// Success: Klien suspended, status updated
```

### 5. Reactivate Suspended Klien

```javascript
// Click "Approve" button (on suspended klien)
// Modal shows
// Fill reason: "Account reviewed and approved"
// Confirm
// Success: Klien reactivated, policy restored to level-based action
```

---

## Security

### Authorization
- **Middleware**: `auth`, `role:owner,super_admin`
- Only owner and super_admin can access
- All routes protected

### CSRF Protection
- All POST requests require CSRF token
- Token checked automatically by Laravel

### Input Validation
- `reason`: max 500 characters
- XSS protection by Laravel

### Database Transactions
- Used in suspend and approve actions
- Auto-rollback on error

### Logging
- All admin actions logged
- Includes: admin_id, klien_id, reason, timestamp, IP
- Immutable audit trail

---

## Best Practices

### 1. Regular Monitoring
- Check dashboard daily
- Focus on Critical and High levels first
- Review suspended accounts weekly

### 2. Action Guidelines

**When to Reset Score**:
- False-positive detections
- System errors
- After investigation proves innocence

**When to Suspend**:
- Repeated violations
- Critical abuse detected
- Safety concerns

**When to Approve**:
- Klien provides explanation
- Issue resolved
- Account verified clean

### 3. Documentation
- **Always provide meaningful reasons**
- Be specific in notes
- Document investigation process

### 4. Communication
- Inform klien before/after actions
- Provide explanation for suspension
- Offer support for improvement

---

## Troubleshooting

### Issue: Routes not found
**Solution**: Clear route cache
```bash
php artisan route:clear
php artisan route:cache
```

### Issue: Modal not showing
**Solution**: Check jQuery and Bootstrap JS are loaded
```html
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
```

### Issue: Action fails silently
**Solution**: Check Laravel logs
```bash
tail -f storage/logs/laravel.log
```

### Issue: Statistics not showing
**Solution**: Ensure abuse scoring system is running
```bash
php artisan config:clear
php artisan cache:clear
```

---

## Future Enhancements

### Potential Improvements:
1. **Bulk Actions**: Suspend/approve multiple klien at once
2. **Export Report**: Export abuse data to CSV/PDF
3. **Email Notifications**: Auto-notify owner of critical events
4. **Action History**: Dedicated table for action history
5. **Charts**: Visualize abuse trends over time
6. **Custom Filters**: Search by score range, date range
7. **Notes System**: Add internal notes for each klien
8. **Whitelist Management**: UI to manage whitelist

---

## Summary

**Abuse Monitor Panel** provides comprehensive abuse management capabilities for Owner/Super Admin:

✅ **Real-time monitoring** of all klien abuse scores  
✅ **Filter & sort** by level, score, activity  
✅ **Detailed event history** for investigation  
✅ **Admin actions** (reset, suspend, approve)  
✅ **Complete audit logging** for compliance  
✅ **Seamless integration** with Abuse Scoring System  
✅ **Secure & role-protected** access  
✅ **User-friendly interface** with modals  

**Access**: `/owner/abuse-monitor` (owner/super_admin only)

**All actions are logged for audit trail** ✅
