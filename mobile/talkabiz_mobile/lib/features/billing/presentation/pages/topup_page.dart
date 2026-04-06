import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../../core/preview/app_preview.dart';
import '../../data/repositories/billing_repository_impl.dart';
import '../../domain/entities/billing_entities.dart';
import '../providers/billing_provider.dart';

class TopUpPage extends ConsumerStatefulWidget {
  const TopUpPage({super.key});

  @override
  ConsumerState<TopUpPage> createState() => _TopUpPageState();
}

class _TopUpPageState extends ConsumerState<TopUpPage>
    with WidgetsBindingObserver {
  int? _selectedAmount;
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
    final optionsAsync = ref.watch(topUpOptionsProvider);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Top Up Saldo')),
      body: optionsAsync.when(
        data: (options) {
          return Column(
            children: [
              Expanded(
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
                  children: [
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: const Color(0xFFFFFCF6),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Pilih nominal top up',
                            style: theme.textTheme.titleMedium,
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Saldo digunakan untuk mengirim pesan WA blast. Terpisah dari biaya langganan.',
                            style: theme.textTheme.bodySmall?.copyWith(
                              height: 1.3,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    ...options.map(
                      (opt) => Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: _TopUpOptionTile(
                          option: opt,
                          isSelected: _selectedAmount == opt.amount,
                          onTap: () {
                            setState(() => _selectedAmount = opt.amount);
                          },
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              // Bottom checkout bar
              Container(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
                decoration: const BoxDecoration(
                  color: Colors.white,
                  borderRadius:
                      BorderRadius.vertical(top: Radius.circular(24)),
                  boxShadow: [
                    BoxShadow(
                      color: Color(0x0F000000),
                      blurRadius: 8,
                      offset: Offset(0, -2),
                    ),
                  ],
                ),
                child: SafeArea(
                  top: false,
                  child: SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: _selectedAmount != null
                          ? () => _handleTopUp(context)
                          : null,
                      child: Text(
                        _selectedAmount != null
                            ? 'Top Up Rp ${_formatNumber(_selectedAmount!)}'
                            : 'Pilih nominal',
                      ),
                    ),
                  ),
                ),
              ),
            ],
          );
        },
        error: (error, _) => Center(
          child: Text('Gagal memuat opsi top up: $error'),
        ),
        loading: () => const Center(child: CircularProgressIndicator()),
      ),
    );
  }

  void _handleTopUp(BuildContext context) {
    final amount = _selectedAmount;
    if (amount == null) return;

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
              'Konfirmasi Top Up',
              style: Theme.of(ctx).textTheme.titleLarge,
            ),
            const SizedBox(height: 12),
            Text(
              'Rp ${_formatNumber(amount)}',
              style: Theme.of(ctx).textTheme.headlineSmall?.copyWith(
                    color: const Color(0xFF25D366),
                  ),
            ),
            const SizedBox(height: 6),
            Text(
              'Anda akan diarahkan ke halaman pembayaran.',
              style: Theme.of(ctx).textTheme.bodySmall,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 20),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: () {
                  Navigator.pop(ctx);
                  _processTopUp(context, amount);
                },
                child: const Text('Bayar Sekarang'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _processTopUp(BuildContext context, int amount) async {
    // Show loading
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => const Center(child: CircularProgressIndicator()),
    );

    try {
      final repo = ref.read(billingRepositoryProvider);
      final result = await repo.topUp(amount);

      if (!context.mounted) return;
      Navigator.pop(context); // dismiss loading

      // Preview mode: show simulated success
      if (kUsePreviewData) {
        _showPaymentSimulation(context, result);
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

  void _showPaymentSimulation(BuildContext context, CheckoutResult result) {
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

class _TopUpOptionTile extends StatelessWidget {
  const _TopUpOptionTile({
    required this.option,
    required this.isSelected,
    required this.onTap,
  });

  final TopUpOption option;
  final bool isSelected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: isSelected ? const Color(0xFFECFDF5) : Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
            color: isSelected
                ? const Color(0xFF25D366)
                : const Color(0xFFE6E0D5),
            width: isSelected ? 2 : 1,
          ),
        ),
        child: Row(
          children: [
            Container(
              height: 40,
              width: 40,
              decoration: BoxDecoration(
                color: isSelected
                    ? const Color(0xFF25D366).withValues(alpha: 0.15)
                    : const Color(0xFFF4F1EA),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(
                Icons.account_balance_wallet_rounded,
                color: isSelected
                    ? const Color(0xFF25D366)
                    : const Color(0xFF6A756C),
                size: 20,
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    option.label,
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontSize: 15,
                      color: isSelected ? const Color(0xFF065F46) : null,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    option.description,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            if (isSelected)
              const Icon(
                Icons.check_circle_rounded,
                color: Color(0xFF25D366),
                size: 22,
              ),
          ],
        ),
      ),
    );
  }
}
