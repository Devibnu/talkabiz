import 'package:dio/dio.dart';

import '../models/inbox_conversation_detail_model.dart';
import '../models/inbox_conversation_item_model.dart';

class InboxRemoteDatasource {
  const InboxRemoteDatasource(this._dio);

  final Dio _dio;

  Future<List<InboxConversationItemModel>> getConversations({
    String? search,
  }) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/inbox',
      queryParameters: {
        if (search != null && search.trim().isNotEmpty) 'search': search.trim(),
      },
    );

    final items = response.data?['data'] as List<dynamic>? ?? [];

    return items
        .map(
          (item) =>
              InboxConversationItemModel.fromJson(item as Map<String, dynamic>),
        )
        .toList();
  }

  Future<InboxConversationDetailModel> getConversationDetail(
    int conversationId,
  ) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/inbox/$conversationId',
    );
    return InboxConversationDetailModel.fromJson(response.data ?? {});
  }
}
