import '../../domain/entities/template_detail.dart';

class TemplateDetailModel extends TemplateDetail {
  const TemplateDetailModel({
    required super.id,
    required super.name,
    required super.displayName,
    required super.category,
    required super.language,
    required super.status,
    required super.body,
    required super.isUsable,
    required super.canEdit,
    required super.canSubmit,
    required super.sentCount,
    required super.readCount,
    required super.usedCount,
    required super.exampleVariables,
    super.header,
    super.headerType,
    super.footer,
    super.buttons,
    super.rejectionReason,
    super.submittedAt,
    super.approvedAt,
    super.createdAt,
    super.updatedAt,
  });

  factory TemplateDetailModel.fromJson(Map<String, dynamic> json) {
    return TemplateDetailModel(
      id: json['id'] as int? ?? 0,
      name: json['name'] as String? ?? '',
      displayName: json['display_name'] as String? ?? '',
      category: json['category'] as String? ?? '',
      language: json['language'] as String? ?? 'id',
      status: json['status'] as String? ?? 'draft',
      header: json['header'] as String?,
      headerType: json['header_type'] as String? ?? 'none',
      body: json['body'] as String? ?? '',
      footer: json['footer'] as String?,
      buttons: json['buttons'] as List<dynamic>?,
      exampleVariables:
          (json['example_variables'] as Map<String, dynamic>?) ?? {},
      rejectionReason: json['rejection_reason'] as String?,
      isUsable: json['is_usable'] as bool? ?? false,
      canEdit: json['can_edit'] as bool? ?? false,
      canSubmit: json['can_submit'] as bool? ?? false,
      sentCount: json['sent_count'] as int? ?? 0,
      readCount: json['read_count'] as int? ?? 0,
      usedCount: json['used_count'] as int? ?? 0,
      submittedAt: DateTime.tryParse(json['submitted_at'] as String? ?? ''),
      approvedAt: DateTime.tryParse(json['approved_at'] as String? ?? ''),
      createdAt: DateTime.tryParse(json['created_at'] as String? ?? ''),
      updatedAt: DateTime.tryParse(json['updated_at'] as String? ?? ''),
    );
  }
}
