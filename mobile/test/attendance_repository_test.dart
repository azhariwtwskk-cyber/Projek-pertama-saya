// Phase M1 (Attendance History & Server Status Integration) repository-level
// coverage: verifies `ApiAttendanceRepository.fetchHistory()`/`fetchStatus()`
// actually call the real endpoints with the right parameters, and — the
// core regression this phase fixes — that a failed/malformed response is
// never silently swallowed into an empty history. Uses the same scripted
// fake Dio adapter pattern as auth_stage1_test.dart (no real network, no
// mocking package needed).

import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:cpmspro_workforce/core/api/api_client.dart';
import 'package:cpmspro_workforce/core/api/api_endpoints.dart';
import 'package:cpmspro_workforce/core/api/api_exception.dart';
import 'package:cpmspro_workforce/core/storage/secure_storage_service.dart';
import 'package:cpmspro_workforce/features/attendance/data/api_attendance_repository.dart';
import 'package:cpmspro_workforce/features/attendance/domain/attendance_models.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';

/// In-memory secure storage so tests never touch a platform channel.
class _InMemorySecureStorage extends FlutterSecureStoragePlatform {
  final Map<String, String> _values = {};

  @override
  Future<bool> containsKey(
          {required String key, required Map<String, String> options}) async =>
      _values.containsKey(key);

  @override
  Future<void> delete(
      {required String key, required Map<String, String> options}) async {
    _values.remove(key);
  }

  @override
  Future<void> deleteAll({required Map<String, String> options}) async =>
      _values.clear();

  @override
  Future<String?> read(
          {required String key, required Map<String, String> options}) async =>
      _values[key];

  @override
  Future<Map<String, String>> readAll(
          {required Map<String, String> options}) async =>
      Map.of(_values);

  @override
  Future<void> write(
      {required String key,
      required String value,
      required Map<String, String> options}) async {
    _values[key] = value;
  }
}

SecureStorageService _newStorage() {
  FlutterSecureStoragePlatform.instance = _InMemorySecureStorage();
  return SecureStorageService();
}

class _CapturedRequest {
  _CapturedRequest(this.method, this.path, this.queryParameters);
  final String method;
  final String path;
  final Map<String, dynamic> queryParameters;
}

/// A tiny scripted [HttpClientAdapter]: each call to [enqueue] queues one
/// canned response (JSON body + status), consumed in order.
class _ScriptedAdapter implements HttpClientAdapter {
  final List<(int, Object)> _queue = [];
  final List<_CapturedRequest> requests = [];

  void enqueue(int statusCode, Object jsonBody) {
    _queue.add((statusCode, jsonBody));
  }

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(_CapturedRequest(
        options.method, options.path, options.queryParameters));

    if (_queue.isEmpty) {
      throw StateError(
          'No scripted response queued for ${options.method} ${options.path}');
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

void main() {
  group('ApiAttendanceRepository (scripted HTTP, no real network)', () {
    late _ScriptedAdapter adapter;
    late ApiAttendanceRepository repository;

    setUp(() async {
      adapter = _ScriptedAdapter();
      final storage = _newStorage();
      await storage.saveSession(
        accessToken: 'test_access',
        refreshToken: 'test_refresh',
        accessExpiresAt: DateTime.now().add(const Duration(hours: 1)),
        refreshExpiresAt: DateTime.now().add(const Duration(days: 30)),
      );
      final dio = Dio(BaseOptions(baseUrl: 'https://cpms.test'))
        ..httpClientAdapter = adapter;
      final client = ApiClient(secureStorage: storage, dio: dio);
      repository = ApiAttendanceRepository(client);
    });

    test(
        'TEST 9: fetchHistory(year: 2026, month: 9) calls history.php with '
        'year=2026&month=9, not month=8', () async {
      adapter.enqueue(200, {
        'ok': true,
        'data': {
          'summary': {
            'days_worked': 0,
            'total_hours': 0,
            'late_arrivals': 0,
            'overtime_hours': 0
          },
          'records': <dynamic>[],
        },
      });

      await repository.fetchHistory(year: 2026, month: 9);

      final request = adapter.requests.single;
      expect(request.method, 'GET');
      expect(request.path, ApiEndpoints.attendanceHistory);
      expect(request.queryParameters['year'], 2026);
      expect(request.queryParameters['month'], 9,
          reason: 'Dart DateTime.month is already 1-based; September must '
              'be sent as 9, never 8 (a 0-based off-by-one).');
    });

    test(
        'TEST 8: a malformed/error API response propagates as an error — '
        'it must NOT silently resolve to an empty history', () async {
      adapter.enqueue(500, {
        'ok': false,
        'error': {'code': 'SERVER_ERROR', 'message': 'CPMSPro server error.'},
      });

      await expectLater(
        repository.fetchHistory(year: 2026, month: 9),
        throwsA(isA<ApiException>()),
      );
    });

    test(
        'a 200 response with a structurally malformed payload (records '
        'missing) also propagates as an error, not an empty summary', () async {
      adapter.enqueue(200, {
        'ok': true,
        'data': {
          'summary': {
            'days_worked': 0,
            'total_hours': 0,
            'late_arrivals': 0,
            'overtime_hours': 0
          }
        },
      });

      await expectLater(
        repository.fetchHistory(year: 2026, month: 9),
        throwsA(anything),
      );
    });

    test(
        'fetchStatus() calls status.php and parses a real CLOCKED_IN '
        'response', () async {
      adapter.enqueue(200, {
        'ok': true,
        'data': {
          'attendance_state': 'in',
          'clock_in_at': '2026-09-14 12:09:00',
          'shift_label': '8:00 AM - 5:00 PM',
          'property_name': 'Property A',
        },
      });

      final status = await repository.fetchStatus();

      expect(adapter.requests.single.path, ApiEndpoints.attendanceStatus);
      expect(status.status, ClockStatus.clockedIn);
      expect(status.clockInTime, DateTime.parse('2026-09-14 12:09:00'));
      expect(status.propertyName, 'Property A');
    });

    test(
        'fetchStatus() failure also propagates rather than defaulting to '
        'clocked-out', () async {
      adapter.enqueue(401, {
        'ok': false,
        'error': {'code': 'SESSION_EXPIRED', 'message': 'Sesi telah tamat.'},
      });

      await expectLater(repository.fetchStatus(), throwsA(isA<ApiException>()));
    });
  });
}
