import '../../domain/entities/inbox_message_item.dart';

class InboxMessageItemModel extends InboxMessageItem {
  const InboxMessageItemModel({
    required super.id,
    required super.direction,
    required super.type,
    required super.content,
    required super.timestamp,
    required super.status,
  });

  factory InboxMessageItemModel.fromJson(Map<String, dynamic> json) {
    return InboxMessageItemModel(
      id: json['id'] as int? ?? 0,
      direction: json['direction'] as String? ?? 'outbound',
      type: json['type'] as String? ?? 'teks',
      content: json['content'] as String? ?? '',
      timestamp: DateTime.tryParse(json['timestamp'] as String? ?? ''),
      status: json['status'] as String? ?? 'unknown',
    );
  }
}
