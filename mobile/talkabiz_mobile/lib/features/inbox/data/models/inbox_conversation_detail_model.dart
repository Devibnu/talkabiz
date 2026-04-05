import '../../domain/entities/inbox_conversation_detail.dart';
import 'inbox_message_item_model.dart';

class InboxConversationDetailModel extends InboxConversationDetail {
  const InboxConversationDetailModel({
    required super.id,
    required super.contactName,
    required super.phone,
    required super.status,
    required super.priority,
    required super.messages,
  });

  factory InboxConversationDetailModel.fromJson(Map<String, dynamic> json) {
    final data = json['data'] as Map<String, dynamic>? ?? {};
    final conversation = data['conversation'] as Map<String, dynamic>? ?? {};
    final messages = data['messages'] as List<dynamic>? ?? [];

    return InboxConversationDetailModel(
      id: conversation['id'] as int? ?? 0,
      contactName: conversation['contact_name'] as String? ?? '-',
      phone: conversation['phone'] as String? ?? '-',
      status: conversation['status'] as String? ?? 'unknown',
      priority: conversation['priority'] as String? ?? 'normal',
      messages: messages
          .map(
            (item) =>
                InboxMessageItemModel.fromJson(item as Map<String, dynamic>),
          )
          .toList(),
    );
  }
}
