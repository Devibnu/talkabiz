import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/campaign_repository_impl.dart';
import '../../domain/entities/campaign_entities.dart';
import '../providers/campaign_provider.dart';

class CampaignDetailPage extends ConsumerWidget {
  const CampaignDetailPage({super.key, required this.campaignId});
  final int campaignId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detailAsync = ref.watch(campaignDetailProvider(campaignId));
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Detail Kampanye')),
      body: detailAsync.when(
        data: (detail) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(campaignDetailProvider(campaignId)),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
            children: [
              // Header card
              _HeaderCard(campaign: detail.campaign),
              const SizedBox(height: 16),

              // Actions
              if (detail.campaign.canStart ||
                  detail.campaign.canPause ||
                  detail.campaign.canCancel)
                _ActionButtons(
                  campaign: detail.campaign,
                  onAction: () {
                    ref.invalidate(campaignDetailProvider(campaignId));
                    ref.invalidate(campaignsProvider);
                    ref.invalidate(campaignStatsProvider);
                  },
                  repo: ref.read(campaignRepositoryProvider),
                ),

              const SizedBox(height: 16),

              // Recipient stats
              Text('Statistik Penerima',
                  style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              _RecipientStatsGrid(stats: detail.recipientStats),
              const SizedBox(height: 16),

              // Template info
              if (detail.template != null) ...[
                Text('Template',
                    style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600)),
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(detail.template!.name,
                          style: theme.textTheme.bodyMedium
                              ?.copyWith(fontWeight: FontWeight.w600)),
                      const SizedBox(height: 4),
                      Text(
                        '${detail.template!.category} • ${detail.template!.language}',
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: theme.colorScheme.outline),
                      ),
                    ],
                  ),
                ),
              ],

              // Audience tags
              if (detail.audienceFilter != null &&
                  detail.audienceFilter!.containsKey('tags')) ...[
                const SizedBox(height: 16),
                Text('Target Audience',
                    style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600)),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: (detail.audienceFilter!['tags'] as List<dynamic>)
                      .map((t) => Chip(
                            label: Text(t.toString(), style: const TextStyle(fontSize: 12)),
                            materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                            visualDensity: VisualDensity.compact,
                          ))
                      .toList(),
                ),
              ],

              // Cost info
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: theme.colorScheme.primaryContainer.withValues(alpha: 0.3),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(
                  children: [
                    Icon(Icons.payments_outlined, color: theme.colorScheme.primary),
                    const SizedBox(width: 10),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Estimasi Biaya',
                            style: theme.textTheme.bodySmall
                                ?.copyWith(color: theme.colorScheme.outline)),
                        Text(
                          detail.campaign.formattedCost,
                          style: theme.textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.bold,
                            color: theme.colorScheme.primary,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (err, _) => Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.error_outline, size: 48, color: theme.colorScheme.error),
              const SizedBox(height: 12),
              Text('Gagal memuat detail kampanye'),
              const SizedBox(height: 8),
              TextButton(
                onPressed: () => ref.invalidate(campaignDetailProvider(campaignId)),
                child: const Text('Coba lagi'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _HeaderCard extends StatelessWidget {
  const _HeaderCard({required this.campaign});
  final CampaignItem campaign;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final (statusLabel, statusColor) = _resolveStatus(campaign.status);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: theme.colorScheme.outlineVariant.withValues(alpha: 0.3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  campaign.name,
                  style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  statusLabel,
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: statusColor),
                ),
              ),
            ],
          ),
          if (campaign.description != null && campaign.description!.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(
              campaign.description!,
              style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.outline),
            ),
          ],
          const SizedBox(height: 12),
          Row(
            children: [
              Icon(Icons.description_outlined, size: 14, color: theme.colorScheme.outline),
              const SizedBox(width: 4),
              Text(
                campaign.templateName,
                style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.outline),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              Icon(Icons.people_outline, size: 14, color: theme.colorScheme.outline),
              const SizedBox(width: 4),
              Text(
                '${campaign.totalRecipients} penerima',
                style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.outline),
              ),
            ],
          ),
        ],
      ),
    );
  }

  static (String, Color) _resolveStatus(String status) {
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

class _ActionButtons extends StatefulWidget {
  const _ActionButtons({
    required this.campaign,
    required this.onAction,
    required this.repo,
  });
  final CampaignItem campaign;
  final VoidCallback onAction;
  final dynamic repo;

  @override
  State<_ActionButtons> createState() => _ActionButtonsState();
}

class _ActionButtonsState extends State<_ActionButtons> {
  bool _loading = false;

  Future<void> _doAction(Future<void> Function() action) async {
    setState(() => _loading = true);
    try {
      await action();
      if (mounted) {
        widget.onAction();
      }
    } catch (e) {
      if (mounted) {
        String errorMsg = 'Gagal menjalankan aksi';
        if (e is DioException && e.response?.data is Map) {
          final data = e.response!.data as Map;
          errorMsg = (data['message'] as String?) ?? errorMsg;
        }
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMsg)),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        if (widget.campaign.canStart)
          FilledButton.icon(
            onPressed: () => _doAction(
              () async => widget.repo.startCampaign(widget.campaign.id),
            ),
            icon: const Icon(Icons.play_arrow_rounded, size: 18),
            label: const Text('Mulai'),
          ),
        if (widget.campaign.canPause)
          OutlinedButton.icon(
            onPressed: () => _doAction(
              () async => widget.repo.pauseCampaign(widget.campaign.id),
            ),
            icon: const Icon(Icons.pause_rounded, size: 18),
            label: const Text('Pause'),
          ),
        if (widget.campaign.status == 'paused')
          FilledButton.icon(
            onPressed: () => _doAction(
              () async => widget.repo.resumeCampaign(widget.campaign.id),
            ),
            icon: const Icon(Icons.play_arrow_rounded, size: 18),
            label: const Text('Lanjut'),
          ),
        if (widget.campaign.canCancel)
          OutlinedButton.icon(
            onPressed: () => _doAction(
              () async => widget.repo.cancelCampaign(widget.campaign.id),
            ),
            icon: Icon(Icons.cancel_outlined,
                size: 18, color: Theme.of(context).colorScheme.error),
            label: Text('Batal',
                style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ),
      ],
    );
  }
}

class _RecipientStatsGrid extends StatelessWidget {
  const _RecipientStatsGrid({required this.stats});
  final RecipientStats stats;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      children: [
        _RecipientStatTile('Pending', stats.pending, Colors.grey, theme),
        const SizedBox(width: 6),
        _RecipientStatTile('Sent', stats.sent, Colors.blue, theme),
        const SizedBox(width: 6),
        _RecipientStatTile('Delivered', stats.delivered, Colors.green, theme),
        const SizedBox(width: 6),
        _RecipientStatTile('Read', stats.read, Colors.teal, theme),
        const SizedBox(width: 6),
        _RecipientStatTile('Failed', stats.failed, Colors.red, theme),
      ],
    );
  }

  Widget _RecipientStatTile(String label, int value, Color color, ThemeData theme) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          children: [
            Text(
              value.toString(),
              style: theme.textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.bold,
                color: color,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              label,
              style: TextStyle(fontSize: 10, color: color),
            ),
          ],
        ),
      ),
    );
  }
}
