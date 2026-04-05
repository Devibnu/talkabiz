import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../datasources/auth_remote_datasource.dart';
import '../../domain/entities/auth_session.dart';
import '../../domain/entities/auth_user.dart';
import '../../domain/repositories/auth_repository.dart';
import '../models/login_request_model.dart';

final authRemoteDatasourceProvider = Provider<AuthRemoteDatasource>((ref) {
  return AuthRemoteDatasource(ref.watch(dioProvider));
});

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepositoryImpl(ref.watch(authRemoteDatasourceProvider));
});

class AuthRepositoryImpl implements AuthRepository {
  const AuthRepositoryImpl(this._remoteDatasource);

  final AuthRemoteDatasource _remoteDatasource;

  @override
  Future<AuthSession> login({
    required String email,
    required String password,
    required String deviceName,
  }) {
    return _remoteDatasource.login(
      LoginRequestModel(
        email: email,
        password: password,
        deviceName: deviceName,
      ),
    );
  }

  @override
  Future<AuthUser> me() {
    return _remoteDatasource.me();
  }

  @override
  Future<void> logout() {
    return _remoteDatasource.logout();
  }
}
