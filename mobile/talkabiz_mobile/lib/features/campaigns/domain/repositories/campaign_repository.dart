import '../entities/campaign_entities.dart';

abstract class CampaignRepository {
  Future<List<CampaignItem>> getCampaigns({String? search, String? status});
  Future<CampaignStats> getStats();
  Future<CampaignDetail> getCampaign(int id);
  Future<CampaignItem> createCampaign({
    required String name,
    String? description,
    required int templateId,
    required String audience,
    List<String>? tags,
    Map<String, dynamic>? templateVariables,
    String? scheduledAt,
  });
  Future<CampaignItem> startCampaign(int id);
  Future<CampaignItem> pauseCampaign(int id);
  Future<CampaignItem> resumeCampaign(int id);
  Future<CampaignItem> cancelCampaign(int id);
  Future<void> deleteCampaign(int id);
  Future<CostEstimate> estimateCost({
    required int templateId,
    required String audience,
    List<String>? tags,
  });
}
