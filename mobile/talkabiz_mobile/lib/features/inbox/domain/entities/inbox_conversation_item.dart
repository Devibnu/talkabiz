class InboxConversationItem {
  const InboxConversationItem({
    required this.id,
    required this.contactName,
    required this.phone,
    required this.lastMessage,
    required this.lastMessageAt,
    required this.unreadCount,
    required this.status,
    required this.assignedToMe,
  });

  final int id;
  final String contactName;
  final String phone;
  final String? lastMessage;
  final DateTime? lastMessageAt;
  final int unreadCount;
  final String status;
  final bool assignedToMe;
}
