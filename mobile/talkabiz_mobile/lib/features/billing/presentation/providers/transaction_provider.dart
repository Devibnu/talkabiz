import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/billing_repository_impl.dart';
import '../../domain/entities/transaction_item.dart';
import '../../../../core/preview/app_preview.dart';

final transactionsProvider = FutureProvider<List<TransactionItem>>((ref) async {
  if (kUsePreviewData) {
    return _previewTransactions;
  }

  final remote = ref.watch(billingRemoteDatasourceProvider);
  final response = await remote.getTransactions();
  return response;
});

final List<TransactionItem> _previewTransactions = [
  TransactionItem(
    id: 'saldo_1',
    type: 'saldo',
    subtype: 'topup',
    code: 'TOPUP-20260401-ABC123',
    amount: 500000,
    formattedAmount: 'Rp 500.000',
    status: 'success',
    description: 'Top Up Saldo',
    createdAt: '2026-04-01T10:30:00+07:00',
  ),
  TransactionItem(
    id: 'plan_1',
    type: 'plan',
    subtype: 'purchase',
    code: 'PLAN-20260328-XYZ789',
    amount: 299000,
    formattedAmount: 'Rp 299.000',
    status: 'success',
    description: 'Paket Pro (purchase)',
    createdAt: '2026-03-28T14:15:00+07:00',
  ),
  TransactionItem(
    id: 'saldo_2',
    type: 'saldo',
    subtype: 'potong',
    code: 'TRX-20260325-DEF456',
    amount: 75000,
    formattedAmount: 'Rp 75.000',
    status: 'success',
    description: 'Pemakaian Saldo',
    createdAt: '2026-03-25T09:45:00+07:00',
  ),
  TransactionItem(
    id: 'saldo_3',
    type: 'saldo',
    subtype: 'topup',
    code: 'TOPUP-20260320-GHI012',
    amount: 100000,
    formattedAmount: 'Rp 100.000',
    status: 'pending',
    description: 'Top Up Saldo',
    createdAt: '2026-03-20T16:00:00+07:00',
  ),
  TransactionItem(
    id: 'plan_2',
    type: 'plan',
    subtype: 'purchase',
    code: 'PLAN-20260228-JKL345',
    amount: 99000,
    formattedAmount: 'Rp 99.000',
    status: 'expired',
    description: 'Paket Starter (purchase)',
    createdAt: '2026-02-28T11:00:00+07:00',
  ),
];
