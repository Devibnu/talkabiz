import 'package:dio/dio.dart';

import '../models/template_detail_model.dart';
import '../models/template_item_model.dart';

class TemplateRemoteDatasource {
  const TemplateRemoteDatasource(this._dio);

  final Dio _dio;

  Future<List<TemplateItemModel>> getTemplates({
    String? status,
    String? category,
    String? search,
  }) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/templates',
      queryParameters: {
        if (status != null && status.isNotEmpty) 'status': status,
        if (category != null && category.isNotEmpty) 'category': category,
        if (search != null && search.trim().isNotEmpty) 'search': search.trim(),
      },
    );

    final items = response.data?['data'] as List<dynamic>? ?? [];
    return items
        .map((e) => TemplateItemModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<TemplateDetailModel> getTemplate(int id) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/mobile/templates/$id',
    );

    final data = response.data?['data'] as Map<String, dynamic>? ?? {};
    return TemplateDetailModel.fromJson(data);
  }

  Future<TemplateDetailModel> createTemplate({
    required String nama,
    required String kategori,
    required String konten,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/mobile/templates',
      data: {
        'nama': nama,
        'kategori': kategori,
        'konten': konten,
      },
    );

    final data = response.data?['data'] as Map<String, dynamic>? ?? {};
    return TemplateDetailModel.fromJson(data);
  }

  Future<TemplateDetailModel> updateTemplate(
    int id, {
    String? nama,
    String? kategori,
    String? konten,
  }) async {
    final response = await _dio.put<Map<String, dynamic>>(
      '/mobile/templates/$id',
      data: {
        if (nama != null) 'nama': nama,
        if (kategori != null) 'kategori': kategori,
        if (konten != null) 'konten': konten,
      },
    );

    final data = response.data?['data'] as Map<String, dynamic>? ?? {};
    return TemplateDetailModel.fromJson(data);
  }

  Future<void> deleteTemplate(int id) async {
    await _dio.delete('/mobile/templates/$id');
  }

  Future<TemplateDetailModel> submitTemplate(int id) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/mobile/templates/$id/submit',
    );

    final data = response.data?['data'] as Map<String, dynamic>? ?? {};
    return TemplateDetailModel.fromJson(data);
  }

  Future<int> syncStatus() async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/mobile/templates/sync-status',
    );

    final data = response.data?['data'] as Map<String, dynamic>? ?? {};
    return data['synced'] as int? ?? 0;
  }
}
