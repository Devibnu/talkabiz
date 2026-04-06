import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../domain/entities/template_detail.dart';
import '../providers/template_provider.dart';

class TemplateDetailPage extends ConsumerWidget {
  const TemplateDetailPage({super.key, required this.templateId});

  final int templateId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detailAsync = ref.watch(templateDetailProvider(templateId));
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Detail Template'),
        actions: [
          detailAsync.whenOrNull(
                data: (t) => t.canEdit
                    ? PopupMenuButton<String>(
                        onSelected: (value) =>
                            _onMenuAction(context, ref, value, t),
                        itemBuilder: (_) => [
                          const PopupMenuItem(
                            value: 'edit',
                            child: ListTile(
                              leading: Icon(Icons.edit_rounded),
                              title: Text('Edit'),
                              contentPadding: EdgeInsets.zero,
                            ),
                          ),
                          const PopupMenuItem(
                            value: 'delete',
                            child: ListTile(
                              leading:
                                  Icon(Icons.delete_rounded, color: Colors.red),
                              title: Text('Hapus',
                                  style: TextStyle(color: Colors.red)),
                              contentPadding: EdgeInsets.zero,
                            ),
                          ),
                        ],
                      )
                    : null,
              ) ??
              const SizedBox.shrink(),
        ],
      ),
      body: detailAsync.when(
        data: (t) => _DetailBody(template: t),
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (err, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('Gagal memuat detail', style: theme.textTheme.titleMedium),
              const SizedBox(height: 8),
              ElevatedButton(
                onPressed: () =>
                    ref.invalidate(templateDetailProvider(templateId)),
                child: const Text('Coba Lagi'),
              ),
            ],
          ),
        ),
      ),
      bottomNavigationBar: detailAsync.whenOrNull(
        data: (t) => t.canSubmit
            ? SafeArea(
                child: Padding(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                  child: FilledButton.icon(
                    onPressed: () => _submitToMeta(context, ref, t),
                    icon: const Icon(Icons.send_rounded),
                    label: const Text('Ajukan ke Meta'),
                  ),
                ),
              )
            : null,
      ),
    );
  }

  void _onMenuAction(BuildContext context, WidgetRef ref, String action,
      TemplateDetail t) {
    switch (action) {
      case 'edit':
        context.pushNamed('template-edit', pathParameters: {
          'id': t.id.toString(),
        });
        break;
      case 'delete':
        _confirmDelete(context, ref, t);
        break;
    }
  }

  void _confirmDelete(
      BuildContext context, WidgetRef ref, TemplateDetail t) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Hapus Template?'),
        content: Text(
            'Template "${t.displayName}" akan dihapus. Tindakan ini tidak dapat dibatalkan.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Batal'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () async {
              Navigator.pop(ctx);
              final success = await ref
                  .read(templateActionProvider.notifier)
                  .delete(t.id);
              if (success && context.mounted) {
                context.pop();
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Template berhasil dihapus')),
                );
              }
            },
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
  }

  void _submitToMeta(
      BuildContext context, WidgetRef ref, TemplateDetail t) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Ajukan ke Meta?'),
        content: Text(
            'Template "${t.displayName}" akan dikirim ke Meta untuk di-review. '
            'Proses review biasanya 1-2 hari kerja.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () async {
              Navigator.pop(ctx);
              final result = await ref
                  .read(templateActionProvider.notifier)
                  .submit(t.id);
              if (context.mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text(result != null
                        ? 'Template berhasil diajukan ke Meta'
                        : 'Gagal mengajukan template'),
                  ),
                );
              }
            },
            child: const Text('Ajukan'),
          ),
        ],
      ),
    );
  }
}

class _DetailBody extends StatelessWidget {
  const _DetailBody({required this.template});

  final TemplateDetail template;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        // Status & info header
        _InfoCard(template: template),
        const SizedBox(height: 16),

        // Rejection reason
        if (template.rejectionReason != null &&
            template.rejectionReason!.isNotEmpty) ...[
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.red.shade50,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.red.shade200),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.warning_rounded,
                        color: Colors.red.shade700, size: 20),
                    const SizedBox(width: 8),
                    Text('Alasan Penolakan',
                        style: theme.textTheme.titleSmall
                            ?.copyWith(color: Colors.red.shade700)),
                  ],
                ),
                const SizedBox(height: 8),
                Text(template.rejectionReason!,
                    style: theme.textTheme.bodyMedium),
              ],
            ),
          ),
          const SizedBox(height: 16),
        ],

        // Template body preview
        Text('Isi Template', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: const Color(0xFFE7FFDB),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (template.header != null &&
                  template.header!.isNotEmpty) ...[
                Text(template.header!,
                    style: theme.textTheme.titleSmall
                        ?.copyWith(fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
              ],
              Text(template.body, style: theme.textTheme.bodyMedium),
              if (template.footer != null &&
                  template.footer!.isNotEmpty) ...[
                const SizedBox(height: 8),
                Text(template.footer!,
                    style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.outline)),
              ],
            ],
          ),
        ),
        const SizedBox(height: 16),

        // Example variables
        if (template.exampleVariables.isNotEmpty) ...[
          Text('Contoh Variabel', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          ...template.exampleVariables.entries.map(
            (entry) => Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Row(
                children: [
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.primaryContainer,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text('{{${entry.key}}}',
                        style: theme.textTheme.bodySmall?.copyWith(
                            fontFamily: 'monospace',
                            fontWeight: FontWeight.bold)),
                  ),
                  const SizedBox(width: 8),
                  const Icon(Icons.arrow_forward_rounded, size: 14),
                  const SizedBox(width: 8),
                  Expanded(
                      child: Text(entry.value.toString(),
                          style: theme.textTheme.bodyMedium)),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
        ],

        // Stats
        if (template.sentCount > 0 || template.readCount > 0) ...[
          Text('Statistik', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          Row(
            children: [
              _StatCard(
                icon: Icons.send_rounded,
                label: 'Terkirim',
                value: template.sentCount.toString(),
                color: Colors.blue,
              ),
              const SizedBox(width: 12),
              _StatCard(
                icon: Icons.done_all_rounded,
                label: 'Dibaca',
                value: template.readCount.toString(),
                color: Colors.green,
              ),
              const SizedBox(width: 12),
              _StatCard(
                icon: Icons.campaign_rounded,
                label: 'Dipakai',
                value: template.usedCount.toString(),
                color: Colors.orange,
              ),
            ],
          ),
        ],
      ],
    );
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.template});

  final TemplateDetail template;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final (statusColor, statusLabel) = switch (template.status) {
      'draft' => (Colors.grey, 'Draft'),
      'diajukan' => (Colors.orange, 'Diajukan'),
      'disetujui' => (Colors.green, 'Disetujui'),
      'ditolak' => (Colors.red, 'Ditolak'),
      'arsip' => (Colors.blueGrey, 'Arsip'),
      _ => (Colors.grey, template.status),
    };

    final categoryLabel = switch (template.category) {
      'marketing' => 'Marketing',
      'utility' => 'Utility',
      'authentication' => 'Authentication',
      _ => template.category,
    };

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    template.displayName,
                    style: theme.textTheme.titleLarge
                        ?.copyWith(fontWeight: FontWeight.bold),
                  ),
                ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: statusColor.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(16),
                    border:
                        Border.all(color: statusColor.withValues(alpha: 0.3)),
                  ),
                  child: Text(statusLabel,
                      style: TextStyle(
                          color: statusColor,
                          fontWeight: FontWeight.w600,
                          fontSize: 13)),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(template.name,
                style: theme.textTheme.bodySmall?.copyWith(
                    fontFamily: 'monospace',
                    color: theme.colorScheme.outline)),
            const SizedBox(height: 12),
            Wrap(
              spacing: 16,
              runSpacing: 8,
              children: [
                _InfoItem(
                    icon: Icons.category_rounded,
                    label: categoryLabel),
                _InfoItem(
                    icon: Icons.language_rounded,
                    label: template.language.toUpperCase()),
                if (template.headerType != null &&
                    template.headerType != 'none')
                  _InfoItem(
                    icon: Icons.title_rounded,
                    label:
                        'Header: ${template.headerType}',
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _InfoItem extends StatelessWidget {
  const _InfoItem({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 16, color: Theme.of(context).colorScheme.outline),
        const SizedBox(width: 4),
        Text(label,
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: Theme.of(context).colorScheme.outline)),
      ],
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.icon,
    required this.label,
    required this.value,
    required this.color,
  });

  final IconData icon;
  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.05),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color.withValues(alpha: 0.2)),
        ),
        child: Column(
          children: [
            Icon(icon, color: color, size: 20),
            const SizedBox(height: 4),
            Text(value,
                style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: color)),
            Text(label,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: color)),
          ],
        ),
      ),
    );
  }
}
