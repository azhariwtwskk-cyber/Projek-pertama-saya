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
    defaultValue: 'https://cpmspro.my',
  );

  static const String apiVersion = 'v1';

  /// Kept in sync with `pubspec.yaml`'s `version:` field by hand — shown
  /// on the Profile screen's About dialog. Not read from a package-info
  /// plugin to avoid adding a new dependency for one label.
  static const String appVersion = '1.0.0+1';

  /// When true, the app runs entirely against in-memory fixture data
  /// (see core/api/mock) instead of the live Dio-backed API client. This
  /// lets the full UX be demoed and QA'd before backend endpoints exist,
  /// and is what CI/local `flutter run` uses by default. Flip to false
  /// (or override via --dart-define=USE_MOCK_API=false) once the CPMSPro
  /// backend team has published the endpoints in section 32.
  static const bool useMockApi = bool.fromEnvironment(
    'USE_MOCK_API',
    defaultValue: false,
  );

  static const Duration apiConnectTimeout = Duration(seconds: 15);
  static const Duration apiReceiveTimeout = Duration(seconds: 30);

  /// Max long edge (px) for compressed evidence photos before upload.
  static const int evidenceImageMaxDimension = 1600;
  static const int evidenceImageQuality = 78;

  static const int sessionRefreshLeewaySeconds = 60;

  /// Every real CPMSPro endpoint that returns an uploaded-file URL
  /// (`work_order_images`, `daily_work_images`, PM evidence, asset
  /// inspection photos, property branding logos) returns it *root-relative*
  /// (e.g. `/cpms/uploads/daily_work/xxx.jpg`), not as a full URL — the
  /// same convention `cpmsApiSaveImage()`/`task-photo.php` use server-side.
  /// [FullScreenImageViewer] and any `CachedNetworkImage` needs an
  /// absolute URL to tell a *local* file path from a *remote* one, so
  /// every such path must be resolved through this before display.
  static String resolveUrl(String path) {
    final trimmed = path.trim();
    if (trimmed.isEmpty) return trimmed;
    if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
      return trimmed;
    }
    final base = apiBaseUrl.endsWith('/') ? apiBaseUrl.substring(0, apiBaseUrl.length - 1) : apiBaseUrl;
    return base + (trimmed.startsWith('/') ? trimmed : '/$trimmed');
  }
}
