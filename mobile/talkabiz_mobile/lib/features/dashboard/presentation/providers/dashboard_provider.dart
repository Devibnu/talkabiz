import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/dashboard_repository_impl.dart';
import '../../domain/entities/dashboard_summary.dart';

final dashboardProvider = FutureProvider<DashboardSummary>((ref) async {
  return ref.watch(dashboardRepositoryProvider).getSummary();
});
