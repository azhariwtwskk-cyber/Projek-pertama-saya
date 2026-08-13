import 'package:uuid/uuid.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../domain/staff_user.dart';
import 'auth_repository.dart';

/// Real implementation of [AuthRepository] against the CPMSPro REST API
/// (section 32). Activated once `AppConfig.useMockApi` is false.
class ApiAuthRepository implements AuthRepository {
  ApiAuthRepository(this._client);

  final ApiClient _client;

  @override
  Future<LoginResult> login({required String usernameOrEmail, required String password}) {
    return _client.request(
      (dio) => dio.post(ApiEndpoints.login, data: {
        'username': usernameOrEmail,
        'password': password,
        'device_session_id': const Uuid().v4(),
      }),
      (data) {
        final json = data as Map<String, dynamic>;
        return LoginResult(
          user: StaffUser.fromJson(json),
          accessToken: json['access_token'] as String,
          refreshToken: json['refresh_token'] as String,
          expiresAt: DateTime.parse(json['expires_at'] as String),
          deviceSessionId: json['device_session_id'] as String,
        );
      },
    );
  }

  @override
  Future<void> logout() {
    return _client.request((dio) => dio.post(ApiEndpoints.logout), (_) {});
  }

  @override
  Future<StaffUser> fetchProfile() {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.staffProfile),
      (data) => StaffUser.fromJson(data as Map<String, dynamic>),
    );
  }
}
