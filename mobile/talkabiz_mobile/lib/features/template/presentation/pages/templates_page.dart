import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../app/router/route_names.dart';
import '../../../../core/navigation/mobile_bottom_nav.dart';
import '../../domain/entities/template_item.dart';
import '../providers/template_provider.dart';

class TemplatesPage extends ConsumerStatefulWidget {
  const TemplatesPage({super.key});

  @override
  ConsumerState<TemplatesPage> createState() => _TemplatesPageState();
}

class _TemplatesPageState extends ConsumerState<TemplatesPage> {
  late final TextEditingController _searchController;

  @override
  void initState() {
    super.initState();
    _searchController = TextEditingController();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final templatesAsync = ref.watch(templatesProvider);
    final statusFilter = ref.watch(templateStatusFilterProvider);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Template Pesan'),
        actions: [
          IconButton(
            icon: const Icon(Icons.sync_rounded),
            tooltip: 'Sync status dari Meta',
            onPressed: () => _syncStatus(),
          ),
        ],
      ),
      bottomNavigationBar: const MobileBottomNav(),
      floatingActionButton: FloatingActionButton(
        onPressed: () => context.pushNamed(RouteNames.templateCreate),
        child: const Icon(Icons.add_rounded),
      ),
      body: Column(
        children: [
          // Info banner (same as web)
          Container(
            margin: const EdgeInsets.fromLTRB(20, 8, 20, 0),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [Color(0xFF6C63FF), Color(0xFF9B59B6)],
              ),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(Icons.chat_rounded, color: Colors.white, size: 16),
                    const SizedBox(width: 6),
                    Text(
                      'Cara Menggunakan Template',
                      style: theme.textTheme.labelLarge?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  '1. Buat template  2. Submit ke WA  3. Tunggu approval\n'
                  '4. Sync status  5. Pakai di Campaign WA Blast',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: Colors.white.withValues(alpha: 0.9),
                    height: 1.5,
                  ),
                ),
              ],
            ),
          ),

          // Header & Search
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
            child: TextField(
              controller: _searchController,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: 'Cari template...',
                prefixIcon: const Icon(Icons.search_rounded),
                suffixIcon: _searchController.text.isEmpty
                    ? null
                    : IconButton(
                        onPressed: () {
                          _searchController.clear();
                          setState(() {});
                          ref.read(templateSearchProvider.notifier).state = '';
                        },
                        icon: const Icon(Icons.close_rounded),
                      ),
              ),
              onChanged: (value) {
                setState(() {});
                ref.read(templateSearchProvider.notifier).state = value;
              },
            ),
          ),

          // Status filter chips
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
            child: Row(
              children: [
                _FilterChip(
                  label: 'Semua',
                  selected: statusFilter == null,
                  onSelected: () => ref
                      .read(templateStatusFilterProvider.notifier)
                      .state = null,
                ),
                const SizedBox(width: 8),
                _FilterChip(
                  label: 'Draft',
                  selected: statusFilter == 'draft',
                  onSelected: () => ref
                      .read(templateStatusFilterProvider.notifier)
                      .state = 'draft',
                ),
                const SizedBox(width: 8),
                _FilterChip(
                  label: 'Diajukan',
                  selected: statusFilter == 'diajukan',
                  onSelected: () => ref
                      .read(templateStatusFilterProvider.notifier)
                      .state = 'diajukan',
                ),
                const SizedBox(width: 8),
                _FilterChip(
                  label: 'Disetujui',
                  selected: statusFilter == 'disetujui',
                  onSelected: () => ref
                      .read(templateStatusFilterProvider.notifier)
                      .state = 'disetujui',
                ),
                const SizedBox(width: 8),
                _FilterChip(
                  label: 'Ditolak',
                  selected: statusFilter == 'ditolak',
                  onSelected: () => ref
                      .read(templateStatusFilterProvider.notifier)
                      .state = 'ditolak',
                ),
              ],
            ),
          ),

          // Template list
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async {
                ref.invalidate(templatesProvider);
                await ref.read(templatesProvider.future);
              },
              child: templatesAsync.when(
                data: (templates) {
                  if (templates.isEmpty) {
                    return Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.description_outlined,
                              size: 64,
                              color: theme.colorScheme.outline),
                          const SizedBox(height: 16),
                          Text('Belum ada template',
                              style: theme.textTheme.titleMedium?.copyWith(
                                  color: theme.colorScheme.outline)),
                          const SizedBox(height: 8),
                          Text('Buat template baru untuk campaign WhatsApp',
                              style: theme.textTheme.bodyMedium?.copyWith(
                                  color: theme.colorScheme.outline)),
                        ],
                      ),
                    );
                  }

                  return ListView.separated(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(20, 0, 20, 100),
                    itemCount: templates.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 12),
                    itemBuilder: (context, index) =>
                        _TemplateCard(template: templates[index]),
                  );
                },
                loading: () =>
                    const Center(child: CircularProgressIndicator()),
                error: (err, _) => Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text('Gagal memuat template',
                          style: theme.textTheme.titleMedium),
                      const SizedBox(height: 8),
                      ElevatedButton(
                        onPressed: () => ref.invalidate(templatesProvider),
                        child: const Text('Coba Lagi'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  void _syncStatus() async {
    final synced =
        await ref.read(templateActionProvider.notifier).syncStatus();
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('$synced template berhasil di-sync')),
      );
    }
  }
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({
    required this.label,
    required this.selected,
    required this.onSelected,
  });

  final String label;
  final bool selected;
  final VoidCallback onSelected;

  @override
  Widget build(BuildContext context) {
    return FilterChip(
      label: Text(label),
      selected: selected,
      onSelected: (_) => onSelected(),
    );
  }
}

class _TemplateCard extends StatelessWidget {
  const _TemplateCard({required this.template});

  final TemplateItem template;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () =>
            context.pushNamed(RouteNames.templateDetail, pathParameters: {
          'id': template.id.toString(),
        }),
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
                      style: theme.textTheme.titleMedium
                          ?.copyWith(fontWeight: FontWeight.w600),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(width: 8),
                  _StatusBadge(status: template.status),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                template.name,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                  fontFamily: 'monospace',
                ),
              ),
              const SizedBox(height: 8),
              Text(
                template.bodyPreview,
                style: theme.textTheme.bodyMedium,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  _CategoryChip(category: template.category),
                  const Spacer(),
                  if (template.sentCount > 0) ...[
                    Icon(Icons.send_rounded,
                        size: 14, color: theme.colorScheme.outline),
                    const SizedBox(width: 4),
                    Text('${template.sentCount}',
                        style: theme.textTheme.bodySmall),
                    const SizedBox(width: 12),
                  ],
                  if (template.readCount > 0) ...[
                    Icon(Icons.done_all_rounded,
                        size: 14, color: Colors.blue),
                    const SizedBox(width: 4),
                    Text('${template.readCount}',
                        style: theme.textTheme.bodySmall),
                  ],
                ],
              ),
            ],
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
    final (color, label) = switch (status) {
      'draft' => (Colors.grey, 'Draft'),
      'diajukan' => (Colors.orange, 'Diajukan'),
      'disetujui' => (Colors.green, 'Disetujui'),
      'ditolak' => (Colors.red, 'Ditolak'),
      'arsip' => (Colors.blueGrey, 'Arsip'),
      _ => (Colors.grey, status),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

class _CategoryChip extends StatelessWidget {
  const _CategoryChip({required this.category});

  final String category;

  @override
  Widget build(BuildContext context) {
    final (icon, label) = switch (category) {
      'marketing' => (Icons.campaign_rounded, 'Marketing'),
      'utility' => (Icons.build_rounded, 'Utility'),
      'authentication' => (Icons.lock_rounded, 'Auth'),
      _ => (Icons.label_rounded, category),
    };

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 14, color: Theme.of(context).colorScheme.outline),
        const SizedBox(width: 4),
        Text(
          label,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: Theme.of(context).colorScheme.outline,
              ),
        ),
      ],
    );
  }
}
