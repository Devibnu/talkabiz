import '../entities/billing_entities.dart';

abstract class BillingRepository {
  Future<BillingOverview> getOverview();
  Future<List<PlanItem>> getPlans();
  Future<List<TopUpOption>> getTopUpOptions();
  Future<CheckoutResult> checkoutPlan(int planId);
  Future<CheckoutResult> topUp(int amount);
}
