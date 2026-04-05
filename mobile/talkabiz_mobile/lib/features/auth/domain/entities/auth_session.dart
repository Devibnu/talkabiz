import 'auth_user.dart';

class AuthSession {
  const AuthSession({
    required this.token,
    required this.tokenType,
    required this.user,
  });

  final String token;
  final String tokenType;
  final AuthUser user;
}
