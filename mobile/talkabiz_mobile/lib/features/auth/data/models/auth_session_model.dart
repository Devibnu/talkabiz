import '../../domain/entities/auth_session.dart';
import 'auth_user_model.dart';

class AuthSessionModel extends AuthSession {
  const AuthSessionModel({
    required super.token,
    required super.tokenType,
    required super.user,
  });

  factory AuthSessionModel.fromJson(Map<String, dynamic> json) {
    final data = json['data'] as Map<String, dynamic>? ?? {};

    return AuthSessionModel(
      token: data['token'] as String? ?? '',
      tokenType: data['token_type'] as String? ?? 'Bearer',
      user: AuthUserModel.fromJson(data['user'] as Map<String, dynamic>? ?? {}),
    );
  }
}
