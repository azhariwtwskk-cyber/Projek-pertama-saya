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
      expiresAt: DateTime.now().add(const Duration(hours: 8)),
      deviceSessionId: const Uuid().v4(),
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
}
