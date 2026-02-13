# Recipient Complaint Loop System

## Overview

Sistem untuk menangkap dan memproses laporan spam/abuse dari recipients melalui webhook provider (Gupshup, Twilio, dll). System ini terintegrasi dengan Abuse Scoring System untuk melakukan risk escalation otomatis berdasarkan complaint patterns.

## Features

✅ **Multi-Provider Webhook Support**
- Gupshup complaint webhook dengan signature validation
- Twilio complaint webhook
- Generic complaint API untuk manual reports atau third-party integration

✅ **Configurable Scoring System**
- Base weights per complaint type (spam, abuse, phishing, inappropriate, frequency, other)
- Severity multipliers (low 1.0x, medium 1.5x, high 2.0x, critical 3.0x)
- Source multipliers (provider_webhook 1.0x, manual_report 0.8x, internal_flag 0.9x, third_party 0.7x)
- Provider multipliers (customizable per provider)

✅ **Automated Risk Escalation**
- **Critical Type Escalation**: Phishing dan abuse types → immediate 14-day temp suspension
- **Volume Escalation**: Auto-suspend setelah 10 complaints dalam 30 hari
- **Pattern Detection**: 
  - Same recipient ≥3 times dalam 90 hari
  - Same complaint type ≥5 times dalam 30 hari
  - Multiple sources (2+ sources) → elevated risk

✅ **Deduplication**
- 24-hour deduplication window
- Prevents score inflation dari repeated reports
- Configurable deduplication fields

✅ **Audit Trail**
- Comprehensive logging di Laravel logs
- Database storage dengan full metadata
- Tracks all actions (create, process, escalate)
- Links to abuse_events for complete audit history

✅ **Production-Ready**
- Database transactions untuk data consistency
- Comprehensive error handling
- Security middleware (signature validation, IP whitelist)
- Rate limiting protection
- Does NOT interfere dengan billing/topup flows

---

## Architecture

### Database Schema

**Table: `recipient_complaints`**

| Column | Type | Description |
|--------|------|-------------|
| id | bigint unsigned | Primary key |
| klien_id | bigint unsigned | Foreign key to kliens |
| recipient_phone | varchar(20) | Phone number yang melaporkan |
| complaint_type | enum | spam, abuse, phishing, inappropriate, frequency, other |
| complaint_source | enum | provider_webhook, manual_report, internal_flag, third_party |
| provider_name | varchar(50) | Nama provider (gupshup, twilio, etc) |
| message_id | varchar(100) | Message ID yang dilaporkan (nullable) |
| message_content | text | Sample content (first 500 chars) |
| reported_at | timestamp | Waktu laporan diterima |
| complaint_received_at | timestamp | Waktu webhook received |
| severity | enum | low, medium, high, critical |
| is_processed | boolean | Sudah diproses atau belum |
| abuse_score_impact | integer | Impact score (calculated) |
| abuse_event_id | bigint unsigned | Link to abuse_events (nullable) |
| action_taken | varchar(50) | Action yang diambil (nullable) |
| processed_at | timestamp | Waktu diproses (nullable) |
| processed_by | bigint unsigned | User yang process (nullable) |
| reporter_name | varchar(100) | Nama reporter (nullable) |
| complaint_reason | text | Alasan complaint (nullable) |
| metadata | json | Additional metadata |

**Indexes:**
- `klien_id` + `complaint_type`
- `klien_id` + `is_processed`
- `klien_id` + `severity`
- `provider_name` + `complaint_received_at`
- `recipient_phone`
- `message_id`
- `created_at`

**Foreign Keys:**
- `klien_id` → `kliens.id` (cascade on delete)
- `abuse_event_id` → `abuse_events.id` (set null on delete)
- `processed_by` → `users.id` (set null on delete)

---

## Configuration

All configuration di `config/abuse.php`:

### Complaint Weights

```php
'recipient_complaint_weights' => [
    'complaint_types' => [
        'spam' => 25,
        'abuse' => 50,
        'phishing' => 100,
        'inappropriate' => 35,
        'frequency' => 20,
        'other' => 15,
    ],
    
    'severity_multipliers' => [
        'low' => 1.0,
        'medium' => 1.5,
        'high' => 2.0,
        'critical' => 3.0,
    ],
    
    'source_multipliers' => [
        'provider_webhook' => 1.0,
        'manual_report' => 0.8,
        'internal_flag' => 0.9,
        'third_party' => 0.7,
    ],
    
    'provider_multipliers' => [
        'gupshup' => 1.0,
        'twilio' => 1.0,
        'vonage' => 1.0,
        'default' => 0.9,
    ],
],
```

### Escalation Rules

```php
'complaint_escalation' => [
    'critical_types' => ['phishing', 'abuse'],
    
    'volume_thresholds' => [
        'auto_suspend' => 10,        // Auto-suspend at 10 complaints in 30 days
        'require_approval' => 5,     // Require approval at 5 complaints
        'high_risk' => 3,           // Mark high risk at 3 complaints
        'timeframe_days' => 30,
    ],
    
    'pattern_detection' => [
        'same_recipient' => 3,       // ≥3 complaints from same recipient
        'same_type' => 5,           // ≥5 complaints of same type
        'multiple_sources' => 2,    // Complaints from 2+ sources
        'pattern_timeframe_days' => 90,
    ],
    
    'auto_actions' => [
        'create_abuse_event',
        'notify_admin',
        'log_to_audit',
    ],
    
    'rate_limit_severity' => 'high',
],
```

### Processing Options

```php
'complaint_processing' => [
    'auto_process' => true,
    'require_manual_review' => false,
    
    'deduplicate' => [
        'enabled' => true,
        'window_hours' => 24,
        'fields' => ['klien_id', 'recipient_phone', 'complaint_type'],
    ],
    
    'cumulative_scoring' => true,
    'immediate_enforcement' => true,
    'track_metadata' => true,
    
    'store_message_sample' => [
        'enabled' => true,
        'max_length' => 500,
    ],
],
```

---

## API Endpoints

### 1. Gupshup Webhook

**Endpoint:** `POST /api/webhook/complaints/gupshup`

**Security:** 
- Middleware: `gupshup.signature`, `gupshup.ip`
- Signature validation via X-Gupshup-Signature header
- IP whitelist validation

**Request Body (example):**
```json
{
  "eventType": "spam_report",
  "timestamp": 1707654321000,
  "destination": "6289876543210",
  "messageId": "gupshup_msg_abc123",
  "reason": "User reported as spam",
  "appName": "6281234567890"
}
```

**Response:**
```json
{
  "success": true,
  "complaint_id": 123,
  "message": "Complaint recorded successfully",
  "severity": "medium",
  "action_taken": "score_updated"
}
```

### 2. Twilio Webhook

**Endpoint:** `POST /api/webhook/complaints/twilio`

**Request Body (example):**
```json
{
  "MessageSid": "SMxxxxxxxxxxxxx",
  "From": "+6289876543210",
  "To": "+6281234567890",
  "MessageStatus": "failed",
  "ErrorCode": "30007",
  "ErrorMessage": "Message delivery failed due to spam report"
}
```

**Response:** Same as Gupshup

### 3. Generic Complaint API

**Endpoint:** `POST /api/webhook/complaints/generic`

**Security:** None (can be protected with API key if needed)

**Request Body:**
```json
{
  "klien_id": 123,
  "recipient_phone": "6289876543210",
  "complaint_type": "spam",
  "complaint_source": "manual_report",
  "provider_name": "system",
  "message_id": "msg_123",
  "reported_at": "2024-02-11T10:30:00Z",
  "reporter_name": "Admin",
  "complaint_reason": "Multiple complaints received via support",
  "metadata": {
    "ticket_id": "SUP-12345",
    "agent": "support@example.com"
  }
}
```

**Validation Rules:**
- `recipient_phone`: required, string, max:20
- `complaint_type`: required, in:spam,abuse,phishing,inappropriate,frequency,other
- `complaint_source`: required, in:provider_webhook,manual_report,internal_flag,third_party
- `klien_id`: nullable, exists:kliens
- `provider_name`: nullable, string, max:50
- `message_id`: nullable, string, max:100
- `reported_at`: nullable, date
- `reporter_name`: nullable, string, max:100
- `complaint_reason`: nullable, string, max:1000
- `metadata`: nullable, array

**Response:**
```json
{
  "success": true,
  "complaint_id": 124,
  "message": "Complaint recorded successfully"
}
```

**Error Response (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "complaint_type": ["The complaint type field is required."],
    "recipient_phone": ["The recipient phone field is required."]
  }
}
```

### 4. Test Endpoint (Development Only)

**Endpoint:** `POST /api/webhook/complaints/test`

**Environment:** Only accessible in local/dev

**Request Body:**
```json
{
  "complaint_type": "spam",
  "klien_id": 1
}
```

**Response:** Same as generic endpoint

---

## Service Methods

### AbuseScoringService

#### `recordComplaint($klienId, $recipientPhone, $complaintType, $complaintSource, $metadata = [])`

Main entry point untuk record complaint.

**Parameters:**
- `$klienId` (int): ID klien yang dilaporkan
- `$recipientPhone` (string): Phone number reporter
- `$complaintType` (string): spam|abuse|phishing|inappropriate|frequency|other
- `$complaintSource` (string): provider_webhook|manual_report|internal_flag|third_party
- `$metadata` (array): Additional data (provider_name, message_id, reported_at, dll)

**Returns:** `RecipientComplaint` model or existing complaint if duplicate

**Process Flow:**
1. Check deduplication (24-hour window)
2. Calculate severity based on history
3. Calculate score impact with multipliers
4. Create RecipientComplaint record
5. Auto-process if enabled (create abuse event)
6. Check escalation triggers
7. Return complaint model

**Example:**
```php
$complaint = app(AbuseScoringService::class)->recordComplaint(
    klienId: 123,
    recipientPhone: '6289876543210',
    complaintType: 'spam',
    complaintSource: 'provider_webhook',
    metadata: [
        'provider_name' => 'gupshup',
        'message_id' => 'msg_abc123',
        'reported_at' => now()->toIso8601String(),
    ]
);
```

#### `getComplaintStats($klienId, $days = 30)`

Get complaint statistics untuk klien.

**Returns:**
```php
[
    'total_complaints' => 15,
    'by_type' => [
        'spam' => 10,
        'phishing' => 2,
        'abuse' => 3,
    ],
    'by_severity' => [
        'low' => 8,
        'medium' => 4,
        'high' => 2,
        'critical' => 1,
    ],
    'unique_recipients' => 12,
    'critical_count' => 3,
    'unprocessed_count' => 2,
    'total_score_impact' => 450,
]
```

---

## Model Methods

### RecipientComplaint Model

#### Scopes

```php
// Get unprocessed complaints
RecipientComplaint::unprocessed()->get();

// Get processed complaints
RecipientComplaint::processed()->get();

// Get by type
RecipientComplaint::ofType('spam')->get();

// Get by severity
RecipientComplaint::bySeverity('critical')->get();

// Get critical complaints only
RecipientComplaint::critical()->get();

// Get high severity complaints
RecipientComplaint::highSeverity()->get();

// Get recent complaints (default 7 days)
RecipientComplaint::recent(30)->get();

// Get by provider
RecipientComplaint::fromProvider('gupshup')->get();

// Get for specific klien
RecipientComplaint::forKlien(123)->get();

// Get for specific recipient
RecipientComplaint::forRecipient('6289876543210')->get();
```

#### Helper Methods

```php
$complaint = RecipientComplaint::find(123);

// Check if requires immediate action
if ($complaint->requiresImmediateAction()) {
    // Critical or phishing/abuse type
}

// Get display name
echo $complaint->getTypeDisplayName(); // "Phishing"

// Get badge class for UI
echo $complaint->getSeverityBadgeClass(); // "badge-danger"

// Get summary text
echo $complaint->getSummary();
// "Phishing complaint from 6289876543210 (critical severity)"

// Mark as processed
$complaint->markAsProcessed($userId, 'temp_suspended');
```

---

## Escalation Logic

### 1. Critical Type Escalation

**Trigger:** Complaint type = 'phishing' OR 'abuse'

**Action:**
- Immediate temp suspension (14 days)
- Create critical abuse event
- Send admin notification
- Log to audit

**Code Location:** `AbuseScoringService::escalateCriticalComplaint()`

### 2. Volume Escalation

**Trigger:** Total complaints exceeds threshold dalam timeframe

**Thresholds:**
- 10 complaints dalam 30 hari → Auto-suspend
- 5 complaints dalam 30 hari → Require approval for next message
- 3 complaints dalam 30 hari → Mark as high risk

**Action:**
- Auto-suspend klien (temp_suspended, 7 days)
- Create abuse event dengan escalation metadata
- Log escalation reason

**Code Location:** `AbuseScoringService::checkVolumeEscalation()`

### 3. Pattern Detection Escalation

**Patterns Detected:**

**Pattern A: Same Recipient**
- ≥3 complaints dari recipient yang sama dalam 90 hari
- Indicates targeted harassment or unwanted messaging

**Pattern B: Same Type** 
- ≥5 complaints dengan type yang sama dalam 30 hari
- Indicates systematic abuse behavior

**Action:**
- Create abuse event dengan pattern metadata
- Add pattern score impact
- Log pattern detection

**Code Location:** `AbuseScoringService::checkPatternEscalation()`

---

## Integration dengan Rate Limiting

Complaints terintegrasi dengan Rate Limiting System:

1. **High-Risk Kliens:** Complaints meningkatkan risk_level, yang kemudian di-match oleh rate limit rules
2. **Dynamic Rate Limits:** Kliens dengan complaints history mendapat rate limits lebih ketat
3. **Endpoint Protection:** Critical endpoints (broadcast, campaign) get stricter limits untuk high-risk accounts

**Rate Limit Rules Example:**
```php
// Rules automatically apply ke kliens dengan high risk dari complaints
'risk_level' => 'high',
'max_requests' => 50,  // Reduced from 200
'window' => 60,
```

---

## Logging Strategy

### Application Logs (`storage/logs/laravel.log`)

**Log Levels:**
- `INFO`: Normal complaint recording, processing
- `WARNING`: Escalation triggers, pattern detection
- `CRITICAL`: Critical type complaints (phishing, abuse)
- `ERROR`: Processing errors, webhook failures

**Example Log:**
```
[2024-02-11 10:30:00] production.INFO: Recipient complaint recorded {"klien_id":123,"type":"spam","severity":"medium","score_impact":25}
[2024-02-11 10:30:01] production.WARNING: Volume escalation triggered {"klien_id":123,"complaint_count":10,"action":"auto_suspend"}
[2024-02-11 10:35:00] production.CRITICAL: Critical complaint received {"klien_id":456,"type":"phishing","action":"immediate_suspension"}
```

### Database Audit Trail

**Tables:**
- `recipient_complaints`: Full complaint records dengan metadata
- `abuse_events`: Linked abuse events untuk escalations
- `abuse_scores`: Score updates dan history

**Queryable Fields:**
- Timestamp tracking (complaint_received_at, processed_at, created_at)
- Action tracking (action_taken, is_processed, processed_by)
- Full metadata storage (JSON)
- Relationships (klien, abuse event, processor)

---

## Testing

### Manual Test Script

Create file `test-complaint-system.php`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Klien;
use App\Services\AbuseScoringService;

$service = app(AbuseScoringService::class);

// Test 1: Record spam complaint
echo "Test 1: Recording spam complaint...\n";
$klien = Klien::first();
$complaint1 = $service->recordComplaint(
    $klien->id,
    '6289876543210',
    'spam',
    'provider_webhook',
    ['provider_name' => 'gupshup', 'message_id' => 'test_001']
);
echo "✓ Complaint recorded: ID {$complaint1->id}, Severity: {$complaint1->severity}\n\n";

// Test 2: Record phishing (critical)
echo "Test 2: Recording phishing complaint (should trigger suspension)...\n";
$complaint2 = $service->recordComplaint(
    $klien->id,
    '6289876543211',
    'phishing',
    'provider_webhook',
    ['provider_name' => 'gupshup']
);
$klien->refresh();
echo "✓ Complaint recorded: Severity: {$complaint2->severity}\n";
echo "✓ Klien status: {$klien->status}\n\n";

// Test 3: Get statistics
echo "Test 3: Getting complaint statistics...\n";
$stats = $service->getComplaintStats($klien->id);
echo "✓ Total complaints: {$stats['total_complaints']}\n";
echo "✓ Critical count: {$stats['critical_count']}\n";
echo "✓ Score impact: {$stats['total_score_impact']}\n\n";

// Test 4: Deduplication
echo "Test 4: Testing deduplication (should return existing)...\n";
$complaint3 = $service->recordComplaint(
    $klien->id,
    '6289876543210',
    'spam',
    'provider_webhook',
    ['provider_name' => 'gupshup']
);
echo "✓ Is duplicate: " . ($complaint3->id === $complaint1->id ? 'Yes' : 'No') . "\n\n";

echo "All tests completed!\n";
```

Run with:
```bash
php test-complaint-system.php
```

### cURL Examples

**Test Gupshup Webhook:**
```bash
curl -X POST http://localhost:8000/api/webhook/complaints/gupshup \
  -H "Content-Type: application/json" \
  -H "X-Gupshup-Signature: your_signature_here" \
  -d '{
    "eventType": "spam_report",
    "timestamp": 1707654321000,
    "destination": "6289876543210",
    "messageId": "gupshup_msg_test",
    "reason": "Spam reported",
    "appName": "6281234567890"
  }'
```

**Test Generic Endpoint:**
```bash
curl -X POST http://localhost:8000/api/webhook/complaints/generic \
  -H "Content-Type: application/json" \
  -d '{
    "klien_id": 1,
    "recipient_phone": "6289876543210",
    "complaint_type": "spam",
    "complaint_source": "manual_report",
    "provider_name": "system",
    "complaint_reason": "Testing complaint system"
  }'
```

---

## Deployment Checklist

### 1. Database Migration
```bash
php artisan migrate
```

Verify tables created:
- `recipient_complaints` (with 25 columns, 10 indexes, 3 foreign keys)

### 2. Configuration Review

Check `config/abuse.php`:
- Verify complaint weights sesuai dengan business requirements
- Adjust escalation thresholds per needs
- Configure deduplication settings
- Set auto-processing preferences

### 3. Webhook Provider Setup

**Gupshup:**
1. Login ke Gupshup Partner Dashboard
2. Navigate to Webhooks section
3. Add webhook URL: `https://yourdomain.com/api/webhook/complaints/gupshup`
4. Configure webhook secret untuk signature validation
5. Enable "Spam Report" event

**Twilio:**
1. Login ke Twilio Console
2. Navigate to Messaging > Settings > Webhooks
3. Add Status Callback URL: `https://yourdomain.com/api/webhook/complaints/twilio`
4. Enable "Failed" message status

### 4. Security Configuration

**Environment Variables (.env):**
```env
GUPSHUP_WEBHOOK_SECRET=your_secret_here
GUPSHUP_WEBHOOK_IP_WHITELIST=52.66.99.71,52.66.99.72
```

**Middleware Configuration:**
- Ensure `gupshup.signature` middleware registered
- Ensure `gupshup.ip` middleware registered
- Verify IP whitelist up-to-date

### 5. Monitoring Setup

**Log Monitoring:**
```bash
tail -f storage/logs/laravel.log | grep complaint
```

**Database Monitoring:**
```sql
-- Check complaint volume
SELECT DATE(created_at) as date, COUNT(*) as count, AVG(abuse_score_impact) as avg_score
FROM recipient_complaints
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at);

-- Check critical complaints
SELECT * FROM recipient_complaints
WHERE severity = 'critical'
AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;

-- Check unprocessed complaints
SELECT COUNT(*) FROM recipient_complaints WHERE is_processed = 0;
```

### 6. Testing

Run manual tests untuk verify:
- ✓ Complaint recording works
- ✓ Score calculation correct
- ✓ Escalation triggers working
- ✓ Deduplication working
- ✓ Webhook endpoints accessible
- ✓ Security middleware functioning

### 7. Notification Setup (Optional)

Configure admin notifications untuk:
- Critical complaints received
- Volume escalation triggered
- Pattern detection alerts

Add to `app/Notifications/ComplaintNotification.php`:
```php
// Implement notification sending via email/Slack/etc
```

---

## Troubleshooting

### Issue: Webhooks not receiving

**Check:**
1. Webhook URL accessible from internet
2. SSL certificate valid (providers require HTTPS)
3. No firewall blocking provider IPs
4. Signature validation not blocking legitimate requests

**Debug:**
```bash
# Check logs
tail -f storage/logs/laravel.log | grep webhook

# Test webhook locally
curl -v POST http://localhost:8000/api/webhook/complaints/test \
  -H "Content-Type: application/json" \
  -d '{"complaint_type":"spam","klien_id":1}'
```

### Issue: Complaints not creating abuse events

**Check:**
1. `config/abuse.php` → `complaint_processing.auto_process` = true
2. AbuseScoringService not throwing errors (check logs)
3. Foreign keys exist (abuse_events table)

**Debug:**
```php
// Check auto-processing config
Config::get('abuse.complaint_processing.auto_process');

// Manually process complaint
$complaint = RecipientComplaint::find(123);
app(AbuseScoringService::class)->processComplaint($complaint);
```

### Issue: Deduplication not working

**Check:**
1. `config/abuse.php` → `complaint_processing.deduplicate.enabled` = true
2. Window hours configured (default 24)
3. Deduplication fields match (klien_id, recipient_phone, complaint_type)

**Debug:**
```php
// Check if complaint is duplicate
$isDuplicate = app(AbuseScoringService::class)->isDuplicateComplaint(
    $klienId, 
    $recipientPhone, 
    $complaintType
);
```

### Issue: Escalation not triggering

**Check:**
1. Escalation thresholds in config
2. Complaint count meets threshold
3. Klien status (already suspended?)
4. Logs for escalation attempts

**Debug:**
```sql
-- Check complaint count for klien
SELECT COUNT(*) FROM recipient_complaints
WHERE klien_id = 123
AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Check abuse events
SELECT * FROM abuse_events
WHERE klien_id = 123
AND signal_type = 'recipient_complaint'
ORDER BY created_at DESC;
```

---

## Maintenance

### Regular Tasks

**Daily:**
- Monitor unprocessed complaints count
- Check for critical complaints needing review
- Verify webhook failures

**Weekly:**
- Review escalation patterns
- Analyze complaint trends by type/provider
- Update provider multipliers if needed

**Monthly:**
- Review and adjust escalation thresholds
- Optimize deduplication window
- Clean up old processed complaints (optional archiving)

### Query Examples

```sql
-- Top kliens by complaint count (last 30 days)
SELECT k.id, k.business_name, COUNT(rc.id) as complaint_count, SUM(rc.abuse_score_impact) as total_impact
FROM kliens k
JOIN recipient_complaints rc ON rc.klien_id = k.id
WHERE rc.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY k.id
ORDER BY complaint_count DESC
LIMIT 20;

-- Complaint type distribution
SELECT complaint_type, COUNT(*) as count, AVG(abuse_score_impact) as avg_impact
FROM recipient_complaints
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY complaint_type;

-- Provider effectiveness (by average severity)
SELECT provider_name, 
       COUNT(*) as complaint_count,
       SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
       AVG(abuse_score_impact) as avg_impact
FROM recipient_complaints
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY provider_name;
```

---

## Performance Considerations

### Database Indexing

Indexes sudah optimal untuk common queries:
- `klien_id` + `created_at` → untuk time-series queries
- `recipient_phone` → untuk deduplication checks
- `message_id` → untuk provider lookups
- `klien_id` + `severity` → untuk risk assessment

### Caching Strategy

Consider caching untuk:
- Complaint stats per klien (TTL: 5 minutes)
- Recent complaints count (TTL: 1 minute)
- Critical complaints indicator (real-time)

**Example:**
```php
Cache::remember("complaints:stats:{$klienId}", 300, function() use ($klienId) {
    return app(AbuseScoringService::class)->getComplaintStats($klienId);
});
```

### Scaling Considerations

**High Volume (>1000 complaints/day):**
1. Implement queue untuk complaint processing
2. Use Redis untuk deduplication checks
3. Archive old complaints to separate table
4. Implement complaint stats aggregation

**Queue Implementation Example:**
```php
// In controller
dispatch(new ProcessComplaintJob($complaint));

// In job
class ProcessComplaintJob implements ShouldQueue {
    public function handle(AbuseScoringService $service) {
        $service->processComplaint($this->complaint);
    }
}
```

---

## Security Notes

### Webhook Security

1. **Signature Validation:** Gupshup webhooks validate HMAC signature
2. **IP Whitelisting:** Only allow provider IPs
3. **Rate Limiting:** Webhook endpoints have rate limits
4. **Input Validation:** All inputs validated before processing

### Data Protection

1. **Message Content:** Only store first 500 chars (configurable)
2. **Phone Numbers:** Store as-is (consider hashing for GDPR)
3. **Metadata:** Limit to necessary fields only
4. **PII Handling:** Review for GDPR compliance

### Access Control

Complaint data accessible hanya untuk:
- System (automated processing)
- Admin users (manual review)
- Owner users (own complaints only)

---

## Future Enhancements

**Planned Features:**
- [ ] Owner dashboard untuk view own complaints
- [ ] Appeal mechanism untuk disputed complaints
- [ ] Machine learning scoring (pattern recognition)
- [ ] Real-time webhook status dashboard
- [ ] Automated dispute resolution
- [ ] Integration dengan external fraud databases
- [ ] Multi-language support untuk complaint reasons
- [ ] Complaint analytics dashboard
- [ ] Export complaints untuk compliance reporting
- [ ] Webhook retry mechanism dengan exponential backoff

---

## Support

For issues or questions:
- Check logs: `storage/logs/laravel.log`
- Review configuration: `config/abuse.php`
- Consult this documentation
- Contact development team

**Common Support Queries:**
- Q: How to adjust escalation thresholds? → Edit `config/abuse.php`
- Q: How to manually process complaint? → Use `RecipientComplaint::markAsProcessed()`
- Q: How to reset klien after false positive? → Use `AbuseScoringService::resetScore()`
- Q: How to disable auto-suspension? → Set `complaint_processing.immediate_enforcement = false`
