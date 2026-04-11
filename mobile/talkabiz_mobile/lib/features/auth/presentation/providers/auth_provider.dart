import 'dart:developer' as developer;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

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
      final token = await _storage.readToken()
          .timeout(const Duration(seconds: 5), onTimeout: () => null);
      if (token == null || token.isEmpty) {
        state = state.copyWith(isLoading: false, isReady: true);
        return;
      }

      final user = await _repository.me()
          .timeout(const Duration(seconds: 8));
      state = AuthState(
        session: AuthSession(token: token, tokenType: 'Bearer', user: user),
        isLoading: false,
        isReady: true,
      );
    } catch (e) {
      developer.log('restoreSession failed: $e', name: 'Auth');
      await _storage.clearToken();
      state = const AuthState(isLoading: false, isReady: true);
    }
  }

  Future<bool> login({required String email, required String password}) async {
    state = state.copyWith(isLoading: true, clearError: true);

    try {
      developer.log('Login attempt: $email', name: 'Auth');
      final session = await _repository.login(
        email: email,
        password: password,
        deviceName: 'Flutter iOS',
      );
      developer.log('Login success, token: ${session.token.substring(0, 10)}...', name: 'Auth');

      await _storage.saveToken(session.token);

      state = AuthState(session: session, isLoading: false, isReady: true);

      return true;
    } catch (error) {
      developer.log('Login error: $error', name: 'Auth');
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Login gagal. Periksa email dan password.',
        isReady: true,
      );
      return false;
    }
  }

  /// Opens Google OAuth in external browser.
  /// The callback is handled via deep link in the app.
  Future<void> startGoogleLogin() async {
    state = state.copyWith(isLoading: true, clearError: true);

    try {
      const baseUrl = 'https://talkabiz.ibnuapps.cloud';
      final url = Uri.parse(
        '$baseUrl/mobile/auth/google?device_name=Flutter+iOS',
      );

      final launched = await launchUrl(url, mode: LaunchMode.externalApplication);
      if (!launched) {
        state = state.copyWith(
          isLoading: false,
          errorMessage: 'Tidak bisa membuka browser.',
          isReady: true,
        );
      }
      // isLoading stays true — will be resolved when deep link arrives
    } catch (error) {
      developer.log('Google login launch error: $error', name: 'GoogleLogin');
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Login Google gagal. Coba lagi.',
        isReady: true,
      );
    }
  }

  /// Called when the app receives the deep link callback from Google OAuth.
  Future<bool> handleGoogleCallback(String token) async {
    try {
      await _storage.saveToken(token);
      final user = await _repository.me();
      state = AuthState(
        session: AuthSession(token: token, tokenType: 'Bearer', user: user),
        isLoading: false,
        isReady: true,
      );
      return true;
    } catch (error) {
      developer.log('Google callback error: $error', name: 'GoogleLogin');
      await _storage.clearToken();
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Login Google gagal. Coba lagi.',
        isReady: true,
      );
      return false;
    }
  }

  /// Reset loading state (e.g., when user returns without completing login)
  void cancelGoogleLogin() {
    if (state.isLoading) {
      state = state.copyWith(isLoading: false, isReady: true);
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
