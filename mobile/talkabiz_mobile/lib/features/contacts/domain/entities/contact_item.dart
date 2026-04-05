class ContactItem {
  const ContactItem({
    required this.id,
    required this.name,
    required this.phone,
    required this.email,
    required this.tags,
    required this.lastInteractionAt,
  });

  final int id;
  final String name;
  final String phone;
  final String? email;
  final List<String> tags;
  final DateTime? lastInteractionAt;
}
