import 'package:dio/dio.dart';

import '../models/contact_item_model.dart';

class ContactsRemoteDatasource {
  const ContactsRemoteDatasource(this._dio);

  final Dio _dio;

  Future<List<ContactItemModel>> getContacts({String? search}) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/contacts',
      queryParameters: {
        if (search != null && search.trim().isNotEmpty) 'search': search.trim(),
      },
    );

    final items = response.data?['data'] as List<dynamic>? ?? [];

    return items
        .map((item) => ContactItemModel.fromJson(item as Map<String, dynamic>))
        .toList();
  }
}
