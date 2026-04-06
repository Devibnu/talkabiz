import 'package:dio/dio.dart';

import '../models/inbox_conversation_detail_model.dart';
import '../models/inbox_conversation_item_model.dart';

class MediaUploadResult {
  const MediaUploadResult({
    required this.url,
    required this.mediaType,
    required this.filename,
  });

  final String url;
  final String mediaType;
  final String filename;
}

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

  Future<void> sendMessage({
    required int conversationId,
    required String message,
    String? type,
    String? mediaUrl,
  }) async {
    await _dio.post<Map<String, dynamic>>(
      '/mobile/inbox/$conversationId/send',
      data: {
        'message': message,
        if (type != null) 'type': type,
        if (mediaUrl != null) 'media_url': mediaUrl,
      },
    );
  }

  Future<MediaUploadResult> uploadMedia(String filePath) async {
    final formData = FormData.fromMap({
      'file': await MultipartFile.fromFile(filePath),
    });

    final response = await _dio.post<Map<String, dynamic>>(
      '/mobile/inbox/upload-media',
      data: formData,
    );

    final data = response.data?['data'] as Map<String, dynamic>? ?? {};
    return MediaUploadResult(
      url: data['url'] as String? ?? '',
      mediaType: data['media_type'] as String? ?? 'dokumen',
      filename: data['filename'] as String? ?? '',
    );
  }
}
