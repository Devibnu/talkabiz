import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../app/router/route_names.dart';
import '../../../../core/navigation/mobile_bottom_nav.dart';
import '../providers/billing_provider.dart';

class BillingPage extends ConsumerWidget {
  const BillingPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final overviewAsync = ref.watch(billingOverviewProvider);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Billing')),
      body: overviewAsync.when(
        data: (overview) {
          final sub = overview.subscription;
          final wallet = overview.wallet;
          final isExpiring = sub.daysRemaining <= 7 && sub.daysRemaining > 0;
          final isExpired = sub.status == 'expired' || sub.status == 'inactive';

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(billingOverviewProvider),
            child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
            children: [
              // -- Subscription Card --
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(24),
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: isExpired
                        ? [const Color(0xFF7F1D1D), const Color(0xFFDC2626)]
                        : [const Color(0xFF173B2F), const Color(0xFF25D366)],
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            'Paket ${sub.planName}',
                            style: theme.textTheme.titleLarge?.copyWith(
                              color: Colors.white,
                              fontSize: 20,
                            ),
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 10,
                            vertical: 4,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.2),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Text(
                            sub.formattedPrice,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: Colors.white,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(
                      isExpired
                          ? 'Langganan Anda sudah berakhir'
                          : isExpiring
                              ? 'Berakhir dalam ${sub.daysRemaining} hari — segera perpanjang!'
                              : '${sub.daysRemaining} hari tersisa',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: Colors.white.withValues(alpha: 0.85),
                        fontSize: 13,
                      ),
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        _ActionChip(
                          label: isExpired ? 'Pilih Paket' : 'Ganti Paket',
                          onTap: () => context.pushNamed(RouteNames.billingPlans),
                        ),
                        if (isExpiring || isExpired) ...[
                          const SizedBox(width: 8),
                          _ActionChip(
                            label: 'Perpanjang',
                            onTap: () =>
                                context.pushNamed(RouteNames.billingPlans),
                          ),
                        ],
                      ],
                    ),
                    if (sub.features.isNotEmpty) ...[
                      const SizedBox(height: 12),
                      Wrap(
                        spacing: 6,
                        runSpacing: 4,
                        children: sub.features
                            .take(5)
                            .map(
                              (f) => Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 8,
                                  vertical: 3,
                                ),
                                decoration: BoxDecoration(
                                  color: Colors.white.withValues(alpha: 0.14),
                                  borderRadius: BorderRadius.circular(10),
                                ),
                                child: Text(
                                  f,
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: Colors.white.withValues(alpha: 0.8),
                                    fontSize: 11,
                                  ),
                                ),
                              ),
                            )
                            .toList(),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // -- Wallet / Saldo Card --
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(24),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Container(
                          height: 36,
                          width: 36,
                          decoration: BoxDecoration(
                            color: const Color(0xFF25D366).withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Icon(
                            Icons.account_balance_wallet_rounded,
                            color: Color(0xFF25D366),
                            size: 18,
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                'Saldo WA Blast',
                                style: theme.textTheme.bodySmall,
                              ),
                              Text(
                                wallet.formattedBalance,
                                style: theme.textTheme.titleLarge?.copyWith(
                                  fontSize: 20,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Text(
                      'Saldo digunakan untuk mengirim pesan WhatsApp. Terpisah dari biaya langganan bulanan.',
                      style: theme.textTheme.bodySmall?.copyWith(
                        height: 1.3,
                      ),
                    ),
                    const SizedBox(height: 12),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        onPressed: () =>
                            context.pushNamed(RouteNames.billingTopUp),
                        icon: const Icon(Icons.add_rounded, size: 18),
                        label: const Text('Top Up Saldo'),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // -- Info section --
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFF7ED),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(
                    color: const Color(0xFFFED7AA),
                    width: 0.5,
                  ),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(
                      Icons.info_outline_rounded,
                      size: 18,
                      color: Color(0xFFF59E0B),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'Biaya langganan (paket bulanan) dan saldo WA blast adalah dua hal terpisah. Paket menentukan fitur yang bisa digunakan, sedangkan saldo digunakan untuk mengirim pesan.',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: const Color(0xFF92400E),
                          height: 1.4,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          );
        },
        error: (error, _) => Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text('Gagal memuat billing: $error'),
          ),
        ),
        loading: () => const Center(child: CircularProgressIndicator()),
      ),
      bottomNavigationBar: const MobileBottomNav(),
    );
  }
}

class _ActionChip extends StatelessWidget {
  const _ActionChip({required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.2),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: Colors.white.withValues(alpha: 0.3),
          ),
        ),
        child: Text(
          label,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: Colors.white,
                fontWeight: FontWeight.w700,
                fontSize: 12,
              ),
        ),
      ),
    );
  }
}
