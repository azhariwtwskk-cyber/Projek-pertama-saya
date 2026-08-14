import '../domain/staff_user.dart';

class LoginResult {
  const LoginResult({
    required this.user,
    required this.accessToken,
    required this.refreshToken,
    required this.accessExpiresAt,
    required this.refreshExpiresAt,
  });

  final StaffUser user;
  final String accessToken;
  final String refreshToken;
  final DateTime accessExpiresAt;
  final DateTime refreshExpiresAt;
}

/// Result of exchanging a still-valid refresh token for a new session
/// (`POST /cpms/api/v1/auth/refresh.php`). The backend rotates both
/// tokens on every call — the refresh token used to make this call is
/// invalidated the moment it succeeds, so callers must persist
/// [refreshToken] immediately, not just [accessToken].
class RefreshResult {
  const RefreshResult({
    required this.accessToken,
    required this.refreshToken,
    required this.accessExpiresAt,
    required this.refreshExpiresAt,
  });

  final String accessToken;
  final String refreshToken;
  final DateTime accessExpiresAt;
  final DateTime refreshExpiresAt;
}

/// Thrown by [AuthRepository.refresh] when the refresh token is missing,
/// expired, revoked, or already rotated (reused) — the caller's only
/// correct response is to force a full logout, never retry.
class RefreshTokenInvalid implements Exception {
  const RefreshTokenInvalid([this.message = 'Refresh token is invalid or expired.']);
  final String message;
}

/// Contract the rest of the app codes against. [MockAuthRepository] backs
/// local/demo runs; [ApiAuthRepository] is the real
/// `POST /cpms/api/v1/auth/login.php` implementation — swap via
/// `AppConfig.useMockApi` in `auth_providers.dart`, nothing else changes.
abstract class AuthRepository {
  Future<LoginResult> login({required String usernameOrEmail, required String password});
  Future<void> logout();
  Future<StaffUser> fetchProfile();

  /// Exchanges [refreshToken] for a new access+refresh token pair.
  /// Throws [RefreshTokenInvalid] if the token can't be used — never
  /// returns null, so callers can't accidentally treat a failure as
  /// success.
  Future<RefreshResult> refresh({required String refreshToken});
}
