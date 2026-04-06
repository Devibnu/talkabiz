import '../../features/billing/domain/entities/billing_entities.dart';
import '../../features/billing/domain/repositories/billing_repository.dart';
import '../../features/contacts/domain/entities/contact_item.dart';
import '../../features/contacts/domain/repositories/contacts_repository.dart';
import '../../features/dashboard/domain/entities/dashboard_summary.dart';
import '../../features/dashboard/domain/repositories/dashboard_repository.dart';
import '../../features/inbox/domain/entities/inbox_conversation_detail.dart';
import '../../features/inbox/domain/entities/inbox_conversation_item.dart';
import '../../features/inbox/domain/entities/inbox_message_item.dart';
import '../../features/inbox/domain/repositories/inbox_repository.dart';

const bool kUsePreviewData = bool.fromEnvironment(
  'TALKABIZ_USE_PREVIEW_DATA',
  defaultValue: false,
);

const String kPreviewInitialRoute = String.fromEnvironment(
  'TALKABIZ_INITIAL_ROUTE',
  defaultValue: '/',
);

class PreviewDashboardRepository implements DashboardRepository {
  const PreviewDashboardRepository();

  @override
  Future<DashboardSummary> getSummary() async {
    return const DashboardSummary(
      wallet: WalletSummary(
        balance: 2450000,
        formattedBalance: 'Rp 2.450.000',
        status: 'Saldo aman untuk 12 hari',
      ),
      whatsapp: WhatsAppConnectionSummary(
        connected: true,
        phoneNumber: '+62 812-8888-1024',
        businessName: 'Talkabiz Atelier',
        qualityRating: 'Tinggi',
        status: 'Connected 24/7',
      ),
      stats: DashboardStats(
        messagesToday: 184,
        campaignsActive: 6,
        templatesActive: 12,
        contactsTotal: 2486,
      ),
      subscription: SubscriptionSummary(
        planName: 'Pro',
        status: 'active',
        expiresAt: '2026-05-05T00:00:00+07:00',
        daysRemaining: 30,
      ),
      quickActions: [
        QuickActionItem(key: 'broadcast', label: 'Broadcast', icon: 'campaign'),
        QuickActionItem(key: 'contacts', label: 'Tambah Kontak', icon: 'people'),
        QuickActionItem(key: 'templates', label: 'Template', icon: 'description'),
        QuickActionItem(key: 'billing', label: 'Top Up', icon: 'account_balance_wallet'),
      ],
    );
  }
}

class PreviewInboxRepository implements InboxRepository {
  const PreviewInboxRepository();

  static final List<InboxConversationItem> _conversations = [
    InboxConversationItem(
      id: 1,
      contactName: 'Nadia Prameswari',
      phone: '+62 812-2200-1108',
      lastMessage: 'Boleh kirim katalog warna terbaru untuk batch Ramadan?',
      lastMessageAt: DateTime(2026, 4, 5, 10, 42),
      unreadCount: 3,
      status: 'Hot lead',
      assignedToMe: true,
    ),
    InboxConversationItem(
      id: 2,
      contactName: 'Fikri Setiawan',
      phone: '+62 811-9055-1290',
      lastMessage: 'Invoice sudah saya terima, tinggal tunggu approval finance.',
      lastMessageAt: DateTime(2026, 4, 5, 9, 18),
      unreadCount: 0,
      status: 'Follow up',
      assignedToMe: false,
    ),
    InboxConversationItem(
      id: 3,
      contactName: 'Alya Cosmetics',
      phone: '+62 878-1100-4421',
      lastMessage: 'MOQ untuk packaging matte berapa pcs ya?',
      lastMessageAt: DateTime(2026, 4, 4, 18, 7),
      unreadCount: 1,
      status: 'New',
      assignedToMe: true,
    ),
    InboxConversationItem(
      id: 4,
      contactName: 'Bima Arkatama',
      phone: '+62 821-7000-5504',
      lastMessage: 'Terima kasih, saya kabari lagi besok pagi.',
      lastMessageAt: DateTime(2026, 4, 4, 16, 20),
      unreadCount: 0,
      status: 'Warm',
      assignedToMe: true,
    ),
  ];

  static final Map<int, InboxConversationDetail> _details = {
    1: InboxConversationDetail(
      id: 1,
      contactName: 'Nadia Prameswari',
      phone: '+62 812-2200-1108',
      status: 'Hot lead',
      priority: 'Tinggi',
      messages: [
        InboxMessageItem(
          id: 1,
          direction: 'inbound',
          type: 'text',
          content: 'Halo kak, aku lihat katalog linen set minggu lalu.',
          timestamp: DateTime(2026, 4, 5, 10, 11),
          status: 'read',
        ),
        InboxMessageItem(
          id: 2,
          direction: 'outbound',
          type: 'text',
          content: 'Halo Nadia, siap. Model yang warna sage dan sand masih available.',
          timestamp: DateTime(2026, 4, 5, 10, 15),
          status: 'delivered',
        ),
        InboxMessageItem(
          id: 3,
          direction: 'inbound',
          type: 'text',
          content: 'Boleh kirim katalog warna terbaru untuk batch Ramadan?',
          timestamp: DateTime(2026, 4, 5, 10, 42),
          status: 'read',
        ),
      ],
    ),
    2: InboxConversationDetail(
      id: 2,
      contactName: 'Fikri Setiawan',
      phone: '+62 811-9055-1290',
      status: 'Follow up',
      priority: 'Sedang',
      messages: [
        InboxMessageItem(
          id: 4,
          direction: 'outbound',
          type: 'text',
          content: 'Saya kirim invoice final dan estimasi pengiriman ya, Pak.',
          timestamp: DateTime(2026, 4, 5, 8, 55),
          status: 'read',
        ),
        InboxMessageItem(
          id: 5,
          direction: 'inbound',
          type: 'text',
          content: 'Invoice sudah saya terima, tinggal tunggu approval finance.',
          timestamp: DateTime(2026, 4, 5, 9, 18),
          status: 'read',
        ),
      ],
    ),
  };

  @override
  Future<List<InboxConversationItem>> getConversations({String? search}) async {
    final query = (search ?? '').trim().toLowerCase();
    if (query.isEmpty) {
      return _conversations;
    }

    return _conversations.where((item) {
      return item.contactName.toLowerCase().contains(query) ||
          item.phone.toLowerCase().contains(query) ||
          (item.lastMessage?.toLowerCase().contains(query) ?? false);
    }).toList();
  }

  @override
  Future<InboxConversationDetail> getConversationDetail(int conversationId) async {
    return _details[conversationId] ?? _details.values.first;
  }

  @override
  Future<void> sendMessage({
    required int conversationId,
    required String message,
    String? type,
    String? mediaUrl,
  }) async {}

  @override
  Future<({String url, String mediaType})> uploadMedia(String filePath) async {
    return (url: 'https://example.com/preview.jpg', mediaType: 'gambar');
  }
}

class PreviewContactsRepository implements ContactsRepository {
  const PreviewContactsRepository();

  static final List<ContactItem> _contacts = [
    ContactItem(
      id: 1,
      name: 'Nadia Prameswari',
      phone: '+62 812-2200-1108',
      email: 'nadia@atelier.id',
      tags: const ['VIP', 'Retail', 'Ramadan'],
      lastInteractionAt: DateTime(2026, 4, 5, 10, 42),
    ),
    ContactItem(
      id: 2,
      name: 'Alya Cosmetics',
      phone: '+62 878-1100-4421',
      email: 'ops@alyacosmetics.co',
      tags: const ['B2B', 'Packaging'],
      lastInteractionAt: DateTime(2026, 4, 4, 18, 7),
    ),
    ContactItem(
      id: 3,
      name: 'Bima Arkatama',
      phone: '+62 821-7000-5504',
      email: null,
      tags: const ['Prospect', 'Warm'],
      lastInteractionAt: DateTime(2026, 4, 4, 16, 20),
    ),
    ContactItem(
      id: 4,
      name: 'Fikri Setiawan',
      phone: '+62 811-9055-1290',
      email: 'fikri@arkalogistik.id',
      tags: const ['Finance', 'Invoice'],
      lastInteractionAt: DateTime(2026, 4, 5, 9, 18),
    ),
  ];

  @override
  Future<List<ContactItem>> getContacts({String? search}) async {
    final query = (search ?? '').trim().toLowerCase();
    if (query.isEmpty) {
      return _contacts;
    }

    return _contacts.where((item) {
      return item.name.toLowerCase().contains(query) ||
          item.phone.toLowerCase().contains(query) ||
          (item.email?.toLowerCase().contains(query) ?? false) ||
          item.tags.any((tag) => tag.toLowerCase().contains(query));
    }).toList();
  }
}

class PreviewBillingRepository implements BillingRepository {
  const PreviewBillingRepository();

  @override
  Future<BillingOverview> getOverview() async {
    return const BillingOverview(
      subscription: BillingSubscription(
        planName: 'Pro',
        planCode: 'pro',
        priceMonthly: 299000,
        formattedPrice: 'Rp 299.000/bln',
        status: 'active',
        expiresAt: '2026-05-05T00:00:00+07:00',
        daysRemaining: 30,
        autoRenew: true,
        features: ['inbox', 'broadcast', 'campaign', 'template', 'analytics'],
      ),
      wallet: BillingWallet(
        balance: 2450000,
        formattedBalance: 'Rp 2.450.000',
      ),
    );
  }

  @override
  Future<List<PlanItem>> getPlans() async {
    return const [
      PlanItem(
        id: 1,
        code: 'starter',
        name: 'Starter',
        description: 'Untuk UMKM yang baru mulai pakai WhatsApp Business.',
        priceMonthly: 99000,
        formattedPrice: 'Rp 99.000/bln',
        durationDays: 30,
        features: ['inbox', 'broadcast'],
        maxWaNumbers: 1,
        maxCampaigns: 3,
        isPopular: false,
      ),
      PlanItem(
        id: 2,
        code: 'pro',
        name: 'Pro',
        description: 'Untuk bisnis yang butuh fitur lengkap dan campaign.',
        priceMonthly: 299000,
        formattedPrice: 'Rp 299.000/bln',
        durationDays: 30,
        features: ['inbox', 'broadcast', 'campaign', 'template', 'analytics'],
        maxWaNumbers: 3,
        maxCampaigns: 10,
        isPopular: true,
      ),
      PlanItem(
        id: 3,
        code: 'enterprise',
        name: 'Enterprise',
        description: 'Untuk perusahaan besar dengan kebutuhan advanced.',
        priceMonthly: 799000,
        formattedPrice: 'Rp 799.000/bln',
        durationDays: 30,
        features: [
          'inbox', 'broadcast', 'campaign', 'template', 'automation',
          'api', 'webhook', 'multi_agent', 'analytics', 'export',
        ],
        maxWaNumbers: 10,
        maxCampaigns: 50,
        isPopular: false,
      ),
    ];
  }

  @override
  Future<List<TopUpOption>> getTopUpOptions() async {
    return const [
      TopUpOption(amount: 50000, label: 'Rp 50.000', description: '~100 pesan'),
      TopUpOption(amount: 100000, label: 'Rp 100.000', description: '~200 pesan'),
      TopUpOption(amount: 250000, label: 'Rp 250.000', description: '~500 pesan'),
      TopUpOption(amount: 500000, label: 'Rp 500.000', description: '~1.000 pesan'),
      TopUpOption(amount: 1000000, label: 'Rp 1.000.000', description: '~2.000 pesan'),
      TopUpOption(amount: 2500000, label: 'Rp 2.500.000', description: '~5.000 pesan'),
    ];
  }

  @override
  Future<CheckoutResult> checkoutPlan(int planId) async {
    return const CheckoutResult(
      snapToken: 'preview-snap-token',
      redirectUrl: 'https://app.sandbox.midtrans.com/snap/v2/vtweb/preview-snap-token',
      orderId: 'PLAN-PREVIEW-001',
      amount: 299000,
      formattedAmount: 'Rp 299.000',
    );
  }

  @override
  Future<CheckoutResult> topUp(int amount) async {
    return CheckoutResult(
      snapToken: 'preview-snap-token',
      redirectUrl: 'https://app.sandbox.midtrans.com/snap/v2/vtweb/preview-snap-token',
      orderId: 'TOPUP-PREVIEW-001',
      amount: amount,
      formattedAmount: 'Rp ${_formatNumber(amount)}',
    );
  }

  static String _formatNumber(int n) {
    final s = n.toString();
    final buf = StringBuffer();
    for (var i = 0; i < s.length; i++) {
      if (i > 0 && (s.length - i) % 3 == 0) buf.write('.');
      buf.write(s[i]);
    }
    return buf.toString();
  }
}