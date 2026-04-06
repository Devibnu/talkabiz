class TransactionItem {
  const TransactionItem({
    required this.id,
    required this.type,
    required this.subtype,
    required this.code,
    required this.amount,
    required this.formattedAmount,
    required this.status,
    required this.description,
    required this.createdAt,
  });

  final String id;
  final String type; // 'saldo' or 'plan'
  final String subtype; // 'topup', 'potong', 'purchase', etc.
  final String code;
  final int amount;
  final String formattedAmount;
  final String status; // 'success', 'pending', 'failed', 'expired'
  final String description;
  final String? createdAt;
}
