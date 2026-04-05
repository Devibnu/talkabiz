import '../../domain/entities/inbox_conversation_item.dart';

class InboxConversationItemModel extends InboxConversationItem {
  const InboxConversationItemModel({
    required super.id,
    required super.contactName,
    required super.phone,
    required super.lastMessage,
    required super.lastMessageAt,
    required super.unreadCount,
    required super.status,
    required super.assignedToMe,
  });

  factory InboxConversationItemModel.fromJson(Map<String, dynamic> json) {
    return InboxConversationItemModel(
      id: json['id'] as int? ?? 0,
      contactName: json['contact_name'] as String? ?? '-',
      phone: json['phone'] as String? ?? '-',
      lastMessage: json['last_message'] as String?,
      lastMessageAt: DateTime.tryParse(
        json['last_message_at'] as String? ?? '',
      ),
      unreadCount: json['unread_count'] as int? ?? 0,
      status: json['status'] as String? ?? 'unknown',
      assignedToMe: json['assigned_to_me'] as bool? ?? false,
    );
  }
}
