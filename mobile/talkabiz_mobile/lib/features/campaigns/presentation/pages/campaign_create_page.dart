import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../template/domain/entities/template_item.dart';
import '../../../template/presentation/providers/template_provider.dart';
import '../../data/repositories/campaign_repository_impl.dart';
import '../providers/campaign_provider.dart';

class CampaignCreatePage extends ConsumerStatefulWidget {
  const CampaignCreatePage({super.key});

  @override
  ConsumerState<CampaignCreatePage> createState() => _CampaignCreatePageState();
}

class _CampaignCreatePageState extends ConsumerState<CampaignCreatePage> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _descriptionController = TextEditingController();

  int? _selectedTemplateId;
  String _audience = 'all';
  bool _submitting = false;

  @override
  void dispose() {
    _nameController.dispose();
    _descriptionController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_selectedTemplateId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Pilih template terlebih dahulu')),
      );
      return;
    }

    setState(() => _submitting = true);

    try {
      await ref.read(campaignRepositoryProvider).createCampaign(
            name: _nameController.text.trim(),
            description: _descriptionController.text.trim().isEmpty
                ? null
                : _descriptionController.text.trim(),
            templateId: _selectedTemplateId!,
            audience: _audience,
          );

      ref.invalidate(campaignsProvider);
      ref.invalidate(campaignStatsProvider);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Kampanye berhasil dibuat!')),
        );
        context.pop();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Gagal: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final templatesAsync = ref.watch(templatesProvider);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Buat Kampanye')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
          children: [
            // Campaign name
            TextFormField(
              controller: _nameController,
              decoration: InputDecoration(
                labelText: 'Nama Kampanye',
                hintText: 'Contoh: Promo Ramadhan 2026',
                filled: true,
                fillColor: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: BorderSide.none,
                ),
              ),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Nama wajib diisi' : null,
            ),
            const SizedBox(height: 16),

            // Description
            TextFormField(
              controller: _descriptionController,
              decoration: InputDecoration(
                labelText: 'Deskripsi (opsional)',
                filled: true,
                fillColor: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: BorderSide.none,
                ),
              ),
              maxLines: 3,
            ),
            const SizedBox(height: 16),

            // Template selection
            Text('Template',
                style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            templatesAsync.when(
              data: (templates) {
                final usable = templates.where((t) => t.isUsable).toList();
                if (usable.isEmpty) {
                  return Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.errorContainer.withValues(alpha: 0.3),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: const Text(
                      'Tidak ada template yang approved. Buat & submit template terlebih dahulu.',
                    ),
                  );
                }
                return _TemplateSelector(
                  templates: usable,
                  selectedId: _selectedTemplateId,
                  onSelected: (id) => setState(() => _selectedTemplateId = id),
                );
              },
              loading: () => const SizedBox(
                  height: 60, child: Center(child: CircularProgressIndicator())),
              error: (_, __) => const Text('Gagal memuat template'),
            ),
            const SizedBox(height: 16),

            // Audience selection
            Text('Target Penerima',
                style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            _AudienceSelector(
              selected: _audience,
              onChanged: (v) => setState(() => _audience = v),
            ),
            const SizedBox(height: 32),

            // Submit button
            SizedBox(
              height: 50,
              child: FilledButton(
                onPressed: _submitting ? null : _submit,
                style: FilledButton.styleFrom(
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                  ),
                ),
                child: _submitting
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : const Text('Buat Kampanye', style: TextStyle(fontSize: 16)),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _TemplateSelector extends StatelessWidget {
  const _TemplateSelector({
    required this.templates,
    required this.selectedId,
    required this.onSelected,
  });
  final List<TemplateItem> templates;
  final int? selectedId;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      children: templates
          .map((t) => Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Material(
                  color: selectedId == t.id
                      ? theme.colorScheme.primaryContainer
                      : theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
                  borderRadius: BorderRadius.circular(14),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(14),
                    onTap: () => onSelected(t.id),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Row(
                        children: [
                          Icon(
                            selectedId == t.id
                                ? Icons.radio_button_checked
                                : Icons.radio_button_unchecked,
                            size: 20,
                            color: selectedId == t.id
                                ? theme.colorScheme.primary
                                : theme.colorScheme.outline,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  t.displayName,
                                  style: theme.textTheme.bodyMedium
                                      ?.copyWith(fontWeight: FontWeight.w600),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  '${t.category} • ${t.language}',
                                  style: theme.textTheme.bodySmall
                                      ?.copyWith(color: theme.colorScheme.outline, fontSize: 11),
                                ),
                                if (t.bodyPreview.isNotEmpty) ...[
                                  const SizedBox(height: 4),
                                  Text(
                                    t.bodyPreview,
                                    style: theme.textTheme.bodySmall
                                        ?.copyWith(color: theme.colorScheme.outline),
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ))
          .toList(),
    );
  }
}

class _AudienceSelector extends StatelessWidget {
  const _AudienceSelector({required this.selected, required this.onChanged});
  final String selected;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      children: [
        _AudienceOption(
          label: 'Semua Kontak',
          subtitle: 'Kirim ke semua kontak yang terdaftar',
          icon: Icons.people_rounded,
          selected: selected == 'all',
          onTap: () => onChanged('all'),
          theme: theme,
        ),
      ],
    );
  }
}

class _AudienceOption extends StatelessWidget {
  const _AudienceOption({
    required this.label,
    required this.subtitle,
    required this.icon,
    required this.selected,
    required this.onTap,
    required this.theme,
  });
  final String label;
  final String subtitle;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;
  final ThemeData theme;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected
          ? theme.colorScheme.primaryContainer
          : theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Icon(icon,
                  size: 24,
                  color: selected ? theme.colorScheme.primary : theme.colorScheme.outline),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label,
                        style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
                    Text(subtitle,
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: theme.colorScheme.outline, fontSize: 11)),
                  ],
                ),
              ),
              Icon(
                selected ? Icons.check_circle : Icons.circle_outlined,
                size: 20,
                color: selected ? theme.colorScheme.primary : theme.colorScheme.outline,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
