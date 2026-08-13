/// User-facing API failure. Repositories translate raw Dio/Socket errors
/// into one of these so the presentation layer never has to know about
/// transport details, only [ApiFailureType].
class ApiException implements Exception {
  const ApiException(this.type, this.message, {this.statusCode});

  final ApiFailureType type;
  final String message;
  final int? statusCode;

  @override
  String toString() => 'ApiException($type, $statusCode): $message';
}

enum ApiFailureType {
  noConnection,
  timeout,
  sessionExpired,
  forbidden,
  notFound,
  validation,
  server,
  unknown,
}
