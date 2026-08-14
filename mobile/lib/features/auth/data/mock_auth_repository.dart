import 'package:uuid/uuid.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/mock/mock_fixtures.dart';
import '../domain/staff_user.dart';
import 'auth_repository.dart';

class MockAuthRepository implements AuthRepository {
  @override
  Future<LoginResult> login({required String usernameOrEmail, required String password}) async {
    await Future.delayed(const Duration(milliseconds: 900));
    if (usernameOrEmail.trim().isEmpty || password.isEmpty) {
      throw const ApiException(ApiFailureType.validation, 'Please enter your username/email and password.');
    }
    if (password.length < 4) {
      throw const ApiException(ApiFailureType.forbidden, 'Incorrect username or password.');
    }
    return LoginResult(
      user: MockFixtures.instance.staffUser,
      accessToken: 'mock_access_${const Uuid().v4()}',
      refreshToken: 'mock_refresh_${const Uuid().v4()}',
      accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
      refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
    );
  }

  @override
  Future<void> logout() async {
    await Future.delayed(const Duration(milliseconds: 300));
  }

  @override
  Future<StaffUser> fetchProfile() async {
    await Future.delayed(const Duration(milliseconds: 300));
    return MockFixtures.instance.staffUser;
  }

  @override
  Future<RefreshResult> refresh({required String refreshToken}) async {
    await Future.delayed(const Duration(milliseconds: 400));
    // Mirrors the real backend's rotation-invalidates-the-old-token
    // behaviour closely enough to exercise the same Flutter-side flow in
    // mock/demo mode: any token that doesn't look like one this mock
    // itself issued is rejected, exactly like an unknown/already-rotated
    // refresh token is on the real server.
    if (!refreshToken.startsWith('mock_refresh_')) {
      throw const RefreshTokenInvalid('Refresh token is invalid or expired.');
    }
    return RefreshResult(
      accessToken: 'mock_access_${const Uuid().v4()}',
      refreshToken: 'mock_refresh_${const Uuid().v4()}',
      accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
      refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
    );
  }
}
