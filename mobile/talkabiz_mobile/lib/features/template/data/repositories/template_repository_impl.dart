import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../../../core/preview/app_preview.dart';
import '../../domain/entities/template_detail.dart';
import '../../domain/entities/template_item.dart';
import '../../domain/repositories/template_repository.dart';
import '../datasources/template_remote_datasource.dart';

final templateRemoteDatasourceProvider =
    Provider<TemplateRemoteDatasource>((ref) {
  return TemplateRemoteDatasource(ref.watch(dioProvider));
});

final templateRepositoryProvider = Provider<TemplateRepository>((ref) {
  if (kUsePreviewData) {
    return const PreviewTemplateRepository();
  }
  return TemplateRepositoryImpl(ref.watch(templateRemoteDatasourceProvider));
});

class TemplateRepositoryImpl implements TemplateRepository {
  const TemplateRepositoryImpl(this._datasource);

  final TemplateRemoteDatasource _datasource;

  @override
  Future<List<TemplateItem>> getTemplates({
    String? status,
    String? category,
    String? search,
  }) =>
      _datasource.getTemplates(
          status: status, category: category, search: search);

  @override
  Future<TemplateDetail> getTemplate(int id) => _datasource.getTemplate(id);

  @override
  Future<TemplateDetail> createTemplate({
    required String nama,
    required String kategori,
    required String konten,
  }) =>
      _datasource.createTemplate(
        nama: nama,
        kategori: kategori,
        konten: konten,
      );

  @override
  Future<TemplateDetail> updateTemplate(
    int id, {
    String? nama,
    String? kategori,
    String? konten,
  }) =>
      _datasource.updateTemplate(
        id,
        nama: nama,
        kategori: kategori,
        konten: konten,
      );

  @override
  Future<void> deleteTemplate(int id) => _datasource.deleteTemplate(id);

  @override
  Future<TemplateDetail> submitTemplate(int id) =>
      _datasource.submitTemplate(id);

  @override
  Future<int> syncStatus() => _datasource.syncStatus();
}

class PreviewTemplateRepository implements TemplateRepository {
  const PreviewTemplateRepository();

  @override
  Future<List<TemplateItem>> getTemplates({
    String? status,
    String? category,
    String? search,
  }) async =>
      const [
        TemplateItem(
          id: 1,
          name: 'promo_diskon',
          displayName: 'Promo Diskon',
          category: 'marketing',
          language: 'id',
          status: 'disetujui',
          bodyPreview: 'Halo {{1}}, dapatkan diskon {{2}}% untuk...',
          isUsable: true,
          sentCount: 150,
          readCount: 120,
        ),
        TemplateItem(
          id: 2,
          name: 'notifikasi_pesanan',
          displayName: 'Notifikasi Pesanan',
          category: 'utility',
          language: 'id',
          status: 'diajukan',
          bodyPreview: 'Pesanan {{1}} Anda sedang diproses...',
          isUsable: false,
          sentCount: 0,
          readCount: 0,
        ),
      ];

  @override
  Future<TemplateDetail> getTemplate(int id) async => const TemplateDetail(
        id: 1,
        name: 'promo_diskon',
        displayName: 'Promo Diskon',
        category: 'marketing',
        language: 'id',
        status: 'disetujui',
        body: 'Halo {{1}}, dapatkan diskon {{2}}% untuk produk pilihan kami!',
        isUsable: true,
        canEdit: false,
        canSubmit: false,
        sentCount: 150,
        readCount: 120,
        usedCount: 5,
        exampleVariables: {'1': 'Budi', '2': '20'},
      );

  @override
  Future<TemplateDetail> createTemplate({
    required String nama,
    required String kategori,
    required String konten,
  }) async =>
      TemplateDetail(
        id: 99,
        name: nama,
        displayName: nama,
        category: kategori,
        language: 'id',
        status: 'draft',
        body: konten,
        isUsable: false,
        canEdit: true,
        canSubmit: true,
        sentCount: 0,
        readCount: 0,
        usedCount: 0,
        exampleVariables: const {},
      );

  @override
  Future<TemplateDetail> updateTemplate(
    int id, {
    String? nama,
    String? kategori,
    String? konten,
  }) async =>
      getTemplate(id);

  @override
  Future<void> deleteTemplate(int id) async {}

  @override
  Future<TemplateDetail> submitTemplate(int id) async => getTemplate(id);

  @override
  Future<int> syncStatus() async => 0;
}
