import 'dart:io' show Platform;

import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/api/api_exception.dart';
import '../domain/staff_user.dart';
import 'auth_repository.dart';

/// Real implementation of [AuthRepository] against the CPMSPro REST API.
/// Activated once `AppConfig.useMockApi` is false.
///
/// Stage 1 (see mobile/docs/BACKEND_INTEGRATION_AUDIT.md): request/response
/// shapes below match the real `cpms/api/v1/auth/*.php` and `me.php`
/// endpoints, verified against a live instance during implementation —
/// not the originally-assumed contract this file used to encode.
class ApiAuthRepository implements AuthRepository {
  ApiAuthRepository(this._client);

  final ApiClient _client;

  String get _platform {
    if (Platform.isIOS) return 'ios';
    if (Platform.isAndroid) return 'android';
    return 'other';
  }

  DateTime _expiryFromSeconds(dynamic seconds) {
    final value =
        seconds is int ? seconds : int.tryParse(seconds?.toString() ?? '') ?? 0;
    return DateTime.now().add(Duration(seconds: value));
  }

  @override
  Future<LoginResult> login(
      {required String usernameOrEmail, required String password}) {
    return _client.request(
      (dio) => dio.post(ApiEndpoints.login, data: {
        'username': usernameOrEmail,
        'password': password,
        'device': {'platform': _platform},
      }),
      (data) {
        final json = data as Map<String, dynamic>;
        return LoginResult(
          user: StaffUser.fromJson(json),
          accessToken: json['access_token'] as String,
          refreshToken: json['refresh_token'] as String,
          accessExpiresAt: _expiryFromSeconds(json['expires_in']),
          refreshExpiresAt: _expiryFromSeconds(json['refresh_expires_in']),
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

  @override
  Future<RefreshResult> refresh({required String refreshToken}) async {
    try {
      return await _client.request(
        (dio) => dio.post(ApiEndpoints.refreshToken,
            data: {'refresh_token': refreshToken}),
        (data) {
          final json = data as Map<String, dynamic>;
          return RefreshResult(
            accessToken: json['access_token'] as String,
            refreshToken: json['refresh_token'] as String,
            accessExpiresAt: _expiryFromSeconds(json['expires_in']),
            refreshExpiresAt: _expiryFromSeconds(json['refresh_expires_in']),
          );
        },
      );
    } on ApiException catch (e) {
      // The refresh endpoint reports every failure mode (missing,
      // expired, revoked, already-rotated/reused token; access revoked)
      // as a 401/403 — all of them mean "this refresh token can't be
      // used again," never "try again."
      if (e.type == ApiFailureType.sessionExpired ||
          e.type == ApiFailureType.forbidden) {
        throw RefreshTokenInvalid(e.message);
      }
      rethrow;
    }
  }
}
