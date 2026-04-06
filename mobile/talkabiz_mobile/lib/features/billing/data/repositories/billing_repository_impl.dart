import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../../../core/preview/app_preview.dart';
import '../../domain/entities/billing_entities.dart';
import '../../domain/repositories/billing_repository.dart';
import '../datasources/billing_remote_datasource.dart';

final billingRemoteDatasourceProvider = Provider<BillingRemoteDatasource>((ref) {
  return BillingRemoteDatasource(ref.watch(dioProvider));
});

final billingRepositoryProvider = Provider<BillingRepository>((ref) {
  if (kUsePreviewData) {
    return const PreviewBillingRepository();
  }
  return BillingRepositoryImpl(ref.watch(billingRemoteDatasourceProvider));
});

class BillingRepositoryImpl implements BillingRepository {
  const BillingRepositoryImpl(this._remote);

  final BillingRemoteDatasource _remote;

  @override
  Future<BillingOverview> getOverview() => _remote.getOverview();

  @override
  Future<List<PlanItem>> getPlans() => _remote.getPlans();

  @override
  Future<List<TopUpOption>> getTopUpOptions() => _remote.getTopUpOptions();

  @override
  Future<CheckoutResult> checkoutPlan(int planId) =>
      _remote.checkoutPlan(planId);

  @override
  Future<CheckoutResult> topUp(int amount) => _remote.topUp(amount);
}
