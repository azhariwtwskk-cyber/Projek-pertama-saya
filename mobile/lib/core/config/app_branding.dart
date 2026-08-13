import 'package:flutter/material.dart';

/// Branding is never hard-coded per property. It is always fetched from
/// `GET /api/v1/app/config` (and refreshed as part of the dashboard/profile
/// payload) so the same binary can serve every CPMSPro-managed property.
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
      cpmsproLogoUrl: json['cpmspro_logo_url'] as String? ?? '',
      propertyLogoUrl: json['property_logo_url'] as String? ?? '',
      propertyName: json['property_name'] as String? ?? fallback.propertyName,
      managementCompanyName: json['management_company_name'] as String? ??
          fallback.managementCompanyName,
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
}
