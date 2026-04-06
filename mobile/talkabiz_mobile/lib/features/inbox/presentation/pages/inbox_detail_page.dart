import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:file_picker/file_picker.dart';

import '../../../../app/router/route_names.dart';
import '../../domain/entities/inbox_message_item.dart';
import '../providers/inbox_provider.dart';

class InboxDetailPage extends ConsumerStatefulWidget {
  const InboxDetailPage({required this.conversationId, super.key});

  final int conversationId;

  @override
  ConsumerState<InboxDetailPage> createState() => _InboxDetailPageState();
}

class _InboxDetailPageState extends ConsumerState<InboxDetailPage> {
  late final TextEditingController _messageController;

  @override
  void initState() {
    super.initState();
    _messageController = TextEditingController();
  }

  @override
  void dispose() {
    _messageController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final detailAsync = ref.watch(
      inboxConversationDetailProvider(widget.conversationId),
    );
    final composerState = ref.watch(
      inboxComposerProvider(widget.conversationId),
    );
    final theme = Theme.of(context);

    return Scaffold(
      body: detailAsync.when(
        data: (detail) {
          return SafeArea(
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 6, 12, 8),
                  child: Row(
                    children: [
                      IconButton(
                        onPressed: () {
                          if (Navigator.of(context).canPop()) {
                            Navigator.of(context).pop();
                            return;
                          }

                          context.goNamed(RouteNames.inbox);
                        },
                        icon: const Icon(Icons.arrow_back_rounded),
                      ),
                      const SizedBox(width: 4),
                      Text(
                        'Detail Percakapan',
                        style: theme.textTheme.headlineSmall,
                      ),
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                  child: Container(
                    padding: const EdgeInsets.all(18),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFFCF6),
                      borderRadius: BorderRadius.circular(24),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Container(
                              height: 48,
                              width: 48,
                              decoration: BoxDecoration(
                                color: const Color(0x1F1959D1),
                                borderRadius: BorderRadius.circular(18),
                              ),
                              child: Center(
                                child: Text(
                                  _initials(detail.contactName),
                                  style: theme.textTheme.titleLarge?.copyWith(
                                    color: const Color(0xFF1959D1),
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    detail.contactName,
                                    style: theme.textTheme.titleLarge,
                                  ),
                                  const SizedBox(height: 2),
                                  Text(
                                    detail.phone,
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      color: theme.colorScheme.onSurfaceVariant,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            _HeaderChip(label: detail.status),
                            _HeaderChip(label: 'Prioritas ${detail.priority}'),
                            _HeaderChip(label: '${detail.messages.length} pesan'),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
                Expanded(
                  child: ListView.separated(
                    padding: const EdgeInsets.fromLTRB(20, 4, 20, 20),
                    itemCount: detail.messages.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (context, index) {
                      return _MessageBubble(message: detail.messages[index]);
                    },
                  ),
                ),
                SafeArea(
                  top: false,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        if (composerState.errorMessage != null) ...[
                          Container(
                            width: double.infinity,
                            margin: const EdgeInsets.only(bottom: 10),
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 10,
                            ),
                            decoration: BoxDecoration(
                              color: const Color(0xFFFEE2E2),
                              borderRadius: BorderRadius.circular(14),
                            ),
                            child: Text(composerState.errorMessage!),
                          ),
                        ],
                        if (composerState.hasAttachment) ...[
                          Container(
                            width: double.infinity,
                            margin: const EdgeInsets.only(bottom: 8),
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 10,
                            ),
                            decoration: BoxDecoration(
                              color: const Color(0xFFECFDF5),
                              borderRadius: BorderRadius.circular(14),
                              border: Border.all(color: const Color(0xFF10B981)),
                            ),
                            child: Row(
                              children: [
                                Icon(
                                  _mediaTypeIcon(composerState.attachedMediaType),
                                  size: 20,
                                  color: const Color(0xFF10B981),
                                ),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    composerState.attachedFileName ?? 'File',
                                    overflow: TextOverflow.ellipsis,
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ),
                                GestureDetector(
                                  onTap: () => ref
                                      .read(inboxComposerProvider(
                                              widget.conversationId)
                                          .notifier)
                                      .removeAttachment(),
                                  child: const Icon(
                                    Icons.close_rounded,
                                    size: 20,
                                    color: Color(0xFF6B7280),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            SizedBox(
                              width: 44,
                              height: 52,
                              child: IconButton(
                                onPressed: composerState.isSubmitting
                                    ? null
                                    : () => _showAttachmentSheet(context),
                                icon: const Icon(Icons.attach_file_rounded),
                                color: const Color(0xFF6B7280),
                              ),
                            ),
                            Expanded(
                              child: TextField(
                                controller: _messageController,
                                minLines: 1,
                                maxLines: 3,
                                textInputAction: TextInputAction.send,
                                onSubmitted: composerState.isSubmitting
                                    ? null
                                    : (_) => _submitMessage(),
                                decoration: InputDecoration(
                                  hintText: composerState.hasAttachment
                                      ? 'Tambah keterangan...'
                                      : 'Ketik balasan...',
                                  prefixIcon: const Icon(
                                      Icons.chat_bubble_outline_rounded),
                                ),
                              ),
                            ),
                            const SizedBox(width: 12),
                            SizedBox(
                              width: 52,
                              height: 52,
                              child: ElevatedButton(
                                onPressed: composerState.isSubmitting
                                    ? null
                                    : _submitMessage,
                                child: composerState.isSubmitting
                                    ? const SizedBox(
                                        height: 18,
                                        width: 18,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          color: Colors.white,
                                        ),
                                      )
                                    : const Icon(Icons.send_rounded),
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          );
        },
        error: (error, stackTrace) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Text('Gagal memuat percakapan: $error'),
            ),
          );
        },
        loading: () => const Center(child: CircularProgressIndicator()),
      ),
    );
  }

  Future<void> _submitMessage() async {
    final messenger = ScaffoldMessenger.of(context);
    final message = _messageController.text;
    final success = await ref
        .read(inboxComposerProvider(widget.conversationId).notifier)
        .sendMessage(message);

    if (!mounted || !success) {
      return;
    }

    _messageController.clear();
    ref.invalidate(inboxConversationDetailProvider(widget.conversationId));
    ref.invalidate(inboxProvider);

    messenger.showSnackBar(
      const SnackBar(content: Text('Pesan berhasil dikirim.')),
    );
  }

  void _showAttachmentSheet(BuildContext context) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 40,
                  height: 4,
                  margin: const EdgeInsets.only(bottom: 16),
                  decoration: BoxDecoration(
                    color: const Color(0xFFD1D5DB),
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                Text(
                  'Kirim Lampiran',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                ),
                const SizedBox(height: 16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    _AttachmentOption(
                      icon: Icons.camera_alt_rounded,
                      label: 'Kamera',
                      color: const Color(0xFF10B981),
                      onTap: () {
                        Navigator.pop(ctx);
                        _pickFromCamera();
                      },
                    ),
                    _AttachmentOption(
                      icon: Icons.photo_library_rounded,
                      label: 'Galeri',
                      color: const Color(0xFF6366F1),
                      onTap: () {
                        Navigator.pop(ctx);
                        _pickFromGallery();
                      },
                    ),
                    _AttachmentOption(
                      icon: Icons.insert_drive_file_rounded,
                      label: 'Dokumen',
                      color: const Color(0xFFF59E0B),
                      onTap: () {
                        Navigator.pop(ctx);
                        _pickDocument();
                      },
                    ),
                    _AttachmentOption(
                      icon: Icons.videocam_rounded,
                      label: 'Video',
                      color: const Color(0xFFEF4444),
                      onTap: () {
                        Navigator.pop(ctx);
                        _pickVideo();
                      },
                    ),
                  ],
                ),
                const SizedBox(height: 8),
              ],
            ),
          ),
        );
      },
    );
  }

  Future<void> _pickFromCamera() async {
    final picker = ImagePicker();
    final photo = await picker.pickImage(
      source: ImageSource.camera,
      imageQuality: 80,
    );
    if (photo != null) {
      _attachFile(photo.path, photo.name, 'gambar');
    }
  }

  Future<void> _pickFromGallery() async {
    final picker = ImagePicker();
    final image = await picker.pickImage(
      source: ImageSource.gallery,
      imageQuality: 80,
    );
    if (image != null) {
      _attachFile(image.path, image.name, 'gambar');
    }
  }

  Future<void> _pickVideo() async {
    final picker = ImagePicker();
    final video = await picker.pickVideo(source: ImageSource.gallery);
    if (video != null) {
      _attachFile(video.path, video.name, 'video');
    }
  }

  Future<void> _pickDocument() async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'],
    );
    if (result != null && result.files.single.path != null) {
      final file = result.files.single;
      _attachFile(file.path!, file.name, 'dokumen');
    }
  }

  void _attachFile(String path, String name, String mediaType) {
    ref
        .read(inboxComposerProvider(widget.conversationId).notifier)
        .attachFile(path, name, mediaType);
  }

  IconData _mediaTypeIcon(String? type) {
    switch (type) {
      case 'gambar':
        return Icons.image_rounded;
      case 'video':
        return Icons.videocam_rounded;
      case 'audio':
        return Icons.mic_rounded;
      default:
        return Icons.insert_drive_file_rounded;
    }
  }

  String _initials(String value) {
    final parts = value
        .trim()
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .toList();
    if (parts.isEmpty) {
      return '?';
    }
    if (parts.length == 1) {
      return parts.first.characters.first.toUpperCase();
    }
    return (parts.first.characters.first + parts.last.characters.first)
        .toUpperCase();
  }
}

class _HeaderChip extends StatelessWidget {
  const _HeaderChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
              fontWeight: FontWeight.w600,
            ),
      ),
    );
  }
}

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({required this.message});

  final InboxMessageItem message;

  @override
  Widget build(BuildContext context) {
    final isInbound = message.direction == 'inbound';

    return Align(
      alignment: isInbound ? Alignment.centerLeft : Alignment.centerRight,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 280),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: isInbound ? Colors.white : const Color(0xFFDCFCE7),
            borderRadius: BorderRadius.circular(18),
            border: isInbound
                ? Border.all(color: const Color(0xFFE7E1D6))
                : null,
          ),
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Column(
              crossAxisAlignment:
                  isInbound ? CrossAxisAlignment.start : CrossAxisAlignment.end,
              children: [
                Text(
                  message.content,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        height: 1.35,
                      ),
                ),
                const SizedBox(height: 8),
                Text(
                  '${message.type} • ${_formatDate(message.timestamp)}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  String _formatDate(DateTime? value) {
    if (value == null) {
      return '-';
    }
    final day = value.day.toString().padLeft(2, '0');
    final month = value.month.toString().padLeft(2, '0');
    final hour = value.hour.toString().padLeft(2, '0');
    final minute = value.minute.toString().padLeft(2, '0');
    return '$day/$month $hour:$minute';
  }
}

class _AttachmentOption extends StatelessWidget {
  const _AttachmentOption({
    required this.icon,
    required this.label,
    required this.color,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              color: color.withOpacity(0.1),
              borderRadius: BorderRadius.circular(16),
            ),
            child: Icon(icon, color: color, size: 28),
          ),
          const SizedBox(height: 8),
          Text(
            label,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w500,
                ),
          ),
        ],
      ),
    );
  }
}
