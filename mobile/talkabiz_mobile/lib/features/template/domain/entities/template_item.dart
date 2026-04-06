class TemplateItem {
  const TemplateItem({
    required this.id,
    required this.name,
    required this.displayName,
    required this.category,
    required this.language,
    required this.status,
    required this.bodyPreview,
    required this.isUsable,
    required this.sentCount,
    required this.readCount,
    this.submittedAt,
    this.approvedAt,
    this.createdAt,
  });

  final int id;
  final String name;
  final String displayName;
  final String category;
  final String language;
  final String status;
  final String bodyPreview;
  final bool isUsable;
  final int sentCount;
  final int readCount;
  final DateTime? submittedAt;
  final DateTime? approvedAt;
  final DateTime? createdAt;
}
