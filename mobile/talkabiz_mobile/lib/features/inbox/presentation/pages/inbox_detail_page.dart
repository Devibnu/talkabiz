import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

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

    return Scaffold(
      appBar: AppBar(),
      body: detailAsync.when(
        data: (detail) {
          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      detail.contactName,
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    const SizedBox(height: 6),
                    Text(detail.phone),
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 8,
                      children: [
                        _HeaderChip(label: detail.status),
                        _HeaderChip(label: 'Prioritas ${detail.priority}'),
                      ],
                    ),
                  ],
                ),
              ),
              const Divider(height: 1),
              Expanded(
                child: ListView.separated(
                  padding: const EdgeInsets.all(20),
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
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
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
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Expanded(
                            child: TextField(
                              controller: _messageController,
                              minLines: 1,
                              maxLines: 4,
                              textInputAction: TextInputAction.send,
                              onSubmitted: composerState.isSubmitting
                                  ? null
                                  : (_) => _submitMessage(),
                              decoration: const InputDecoration(
                                hintText: 'Ketik balasan...',
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          SizedBox(
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
      child: Text(label),
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
          ),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: isInbound
                  ? CrossAxisAlignment.start
                  : CrossAxisAlignment.end,
              children: [
                Text(message.content),
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
