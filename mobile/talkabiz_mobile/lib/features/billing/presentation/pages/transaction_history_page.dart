import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../domain/entities/transaction_item.dart';
import '../providers/transaction_provider.dart';

class TransactionHistoryPage extends ConsumerWidget {
  const TransactionHistoryPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final txAsync = ref.watch(transactionsProvider);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Riwayat Transaksi')),
      body: txAsync.when(
        data: (transactions) {
          if (transactions.isEmpty) {
            return Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.receipt_long_outlined, size: 64, color: theme.colorScheme.outline),
                  const SizedBox(height: 12),
                  Text('Belum ada transaksi', style: theme.textTheme.titleMedium),
                ],
              ),
            );
          }

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(transactionsProvider),
            child: ListView.separated(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
              itemCount: transactions.length,
              separatorBuilder: (_, __) => const SizedBox(height: 6),
              itemBuilder: (context, index) {
                return _TransactionTile(tx: transactions[index]);
              },
            ),
          );
        },
        error: (error, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, size: 48, color: Color(0xFFDC2626)),
              const SizedBox(height: 12),
              Text('Gagal memuat: $error', textAlign: TextAlign.center),
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: () => ref.invalidate(transactionsProvider),
                child: const Text('Coba Lagi'),
              ),
            ],
          ),
        ),
        loading: () => const Center(child: CircularProgressIndicator()),
      ),
    );
  }
}

class _TransactionTile extends StatelessWidget {
  const _TransactionTile({required this.tx});

  final TransactionItem tx;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isIncome = tx.subtype == 'topup' || tx.subtype == 'refund' || tx.subtype == 'release';
    final statusColor = _statusColor(tx.status);
    final statusLabel = _statusLabel(tx.status);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          // Icon
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: (isIncome ? const Color(0xFF25D366) : const Color(0xFF6A756C)).withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(
              tx.type == 'plan' ? Icons.card_membership_rounded : (isIncome ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded),
              size: 20,
              color: isIncome ? const Color(0xFF25D366) : const Color(0xFF6A756C),
            ),
          ),
          const SizedBox(width: 12),
          // Details
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  tx.description,
                  style: theme.textTheme.titleMedium?.copyWith(fontSize: 13),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 2),
                Text(
                  _formatDate(tx.createdAt),
                  style: theme.textTheme.bodySmall?.copyWith(fontSize: 11),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          // Amount + status
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                '${isIncome ? '+' : '-'} ${tx.formattedAmount}',
                style: theme.textTheme.titleMedium?.copyWith(
                  fontSize: 13,
                  color: isIncome ? const Color(0xFF25D366) : const Color(0xFF1C2A1F),
                ),
              ),
              const SizedBox(height: 2),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  statusLabel,
                  style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: statusColor),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Color _statusColor(String status) {
    return switch (status) {
      'success' => const Color(0xFF25D366),
      'pending' || 'waiting_payment' => const Color(0xFFEAB308),
      'failed' || 'cancelled' => const Color(0xFFDC2626),
      'expired' => const Color(0xFF6A756C),
      _ => const Color(0xFF6A756C),
    };
  }

  String _statusLabel(String status) {
    return switch (status) {
      'success' => 'Berhasil',
      'pending' => 'Menunggu',
      'waiting_payment' => 'Bayar',
      'failed' => 'Gagal',
      'expired' => 'Kedaluwarsa',
      'cancelled' => 'Dibatalkan',
      _ => status,
    };
  }

  String _formatDate(String? iso) {
    if (iso == null) return '-';
    try {
      final dt = DateTime.parse(iso);
      final months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
      return '${dt.day} ${months[dt.month - 1]} ${dt.year}, ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    } catch (_) {
      return iso;
    }
  }
}
