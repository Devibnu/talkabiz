import 'inbox_message_item.dart';

class InboxConversationDetail {
  const InboxConversationDetail({
    required this.id,
    required this.contactName,
    required this.phone,
    required this.status,
    required this.priority,
    required this.messages,
  });

  final int id;
  final String contactName;
  final String phone;
  final String status;
  final String priority;
  final List<InboxMessageItem> messages;
}
