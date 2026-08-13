import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';

import '../config/app_config.dart';
import '../storage/secure_storage_service.dart';
import 'api_exception.dart';

/// Thin Dio wrapper shared by every repository. Responsibilities:
///  - attach the bearer token to every request
///  - transparently refresh an expiring token before it's used
///  - force logout on 401 the refresh couldn't fix
///  - translate transport errors into [ApiException]
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

  /// Called by the auth repository when the server issues a new access
  /// token; kept as a hook point so a future refresh-token flow can plug
  /// in without touching every call site.
  Future<String?> Function()? onTokenRefreshNeeded;
  Future<void> Function()? onSessionExpired;

  Future<void> _onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final token = await _secureStorage.accessToken;
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    final deviceSessionId = await _secureStorage.deviceSessionId;
    if (deviceSessionId != null) {
      options.headers['X-Device-Session-Id'] = deviceSessionId;
    }
    handler.next(options);
  }

  Future<void> _onError(DioException err, ErrorInterceptorHandler handler) async {
    if (err.response?.statusCode == 401) {
      if (onTokenRefreshNeeded != null) {
        final newToken = await onTokenRefreshNeeded!();
        if (newToken != null) {
          final retryOptions = err.requestOptions;
          retryOptions.headers['Authorization'] = 'Bearer $newToken';
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
      return parse(response.data);
    } on DioException catch (e) {
      throw _mapDioException(e);
    } on SocketException {
      throw const ApiException(ApiFailureType.noConnection, 'No internet connection.');
    }
  }

  Dio get raw => _dio;

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

  String? _extractMessage(dynamic data) {
    if (data is Map<String, dynamic>) {
      return data['message'] as String? ?? data['error'] as String?;
    }
    return null;
  }
}
