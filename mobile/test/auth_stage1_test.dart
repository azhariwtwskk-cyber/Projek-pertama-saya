// Stage 1 (Authentication + Session + Property Context) tests.
// See mobile/docs/BACKEND_INTEGRATION_AUDIT.md for the real backend
// contract these are written against. The scenarios that need a real
// PHP+MySQL server (the actual 8-hour... now 1-hour access-token /
// 30-day refresh-token rotation, reuse rejection, and cross-property
// isolation enforced by cpms_api_tokens/user_roles) were additionally
// verified live against a local instance of backend/cpms/api/v1 during
// implementation — see the Stage 1 completion report for that transcript.
// This file covers everything that IS meaningfully testable in a plain
// `flutter test` run: the AuthController state machine, and ApiClient's
// HTTP-level 401/refresh/retry/envelope handling via a scripted fake
// Dio adapter (no real network, no external package needed).

import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart' show Color;
import 'package:flutter_test/flutter_test.dart';

import 'package:cpmspro_workforce/core/api/api_client.dart';
import 'package:cpmspro_workforce/core/api/api_endpoints.dart';
import 'package:cpmspro_workforce/core/api/api_exception.dart';
import 'package:cpmspro_workforce/core/config/app_branding.dart';
import 'package:cpmspro_workforce/core/storage/secure_storage_service.dart';
import 'package:cpmspro_workforce/features/auth/application/auth_providers.dart';
import 'package:cpmspro_workforce/features/auth/data/auth_repository.dart';
import 'package:cpmspro_workforce/features/auth/data/mock_auth_repository.dart';
import 'package:cpmspro_workforce/features/auth/domain/staff_user.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';

/// In-memory secure storage so tests never touch a platform channel.
class _InMemorySecureStorage extends FlutterSecureStoragePlatform {
  final Map<String, String> _values = {};

  @override
  Future<bool> containsKey({required String key, required Map<String, String> options}) async =>
      _values.containsKey(key);

  @override
  Future<void> delete({required String key, required Map<String, String> options}) async {
    _values.remove(key);
  }

  @override
  Future<void> deleteAll({required Map<String, String> options}) async => _values.clear();

  @override
  Future<String?> read({required String key, required Map<String, String> options}) async => _values[key];

  @override
  Future<Map<String, String>> readAll({required Map<String, String> options}) async => Map.of(_values);

  @override
  Future<void> write({required String key, required String value, required Map<String, String> options}) async {
    _values[key] = value;
  }
}

SecureStorageService _newStorage() {
  FlutterSecureStoragePlatform.instance = _InMemorySecureStorage();
  return SecureStorageService();
}

/// A controllable double for [AuthRepository] used by the AuthController
/// state-machine tests, so each test can dictate exactly what
/// login/fetchProfile/refresh should do without any I/O.
class _FakeAuthRepository implements AuthRepository {
  _FakeAuthRepository({
    this.loginResult,
    this.loginError,
    this.fetchProfileResult,
    this.fetchProfileError,
    this.refreshResult,
    this.refreshError,
  });

  LoginResult? loginResult;
  Object? loginError;
  StaffUser? fetchProfileResult;
  Object? fetchProfileError;
  RefreshResult? refreshResult;
  Object? refreshError;

  int loginCalls = 0;
  int logoutCalls = 0;
  int fetchProfileCalls = 0;
  int refreshCalls = 0;

  @override
  Future<LoginResult> login({required String usernameOrEmail, required String password}) async {
    loginCalls++;
    if (loginError != null) throw loginError!;
    return loginResult!;
  }

  @override
  Future<void> logout() async {
    logoutCalls++;
  }

  @override
  Future<StaffUser> fetchProfile() async {
    fetchProfileCalls++;
    if (fetchProfileError != null) throw fetchProfileError!;
    return fetchProfileResult!;
  }

  @override
  Future<RefreshResult> refresh({required String refreshToken}) async {
    refreshCalls++;
    if (refreshError != null) throw refreshError!;
    return refreshResult!;
  }
}

StaffUser _staffUser({String propertyId = '3'}) => StaffUser.fromJson({
      'user': {'id': 10, 'name': 'Ahmad Rozahari', 'role': 'staff', 'username': 'ahmad.rozahari'},
      'property': {
        'id': propertyId,
        'code': 'V23',
        'name': 'V23 Malawa Ria Apartment',
        'company_name': 'Malawa Property Management Sdn Bhd',
        'logo_url': '/cpms/uploads/branding/v23_logo.png',
        'primary_color': '#0B5FFF',
        'secondary_color': '#12B76A',
      },
    });

LoginResult _loginResult({String propertyId = '3'}) => LoginResult(
      user: _staffUser(propertyId: propertyId),
      accessToken: 'access_1',
      refreshToken: 'refresh_1',
      accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
      refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
    );

void main() {
  group('AuthController state machine', () {
    test('1. successful login moves to authenticated with the server-issued user', () async {
      final repo = _FakeAuthRepository(loginResult: _loginResult());
      final controller = AuthController(repo, _newStorage());
      await Future<void>.delayed(Duration.zero); // let _restoreSession's early "no token" branch finish

      final ok = await controller.login(usernameOrEmail: 'ahmad.rozahari', password: 'Passw0rd!123', rememberMe: false);

      expect(ok, isTrue);
      expect(controller.state.status, AuthStatus.authenticated);
      expect(controller.state.user?.propertyId, '3');
      expect(repo.loginCalls, 1);
    });

    test('2. invalid credentials moves to unauthenticated with an error message, not authenticated', () async {
      final repo = _FakeAuthRepository(
        loginError: const ApiException(ApiFailureType.forbidden, 'Incorrect username or password.'),
      );
      final controller = AuthController(repo, _newStorage());
      await Future<void>.delayed(Duration.zero);

      final ok = await controller.login(usernameOrEmail: 'ahmad.rozahari', password: 'wrong', rememberMe: false);

      expect(ok, isFalse);
      expect(controller.state.status, AuthStatus.unauthenticated);
      expect(controller.state.user, isNull);
      expect(controller.state.errorMessage, isNotNull);
    });

    test('6. session restore: a stored access token is exchanged for a profile on cold start', () async {
      final storage = _newStorage();
      await storage.saveSession(
        accessToken: 'stored_access',
        refreshToken: 'stored_refresh',
        accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
        refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
      );
      final repo = _FakeAuthRepository(fetchProfileResult: _staffUser());
      final controller = AuthController(repo, storage);

      // _restoreSession runs fire-and-forget from the constructor.
      await Future<void>.delayed(const Duration(milliseconds: 50));

      expect(controller.state.status, AuthStatus.authenticated);
      expect(controller.state.user, isNotNull);
      expect(repo.fetchProfileCalls, 1);
    });

    test('session restore with no stored token goes straight to unauthenticated, no network call', () async {
      final repo = _FakeAuthRepository();
      final controller = AuthController(repo, _newStorage());
      await Future<void>.delayed(const Duration(milliseconds: 20));

      expect(controller.state.status, AuthStatus.unauthenticated);
      expect(repo.fetchProfileCalls, 0);
    });

    test('6b. session restore with a stored token the server no longer accepts falls back to unauthenticated', () async {
      final storage = _newStorage();
      await storage.saveSession(
        accessToken: 'stale_access',
        refreshToken: 'stale_refresh',
        accessExpiresAt: DateTime.now().subtract(const Duration(hours: 1)),
        refreshExpiresAt: DateTime.now().subtract(const Duration(days: 1)),
      );
      final repo = _FakeAuthRepository(
        fetchProfileError: const ApiException(ApiFailureType.sessionExpired, 'Sesi telah tamat.'),
      );
      final controller = AuthController(repo, storage);

      await Future<void>.delayed(const Duration(milliseconds: 50));

      expect(controller.state.status, AuthStatus.unauthenticated);
      expect(controller.state.user, isNull);
      expect(await storage.accessToken, isNull, reason: 'a session the server rejects must not linger in storage');
    });

    test('9. refresh rotation: refreshAccessToken persists the NEW pair and returns the new access token', () async {
      final storage = _newStorage();
      await storage.saveSession(
        accessToken: 'old_access',
        refreshToken: 'old_refresh',
        accessExpiresAt: DateTime.now(),
        refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
      );
      final repo = _FakeAuthRepository(
        fetchProfileResult: _staffUser(),
        refreshResult: RefreshResult(
          accessToken: 'new_access',
          refreshToken: 'new_refresh',
          accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
          refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
        ),
      );
      final controller = AuthController(repo, storage);
      await Future<void>.delayed(const Duration(milliseconds: 20));

      final newToken = await controller.refreshAccessToken();

      expect(newToken, 'new_access');
      expect(await storage.accessToken, 'new_access');
      // The old refresh token must be gone from storage -- rotation, not
      // just "also remember a new one."
      expect(await storage.refreshToken, 'new_refresh');
      expect(repo.refreshCalls, 1);
    });

    test('9b. concurrent refresh calls only hit the repository once (single-flight)', () async {
      final storage = _newStorage();
      await storage.saveSession(
        accessToken: 'old_access',
        refreshToken: 'old_refresh',
        accessExpiresAt: DateTime.now(),
        refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
      );
      final repo = _FakeAuthRepository(
        fetchProfileResult: _staffUser(),
        refreshResult: RefreshResult(
          accessToken: 'new_access',
          refreshToken: 'new_refresh',
          accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
          refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
        ),
      );
      final controller = AuthController(repo, storage);
      await Future<void>.delayed(const Duration(milliseconds: 20));

      final results = await Future.wait([
        controller.refreshAccessToken(),
        controller.refreshAccessToken(),
        controller.refreshAccessToken(),
      ]);

      expect(results, everyElement('new_access'));
      expect(repo.refreshCalls, 1, reason: 'three concurrent callers must share one in-flight refresh call');
    });

    test('5. invalid/expired refresh token -> refreshAccessToken reports failure (null), never throws', () async {
      final storage = _newStorage();
      await storage.saveSession(
        accessToken: 'old_access',
        refreshToken: 'stale_refresh',
        accessExpiresAt: DateTime.now(),
        refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
      );
      final repo = _FakeAuthRepository(
        fetchProfileResult: _staffUser(),
        refreshError: const RefreshTokenInvalid('Sesi telah tamat.'),
      );
      final controller = AuthController(repo, storage);
      await Future<void>.delayed(const Duration(milliseconds: 20));
      expect(controller.state.status, AuthStatus.authenticated, reason: 'session restore must succeed first so this exercises refresh, not restore, failing');

      final result = await controller.refreshAccessToken();

      expect(result, isNull);
      expect(repo.refreshCalls, 1);
    });

    test('5b. forceLogout clears the session and surfaces a user-facing message', () async {
      final storage = _newStorage();
      await storage.saveSession(
        accessToken: 'a',
        refreshToken: 'r',
        accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
        refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
      );
      final repo = _FakeAuthRepository(fetchProfileResult: _staffUser());
      final controller = AuthController(repo, storage);
      await Future<void>.delayed(const Duration(milliseconds: 20));
      expect(controller.state.status, AuthStatus.authenticated);

      controller.forceLogout();
      await Future<void>.delayed(Duration.zero);

      expect(controller.state.status, AuthStatus.unauthenticated);
      expect(controller.state.user, isNull);
      expect(controller.state.errorMessage, isNotNull);
      expect(await storage.accessToken, isNull);
      expect(await storage.refreshToken, isNull);
    });

    test('logout revokes the session server-side then always clears local storage', () async {
      final storage = _newStorage();
      await storage.saveSession(
        accessToken: 'a',
        refreshToken: 'r',
        accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
        refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
      );
      final repo = _FakeAuthRepository(fetchProfileResult: _staffUser());
      final controller = AuthController(repo, storage);
      await Future<void>.delayed(const Duration(milliseconds: 20));

      await controller.logout();

      expect(repo.logoutCalls, 1);
      expect(controller.state.status, AuthStatus.unauthenticated);
      expect(await storage.accessToken, isNull);
    });
  });

  group('MockAuthRepository refresh (mock/demo mode parity)', () {
    test('9c. an unrecognized/reused-looking refresh token is rejected', () async {
      final repo = MockAuthRepository();
      expect(
        () => repo.refresh(refreshToken: 'not_a_token_this_mock_ever_issued'),
        throwsA(isA<RefreshTokenInvalid>()),
      );
    });

    test('a freshly-issued mock refresh token is accepted and rotated', () async {
      final repo = MockAuthRepository();
      final login = await repo.login(usernameOrEmail: 'ahmad.rozahari', password: 'Passw0rd!123');
      final rotated = await repo.refresh(refreshToken: login.refreshToken);
      expect(rotated.accessToken, isNot(login.accessToken));
      expect(rotated.refreshToken, isNot(login.refreshToken));
    });
  });

  group('StaffUser / AppBranding parsing (real backend shape)', () {
    test('8. StaffUser.fromJson maps the real {user,property} shape, including branding', () {
      final user = _staffUser(propertyId: '3');
      expect(user.propertyId, '3');
      expect(user.name, 'Ahmad Rozahari');
      expect(user.role, 'staff');
      expect(user.username, 'ahmad.rozahari');
      expect(user.branding.propertyName, 'V23 Malawa Ria Apartment');
      expect(user.branding.managementCompanyName, 'Malawa Property Management Sdn Bhd');
      expect(user.branding.primaryColor, const Color(0xFF0B5FFF));
      expect(user.branding.secondaryColor, const Color(0xFF12B76A));
      // Root-relative logo_url must be resolved to a fully-qualified URL.
      expect(user.branding.propertyLogoUrl, startsWith('http'));
      expect(user.branding.propertyLogoUrl, endsWith('/cpms/uploads/branding/v23_logo.png'));
    });

    test('missing/empty property object falls back safely instead of throwing', () {
      final user = StaffUser.fromJson({
        'user': {'id': 5, 'name': 'Someone', 'role': 'staff'},
      });
      expect(user.propertyId, '');
      expect(user.branding.propertyName, AppBranding.fallback().propertyName);
    });
  });

  group('ApiClient (scripted HTTP, no real network)', () {
    late _ScriptedAdapter adapter;
    late ApiClient client;
    late SecureStorageService storage;

    setUp(() async {
      adapter = _ScriptedAdapter();
      storage = _newStorage();
      await storage.saveSession(
        accessToken: 'expiring_access',
        refreshToken: 'valid_refresh',
        accessExpiresAt: DateTime.now().add(const Duration(minutes: 1)),
        refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
      );
      final dio = Dio(BaseOptions(baseUrl: 'https://cpms.test'))..httpClientAdapter = adapter;
      client = ApiClient(secureStorage: storage, dio: dio);
    });

    test('3. an authenticated request unwraps the {ok,data} envelope and attaches the bearer token', () async {
      adapter.enqueue(200, {
        'ok': true,
        'data': {
          'user': {'id': 10, 'name': 'Ahmad Rozahari', 'username': 'ahmad.rozahari', 'role': 'staff'},
          'property': {'id': 3, 'code': 'V23', 'name': 'V23'},
        },
      });

      final result = await client.request(
        (dio) => dio.get(ApiEndpoints.staffProfile),
        (data) => (data as Map<String, dynamic>)['user']['name'] as String,
      );

      expect(result, 'Ahmad Rozahari');
      expect(adapter.requests.single.headers['Authorization'], 'Bearer expiring_access');
    });

    test('4. expired access token: 401 -> exactly one refresh -> original request retried and succeeds', () async {
      var refreshCalls = 0;
      client.onTokenRefreshNeeded = () async {
        refreshCalls++;
        await storage.saveRefreshedSession(
          accessToken: 'refreshed_access',
          refreshToken: 'refreshed_refresh',
          accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
          refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
        );
        return 'refreshed_access';
      };
      var sessionExpiredCalls = 0;
      client.onSessionExpired = () async => sessionExpiredCalls++;

      adapter.enqueue(401, {
        'ok': false,
        'error': {'code': 'SESSION_EXPIRED', 'message': 'Sesi telah tamat.'},
      });
      adapter.enqueue(200, {
        'ok': true,
        'data': {'ok': true},
      });

      final result = await client.request(
        (dio) => dio.get(ApiEndpoints.staffProfile),
        (data) => (data as Map<String, dynamic>)['ok'] as bool,
      );

      expect(result, isTrue);
      expect(refreshCalls, 1);
      expect(sessionExpiredCalls, 0);
      expect(adapter.requests, hasLength(2));
      expect(adapter.requests[0].headers['Authorization'], 'Bearer expiring_access');
      expect(adapter.requests[1].headers['Authorization'], 'Bearer refreshed_access',
          reason: 'the retried request must use the NEW token, not the stale one');
    });

    test('4b. no infinite loop: a 401 on the retried request calls onSessionExpired exactly once, not a second refresh', () async {
      var refreshCalls = 0;
      client.onTokenRefreshNeeded = () async {
        refreshCalls++;
        return 'refreshed_access_that_still_gets_rejected';
      };
      var sessionExpiredCalls = 0;
      client.onSessionExpired = () async => sessionExpiredCalls++;

      // Every call 401s, including the retry.
      adapter.enqueue(401, {
        'ok': false,
        'error': {'code': 'SESSION_EXPIRED', 'message': 'Sesi telah tamat.'},
      });
      adapter.enqueue(401, {
        'ok': false,
        'error': {'code': 'SESSION_EXPIRED', 'message': 'Sesi telah tamat.'},
      });

      await expectLater(
        client.request((dio) => dio.get(ApiEndpoints.staffProfile), (data) => data),
        throwsA(isA<ApiException>()),
      );

      expect(refreshCalls, 1, reason: 'the retried request must not trigger a second refresh attempt');
      expect(sessionExpiredCalls, 1);
      expect(adapter.requests, hasLength(2));
    });

    test('the login call itself is never routed through the refresh interceptor on a 401', () async {
      var refreshCalls = 0;
      client.onTokenRefreshNeeded = () async {
        refreshCalls++;
        return 'irrelevant';
      };
      adapter.enqueue(401, {
        'ok': false,
        'error': {'code': 'INVALID_CREDENTIALS', 'message': 'Username atau kata laluan tidak sah.'},
      });

      await expectLater(
        client.request(
          (dio) => dio.post(ApiEndpoints.login, data: {'username': 'x', 'password': 'y'}),
          (data) => data,
        ),
        throwsA(isA<ApiException>()),
      );

      expect(refreshCalls, 0);
      expect(adapter.requests, hasLength(1));
    });

    test('error envelope message is extracted from the nested error.message field', () async {
      adapter.enqueue(422, {
        'ok': false,
        'error': {'code': 'FIELDS_REQUIRED', 'message': 'Sila lengkapkan semua medan wajib.'},
      });

      try {
        await client.request((dio) => dio.post(ApiEndpoints.dailyWork, data: {}), (data) => data);
        fail('expected an ApiException');
      } on ApiException catch (e) {
        expect(e.message, 'Sila lengkapkan semua medan wajib.');
        expect(e.type, ApiFailureType.validation);
      }
    });

    test('7. login never sends property_id or staff_id as a trusted identity field', () async {
      adapter.enqueue(200, {
        'ok': true,
        'data': {
          'access_token': 'a',
          'refresh_token': 'r',
          'expires_in': 3600,
          'refresh_expires_in': 2592000,
          'user': {'id': 10, 'name': 'Ahmad Rozahari', 'role': 'staff'},
          'property': {'id': 3, 'code': 'V23', 'name': 'V23'},
        },
      });

      await client.request(
        (dio) => dio.post(ApiEndpoints.login, data: {'username': 'ahmad.rozahari', 'password': 'Passw0rd!123'}),
        (data) => data,
      );

      final sentBody = adapter.requests.single.jsonBody;
      expect(sentBody.containsKey('property_id'), isFalse);
      expect(sentBody.containsKey('staff_id'), isFalse);
    });
  });
}

class _CapturedRequest {
  _CapturedRequest(this.method, this.path, this.headers, this.jsonBody);
  final String method;
  final String path;
  final Map<String, String> headers;
  final Map<String, dynamic> jsonBody;
}

/// A tiny scripted [HttpClientAdapter]: each call to [enqueue] queues one
/// canned JSON response, consumed in order as requests come in. Avoids
/// pulling in a mocking package just to script a handful of HTTP
/// round-trips for the interceptor tests above.
class _ScriptedAdapter implements HttpClientAdapter {
  final List<(int, Map<String, dynamic>)> _queue = [];
  final List<_CapturedRequest> requests = [];

  void enqueue(int statusCode, Map<String, dynamic> jsonBody) {
    _queue.add((statusCode, jsonBody));
  }

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final bytes = <int>[];
    if (requestStream != null) {
      await for (final chunk in requestStream) {
        bytes.addAll(chunk);
      }
    }
    Map<String, dynamic> jsonBody = const {};
    if (bytes.isNotEmpty) {
      final decoded = jsonDecode(utf8.decode(bytes));
      if (decoded is Map<String, dynamic>) jsonBody = decoded;
    }
    requests.add(_CapturedRequest(
      options.method,
      options.path,
      options.headers.map((k, v) => MapEntry(k, v.toString())),
      jsonBody,
    ));

    if (_queue.isEmpty) {
      throw StateError('No scripted response queued for ${options.method} ${options.path}');
    }
    final (status, body) = _queue.removeAt(0);
    return ResponseBody.fromString(
      jsonEncode(body),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
