import '../../../core/config/app_branding.dart';
import '../../../core/permissions/staff_permissions.dart';

/// Everything the app knows about the signed-in staff member.
/// `propertyId` in particular is read-only from here on — there is
/// intentionally no setter anywhere in the app, because staff must never
/// be able to switch properties themselves; it is always the `property.id`
/// the server resolved from the authenticated token, never anything the
/// client sent (see section 30/31 of the original spec).
///
/// Stage 1 (see mobile/docs/BACKEND_INTEGRATION_AUDIT.md): the real
/// `POST /cpms/api/v1/auth/login.php` and `GET /cpms/api/v1/me.php`
/// responses only carry `{user:{id,name,role,username?}, property:{...}}`
/// — there is no `staff_id` distinct from the account id, no
/// `employee_id`, `phone`, `email`, `profile_image` (the `staff` table
/// doesn't have those columns yet) or `permissions[]` list. Those fields
/// are kept on this model (so the screens already built against them in
/// later stages keep compiling) but default to an empty/placeholder value
/// until a later stage adds real backend support — see the audit's
/// per-stage plan. In particular, [permissions] is empty for every real
/// login today, which means every permission-gated quick action on the
/// dashboard is currently hidden for a real (non-mock) session — a known,
/// documented Stage 1 limitation, not a bug.
class StaffUser {
  const StaffUser({
    required this.userId,
    required this.staffId,
    required this.propertyId,
    required this.name,
    required this.role,
    required this.username,
    required this.employeeId,
    required this.phone,
    required this.email,
    required this.profileImageUrl,
    required this.permissions,
    required this.branding,
  });

  final String userId;
  final String staffId;
  final String propertyId;
  final String name;
  final String role;
  final String username;
  final String employeeId;
  final String phone;
  final String email;
  final String profileImageUrl;
  final StaffPermissions permissions;
  final AppBranding branding;

  /// Parses the real backend shape: `{user:{id,name,role,username?},
  /// property:{id,code,name,company_name,logo_url,primary_color,
  /// secondary_color}}`, returned (nested inside the `{ok,data}` envelope,
  /// already unwrapped by [ApiClient]) by both the login and `/me`
  /// endpoints.
  factory StaffUser.fromJson(Map<String, dynamic> json) {
    final user = (json['user'] as Map<String, dynamic>?) ?? const {};
    final property = (json['property'] as Map<String, dynamic>?) ?? const {};
    final userId = (user['id'] ?? '').toString();
    return StaffUser(
      userId: userId,
      // No distinct staff_id is returned yet — the account id is the
      // closest stable identifier available until a later stage exposes
      // the real staff.id (see class doc).
      staffId: userId,
      propertyId: (property['id'] ?? '').toString(),
      name: user['name'] as String? ?? '',
      role: user['role'] as String? ?? '',
      username: user['username'] as String? ?? '',
      employeeId: userId,
      phone: '',
      email: '',
      profileImageUrl: '',
      permissions: StaffPermissions.fromList(const []),
      branding: AppBranding.fromJson(property),
    );
  }

  StaffUser copyWith({String? profileImageUrl, String? phone, String? email}) => StaffUser(
        userId: userId,
        staffId: staffId,
        propertyId: propertyId,
        name: name,
        role: role,
        username: username,
        employeeId: employeeId,
        phone: phone ?? this.phone,
        email: email ?? this.email,
        profileImageUrl: profileImageUrl ?? this.profileImageUrl,
        permissions: permissions,
        branding: branding,
      );
}
