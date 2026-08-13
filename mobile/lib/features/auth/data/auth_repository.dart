import '../domain/staff_user.dart';

class LoginResult {
  const LoginResult({
    required this.user,
    required this.accessToken,
    required this.refreshToken,
    required this.expiresAt,
    required this.deviceSessionId,
  });

  final StaffUser user;
  final String accessToken;
  final String refreshToken;
  final DateTime expiresAt;
  final String deviceSessionId;
}

/// Contract the rest of the app codes against. [MockAuthRepository] backs
/// local/demo runs; [ApiAuthRepository] is the real `POST
/// /api/v1/auth/login` implementation — swap via
/// `AppConfig.useMockApi` in `auth_providers.dart`, nothing else changes.
abstract class AuthRepository {
  Future<LoginResult> login({required String usernameOrEmail, required String password});
  Future<void> logout();
  Future<StaffUser> fetchProfile();
}
