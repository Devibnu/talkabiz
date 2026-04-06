import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../app/router/route_names.dart';
import '../../../../core/navigation/mobile_bottom_nav.dart';
import '../../../auth/presentation/providers/auth_provider.dart';

class SettingsPage extends ConsumerWidget {
  const SettingsPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final authState = ref.watch(authControllerProvider);
    final user = authState.session?.user;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Pengaturan')),
      body: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
        children: [
          // -- Profile Card --
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(24),
              gradient: const LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [Color(0xFF173B2F), Color(0xFF25D366)],
              ),
            ),
            child: Row(
              children: [
                CircleAvatar(
                  radius: 30,
                  backgroundColor: Colors.white.withValues(alpha: 0.2),
                  child: Text(
                    _initials(user?.name ?? 'U'),
                    style: theme.textTheme.titleLarge?.copyWith(
                      color: Colors.white,
                      fontSize: 20,
                    ),
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        user?.name ?? '-',
                        style: theme.textTheme.titleMedium?.copyWith(
                          color: Colors.white,
                          fontSize: 18,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        user?.email ?? '-',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: Colors.white.withValues(alpha: 0.8),
                        ),
                      ),
                      if (user?.businessName != null) ...[
                        const SizedBox(height: 2),
                        Text(
                          user!.businessName!,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: Colors.white.withValues(alpha: 0.7),
                            fontSize: 11,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),

          const SizedBox(height: 20),

          // -- Account Section --
          _SectionHeader(label: 'Akun'),
          const SizedBox(height: 8),
          _SettingsTile(
            icon: Icons.person_outline_rounded,
            label: 'Profil',
            subtitle: user?.name ?? '-',
            onTap: () => context.pushNamed(RouteNames.profile),
          ),
          _SettingsTile(
            icon: Icons.phone_android_rounded,
            label: 'Nomor Telepon',
            subtitle: user?.phone ?? 'Belum diisi',
            onTap: () => context.pushNamed(RouteNames.profile),
          ),
          _SettingsTile(
            icon: Icons.receipt_long_outlined,
            label: 'Riwayat Transaksi',
            subtitle: 'Top up & pembayaran',
            onTap: () => context.pushNamed(RouteNames.transactionHistory),
          ),

          const SizedBox(height: 20),

          // -- App Section --
          _SectionHeader(label: 'Aplikasi'),
          const SizedBox(height: 8),
          _SettingsTile(
            icon: Icons.notifications_outlined,
            label: 'Notifikasi',
            subtitle: 'Push notification',
            onTap: () {},
          ),
          _SettingsTile(
            icon: Icons.info_outline_rounded,
            label: 'Tentang Aplikasi',
            subtitle: 'Talkabiz v0.1.0',
            onTap: () => _showAbout(context),
          ),

          const SizedBox(height: 20),

          // -- Danger Zone --
          _SectionHeader(label: ''),
          const SizedBox(height: 8),
          _SettingsTile(
            icon: Icons.logout_rounded,
            label: 'Keluar',
            subtitle: 'Logout dari akun',
            iconColor: const Color(0xFFDC2626),
            labelColor: const Color(0xFFDC2626),
            onTap: () => _confirmLogout(context, ref),
          ),
        ],
      ),
      bottomNavigationBar: const MobileBottomNav(),
    );
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.length >= 2) {
      return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
    }
    return name.isNotEmpty ? name[0].toUpperCase() : 'U';
  }

  void _confirmLogout(BuildContext context, WidgetRef ref) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: const Text('Keluar?'),
        content: const Text('Anda yakin ingin logout dari akun?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Batal'),
          ),
          TextButton(
            onPressed: () async {
              Navigator.pop(ctx);
              await ref.read(authControllerProvider.notifier).logout();
              if (context.mounted) {
                context.goNamed(RouteNames.login);
              }
            },
            style: TextButton.styleFrom(foregroundColor: const Color(0xFFDC2626)),
            child: const Text('Keluar'),
          ),
        ],
      ),
    );
  }

  void _showAbout(BuildContext context) {
    showAboutDialog(
      context: context,
      applicationName: 'Talkabiz',
      applicationVersion: '0.1.0',
      applicationLegalese: '© 2026 Talkabiz. All rights reserved.',
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    return Text(
      label,
      style: Theme.of(context).textTheme.titleMedium?.copyWith(fontSize: 14),
    );
  }
}

class _SettingsTile extends StatelessWidget {
  const _SettingsTile({
    required this.icon,
    required this.label,
    required this.subtitle,
    required this.onTap,
    this.iconColor,
    this.labelColor,
  });

  final IconData icon;
  final String label;
  final String subtitle;
  final VoidCallback onTap;
  final Color? iconColor;
  final Color? labelColor;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: (iconColor ?? const Color(0xFF25D366)).withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Icon(icon, size: 20, color: iconColor ?? const Color(0xFF25D366)),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        label,
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontSize: 14,
                          color: labelColor,
                        ),
                      ),
                      Text(
                        subtitle,
                        style: theme.textTheme.bodySmall?.copyWith(fontSize: 12),
                      ),
                    ],
                  ),
                ),
                Icon(Icons.chevron_right_rounded, color: theme.colorScheme.outline, size: 20),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
