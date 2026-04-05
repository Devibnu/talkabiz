import '../../domain/entities/dashboard_summary.dart';

class DashboardSummaryModel extends DashboardSummary {
  const DashboardSummaryModel({
    required super.wallet,
    required super.whatsapp,
    required super.stats,
    required super.quickActions,
  });

  factory DashboardSummaryModel.fromJson(Map<String, dynamic> json) {
    final data = json['data'] as Map<String, dynamic>? ?? {};
    final wallet = data['wallet'] as Map<String, dynamic>? ?? {};
    final whatsapp = data['whatsapp'] as Map<String, dynamic>? ?? {};
    final stats = data['stats'] as Map<String, dynamic>? ?? {};
    final quickActions = (data['quick_actions'] as List<dynamic>? ?? [])
        .map(
          (item) => QuickActionItem(
            key: item.toString(),
            label: item.toString(),
            icon: 'flash_on',
          ),
        )
        .toList();

    return DashboardSummaryModel(
      wallet: WalletSummary(
        balance: wallet['balance'] as int? ?? 0,
        formattedBalance: wallet['formatted_balance'] as String? ?? 'Rp 0',
        status: wallet['status'] as String? ?? 'aman',
      ),
      whatsapp: WhatsAppConnectionSummary(
        connected: whatsapp['connected'] as bool? ?? false,
        phoneNumber: whatsapp['phone_number'] as String?,
        businessName: whatsapp['business_name'] as String?,
        qualityRating: whatsapp['quality_rating'] as String?,
        status: whatsapp['status'] as String? ?? 'unknown',
      ),
      stats: DashboardStats(
        messagesToday: stats['messages_today'] as int? ?? 0,
        campaignsActive: stats['campaigns_active'] as int? ?? 0,
        templatesActive: stats['templates_active'] as int? ?? 0,
        contactsTotal: stats['contacts_total'] as int? ?? 0,
      ),
      quickActions: quickActions,
    );
  }
}
