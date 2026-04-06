import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../../core/preview/app_preview.dart';
import '../../data/repositories/billing_repository_impl.dart';
import '../../domain/entities/billing_entities.dart';
import '../providers/billing_provider.dart';

class PlansPage extends ConsumerStatefulWidget {
  const PlansPage({super.key});

  @override
  ConsumerState<PlansPage> createState() => _PlansPageState();
}

class _PlansPageState extends ConsumerState<PlansPage>
    with WidgetsBindingObserver {
  bool _waitingPayment = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _waitingPayment) {
      _waitingPayment = false;
      ref.invalidate(billingOverviewProvider);
      ref.invalidate(billingPlansProvider);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Pembayaran sedang diproses. Data akan diperbarui otomatis.'),
            duration: Duration(seconds: 3),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final plansAsync = ref.watch(billingPlansProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Pilih Paket')),
      body: plansAsync.when(
        data: (plans) {
          return ListView.separated(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
            itemCount: plans.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, index) {
              final plan = plans[index];
              return _PlanCard(
                plan: plan,
                onCheckout: (p) => _processCheckout(context, p),
              );
            },
          );
        },
        error: (error, _) => Center(
          child: Text('Gagal memuat paket: $error'),
        ),
        loading: () => const Center(child: CircularProgressIndicator()),
      ),
    );
  }

  Future<void> _processCheckout(
    BuildContext context,
    PlanItem plan,
  ) async {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => const Center(child: CircularProgressIndicator()),
    );

    try {
      final repo = ref.read(billingRepositoryProvider);
      final result = await repo.checkoutPlan(plan.id);

      if (!context.mounted) return;
      Navigator.pop(context); // dismiss loading

      // Preview mode: show simulated success
      if (kUsePreviewData) {
        _showPaymentSimulation(context, plan, result);
        return;
      }

      final url = result.redirectUrl;
      if (url != null && url.isNotEmpty) {
        final uri = Uri.parse(url);
        if (await canLaunchUrl(uri)) {
          _waitingPayment = true;
          await launchUrl(uri, mode: LaunchMode.externalApplication);
        } else {
          if (!context.mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Tidak bisa membuka halaman pembayaran.')),
          );
        }
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Order ${result.orderId} dibuat. Redirect URL tidak tersedia.'),
          ),
        );
      }
    } catch (e) {
      if (!context.mounted) return;
      Navigator.pop(context); // dismiss loading
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Gagal membuat pembayaran: $e')),
      );
    }
  }

  static void _showPaymentSimulation(BuildContext context, PlanItem plan, CheckoutResult result) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.check_circle_rounded, color: Color(0xFF25D366), size: 64),
            const SizedBox(height: 16),
            Text('Pembayaran Berhasil', style: Theme.of(ctx).textTheme.titleLarge),
            const SizedBox(height: 8),
            Text('Paket ${plan.name}', style: Theme.of(ctx).textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(result.formattedAmount, style: Theme.of(ctx).textTheme.headlineSmall?.copyWith(color: const Color(0xFF25D366))),
            const SizedBox(height: 8),
            Text('Order: ${result.orderId}', style: Theme.of(ctx).textTheme.bodySmall),
            const SizedBox(height: 4),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(color: const Color(0xFFFFF3CD), borderRadius: BorderRadius.circular(8)),
              child: const Text('PREVIEW MODE', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF856404))),
            ),
            const SizedBox(height: 12),
            Text('Di production, user akan diarahkan ke halaman pembayaran Midtrans.', style: Theme.of(ctx).textTheme.bodySmall, textAlign: TextAlign.center),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK')),
        ],
      ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.plan, required this.onCheckout});

  final PlanItem plan;
  final void Function(PlanItem plan) onCheckout;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isPopular = plan.isPopular;

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        border: isPopular
            ? Border.all(color: const Color(0xFF25D366), width: 2)
            : null,
        color: Colors.white,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (isPopular)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 6),
              decoration: const BoxDecoration(
                color: Color(0xFF25D366),
                borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
              ),
              child: Text(
                'PALING POPULER',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 11,
                  letterSpacing: 0.5,
                ),
              ),
            ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  plan.name,
                  style: theme.textTheme.titleLarge?.copyWith(fontSize: 20),
                ),
                const SizedBox(height: 4),
                Text(
                  plan.formattedPrice,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    color: const Color(0xFF25D366),
                    fontSize: 22,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  plan.description,
                  style: theme.textTheme.bodySmall?.copyWith(height: 1.3),
                ),
                const SizedBox(height: 12),
                // Features
                ...plan.features.map(
                  (f) => Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Row(
                      children: [
                        const Icon(
                          Icons.check_circle_rounded,
                          size: 16,
                          color: Color(0xFF25D366),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          _featureLabel(f),
                          style: theme.textTheme.bodySmall?.copyWith(
                            fontSize: 12,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    _DetailChip(
                      icon: Icons.phone_android_rounded,
                      label: '${plan.maxWaNumbers} nomor WA',
                    ),
                    const SizedBox(width: 8),
                    _DetailChip(
                      icon: Icons.campaign_rounded,
                      label: '${plan.maxCampaigns} campaign',
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: () {
                      _showCheckoutConfirm(context, plan);
                    },
                    style: ElevatedButton.styleFrom(
                      backgroundColor: isPopular
                          ? const Color(0xFF25D366)
                          : const Color(0xFF1959D1),
                    ),
                    child: Text('Pilih ${plan.name}'),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  void _showCheckoutConfirm(BuildContext context, PlanItem plan) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(24, 24, 24, 40),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Konfirmasi Pembelian',
              style: Theme.of(ctx).textTheme.titleLarge,
            ),
            const SizedBox(height: 12),
            Text(
              'Paket ${plan.name} — ${plan.formattedPrice}',
              style: Theme.of(ctx).textTheme.titleMedium,
            ),
            const SizedBox(height: 6),
            Text(
              'Anda akan diarahkan ke halaman pembayaran Midtrans.',
              style: Theme.of(ctx).textTheme.bodySmall,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 20),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: () {
                  Navigator.pop(ctx);
                  onCheckout(plan);
                },
                child: const Text('Bayar Sekarang'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  static String _featureLabel(String code) {
    const labels = {
      'inbox': 'Inbox / Chat',
      'broadcast': 'Broadcast',
      'campaign': 'Campaign',
      'template': 'Template Pesan',
      'automation': 'Automation',
      'api': 'API Access',
      'webhook': 'Webhook',
      'multi_agent': 'Multi Agent',
      'analytics': 'Analytics',
      'export': 'Export Data',
    };
    return labels[code] ?? code;
  }
}

class _DetailChip extends StatelessWidget {
  const _DetailChip({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: const Color(0xFFF4F1EA),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: const Color(0xFF6A756C)),
          const SizedBox(width: 4),
          Text(
            label,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  fontSize: 11,
                ),
          ),
        ],
      ),
    );
  }
}
