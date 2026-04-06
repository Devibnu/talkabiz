import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/billing_repository_impl.dart';
import '../../domain/entities/billing_entities.dart';

final billingOverviewProvider = FutureProvider.autoDispose<BillingOverview>((ref) async {
  return ref.watch(billingRepositoryProvider).getOverview();
});

final billingPlansProvider = FutureProvider.autoDispose<List<PlanItem>>((ref) async {
  return ref.watch(billingRepositoryProvider).getPlans();
});

final topUpOptionsProvider = FutureProvider.autoDispose<List<TopUpOption>>((ref) async {
  return ref.watch(billingRepositoryProvider).getTopUpOptions();
});
