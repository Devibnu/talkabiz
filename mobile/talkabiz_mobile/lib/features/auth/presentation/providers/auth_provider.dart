import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/storage/secure_storage_service.dart';
import '../../data/repositories/auth_repository_impl.dart';
import '../../domain/entities/auth_session.dart';

class AuthState {
  const AuthState({
    this.session,
    this.isLoading = false,
    this.errorMessage,
    this.isReady = false,
  });

  final AuthSession? session;
  final bool isLoading;
  final String? errorMessage;
  final bool isReady;

  bool get isAuthenticated => session != null;

  AuthState copyWith({
    AuthSession? session,
    bool? isLoading,
    String? errorMessage,
    bool clearError = false,
    bool? isReady,
  }) {
    return AuthState(
      session: session ?? this.session,
      isLoading: isLoading ?? this.isLoading,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      isReady: isReady ?? this.isReady,
    );
  }
}

final authControllerProvider = StateNotifierProvider<AuthController, AuthState>(
  (ref) {
    return AuthController(
      ref.watch(authRepositoryProvider),
      ref.watch(secureStorageServiceProvider),
    );
  },
);

class AuthController extends StateNotifier<AuthState> {
  AuthController(this._repository, this._storage) : super(const AuthState());

  final dynamic _repository;
  final SecureStorageService _storage;

  Future<void> restoreSession() async {
    state = state.copyWith(isLoading: true, clearError: true);

    try {
      final token = await _storage.readToken();
      if (token == null || token.isEmpty) {
        state = state.copyWith(isLoading: false, isReady: true);
        return;
      }

      final user = await _repository.me();
      state = AuthState(
        session: AuthSession(token: token, tokenType: 'Bearer', user: user),
        isLoading: false,
        isReady: true,
      );
    } catch (_) {
      await _storage.clearToken();
      state = const AuthState(isLoading: false, isReady: true);
    }
  }

  Future<bool> login({required String email, required String password}) async {
    state = state.copyWith(isLoading: true, clearError: true);

    try {
      final session = await _repository.login(
        email: email,
        password: password,
        deviceName: 'Flutter Android',
      );

      await _storage.saveToken(session.token);

      state = AuthState(session: session, isLoading: false, isReady: true);

      return true;
    } catch (error) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Login gagal. Periksa email dan password.',
        isReady: true,
      );
      return false;
    }
  }

  Future<void> logout() async {
    try {
      await _repository.logout();
    } catch (_) {
      // Ignore remote logout failure and clear local token anyway.
    }

    await _storage.clearToken();
    state = const AuthState(isReady: true);
  }
}
