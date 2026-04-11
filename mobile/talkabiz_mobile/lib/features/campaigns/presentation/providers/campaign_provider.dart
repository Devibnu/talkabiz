import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/campaign_repository_impl.dart';
import '../../domain/entities/campaign_entities.dart';

final campaignSearchProvider = StateProvider<String>((ref) => '');
final campaignStatusFilterProvider = StateProvider<String?>((ref) => null);

final campaignsProvider = FutureProvider.autoDispose<List<CampaignItem>>((ref) async {
  final search = ref.watch(campaignSearchProvider);
  final status = ref.watch(campaignStatusFilterProvider);
  return ref.watch(campaignRepositoryProvider).getCampaigns(
        search: search.isEmpty ? null : search,
        status: status,
      );
});

final campaignStatsProvider = FutureProvider.autoDispose<CampaignStats>((ref) async {
  return ref.watch(campaignRepositoryProvider).getStats();
});

final campaignDetailProvider =
    FutureProvider.autoDispose.family<CampaignDetail, int>((ref, id) async {
  return ref.watch(campaignRepositoryProvider).getCampaign(id);
});

final contactTagsProvider = FutureProvider.autoDispose<List<TagItem>>((ref) async {
  return ref.watch(campaignRemoteDatasourceProvider).getContactTags();
});
