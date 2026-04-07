class CampaignItem {
  const CampaignItem({
    required this.id,
    required this.name,
    required this.description,
    required this.status,
    required this.templateName,
    required this.totalRecipients,
    required this.sentCount,
    required this.deliveredCount,
    required this.readCount,
    required this.failedCount,
    required this.estimatedCost,
    required this.formattedCost,
    required this.scheduledAt,
    required this.startedAt,
    required this.completedAt,
    required this.createdAt,
    required this.canStart,
    required this.canPause,
    required this.canCancel,
  });

  final int id;
  final String name;
  final String? description;
  final String status;
  final String templateName;
  final int totalRecipients;
  final int sentCount;
  final int deliveredCount;
  final int readCount;
  final int failedCount;
  final int estimatedCost;
  final String formattedCost;
  final String? scheduledAt;
  final String? startedAt;
  final String? completedAt;
  final String createdAt;
  final bool canStart;
  final bool canPause;
  final bool canCancel;
}

class CampaignDetail {
  const CampaignDetail({
    required this.campaign,
    required this.recipientStats,
    required this.audienceFilter,
    required this.templateVariables,
    required this.template,
  });

  final CampaignItem campaign;
  final RecipientStats recipientStats;
  final Map<String, dynamic>? audienceFilter;
  final Map<String, dynamic>? templateVariables;
  final CampaignTemplate? template;
}

class RecipientStats {
  const RecipientStats({
    required this.pending,
    required this.sent,
    required this.delivered,
    required this.read,
    required this.failed,
  });

  final int pending;
  final int sent;
  final int delivered;
  final int read;
  final int failed;
}

class CampaignTemplate {
  const CampaignTemplate({
    required this.id,
    required this.name,
    required this.language,
    required this.category,
  });

  final int id;
  final String name;
  final String language;
  final String category;
}

class CampaignStats {
  const CampaignStats({
    required this.total,
    required this.draft,
    required this.scheduled,
    required this.running,
    required this.completed,
    required this.cancelled,
  });

  final int total;
  final int draft;
  final int scheduled;
  final int running;
  final int completed;
  final int cancelled;
}

class CostEstimate {
  const CostEstimate({
    required this.totalRecipients,
    required this.costPerMessage,
    required this.totalCost,
    required this.formattedCost,
    required this.currentBalance,
    required this.sufficientBalance,
  });

  final int totalRecipients;
  final int costPerMessage;
  final int totalCost;
  final String formattedCost;
  final int currentBalance;
  final bool sufficientBalance;
}
