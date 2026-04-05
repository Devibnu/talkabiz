class LoginRequestModel {
  const LoginRequestModel({
    required this.email,
    required this.password,
    required this.deviceName,
  });

  final String email;
  final String password;
  final String deviceName;

  Map<String, dynamic> toJson() {
    return {
      'email': email,
      'password': password,
      'device_name': deviceName,
    };
  }
}
