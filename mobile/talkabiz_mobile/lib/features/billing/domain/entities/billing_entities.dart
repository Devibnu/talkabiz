class BillingOverview {
  const BillingOverview({
    required this.subscription,
    required this.wallet,
  });

  final BillingSubscription subscription;
  final BillingWallet wallet;
}

class BillingSubscription {
  const BillingSubscription({
    required this.planName,
    required this.planCode,
    required this.priceMonthly,
    required this.formattedPrice,
    required this.status,
    required this.expiresAt,
    required this.daysRemaining,
    required this.autoRenew,
    required this.features,
  });

  final String planName;
  final String planCode;
  final int priceMonthly;
  final String formattedPrice;
  final String status;
  final String? expiresAt;
  final int daysRemaining;
  final bool autoRenew;
  final List<String> features;
}

class BillingWallet {
  const BillingWallet({
    required this.balance,
    required this.formattedBalance,
  });

  final int balance;
  final String formattedBalance;
}

class PlanItem {
  const PlanItem({
    required this.id,
    required this.code,
    required this.name,
    required this.description,
    required this.priceMonthly,
    required this.formattedPrice,
    required this.durationDays,
    required this.features,
    required this.maxWaNumbers,
    required this.maxCampaigns,
    required this.isPopular,
  });

  final int id;
  final String code;
  final String name;
  final String description;
  final int priceMonthly;
  final String formattedPrice;
  final int durationDays;
  final List<String> features;
  final int maxWaNumbers;
  final int maxCampaigns;
  final bool isPopular;
}

class TopUpOption {
  const TopUpOption({
    required this.amount,
    required this.label,
    required this.description,
  });

  final int amount;
  final String label;
  final String description;
}

class CheckoutResult {
  const CheckoutResult({
    required this.snapToken,
    required this.redirectUrl,
    required this.orderId,
    required this.amount,
    required this.formattedAmount,
  });

  final String snapToken;
  final String? redirectUrl;
  final String orderId;
  final int amount;
  final String formattedAmount;
}
