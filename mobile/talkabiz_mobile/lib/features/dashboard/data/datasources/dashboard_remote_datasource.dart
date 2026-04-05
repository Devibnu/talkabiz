import 'package:dio/dio.dart';

import '../models/dashboard_summary_model.dart';

class DashboardRemoteDatasource {
  const DashboardRemoteDatasource(this._dio);

  final Dio _dio;

  Future<DashboardSummaryModel> getSummary() async {
    final response = await _dio.get<Map<String, dynamic>>('/mobile/dashboard');
    return DashboardSummaryModel.fromJson(response.data ?? {});
  }
}
