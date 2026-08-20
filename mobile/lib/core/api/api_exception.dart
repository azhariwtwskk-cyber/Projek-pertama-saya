/// User-facing API failure. Repositories translate raw Dio/Socket errors
/// into one of these so the presentation layer never has to know about
/// transport details, only [ApiFailureType].
class ApiException implements Exception {
  const ApiException(this.type, this.message, {this.statusCode, this.code});

  final ApiFailureType type;
  final String message;
  final int? statusCode;

  /// The real backend's machine-readable `error.code`
  /// (e.g. `OUTSIDE_GEOFENCE`, `ALREADY_CLOCKED_IN`,
  /// `GEOFENCE_NOT_CONFIGURED`) — present whenever the server returned a
  /// structured `{"ok":false,"error":{...}}` envelope, so callers that
  /// need to branch on a specific condition (not just show [message])
  /// don't have to string-match the Malay [message] text.
  final String? code;

  @override
  String toString() => 'ApiException($type, $statusCode, $code): $message';
}

enum ApiFailureType {
  noConnection,
  timeout,
  sessionExpired,
  forbidden,
  notFound,
  validation,
  conflict,
  server,
  unknown,
}
