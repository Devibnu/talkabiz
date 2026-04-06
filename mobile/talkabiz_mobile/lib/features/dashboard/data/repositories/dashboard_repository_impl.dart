import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../../../core/preview/app_preview.dart';
import '../../domain/entities/dashboard_summary.dart';
import '../../domain/repositories/dashboard_repository.dart';
import '../datasources/dashboard_remote_datasource.dart';

final dashboardRemoteDatasourceProvider = Provider<DashboardRemoteDatasource>((
  ref,
) {
  return DashboardRemoteDatasource(ref.watch(dioProvider));
});

final dashboardRepositoryProvider = Provider<DashboardRepository>((ref) {
  if (kUsePreviewData) {
    return const PreviewDashboardRepository();
  }

  return DashboardRepositoryImpl(ref.watch(dashboardRemoteDatasourceProvider));
});

class DashboardRepositoryImpl implements DashboardRepository {
  const DashboardRepositoryImpl(this._remoteDatasource);

  final DashboardRemoteDatasource _remoteDatasource;

  @override
  Future<DashboardSummary> getSummary() {
    return _remoteDatasource.getSummary();
  }
}
