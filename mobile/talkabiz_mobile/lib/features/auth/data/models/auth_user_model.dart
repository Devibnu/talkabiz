import '../../domain/entities/auth_user.dart';

class AuthUserModel extends AuthUser {
  const AuthUserModel({
    required super.id,
    required super.name,
    required super.email,
    required super.role,
    required super.klienId,
    required super.businessName,
    required super.phone,
    required super.onboardingComplete,
  });

  factory AuthUserModel.fromJson(Map<String, dynamic> json) {
    return AuthUserModel(
      id: json['id'] as int? ?? 0,
      name: json['name'] as String? ?? '',
      email: json['email'] as String? ?? '',
      role: json['role'] as String? ?? 'user',
      klienId: json['klien_id'] as int?,
      businessName: json['business_name'] as String?,
      phone: json['phone'] as String?,
      onboardingComplete: json['onboarding_complete'] as bool? ?? false,
    );
  }
}
