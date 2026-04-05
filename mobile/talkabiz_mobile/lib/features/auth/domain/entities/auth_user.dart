class AuthUser {
  const AuthUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.klienId,
    required this.businessName,
    required this.phone,
    required this.onboardingComplete,
  });

  final int id;
  final String name;
  final String email;
  final String role;
  final int? klienId;
  final String? businessName;
  final String? phone;
  final bool onboardingComplete;
}
