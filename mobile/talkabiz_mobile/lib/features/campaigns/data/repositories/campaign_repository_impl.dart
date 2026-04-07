import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../domain/entities/campaign_entities.dart';
import '../../domain/repositories/campaign_repository.dart';
import '../datasources/campaign_remote_datasource.dart';

final campaignRemoteDatasourceProvider = Provider<CampaignRemoteDatasource>((ref) {
  return CampaignRemoteDatasource(ref.watch(dioProvider));
});

final campaignRepositoryProvider = Provider<CampaignRepository>((ref) {
  return CampaignRepositoryImpl(ref.watch(campaignRemoteDatasourceProvider));
});

class CampaignRepositoryImpl implements CampaignRepository {
  const CampaignRepositoryImpl(this._remote);

  final CampaignRemoteDatasource _remote;

  @override
  Future<List<CampaignItem>> getCampaigns({String? search, String? status}) =>
      _remote.getCampaigns(search: search, status: status);

  @override
  Future<CampaignStats> getStats() => _remote.getStats();

  @override
  Future<CampaignDetail> getCampaign(int id) => _remote.getCampaign(id);

  @override
  Future<CampaignItem> createCampaign({
    required String name,
    String? description,
    required int templateId,
    required String audience,
    List<String>? tags,
    Map<String, dynamic>? templateVariables,
    String? scheduledAt,
  }) =>
      _remote.createCampaign(
        name: name,
        description: description,
        templateId: templateId,
        audience: audience,
        tags: tags,
        templateVariables: templateVariables,
        scheduledAt: scheduledAt,
      );

  @override
  Future<CampaignItem> startCampaign(int id) => _remote.startCampaign(id);

  @override
  Future<CampaignItem> pauseCampaign(int id) => _remote.pauseCampaign(id);

  @override
  Future<CampaignItem> resumeCampaign(int id) => _remote.resumeCampaign(id);

  @override
  Future<CampaignItem> cancelCampaign(int id) => _remote.cancelCampaign(id);

  @override
  Future<void> deleteCampaign(int id) => _remote.deleteCampaign(id);

  @override
  Future<CostEstimate> estimateCost({
    required int templateId,
    required String audience,
    List<String>? tags,
  }) =>
      _remote.estimateCost(
        templateId: templateId,
        audience: audience,
        tags: tags,
      );
}
