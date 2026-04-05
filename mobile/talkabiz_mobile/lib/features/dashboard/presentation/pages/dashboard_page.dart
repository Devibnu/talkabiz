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

    return Scaffold(
      appBar: AppBar(
        title: Text('Halo, ${authState.session?.user.name ?? 'User'}'),
        actions: [
          IconButton(
            onPressed: () async {
              await ref.read(authControllerProvider.notifier).logout();
            },
            icon: const Icon(Icons.logout_rounded),
          ),
        ],
      ),
      body: dashboardAsync.when(
        data: (summary) {
          return ListView(
            padding: const EdgeInsets.all(20),
            children: [
              _InfoCard(
                title: 'Saldo WA',
                value: summary.wallet.formattedBalance,
                subtitle: 'Status: ${summary.wallet.status}',
                icon: Icons.account_balance_wallet_rounded,
                color: const Color(0xFF25D366),
              ),
              const SizedBox(height: 16),
              _InfoCard(
                title: 'Nomor WhatsApp',
                value: summary.whatsapp.phoneNumber ?? 'Belum terhubung',
                subtitle: summary.whatsapp.connected
                    ? 'Terhubung - ${summary.whatsapp.businessName ?? '-'}'
                    : 'Hubungkan nomor WhatsApp Anda',
                icon: Icons.phone_android_rounded,
                color: const Color(0xFF4F6BED),
              ),
              const SizedBox(height: 16),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Statistik Hari Ini',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: 16),
                      _StatRow(
                        label: 'Pesan hari ini',
                        value: summary.stats.messagesToday.toString(),
                      ),
                      _StatRow(
                        label: 'Campaign aktif',
                        value: summary.stats.campaignsActive.toString(),
                      ),
                      _StatRow(
                        label: 'Template aktif',
                        value: summary.stats.templatesActive.toString(),
                      ),
                      _StatRow(
                        label: 'Total kontak',
                        value: summary.stats.contactsTotal.toString(),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Text(
                'Menu Cepat',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 12),
              _QuickActionTile(
                title: 'Inbox Mobile',
                subtitle: 'Lihat daftar percakapan dari endpoint mobile.',
                icon: Icons.chat_bubble_outline_rounded,
                color: const Color(0xFF4F6BED),
                onTap: () => context.goNamed(RouteNames.inbox),
              ),
              const SizedBox(height: 12),
              _QuickActionTile(
                title: 'Kontak Mobile',
                subtitle: 'Lihat daftar kontak dari endpoint mobile.',
                icon: Icons.people_alt_outlined,
                color: const Color(0xFF25D366),
                onTap: () => context.goNamed(RouteNames.contacts),
              ),
            ],
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
          padding: const EdgeInsets.all(18),
          child: Row(
            children: [
              Container(
                height: 48,
                width: 48,
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Icon(icon, color: color),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 4),
                    Text(
                      subtitle,
                      style: Theme.of(context).textTheme.bodyMedium,
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

class _InfoCard extends StatelessWidget {
  const _InfoCard({
    required this.title,
    required this.value,
    required this.subtitle,
    required this.icon,
    required this.color,
  });

  final String title;
  final String value;
  final String subtitle;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Row(
          children: [
            Container(
              height: 52,
              width: 52,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Icon(icon, color: color),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: Theme.of(context).textTheme.bodyMedium),
                  const SizedBox(height: 4),
                  Text(value, style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 4),
                  Text(subtitle, style: Theme.of(context).textTheme.bodySmall),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatRow extends StatelessWidget {
  const _StatRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        children: [
          Expanded(child: Text(label)),
          Text(value, style: Theme.of(context).textTheme.titleMedium),
        ],
      ),
    );
  }
}
