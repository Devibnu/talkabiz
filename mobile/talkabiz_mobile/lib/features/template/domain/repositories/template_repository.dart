import '../entities/template_detail.dart';
import '../entities/template_item.dart';

abstract class TemplateRepository {
  Future<List<TemplateItem>> getTemplates({
    String? status,
    String? category,
    String? search,
  });

  Future<TemplateDetail> getTemplate(int id);

  Future<TemplateDetail> createTemplate({
    required String nama,
    required String kategori,
    required String konten,
    String bahasa = 'id',
  });

  Future<TemplateDetail> updateTemplate(
    int id, {
    String? nama,
    String? kategori,
    String? konten,
  });

  Future<void> deleteTemplate(int id);

  Future<TemplateDetail> submitTemplate(int id);

  Future<int> syncStatus();
}
