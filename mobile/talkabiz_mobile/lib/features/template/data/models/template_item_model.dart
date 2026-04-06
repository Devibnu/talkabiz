import '../../domain/entities/template_item.dart';

class TemplateItemModel extends TemplateItem {
  const TemplateItemModel({
    required super.id,
    required super.name,
    required super.displayName,
    required super.category,
    required super.language,
    required super.status,
    required super.bodyPreview,
    required super.isUsable,
    required super.sentCount,
    required super.readCount,
    super.submittedAt,
    super.approvedAt,
    super.createdAt,
  });

  factory TemplateItemModel.fromJson(Map<String, dynamic> json) {
    return TemplateItemModel(
      id: json['id'] as int? ?? 0,
      name: json['name'] as String? ?? '',
      displayName: json['display_name'] as String? ?? '',
      category: json['category'] as String? ?? '',
      language: json['language'] as String? ?? 'id',
      status: json['status'] as String? ?? 'draft',
      bodyPreview: json['body_preview'] as String? ?? '',
      isUsable: json['is_usable'] as bool? ?? false,
      sentCount: json['sent_count'] as int? ?? 0,
      readCount: json['read_count'] as int? ?? 0,
      submittedAt: DateTime.tryParse(json['submitted_at'] as String? ?? ''),
      approvedAt: DateTime.tryParse(json['approved_at'] as String? ?? ''),
      createdAt: DateTime.tryParse(json['created_at'] as String? ?? ''),
    );
  }
}
