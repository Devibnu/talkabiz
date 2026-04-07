import 'package:dio/dio.dart';

import '../../domain/entities/campaign_entities.dart';

class CampaignRemoteDatasource {
  const CampaignRemoteDatasource(this._dio);

  final Dio _dio;

  Future<List<CampaignItem>> getCampaigns({String? search, String? status}) async {
    final params = <String, dynamic>{};
    if (search != null && search.isNotEmpty) params['search'] = search;
    if (status != null && status.isNotEmpty) params['status'] = status;

    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/campaigns',
      queryParameters: params,
    );
    final items = response.data?['data'] as List<dynamic>? ?? [];
    return items.map((e) => _parseCampaign(e as Map<String, dynamic>)).toList();
  }

  Future<CampaignStats> getStats() async {
    final response = await _dio.get<Map<String, dynamic>>('/mobile/campaigns/stats');
    final d = response.data?['data'] as Map<String, dynamic>? ?? {};
    return CampaignStats(
      total: d['total'] as int? ?? 0,
      draft: d['draft'] as int? ?? 0,
      scheduled: d['scheduled'] as int? ?? 0,
      running: d['running'] as int? ?? 0,
      completed: d['completed'] as int? ?? 0,
      cancelled: d['cancelled'] as int? ?? 0,
    );
  }

  Future<CampaignDetail> getCampaign(int id) async {
    final response = await _dio.get<Map<String, dynamic>>('/mobile/campaigns/$id');
    final d = response.data?['data'] as Map<String, dynamic>? ?? {};
    return _parseCampaignDetail(d);
  }

  Future<CampaignItem> createCampaign({
    required String name,
    String? description,
    required int templateId,
    required String audience,
    List<String>? tags,
    Map<String, dynamic>? templateVariables,
    String? scheduledAt,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/mobile/campaigns',
      data: {
        'name': name,
        'description': description,
        'template_id': templateId,
        'audience': audience,
        if (tags != null) 'tags': tags,
        if (templateVariables != null) 'template_variables': templateVariables,
        if (scheduledAt != null) 'scheduled_at': scheduledAt,
      },
    );
    final d = response.data?['data'] as Map<String, dynamic>? ?? {};
    return _parseCampaign(d);
  }

  Future<CampaignItem> startCampaign(int id) async {
    final response = await _dio.post<Map<String, dynamic>>('/mobile/campaigns/$id/start');
    final d = response.data?['data'] as Map<String, dynamic>? ?? {};
    return _parseCampaign(d);
  }

  Future<CampaignItem> pauseCampaign(int id) async {
    final response = await _dio.post<Map<String, dynamic>>('/mobile/campaigns/$id/pause');
    final d = response.data?['data'] as Map<String, dynamic>? ?? {};
    return _parseCampaign(d);
  }

  Future<CampaignItem> resumeCampaign(int id) async {
    final response = await _dio.post<Map<String, dynamic>>('/mobile/campaigns/$id/resume');
    final d = response.data?['data'] as Map<String, dynamic>? ?? {};
    return _parseCampaign(d);
  }

  Future<CampaignItem> cancelCampaign(int id) async {
    final response = await _dio.post<Map<String, dynamic>>('/mobile/campaigns/$id/cancel');
    final d = response.data?['data'] as Map<String, dynamic>? ?? {};
    return _parseCampaign(d);
  }

  Future<void> deleteCampaign(int id) async {
    await _dio.delete<Map<String, dynamic>>('/mobile/campaigns/$id');
  }

  Future<CostEstimate> estimateCost({
    required int templateId,
    required String audience,
    List<String>? tags,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/mobile/campaigns/estimate-cost',
      data: {
        'template_id': templateId,
        'audience': audience,
        if (tags != null) 'tags': tags,
      },
    );
    final d = response.data?['data'] as Map<String, dynamic>? ?? {};
    return CostEstimate(
      totalRecipients: d['total_recipients'] as int? ?? 0,
      costPerMessage: d['cost_per_message'] as int? ?? 0,
      totalCost: d['total_cost'] as int? ?? 0,
      formattedCost: d['formatted_cost'] as String? ?? '-',
      currentBalance: d['current_balance'] as int? ?? 0,
      sufficientBalance: d['sufficient_balance'] as bool? ?? false,
    );
  }

  CampaignItem _parseCampaign(Map<String, dynamic> d) {
    return CampaignItem(
      id: d['id'] as int? ?? 0,
      name: d['name'] as String? ?? '',
      description: d['description'] as String?,
      status: d['status'] as String? ?? 'draft',
      templateName: d['template_name'] as String? ?? '-',
      totalRecipients: d['total_recipients'] as int? ?? 0,
      sentCount: d['sent_count'] as int? ?? 0,
      deliveredCount: d['delivered_count'] as int? ?? 0,
      readCount: d['read_count'] as int? ?? 0,
      failedCount: d['failed_count'] as int? ?? 0,
      estimatedCost: d['estimated_cost'] as int? ?? 0,
      formattedCost: d['formatted_cost'] as String? ?? '-',
      scheduledAt: d['scheduled_at'] as String?,
      startedAt: d['started_at'] as String?,
      completedAt: d['completed_at'] as String?,
      createdAt: d['created_at'] as String? ?? '',
      canStart: d['can_start'] as bool? ?? false,
      canPause: d['can_pause'] as bool? ?? false,
      canCancel: d['can_cancel'] as bool? ?? false,
    );
  }

  CampaignDetail _parseCampaignDetail(Map<String, dynamic> d) {
    final rs = d['recipient_stats'] as Map<String, dynamic>? ?? {};
    final tpl = d['template'] as Map<String, dynamic>?;

    return CampaignDetail(
      campaign: _parseCampaign(d),
      recipientStats: RecipientStats(
        pending: rs['pending'] as int? ?? 0,
        sent: rs['sent'] as int? ?? 0,
        delivered: rs['delivered'] as int? ?? 0,
        read: rs['read'] as int? ?? 0,
        failed: rs['failed'] as int? ?? 0,
      ),
      audienceFilter: d['audience_filter'] as Map<String, dynamic>?,
      templateVariables: d['template_variables'] as Map<String, dynamic>?,
      template: tpl != null
          ? CampaignTemplate(
              id: tpl['id'] as int? ?? 0,
              name: tpl['name'] as String? ?? '-',
              language: tpl['language'] as String? ?? 'id',
              category: tpl['category'] as String? ?? '-',
            )
          : null,
    );
  }
}
