import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/inbox_repository_impl.dart';
import '../../domain/entities/inbox_conversation_detail.dart';
import '../../domain/entities/inbox_conversation_item.dart';

final inboxSearchProvider = StateProvider<String>((ref) => '');

final inboxProvider = FutureProvider<List<InboxConversationItem>>((ref) async {
  final search = ref.watch(inboxSearchProvider);
  return ref.watch(inboxRepositoryProvider).getConversations(search: search);
});

final inboxConversationDetailProvider =
    FutureProvider.family<InboxConversationDetail, int>((
      ref,
      conversationId,
    ) async {
      return ref
          .watch(inboxRepositoryProvider)
          .getConversationDetail(conversationId);
    });
