class InboxMessageItem {
  const InboxMessageItem({
    required this.id,
    required this.direction,
    required this.type,
    required this.content,
    required this.timestamp,
    required this.status,
  });

  final int id;
  final String direction;
  final String type;
  final String content;
  final DateTime? timestamp;
  final String status;
}
