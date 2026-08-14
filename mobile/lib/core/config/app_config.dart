/// Central runtime configuration. Values are safe to compile in; secrets
/// (API keys, Firebase config) are never hard-coded here and are supplied
/// via `--dart-define` at build time per environment.
class AppConfig {
  const AppConfig._();

  /// Site root the CPMSPro backend is deployed under — the real API lives
  /// at `<apiBaseUrl>/cpms/api/v1/...php` (see [ApiEndpoints]), so this is
  /// the plain domain (e.g. https://yourdomain.com), not an "api."
  /// subdomain or anything with `/cpms` already appended.
  static const String apiBaseUrl = String.fromEnvironment(
    'CPMSPRO_API_BASE_URL',
    defaultValue: 'https://cpmspro.example.com',
  );

  static const String apiVersion = 'v1';

  /// When true, the app runs entirely against in-memory fixture data
  /// (see core/api/mock) instead of the live Dio-backed API client. This
  /// lets the full UX be demoed and QA'd before backend endpoints exist,
  /// and is what CI/local `flutter run` uses by default. Flip to false
  /// (or override via --dart-define=USE_MOCK_API=false) once the CPMSPro
  /// backend team has published the endpoints in section 32.
  static const bool useMockApi = bool.fromEnvironment(
    'USE_MOCK_API',
    defaultValue: true,
  );

  static const Duration apiConnectTimeout = Duration(seconds: 15);
  static const Duration apiReceiveTimeout = Duration(seconds: 30);

  /// Max long edge (px) for compressed evidence photos before upload.
  static const int evidenceImageMaxDimension = 1600;
  static const int evidenceImageQuality = 78;

  static const int sessionRefreshLeewaySeconds = 60;
}
