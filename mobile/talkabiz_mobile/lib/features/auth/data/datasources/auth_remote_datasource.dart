import 'package:dio/dio.dart';

import '../models/auth_session_model.dart';
import '../models/auth_user_model.dart';
import '../models/login_request_model.dart';

class AuthRemoteDatasource {
  const AuthRemoteDatasource(this._dio);

  final Dio _dio;

  Future<AuthSessionModel> login(LoginRequestModel request) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/mobile/auth/login',
      data: request.toJson(),
    );

    return AuthSessionModel.fromJson(response.data ?? {});
  }

  Future<AuthUserModel> me() async {
    final response = await _dio.get<Map<String, dynamic>>('/mobile/auth/me');
    final data = response.data?['data'] as Map<String, dynamic>? ?? {};

    return AuthUserModel.fromJson(data);
  }

  Future<void> logout() async {
    await _dio.post('/mobile/auth/logout');
  }
}
