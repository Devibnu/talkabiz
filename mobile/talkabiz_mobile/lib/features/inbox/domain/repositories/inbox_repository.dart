import '../entities/inbox_conversation_detail.dart';
import '../entities/inbox_conversation_item.dart';

abstract class InboxRepository {
  Future<List<InboxConversationItem>> getConversations({String? search});

  Future<InboxConversationDetail> getConversationDetail(int conversationId);

  Future<void> sendMessage({
    required int conversationId,
    required String message,
  });
}
