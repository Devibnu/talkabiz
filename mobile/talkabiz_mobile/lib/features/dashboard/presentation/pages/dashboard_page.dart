import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../app/router/route_names.dart';
import '../../../../core/navigation/mobile_bottom_nav.dart';
import '../../../auth/presentation/providers/auth_provider.dart';
import '../providers/dashboard_provider.dart';

class DashboardPage extends ConsumerWidget {
  const DashboardPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dashboardAsync = ref.watch(dashboardProvider);
    final authState = ref.watch(authControllerProvider);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Talkabiz'),
      ),
      body: dashboardAsync.when(
        data: (summary) {
          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(dashboardProvider),
            child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            children: [
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(26),
                  gradient: const LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [Color(0xFF173B2F), Color(0xFF25D366)],
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Halo, ${authState.session?.user.name ?? 'User'}',
                      style: theme.textTheme.headlineSmall?.copyWith(
                        color: Colors.white,
                        fontSize: 22,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      summary.whatsapp.connected
                          ? 'Nomor WhatsApp aktif dan siap dipakai untuk operasional hari ini.'
                          : 'Hubungkan nomor WhatsApp agar operasional mobile lebih lengkap.',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: Colors.white.withValues(alpha: 0.82),
                        height: 1.3,
                        fontSize: 13,
                      ),
                    ),
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        _HeroMetric(
                          label: 'Saldo',
                          value: summary.wallet.formattedBalance,
                        ),
                        _HeroMetric(
                          label: 'Pesan',
                          value: summary.stats.messagesToday.toString(),
                        ),
                        _HeroMetric(
                          label: 'Kontak',
                          value: summary.stats.contactsTotal.toString(),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 10),
              // -- Langganan & Saldo row --
              Row(
                children: [
                  Expanded(
                    child: _CompactCard(
                      icon: Icons.card_membership_rounded,
                      color: const Color(0xFF7C3AED),
                      label: 'Paket ${summary.subscription.planName}',
                      value: '${summary.subscription.daysRemaining} hari lagi',
                      valueColor: summary.subscription.daysRemaining <= 7
                          ? const Color(0xFFDC2626)
                          : null,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: _CompactCard(
                      icon: Icons.account_balance_wallet_rounded,
                      color: const Color(0xFF25D366),
                      label: 'Saldo WA Blast',
                      value: summary.wallet.formattedBalance,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              // -- WhatsApp connection --
              _CompactCard(
                icon: Icons.phone_android_rounded,
                color: const Color(0xFF4F6BED),
                label: summary.whatsapp.connected
                    ? '${summary.whatsapp.phoneNumber ?? '-'} · ${summary.whatsapp.businessName ?? '-'}'
                    : 'WhatsApp belum terhubung',
                value: summary.whatsapp.connected ? 'Terhubung' : 'Hubungkan',
                valueColor: summary.whatsapp.connected
                    ? const Color(0xFF25D366)
                    : const Color(0xFFDC2626),
              ),
              const SizedBox(height: 8),
              Text('Statistik Hari Ini', style: theme.textTheme.titleLarge),
              const SizedBox(height: 6),
              GridView.count(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisCount: 2,
                crossAxisSpacing: 10,
                mainAxisSpacing: 10,
                childAspectRatio: 1.7,
                children: [
                  _StatCard(
                    label: 'Pesan hari ini',
                    value: summary.stats.messagesToday.toString(),
                    color: const Color(0xFF1959D1),
                    icon: Icons.mark_chat_unread_rounded,
                  ),
                  _StatCard(
                    label: 'Campaign aktif',
                    value: summary.stats.campaignsActive.toString(),
                    color: const Color(0xFFF59E0B),
                    icon: Icons.campaign_rounded,
                  ),
                  _StatCard(
                    label: 'Template aktif',
                    value: summary.stats.templatesActive.toString(),
                    color: const Color(0xFF7C3AED),
                    icon: Icons.text_snippet_rounded,
                  ),
                  _StatCard(
                    label: 'Total kontak',
                    value: summary.stats.contactsTotal.toString(),
                    color: const Color(0xFF25D366),
                    icon: Icons.people_alt_rounded,
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Text(
                'Menu Cepat',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 10),
              _QuickActionTile(
                title: 'Inbox Mobile',
                subtitle: 'Lihat daftar percakapan dari endpoint mobile.',
                icon: Icons.chat_bubble_outline_rounded,
                color: const Color(0xFF4F6BED),
                onTap: () => context.goNamed(RouteNames.inbox),
              ),
              const SizedBox(height: 10),
              _QuickActionTile(
                title: 'Kontak Mobile',
                subtitle: 'Lihat daftar kontak dari endpoint mobile.',
                icon: Icons.people_alt_outlined,
                color: const Color(0xFF25D366),
                onTap: () => context.goNamed(RouteNames.contacts),
              ),
              const SizedBox(height: 10),
              _QuickActionTile(
                title: 'Template Pesan',
                subtitle: 'Kelola template WhatsApp dan ajukan ke Meta.',
                icon: Icons.text_snippet_rounded,
                color: const Color(0xFFF59E0B),
                onTap: () => context.goNamed(RouteNames.templates),
              ),
              const SizedBox(height: 10),
              _QuickActionTile(
                title: 'Kampanye',
                subtitle: 'Buat dan kelola kampanye WhatsApp blast.',
                icon: Icons.campaign_rounded,
                color: const Color(0xFFEF4444),
                onTap: () => context.goNamed(RouteNames.campaigns),
              ),
              const SizedBox(height: 10),
              _QuickActionTile(
                title: 'Billing & Paket',
                subtitle: 'Langganan, pilih paket, dan top up saldo.',
                icon: Icons.receipt_long_rounded,
                color: const Color(0xFF7C3AED),
                onTap: () => context.goNamed(RouteNames.billing),
              ),
            ],
          ),
          );
        },
        error: (error, stackTrace) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Text('Gagal memuat dashboard: $error'),
            ),
          );
        },
        loading: () => const Center(child: CircularProgressIndicator()),
      ),
      bottomNavigationBar: const MobileBottomNav(),
    );
  }
}

class _QuickActionTile extends StatelessWidget {
  const _QuickActionTile({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.color,
    required this.onTap,
  });

  final String title;
  final String subtitle;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(24),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              Container(
                height: 46,
                width: 46,
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Icon(icon, color: color),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              const Icon(Icons.arrow_forward_rounded),
            ],
          ),
        ),
      ),
    );
  }
}

class _CompactCard extends StatelessWidget {
  const _CompactCard({
    required this.icon,
    required this.color,
    required this.label,
    required this.value,
    this.valueColor,
  });

  final IconData icon;
  final Color color;
  final String label;
  final String value;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            Container(
              height: 32,
              width: 32,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Icon(icon, color: color, size: 16),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    label,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          fontSize: 11,
                          height: 1.2,
                        ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    value,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontSize: 13,
                          height: 1.0,
                          color: valueColor,
                        ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _HeroMetric extends StatelessWidget {
  const _HeroMetric({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: Colors.white.withValues(alpha: 0.78),
                  fontSize: 11,
                ),
          ),
          const SizedBox(height: 2),
          Text(
            value,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: Colors.white,
                  height: 1.0,
                  fontSize: 17,
                ),
          ),
        ],
      ),
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.label,
    required this.value,
    required this.color,
    required this.icon,
  });

  final String label;
  final String value;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  height: 28,
                  width: 28,
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Icon(icon, color: color, size: 16),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    label,
                    style: Theme.of(context).textTheme.bodySmall,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
            const Spacer(),
            Text(
              value,
              style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontSize: 18,
                  ),
            ),
          ],
        ),
      ),
    );
  }
}
