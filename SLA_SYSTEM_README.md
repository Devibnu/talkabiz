# SLA & Support Flow System

Complete SLA-aware support system for Talkabiz WhatsApp SaaS platform with strict business rules and real-time monitoring.

## 📋 Overview

This system implements comprehensive SLA management with package-based access control, automatic escalation, and complete audit trails. **NO SLA bypass allowed** - all support interactions must follow defined SLA rules.

### Key Features

✅ **Package-Based Access Control** - Different support channels based on subscription level
✅ **Automatic SLA Assignment** - No hardcoded priorities, dynamic based on package
✅ **Real-time Compliance Monitoring** - Background job monitors SLA adherence
✅ **Escalation Management** - Automatic escalation with complete audit trail
✅ **Analytics Dashboard** - Real-time metrics and performance analytics
✅ **Agent Performance Tracking** - Individual and team performance metrics

## 🏗️ System Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   WEB INTERFACE │    │   API LAYER     │    │   BACKGROUND    │
│                 │    │                 │    │   MONITORING    │
│ • Dashboard     │◄──►│ • Support API   │◄──►│ • SLA Monitor   │
│ • Agent Portal  │    │ • Analytics API │    │ • Escalation    │
│ • Customer View │    │ • Real-time API │    │ • Queue Jobs    │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         ▼                       ▼                       ▼
┌─────────────────────────────────────────────────────────────────┐
│                        SERVICE LAYER                            │
│ • SupportTicketService   • ChannelAccessService                │
│ • SlaMonitorService     • EscalationService                    │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│                        DATABASE LAYER                           │
│ • sla_definitions      • support_escalations                   │
│ • support_tickets      • support_channels                      │
│ • support_responses                                            │
└─────────────────────────────────────────────────────────────────┘
```

## 📦 Package-Based Channel Access

| Package      | Email | Chat | Phone | WhatsApp | SLA (Response) | SLA (Resolution) |
|--------------|:-----:|:----:|:-----:|:--------:|:--------------:|:---------------:|
| **Starter**  | ✅    | ✅   | ❌    | ❌       | 4 hours        | 24 hours        |
| **Professional** | ✅ | ✅   | ✅    | ❌       | 2 hours        | 12 hours        |
| **Enterprise** | ✅  | ✅   | ✅    | ✅       | 1 hour         | 8 hours         |

## 🚀 Installation & Setup

### 1. Database Migration
```bash
php artisan migrate
php artisan db:seed --class=SlaDefinitionSeeder
```

### 2. Schedule SLA Monitoring
Add to your `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    // SLA monitoring every minute
    $schedule->command('sla:monitor-compliance')->everyMinute();
    
    // Escalation processing every 5 minutes  
    $schedule->command('sla:monitor-compliance --escalations')->everyFiveMinutes();
    
    // Daily performance reports
    $schedule->command('sla:monitor-compliance --reports')->daily();
}
```

### 3. Queue Configuration
Ensure queue workers are running for background SLA monitoring:
```bash
php artisan queue:work --queue=sla-monitoring,default
```

## 🔧 Usage

### Dashboard Access
- **Overview**: `/sla-dashboard` - Real-time SLA compliance overview
- **Agents**: `/sla-dashboard/agents` - Agent performance metrics
- **Packages**: `/sla-dashboard/packages` - Package comparison
- **Escalations**: `/sla-dashboard/escalations` - Escalation management

### Customer Support
- **Create Ticket**: `POST /api/sla/tickets`
- **View Tickets**: `GET /support/tickets`
- **Add Response**: `POST /support/tickets/{id}/response`

### Agent Interface
- **Agent Dashboard**: `/agent/dashboard`
- **Assigned Tickets**: `/agent/tickets/assigned`
- **Resolve Ticket**: `POST /agent/tickets/{id}/resolve`

## 📊 SLA Business Rules

### 🚫 STRICT RULES (NO BYPASS ALLOWED)

1. **Channel Access Control**
   - Users can only access channels based on their package level
   - No manual channel overrides allowed
   - System automatically validates channel access

2. **SLA Assignment**
   - SLA automatically assigned based on user package
   - No hardcoded priorities in code
   - All SLA definitions stored in database

3. **Escalation Requirements**
   - Every escalation must have a valid reason
   - Complete audit trail maintained
   - No anonymous escalations

4. **Support Requirements**
   - All support must be ticket-based
   - No direct support without ticket/log
   - Complete communication history stored

### ⏰ SLA Timing Rules

- **First Response SLA**: Timer starts when ticket created
- **Resolution SLA**: Timer starts when ticket assigned
- **Escalation Trigger**: Automatic when SLA breached
- **Business Hours**: Configurable per package level

## 📈 Monitoring & Analytics

### Real-time Metrics
- SLA compliance percentage
- Active ticket count
- Average response/resolution time
- Agent performance scores
- Escalation rates

### Automated Alerts
- SLA breach warnings (80% of time elapsed)
- Critical escalations requiring attention
- System health monitoring
- Performance degradation alerts

### Reporting
- Daily/Weekly/Monthly compliance reports
- Agent performance reports
- Package comparison analysis
- Customer satisfaction metrics

## 🔐 Security & Access Control

### Role-Based Access
- **Customer**: View own tickets, create tickets, request escalations
- **Agent**: Manage assigned tickets, view dashboard metrics
- **Admin**: Full system access, SLA configuration, user management

### Audit Trail
- All support interactions logged
- SLA changes tracked with user attribution  
- Escalation history maintained
- Performance metrics stored

## 🛠️ Configuration

### Environment Variables
```env
# SLA Monitoring
SLA_MONITORING_ENABLED=true
SLA_ESCALATION_THRESHOLD=0.8
SLA_NOTIFICATION_CHANNELS=email,slack

# Queue Configuration
QUEUE_CONNECTION=redis
SLA_QUEUE_NAME=sla-monitoring

# Dashboard Settings
SLA_DASHBOARD_REFRESH=30000
SLA_TIMEZONE=Asia/Jakarta
```

### Package Configuration
SLA definitions are managed through the admin interface at `/admin/sla/definitions` or via database seeder.

## 🔧 Customization

### Adding New Package Levels
1. Create SLA definition via admin interface
2. Update package access validation
3. Configure channel permissions
4. Test escalation workflows

### Custom Channel Types
1. Add channel type to `support_channels` table
2. Update `ChannelAccessService`
3. Implement channel-specific logic
4. Update dashboard metrics

### Performance Optimization
- Use Redis for real-time metrics caching
- Implement database indexing for large datasets
- Configure queue priorities for critical operations
- Monitor and optimize SQL queries

## 📞 Support

For issues related to the SLA system:

1. Check system logs: `storage/logs/laravel.log`
2. Monitor SLA compliance: `/sla-dashboard`
3. Review escalations: `/sla-dashboard/escalations`
4. Contact system administrator

## 🔄 Maintenance

### Regular Tasks
- Weekly SLA definition review
- Monthly agent performance analysis
- Quarterly package level optimization
- Annual system configuration audit

### Backup Procedures
- Daily database backup (includes all SLA data)
- Configuration backup (SLA definitions)
- Dashboard settings backup

---

## 📋 Business Rule Summary

❌ **FORBIDDEN ACTIONS**:
- Bypassing SLA rules
- Hardcoding priorities
- Support without ticket/log
- Manual channel access override
- Anonymous escalations

✅ **REQUIRED ACTIONS**:
- All support interactions logged
- Package-based channel validation
- Automatic SLA assignment
- Complete audit trail
- Real-time compliance monitoring

This system ensures strict SLA compliance while providing comprehensive support management capabilities for the Talkabiz platform.