import 'package:dio/dio.dart';

import '../../domain/entities/billing_entities.dart';
import '../../domain/entities/transaction_item.dart';

class BillingRemoteDatasource {
  const BillingRemoteDatasource(this._dio);

  final Dio _dio;

  Future<BillingOverview> getOverview() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/billing/overview',
    );
    final data = response.data?['data'] as Map<String, dynamic>? ?? {};
    return _parseOverview(data);
  }

  Future<List<PlanItem>> getPlans() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/billing/plans',
    );
    final items = response.data?['data'] as List<dynamic>? ?? [];
    return items
        .map((e) => _parsePlan(e as Map<String, dynamic>))
        .toList();
  }

  Future<List<TopUpOption>> getTopUpOptions() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/billing/topup-options',
    );
    final items = response.data?['data'] as List<dynamic>? ?? [];
    return items
        .map((e) => _parseTopUpOption(e as Map<String, dynamic>))
        .toList();
  }

  Future<CheckoutResult> checkoutPlan(int planId) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/mobile/billing/checkout-plan',
      data: {'plan_id': planId},
    );
    final data = response.data?['data'] as Map<String, dynamic>? ?? {};
    return _parseCheckout(data);
  }

  Future<CheckoutResult> topUp(int amount) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/mobile/billing/topup',
      data: {'amount': amount},
    );
    final data = response.data?['data'] as Map<String, dynamic>? ?? {};
    return _parseCheckout(data);
  }

  // -- parsers --

  BillingOverview _parseOverview(Map<String, dynamic> d) {
    final sub = d['subscription'] as Map<String, dynamic>? ?? {};
    final w = d['wallet'] as Map<String, dynamic>? ?? {};
    return BillingOverview(
      subscription: BillingSubscription(
        planName: sub['plan_name'] as String? ?? '-',
        planCode: sub['plan_code'] as String? ?? '-',
        priceMonthly: sub['price_monthly'] as int? ?? 0,
        formattedPrice: sub['formatted_price'] as String? ?? '-',
        status: sub['status'] as String? ?? 'inactive',
        expiresAt: sub['expires_at'] as String?,
        daysRemaining: sub['days_remaining'] as int? ?? 0,
        autoRenew: sub['auto_renew'] as bool? ?? false,
        features: (sub['features'] as List<dynamic>? ?? [])
            .map((e) => e.toString())
            .toList(),
      ),
      wallet: BillingWallet(
        balance: w['balance'] as int? ?? 0,
        formattedBalance: w['formatted_balance'] as String? ?? 'Rp 0',
      ),
    );
  }

  PlanItem _parsePlan(Map<String, dynamic> d) {
    return PlanItem(
      id: d['id'] as int? ?? 0,
      code: d['code'] as String? ?? '',
      name: d['name'] as String? ?? '',
      description: d['description'] as String? ?? '',
      priceMonthly: d['price_monthly'] as int? ?? 0,
      formattedPrice: d['formatted_price'] as String? ?? '-',
      durationDays: d['duration_days'] as int? ?? 30,
      features: (d['features'] as List<dynamic>? ?? [])
          .map((e) => e.toString())
          .toList(),
      maxWaNumbers: d['max_wa_numbers'] as int? ?? 1,
      maxCampaigns: d['max_campaigns'] as int? ?? 0,
      isPopular: d['is_popular'] as bool? ?? false,
    );
  }

  TopUpOption _parseTopUpOption(Map<String, dynamic> d) {
    return TopUpOption(
      amount: d['amount'] as int? ?? 0,
      label: d['label'] as String? ?? '',
      description: d['description'] as String? ?? '',
    );
  }

  CheckoutResult _parseCheckout(Map<String, dynamic> d) {
    return CheckoutResult(
      snapToken: d['snap_token'] as String? ?? '',
      redirectUrl: d['redirect_url'] as String?,
      orderId: d['order_id'] as String? ?? '',
      amount: d['amount'] as int? ?? 0,
      formattedAmount: d['formatted_amount'] as String? ?? '',
    );
  }

  Future<List<TransactionItem>> getTransactions() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/billing/transactions',
    );
    final items = response.data?['data'] as List<dynamic>? ?? [];
    return items
        .map((e) => _parseTransaction(e as Map<String, dynamic>))
        .toList();
  }

  TransactionItem _parseTransaction(Map<String, dynamic> d) {
    return TransactionItem(
      id: d['id'] as String? ?? '',
      type: d['type'] as String? ?? '',
      subtype: d['subtype'] as String? ?? '',
      code: d['code'] as String? ?? '',
      amount: d['amount'] as int? ?? 0,
      formattedAmount: d['formatted_amount'] as String? ?? '',
      status: d['status'] as String? ?? '',
      description: d['description'] as String? ?? '',
      createdAt: d['created_at'] as String?,
    );
  }
}
