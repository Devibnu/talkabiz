import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../app/router/route_names.dart';
import '../../../../core/navigation/mobile_bottom_nav.dart';
import '../../domain/entities/inbox_conversation_item.dart';
import '../providers/inbox_provider.dart';

class InboxPage extends ConsumerStatefulWidget {
  const InboxPage({super.key});

  @override
  ConsumerState<InboxPage> createState() => _InboxPageState();
}

class _InboxPageState extends ConsumerState<InboxPage> {
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
    final inboxAsync = ref.watch(inboxProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Inbox')),
      bottomNavigationBar: const MobileBottomNav(),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 8),
            child: TextField(
              controller: _searchController,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: 'Cari percakapan',
                prefixIcon: const Icon(Icons.search_rounded),
                suffixIcon: _searchController.text.isEmpty
                    ? null
                    : IconButton(
                        onPressed: () {
                          _searchController.clear();
                          setState(() {});
                          ref.read(inboxSearchProvider.notifier).state = '';
                        },
                        icon: const Icon(Icons.close_rounded),
                      ),
              ),
              onChanged: (value) {
                setState(() {});
                ref.read(inboxSearchProvider.notifier).state = value;
              },
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async {
                ref.invalidate(inboxProvider);
                await ref.read(inboxProvider.future);
              },
              child: inboxAsync.when(
                data: (conversations) {
                  if (conversations.isEmpty) {
                    return const _InboxEmptyState();
                  }

                  return ListView.separated(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                    itemCount: conversations.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 12),
                    itemBuilder: (context, index) {
                      final item = conversations[index];
                      return _InboxConversationCard(
                        conversation: item,
                        onTap: () {
                          context.goNamed(
                            RouteNames.inboxDetail,
                            pathParameters: {
                              'conversationId': item.id.toString(),
                            },
                          );
                        },
                      );
                    },
                  );
                },
                error: (error, stackTrace) {
                  return ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    children: [
                      Padding(
                        padding: const EdgeInsets.all(24),
                        child: Text('Gagal memuat inbox: $error'),
                      ),
                    ],
                  );
                },
                loading: () => const Center(child: CircularProgressIndicator()),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _InboxConversationCard extends StatelessWidget {
  const _InboxConversationCard({
    required this.conversation,
    required this.onTap,
  });

  final InboxConversationItem conversation;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(24),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      conversation.contactName,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                  if (conversation.unreadCount > 0)
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 4,
                      ),
                      decoration: BoxDecoration(
                        color: const Color(0xFF25D366),
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Text(
                        conversation.unreadCount.toString(),
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                conversation.phone,
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 10),
              Text(
                conversation.lastMessage ?? '-',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  _InboxChip(label: conversation.status),
                  if (conversation.assignedToMe) ...[
                    const SizedBox(width: 8),
                    const _InboxChip(label: 'Saya'),
                  ],
                  const Spacer(),
                  Text(_formatDate(conversation.lastMessageAt)),
                ],
              ),
            ],
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

class _InboxChip extends StatelessWidget {
  const _InboxChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(label),
    );
  }
}

class _InboxEmptyState extends StatelessWidget {
  const _InboxEmptyState();

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            children: [
              const SizedBox(height: 80),
              Icon(
                Icons.chat_bubble_outline_rounded,
                size: 56,
                color: Colors.grey.shade500,
              ),
              const SizedBox(height: 16),
              Text(
                'Belum ada percakapan',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              Text(
                'Inbox mobile dari backend akan tampil di sini.',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ],
          ),
        ),
      ],
    );
  }
}
