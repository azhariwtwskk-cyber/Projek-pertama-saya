import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';

import '../config/app_config.dart';
import '../storage/secure_storage_service.dart';
import 'api_endpoints.dart';
import 'api_exception.dart';

/// Thin Dio wrapper shared by every repository. Responsibilities:
///  - attach the bearer token to every request
///  - transparently refresh an expiring token before it's used
///  - force logout on 401 the refresh couldn't fix
///  - translate transport errors into [ApiException]
///  - unwrap the real backend's `{"ok":true,"data":{...}}` response
///    envelope so every repository's parse callback can keep assuming
///    the value it receives IS the payload
///
/// IMPORTANT: property_id/staff_id/role/permissions are never sent by the
/// client to scope a request — every staff-scoped endpoint infers them
/// server-side from the bearer token (see section 30/31/32). The client
/// only ever *reads* those values back from server responses.
class ApiClient {
  ApiClient({required SecureStorageService secureStorage, Dio? dio})
      : _secureStorage = secureStorage,
        _dio = dio ??
            Dio(BaseOptions(
              baseUrl: AppConfig.apiBaseUrl,
              connectTimeout: AppConfig.apiConnectTimeout,
              receiveTimeout: AppConfig.apiReceiveTimeout,
              headers: {'Accept': 'application/json'},
            )) {
    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: _onRequest,
      onError: _onError,
    ));
  }

  final Dio _dio;
  final SecureStorageService _secureStorage;

  /// Paths that must never trigger the automatic 401 -> refresh -> retry
  /// flow below: refreshing a refresh call that itself 401s would recurse,
  /// and a 401 from login/logout means "invalid credentials" / "already
  /// logged out," never "this access token needs refreshing."
  static const Set<String> _authPathsExcludedFromAutoRefresh = {
    ApiEndpoints.login,
    ApiEndpoints.logout,
    ApiEndpoints.refreshToken,
  };

  /// Called on a 401 to obtain a fresh access token; returns null if the
  /// session can't be refreshed (no stored refresh token, or the refresh
  /// call itself failed) — see `auth_providers.dart` for the real
  /// implementation, which also guarantees at most one refresh call is
  /// ever in flight at a time.
  Future<String?> Function()? onTokenRefreshNeeded;
  Future<void> Function()? onSessionExpired;

  Future<void> _onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final token = await _secureStorage.accessToken;
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  Future<void> _onError(DioException err, ErrorInterceptorHandler handler) async {
    final alreadyRetried = err.requestOptions.extra['cpmspro_retried_after_refresh'] == true;
    final isExcludedPath = _authPathsExcludedFromAutoRefresh.contains(err.requestOptions.path);

    if (err.response?.statusCode == 401 && !alreadyRetried && !isExcludedPath) {
      if (onTokenRefreshNeeded != null) {
        final newToken = await onTokenRefreshNeeded!();
        if (newToken != null) {
          final retryOptions = err.requestOptions;
          retryOptions.headers['Authorization'] = 'Bearer $newToken';
          // Marks this exact request as already-retried so a second 401
          // (e.g. the "fresh" token turns out to be rejected too) falls
          // straight through to onSessionExpired instead of looping.
          retryOptions.extra['cpmspro_retried_after_refresh'] = true;
          try {
            final response = await _dio.fetch(retryOptions);
            return handler.resolve(response);
          } catch (_) {
            // fall through to session-expired handling below
          }
        }
      }
      await onSessionExpired?.call();
    }
    handler.next(err);
  }

  Future<T> request<T>(
    Future<Response<dynamic>> Function(Dio dio) call,
    T Function(dynamic data) parse,
  ) async {
    try {
      final response = await call(_dio);
      return parse(_unwrapEnvelope(response.data));
    } on DioException catch (e) {
      throw _mapDioException(e);
    } on SocketException {
      throw const ApiException(ApiFailureType.noConnection, 'No internet connection.');
    }
  }

  Dio get raw => _dio;

  /// Every `cpms/api/v1` endpoint wraps its payload as
  /// `{"ok":true,"data":{...}}` on success — unwrap once, here.
  dynamic _unwrapEnvelope(dynamic raw) {
    if (raw is Map<String, dynamic> && raw.containsKey('data')) {
      return raw['data'];
    }
    return raw;
  }

  ApiException _mapDioException(DioException e) {
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return const ApiException(ApiFailureType.timeout, 'The request timed out.');
      case DioExceptionType.connectionError:
        return const ApiException(ApiFailureType.noConnection, 'No internet connection.');
      case DioExceptionType.badResponse:
        final status = e.response?.statusCode ?? 0;
        final serverMessage = _extractMessage(e.response?.data);
        if (status == 401) {
          return ApiException(ApiFailureType.sessionExpired, serverMessage ?? 'Session expired.', statusCode: status);
        }
        if (status == 403) {
          return ApiException(ApiFailureType.forbidden, serverMessage ?? 'You do not have access to this resource.', statusCode: status);
        }
        if (status == 404) {
          return ApiException(ApiFailureType.notFound, serverMessage ?? 'Not found.', statusCode: status);
        }
        if (status == 422 || status == 400) {
          return ApiException(ApiFailureType.validation, serverMessage ?? 'Please check the submitted information.', statusCode: status);
        }
        return ApiException(ApiFailureType.server, serverMessage ?? 'CPMSPro server error.', statusCode: status);
      case DioExceptionType.cancel:
        return const ApiException(ApiFailureType.unknown, 'Request cancelled.');
      case DioExceptionType.badCertificate:
        return const ApiException(ApiFailureType.unknown, 'Secure connection could not be verified.');
      case DioExceptionType.unknown:
      default:
        return const ApiException(ApiFailureType.noConnection, 'Unable to connect to CPMSPro.');
    }
  }

  /// The real backend's error envelope is `{"ok":false,"error":{"code":
  /// "...","message":"...","details"?:{...}}}` — fall back to a flat
  /// `message` field defensively for anything that doesn't follow it.
  String? _extractMessage(dynamic data) {
    if (data is Map<String, dynamic>) {
      final error = data['error'];
      if (error is Map<String, dynamic>) {
        return error['message'] as String?;
      }
      return data['message'] as String?;
    }
    return null;
  }
}
