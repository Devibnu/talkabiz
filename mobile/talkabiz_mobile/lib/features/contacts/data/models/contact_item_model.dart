import '../../domain/entities/contact_item.dart';

class ContactItemModel extends ContactItem {
  const ContactItemModel({
    required super.id,
    required super.name,
    required super.phone,
    required super.email,
    required super.tags,
    required super.lastInteractionAt,
  });

  factory ContactItemModel.fromJson(Map<String, dynamic> json) {
    return ContactItemModel(
      id: json['id'] as int? ?? 0,
      name: json['name'] as String? ?? '-',
      phone: json['phone'] as String? ?? '-',
      email: json['email'] as String?,
      tags: (json['tags'] as List<dynamic>? ?? [])
          .map((item) => item.toString())
          .toList(),
      lastInteractionAt: DateTime.tryParse(
        json['last_interaction_at'] as String? ?? '',
      ),
    );
  }
}
