import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../app/router/route_names.dart';
import '../../../../core/navigation/mobile_bottom_nav.dart';
import '../../domain/entities/campaign_entities.dart';
import '../providers/campaign_provider.dart';

class CampaignsPage extends ConsumerStatefulWidget {
  const CampaignsPage({super.key});

  @override
  ConsumerState<CampaignsPage> createState() => _CampaignsPageState();
}

class _CampaignsPageState extends ConsumerState<CampaignsPage> {
  final _searchController = TextEditingController();

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final campaignsAsync = ref.watch(campaignsProvider);
    final statsAsync = ref.watch(campaignStatsProvider);
    final currentFilter = ref.watch(campaignStatusFilterProvider);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Kampanye'),
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () => context.pushNamed(RouteNames.campaignCreate),
        child: const Icon(Icons.add_rounded),
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(campaignsProvider);
          ref.invalidate(campaignStatsProvider);
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(20, 8, 20, kMobileBottomNavContentInset),
          children: [
            // Stats cards
            statsAsync.when(
              data: (stats) => _StatsRow(stats: stats),
              loading: () => const SizedBox(
                height: 80,
                child: Center(child: CircularProgressIndicator()),
              ),
              error: (_, __) => const SizedBox.shrink(),
            ),
            const SizedBox(height: 16),

            // Search bar
            TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: 'Cari kampanye...',
                prefixIcon: const Icon(Icons.search, size: 20),
                filled: true,
                fillColor: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(16),
                  borderSide: BorderSide.none,
                ),
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              ),
              onChanged: (v) => ref.read(campaignSearchProvider.notifier).state = v,
            ),
            const SizedBox(height: 12),

            // Status filter chips
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  _FilterChip(
                    label: 'Semua',
                    selected: currentFilter == null,
                    onTap: () => ref.read(campaignStatusFilterProvider.notifier).state = null,
                  ),
                  const SizedBox(width: 8),
                  ..._statusFilters.map((f) => Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: _FilterChip(
                          label: f['label'] as String,
                          selected: currentFilter == f['value'],
                          onTap: () => ref.read(campaignStatusFilterProvider.notifier).state =
                              f['value'] as String,
                        ),
                      )),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Campaign list
            campaignsAsync.when(
              data: (campaigns) {
                if (campaigns.isEmpty) {
                  return Center(
                    child: Padding(
                      padding: const EdgeInsets.only(top: 60),
                      child: Column(
                        children: [
                          Icon(Icons.campaign_outlined,
                              size: 64, color: theme.colorScheme.outline),
                          const SizedBox(height: 16),
                          Text(
                            'Belum ada kampanye',
                            style: theme.textTheme.titleMedium?.copyWith(
                              color: theme.colorScheme.outline,
                            ),
                          ),
                          const SizedBox(height: 8),
                          FilledButton.icon(
                            onPressed: () => context.pushNamed(RouteNames.campaignCreate),
                            icon: const Icon(Icons.add),
                            label: const Text('Buat Kampanye'),
                          ),
                        ],
                      ),
                    ),
                  );
                }

                return Column(
                  children: campaigns
                      .map((c) => _CampaignCard(
                            campaign: c,
                            onTap: () => context.pushNamed(
                              RouteNames.campaignDetail,
                              pathParameters: {'id': c.id.toString()},
                            ),
                          ))
                      .toList(),
                );
              },
              loading: () => const Padding(
                padding: EdgeInsets.only(top: 60),
                child: Center(child: CircularProgressIndicator()),
              ),
              error: (err, _) => Center(
                child: Padding(
                  padding: const EdgeInsets.only(top: 60),
                  child: Column(
                    children: [
                      Icon(Icons.error_outline, size: 48, color: theme.colorScheme.error),
                      const SizedBox(height: 12),
                      Text('Gagal memuat kampanye',
                          style: theme.textTheme.bodyMedium),
                      const SizedBox(height: 8),
                      TextButton(
                        onPressed: () => ref.invalidate(campaignsProvider),
                        child: const Text('Coba lagi'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: const MobileBottomNav(),
    );
  }

  static const _statusFilters = [
    {'label': 'Draft', 'value': 'draft'},
    {'label': 'Terjadwal', 'value': 'scheduled'},
    {'label': 'Berjalan', 'value': 'running'},
    {'label': 'Selesai', 'value': 'completed'},
    {'label': 'Dibatalkan', 'value': 'cancelled'},
  ];
}

class _StatsRow extends StatelessWidget {
  const _StatsRow({required this.stats});
  final CampaignStats stats;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      children: [
        _StatCard(
          label: 'Total',
          value: stats.total.toString(),
          color: theme.colorScheme.primary,
        ),
        const SizedBox(width: 8),
        _StatCard(
          label: 'Berjalan',
          value: stats.running.toString(),
          color: Colors.blue,
        ),
        const SizedBox(width: 8),
        _StatCard(
          label: 'Terjadwal',
          value: stats.scheduled.toString(),
          color: Colors.orange,
        ),
        const SizedBox(width: 8),
        _StatCard(
          label: 'Selesai',
          value: stats.completed.toString(),
          color: Colors.green,
        ),
      ],
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.label, required this.value, required this.color});
  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.1),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(
          children: [
            Text(
              value,
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.bold,
                color: color,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: color,
                fontSize: 11,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({required this.label, required this.selected, required this.onTap});
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? theme.colorScheme.primary : theme.colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(
          label,
          style: theme.textTheme.bodySmall?.copyWith(
            color: selected ? theme.colorScheme.onPrimary : theme.colorScheme.onSurface,
            fontWeight: selected ? FontWeight.w600 : FontWeight.normal,
          ),
        ),
      ),
    );
  }
}

class _CampaignCard extends StatelessWidget {
  const _CampaignCard({required this.campaign, required this.onTap});
  final CampaignItem campaign;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Material(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        campaign.name,
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    _StatusBadge(status: campaign.status),
                  ],
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Icon(Icons.description_outlined,
                        size: 14, color: theme.colorScheme.outline),
                    const SizedBox(width: 4),
                    Expanded(
                      child: Text(
                        campaign.templateName,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.outline,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    _MiniStat(Icons.people_outline, '${campaign.totalRecipients}'),
                    const SizedBox(width: 16),
                    _MiniStat(Icons.send_outlined, '${campaign.sentCount}'),
                    const SizedBox(width: 16),
                    _MiniStat(Icons.done_all_outlined, '${campaign.deliveredCount}'),
                    const SizedBox(width: 16),
                    if (campaign.failedCount > 0)
                      _MiniStat(Icons.error_outline, '${campaign.failedCount}',
                          color: theme.colorScheme.error),
                    const Spacer(),
                    Text(
                      campaign.formattedCost,
                      style: theme.textTheme.bodySmall?.copyWith(
                        fontWeight: FontWeight.w600,
                        color: theme.colorScheme.primary,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _StatusBadge extends StatelessWidget {
  const _StatusBadge({required this.status});
  final String status;

  @override
  Widget build(BuildContext context) {
    final (label, color) = _resolve(status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          color: color,
        ),
      ),
    );
  }

  static (String, Color) _resolve(String status) {
    return switch (status) {
      'draft' => ('Draft', Colors.grey),
      'scheduled' => ('Terjadwal', Colors.orange),
      'running' => ('Berjalan', Colors.blue),
      'paused' => ('Dijeda', Colors.amber),
      'completed' => ('Selesai', Colors.green),
      'cancelled' => ('Dibatalkan', Colors.red),
      _ => (status, Colors.grey),
    };
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat(this.icon, this.value, {this.color});
  final IconData icon;
  final String value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = color ?? Theme.of(context).colorScheme.outline;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 14, color: c),
        const SizedBox(width: 3),
        Text(value, style: TextStyle(fontSize: 12, color: c)),
      ],
    );
  }
}
