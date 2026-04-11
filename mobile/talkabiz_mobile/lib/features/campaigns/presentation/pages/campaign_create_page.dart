import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../contacts/domain/entities/contact_item.dart';
import '../../../contacts/presentation/providers/contacts_provider.dart';
import '../../../template/domain/entities/template_item.dart';
import '../../../template/presentation/providers/template_provider.dart';
import '../../data/repositories/campaign_repository_impl.dart';
import '../../domain/entities/campaign_entities.dart';
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
  Set<int> _selectedContactIds = <int>{};
  List<String> _selectedTags = [];
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

    if (_audience == 'contacts' && _selectedContactIds.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Pilih minimal satu kontak terlebih dahulu')),
      );
      return;
    }

    if (_audience == 'tag' && _selectedTags.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Pilih minimal satu tag terlebih dahulu')),
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
            contactIds: _audience == 'contacts' ? _selectedContactIds.toList() : null,
            tags: _audience == 'tag' ? _selectedTags : null,
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
        String errorMsg = 'Gagal membuat kampanye';
        if (e is DioException && e.response?.data is Map) {
          final data = e.response!.data as Map;
          errorMsg = (data['message'] as String?) ?? errorMsg;
        }
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMsg)),
        );
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final templatesAsync = ref.watch(templatesProvider);
    final contactsAsync = ref.watch(contactsProvider);
    final tagsAsync = ref.watch(contactTagsProvider);
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
              selectedCount: _selectedContactIds.length,
              selectedTagCount: _selectedTags.length,
              contactsAsync: contactsAsync,
              tagsAsync: tagsAsync,
              onChanged: (v) => setState(() => _audience = v),
              onPickContacts: (contacts) => _openContactPicker(context, contacts),
              onPickTags: (tags) => _openTagPicker(context, tags),
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

  Future<void> _openContactPicker(BuildContext context, List<ContactItem> contacts) async {
    final initialSelection = Set<int>.from(_selectedContactIds);
    final result = await showModalBottomSheet<Set<int>>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _ContactPickerSheet(
        contacts: contacts,
        initialSelection: initialSelection,
      ),
    );

    if (result != null) {
      setState(() {
        _selectedContactIds = result;
        _audience = result.isEmpty ? 'all' : 'contacts';
      });
    }
  }

  Future<void> _openTagPicker(BuildContext context, List<TagItem> tags) async {
    final result = await showModalBottomSheet<List<String>>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _TagPickerSheet(
        tags: tags,
        initialSelection: List<String>.from(_selectedTags),
      ),
    );

    if (result != null) {
      setState(() {
        _selectedTags = result;
        _audience = result.isEmpty ? 'all' : 'tag';
      });
    }
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
  const _AudienceSelector({
    required this.selected,
    required this.selectedCount,
    required this.selectedTagCount,
    required this.contactsAsync,
    required this.tagsAsync,
    required this.onChanged,
    required this.onPickContacts,
    required this.onPickTags,
  });
  final String selected;
  final int selectedCount;
  final int selectedTagCount;
  final AsyncValue<List<ContactItem>> contactsAsync;
  final AsyncValue<List<TagItem>> tagsAsync;
  final ValueChanged<String> onChanged;
  final ValueChanged<List<ContactItem>> onPickContacts;
  final ValueChanged<List<TagItem>> onPickTags;

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
        const SizedBox(height: 10),
        tagsAsync.when(
          data: (tags) => _AudienceOption(
            label: 'Kirim by Tag',
            subtitle: selectedTagCount > 0
                ? '$selectedTagCount tag dipilih'
                : tags.isEmpty
                    ? 'Belum ada tag di kontak'
                    : 'Kirim berdasarkan tag kontak',
            icon: Icons.label_rounded,
            selected: selected == 'tag',
            onTap: tags.isEmpty ? () {} : () => onPickTags(tags),
            theme: theme,
          ),
          loading: () => _AudienceOption(
            label: 'Kirim by Tag',
            subtitle: 'Memuat tag...',
            icon: Icons.label_rounded,
            selected: false,
            onTap: () {},
            theme: theme,
          ),
          error: (_, __) => _AudienceOption(
            label: 'Kirim by Tag',
            subtitle: 'Gagal memuat tag',
            icon: Icons.label_rounded,
            selected: false,
            onTap: () {},
            theme: theme,
          ),
        ),
        const SizedBox(height: 10),
        contactsAsync.when(
          data: (contacts) => _AudienceOption(
            label: 'Pilih Kontak Tertentu',
            subtitle: selectedCount > 0
                ? '$selectedCount kontak dipilih'
                : 'Pilih satu atau beberapa nomor kontak',
            icon: Icons.how_to_reg_rounded,
            selected: selected == 'contacts',
            onTap: () => onPickContacts(contacts),
            theme: theme,
          ),
          loading: () => _AudienceOption(
            label: 'Pilih Kontak Tertentu',
            subtitle: 'Memuat daftar kontak...',
            icon: Icons.how_to_reg_rounded,
            selected: false,
            onTap: () {},
            theme: theme,
          ),
          error: (_, __) => _AudienceOption(
            label: 'Pilih Kontak Tertentu',
            subtitle: 'Gagal memuat kontak. Buka halaman Kontak lalu coba lagi.',
            icon: Icons.how_to_reg_rounded,
            selected: false,
            onTap: () {},
            theme: theme,
          ),
        ),
      ],
    );
  }
}

class _ContactPickerSheet extends StatefulWidget {
  const _ContactPickerSheet({
    required this.contacts,
    required this.initialSelection,
  });

  final List<ContactItem> contacts;
  final Set<int> initialSelection;

  @override
  State<_ContactPickerSheet> createState() => _ContactPickerSheetState();
}

class _ContactPickerSheetState extends State<_ContactPickerSheet> {
  late final TextEditingController _searchController;
  late Set<int> _selection;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _searchController = TextEditingController();
    _selection = Set<int>.from(widget.initialSelection);
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final filteredContacts = widget.contacts.where((contact) {
      final query = _query.trim().toLowerCase();
      if (query.isEmpty) return true;
      return contact.name.toLowerCase().contains(query) ||
          contact.phone.toLowerCase().contains(query) ||
          (contact.email?.toLowerCase().contains(query) ?? false);
    }).toList();

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
          left: 20,
          right: 20,
          top: 8,
          bottom: MediaQuery.of(context).viewInsets.bottom + 20,
        ),
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.78,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Pilih Kontak', style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
              const SizedBox(height: 6),
              Text(
                'Pilih satu atau beberapa kontak untuk kampanye ini.',
                style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.outline),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _searchController,
                onChanged: (value) => setState(() => _query = value),
                decoration: InputDecoration(
                  hintText: 'Cari nama atau nomor',
                  prefixIcon: const Icon(Icons.search_rounded),
                  filled: true,
                  fillColor: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide.none,
                  ),
                ),
              ),
              const SizedBox(height: 12),
              Expanded(
                child: filteredContacts.isEmpty
                    ? Center(
                        child: Text(
                          'Kontak tidak ditemukan.',
                          style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.outline),
                        ),
                      )
                    : ListView.separated(
                        itemCount: filteredContacts.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (context, index) {
                          final contact = filteredContacts[index];
                          final isSelected = _selection.contains(contact.id);
                          return Material(
                            color: isSelected
                                ? theme.colorScheme.primaryContainer
                                : theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
                            borderRadius: BorderRadius.circular(14),
                            child: InkWell(
                              borderRadius: BorderRadius.circular(14),
                              onTap: () {
                                setState(() {
                                  if (isSelected) {
                                    _selection.remove(contact.id);
                                  } else {
                                    _selection.add(contact.id);
                                  }
                                });
                              },
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Row(
                                  children: [
                                    Icon(
                                      isSelected ? Icons.check_circle : Icons.circle_outlined,
                                      color: isSelected ? theme.colorScheme.primary : theme.colorScheme.outline,
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Text(contact.name, style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
                                          const SizedBox(height: 2),
                                          Text(contact.phone, style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.outline)),
                                          if ((contact.email ?? '').isNotEmpty)
                                            Text(contact.email!, style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.outline)),
                                        ],
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          );
                        },
                      ),
              ),
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.of(context).pop(_selection),
                  child: Text(_selection.isEmpty ? 'Gunakan Semua Kontak' : 'Pilih ${_selection.length} Kontak'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _TagPickerSheet extends StatefulWidget {
  const _TagPickerSheet({
    required this.tags,
    required this.initialSelection,
  });

  final List<TagItem> tags;
  final List<String> initialSelection;

  @override
  State<_TagPickerSheet> createState() => _TagPickerSheetState();
}

class _TagPickerSheetState extends State<_TagPickerSheet> {
  late Set<String> _selection;

  @override
  void initState() {
    super.initState();
    _selection = Set<String>.from(widget.initialSelection);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
          left: 20,
          right: 20,
          top: 8,
          bottom: MediaQuery.of(context).viewInsets.bottom + 20,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Pilih Tag',
                style: theme.textTheme.titleLarge
                    ?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Text(
              'Kirim ke semua kontak yang memiliki tag ini.',
              style: theme.textTheme.bodyMedium
                  ?.copyWith(color: theme.colorScheme.outline),
            ),
            const SizedBox(height: 14),
            ...widget.tags.map((tag) {
              final isSelected = _selection.contains(tag.name);
              return Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Material(
                  color: isSelected
                      ? theme.colorScheme.primaryContainer
                      : theme.colorScheme.surfaceContainerHighest
                          .withValues(alpha: 0.4),
                  borderRadius: BorderRadius.circular(14),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(14),
                    onTap: () {
                      setState(() {
                        if (isSelected) {
                          _selection.remove(tag.name);
                        } else {
                          _selection.add(tag.name);
                        }
                      });
                    },
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Row(
                        children: [
                          Icon(
                            isSelected
                                ? Icons.check_circle
                                : Icons.circle_outlined,
                            color: isSelected
                                ? theme.colorScheme.primary
                                : theme.colorScheme.outline,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Text(
                              tag.name,
                              style: theme.textTheme.bodyMedium
                                  ?.copyWith(fontWeight: FontWeight.w600),
                            ),
                          ),
                          Text(
                            '${tag.count} kontak',
                            style: theme.textTheme.bodySmall
                                ?.copyWith(color: theme.colorScheme.outline),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              );
            }),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () =>
                    Navigator.of(context).pop(_selection.toList()),
                child: Text(_selection.isEmpty
                    ? 'Gunakan Semua Kontak'
                    : 'Pilih ${_selection.length} Tag'),
              ),
            ),
          ],
        ),
      ),
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
