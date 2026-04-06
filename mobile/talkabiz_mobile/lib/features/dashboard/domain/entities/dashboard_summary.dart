class WalletSummary {
  const WalletSummary({
    required this.balance,
    required this.formattedBalance,
    required this.status,
  });

  final int balance;
  final String formattedBalance;
  final String status;
}

class WhatsAppConnectionSummary {
  const WhatsAppConnectionSummary({
    required this.connected,
    required this.phoneNumber,
    required this.businessName,
    required this.qualityRating,
    required this.status,
  });

  final bool connected;
  final String? phoneNumber;
  final String? businessName;
  final String? qualityRating;
  final String status;
}

class DashboardStats {
  const DashboardStats({
    required this.messagesToday,
    required this.campaignsActive,
    required this.templatesActive,
    required this.contactsTotal,
  });

  final int messagesToday;
  final int campaignsActive;
  final int templatesActive;
  final int contactsTotal;
}

class SubscriptionSummary {
  const SubscriptionSummary({
    required this.planName,
    required this.status,
    required this.expiresAt,
    required this.daysRemaining,
  });

  final String planName;
  final String status;
  final String? expiresAt;
  final int daysRemaining;
}

class QuickActionItem {
  const QuickActionItem({
    required this.key,
    required this.label,
    required this.icon,
  });

  final String key;
  final String label;
  final String icon;
}

class DashboardSummary {
  const DashboardSummary({
    required this.wallet,
    required this.whatsapp,
    required this.stats,
    required this.quickActions,
    required this.subscription,
  });

  final WalletSummary wallet;
  final WhatsAppConnectionSummary whatsapp;
  final DashboardStats stats;
  final List<QuickActionItem> quickActions;
  final SubscriptionSummary subscription;
}
