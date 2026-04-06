class TemplateDetail {
  const TemplateDetail({
    required this.id,
    required this.name,
    required this.displayName,
    required this.category,
    required this.language,
    required this.status,
    required this.body,
    required this.isUsable,
    required this.canEdit,
    required this.canSubmit,
    required this.sentCount,
    required this.readCount,
    required this.usedCount,
    required this.exampleVariables,
    this.header,
    this.headerType,
    this.footer,
    this.buttons,
    this.rejectionReason,
    this.submittedAt,
    this.approvedAt,
    this.createdAt,
    this.updatedAt,
  });

  final int id;
  final String name;
  final String displayName;
  final String category;
  final String language;
  final String status;
  final String? header;
  final String? headerType;
  final String body;
  final String? footer;
  final List<dynamic>? buttons;
  final Map<String, dynamic> exampleVariables;
  final String? rejectionReason;
  final bool isUsable;
  final bool canEdit;
  final bool canSubmit;
  final int sentCount;
  final int readCount;
  final int usedCount;
  final DateTime? submittedAt;
  final DateTime? approvedAt;
  final DateTime? createdAt;
  final DateTime? updatedAt;
}
