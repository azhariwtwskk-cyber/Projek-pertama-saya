import 'package:flutter/material.dart';

import 'app_config.dart';

/// Branding is never hard-coded per property. It is parsed from the
/// `property` object returned by `POST /cpms/api/v1/auth/login.php` and
/// `GET /cpms/api/v1/me.php` (see [StaffUser.fromJson]) so the same binary
/// can serve every CPMSPro-managed property.
///
/// Stage 1 (see mobile/docs/BACKEND_INTEGRATION_AUDIT.md): confirmed
/// against the real backend that `cpms_properties` has `company_name`,
/// `logo_path` (returned as an already-built `logo_url`), `primary_color`
/// and `secondary_color` columns — there is no separate CPMSPro-wide logo
/// distinct from the property's own, so [cpmsproLogoUrl] has no backend
/// source yet and stays empty until a later stage adds one.
class AppBranding {
  const AppBranding({
    required this.cpmsproLogoUrl,
    required this.propertyLogoUrl,
    required this.propertyName,
    required this.managementCompanyName,
    required this.primaryColor,
    required this.secondaryColor,
  });

  final String cpmsproLogoUrl;
  final String propertyLogoUrl;
  final String propertyName;
  final String managementCompanyName;
  final Color primaryColor;
  final Color secondaryColor;

  /// Sensible fallback shown before the server config has loaded (e.g. on
  /// the very first frame of the login screen, or when offline with no
  /// cached config yet).
  factory AppBranding.fallback() {
    return const AppBranding(
      cpmsproLogoUrl: '',
      propertyLogoUrl: '',
      propertyName: 'CPMSPro Workforce',
      managementCompanyName: 'CPMSPro',
      primaryColor: Color(0xFF0B5FFF),
      secondaryColor: Color(0xFF00B894),
    );
  }

  /// Parses the `property` object as returned by the real backend:
  /// `{id, code, name, company_name, logo_url, primary_color, secondary_color}`.
  factory AppBranding.fromJson(Map<String, dynamic> json) {
    Color parseColor(String? hex, Color fallback) {
      if (hex == null || hex.isEmpty) return fallback;
      final buffer = StringBuffer();
      if (hex.length == 6 || hex.length == 7) buffer.write('ff');
      buffer.write(hex.replaceFirst('#', ''));
      return Color(int.parse(buffer.toString(), radix: 16));
    }

    final fallback = AppBranding.fallback();
    return AppBranding(
      cpmsproLogoUrl: '',
      propertyLogoUrl: _resolveAssetUrl(json['logo_url'] as String?),
      propertyName: json['name'] as String? ?? fallback.propertyName,
      managementCompanyName:
          json['company_name'] as String? ?? fallback.managementCompanyName,
      primaryColor: parseColor(
        json['primary_color'] as String?,
        fallback.primaryColor,
      ),
      secondaryColor: parseColor(
        json['secondary_color'] as String?,
        fallback.secondaryColor,
      ),
    );
  }

  /// The backend returns logo URLs root-relative (e.g. `/cpms/uploads/...`)
  /// rather than fully qualified, so this resolves them against
  /// [AppConfig.apiBaseUrl] — the same origin the API itself is called on.
  static String _resolveAssetUrl(String? path) {
    final trimmed = (path ?? '').trim();
    if (trimmed.isEmpty) return '';
    if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
      return trimmed;
    }
    final base = AppConfig.apiBaseUrl.endsWith('/')
        ? AppConfig.apiBaseUrl.substring(0, AppConfig.apiBaseUrl.length - 1)
        : AppConfig.apiBaseUrl;
    final path0 = trimmed.startsWith('/') ? trimmed : '/$trimmed';
    return '$base$path0';
  }
}
