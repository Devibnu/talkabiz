import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/template_repository_impl.dart';
import '../../domain/entities/template_detail.dart';
import '../../domain/entities/template_item.dart';

// Filter providers
final templateStatusFilterProvider = StateProvider<String?>((ref) => null);
final templateCategoryFilterProvider = StateProvider<String?>((ref) => null);
final templateSearchProvider = StateProvider<String>((ref) => '');

// List provider
final templatesProvider = FutureProvider<List<TemplateItem>>((ref) async {
  final status = ref.watch(templateStatusFilterProvider);
  final category = ref.watch(templateCategoryFilterProvider);
  final search = ref.watch(templateSearchProvider);

  return ref.watch(templateRepositoryProvider).getTemplates(
        status: status,
        category: category,
        search: search,
      );
});

// Detail provider
final templateDetailProvider =
    FutureProvider.family<TemplateDetail, int>((ref, id) async {
  return ref.watch(templateRepositoryProvider).getTemplate(id);
});

// Action controller
final templateActionProvider =
    StateNotifierProvider<TemplateActionController, AsyncValue<void>>((ref) {
  return TemplateActionController(ref);
});

class TemplateActionController extends StateNotifier<AsyncValue<void>> {
  TemplateActionController(this._ref) : super(const AsyncData(null));

  final Ref _ref;

  Future<TemplateDetail?> create({
    required String nama,
    required String kategori,
    required String konten,
  }) async {
    state = const AsyncLoading();
    try {
      final result =
          await _ref.read(templateRepositoryProvider).createTemplate(
                nama: nama,
                kategori: kategori,
                konten: konten,
              );
      state = const AsyncData(null);
      _ref.invalidate(templatesProvider);
      return result;
    } catch (e, st) {
      state = AsyncError(e, st);
      return null;
    }
  }

  Future<TemplateDetail?> update(
    int id, {
    String? nama,
    String? kategori,
    String? konten,
  }) async {
    state = const AsyncLoading();
    try {
      final result =
          await _ref.read(templateRepositoryProvider).updateTemplate(
                id,
                nama: nama,
                kategori: kategori,
                konten: konten,
              );
      state = const AsyncData(null);
      _ref.invalidate(templatesProvider);
      _ref.invalidate(templateDetailProvider(id));
      return result;
    } catch (e, st) {
      state = AsyncError(e, st);
      return null;
    }
  }

  Future<bool> delete(int id) async {
    state = const AsyncLoading();
    try {
      await _ref.read(templateRepositoryProvider).deleteTemplate(id);
      state = const AsyncData(null);
      _ref.invalidate(templatesProvider);
      return true;
    } catch (e, st) {
      state = AsyncError(e, st);
      return false;
    }
  }

  Future<TemplateDetail?> submit(int id) async {
    state = const AsyncLoading();
    try {
      final result =
          await _ref.read(templateRepositoryProvider).submitTemplate(id);
      state = const AsyncData(null);
      _ref.invalidate(templatesProvider);
      _ref.invalidate(templateDetailProvider(id));
      return result;
    } catch (e, st) {
      state = AsyncError(e, st);
      return null;
    }
  }

  Future<int> syncStatus() async {
    state = const AsyncLoading();
    try {
      final synced =
          await _ref.read(templateRepositoryProvider).syncStatus();
      state = const AsyncData(null);
      _ref.invalidate(templatesProvider);
      return synced;
    } catch (e, st) {
      state = AsyncError(e, st);
      return 0;
    }
  }
}
