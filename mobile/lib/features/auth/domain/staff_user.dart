import '../../../core/config/app_branding.dart';
import '../../../core/permissions/staff_permissions.dart';

/// Everything the app knows about the signed-in staff member, entirely
/// server-issued at login (section 4/30). `propertyId` in particular is
/// read-only from here on — there is intentionally no setter anywhere in
/// the app, because staff must never be able to switch properties
/// themselves.
class StaffUser {
  const StaffUser({
    required this.userId,
    required this.staffId,
    required this.propertyId,
    required this.name,
    required this.role,
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
  final String employeeId;
  final String phone;
  final String email;
  final String profileImageUrl;
  final StaffPermissions permissions;
  final AppBranding branding;

  factory StaffUser.fromJson(Map<String, dynamic> json) {
    return StaffUser(
      userId: json['user_id'] as String,
      staffId: json['staff_id'] as String,
      propertyId: json['property_id'] as String,
      name: json['staff_name'] as String,
      role: json['role'] as String,
      employeeId: json['employee_id'] as String? ?? json['staff_id'] as String,
      phone: json['phone'] as String? ?? '',
      email: json['email'] as String? ?? '',
      profileImageUrl: json['profile_image'] as String? ?? '',
      permissions: StaffPermissions.fromList(json['permissions'] as List<dynamic>? ?? const []),
      branding: AppBranding.fromJson(
        (json['property_branding'] as Map<String, dynamic>?) ?? const {},
      ),
    );
  }

  StaffUser copyWith({String? profileImageUrl, String? phone, String? email}) => StaffUser(
        userId: userId,
        staffId: staffId,
        propertyId: propertyId,
        name: name,
        role: role,
        employeeId: employeeId,
        phone: phone ?? this.phone,
        email: email ?? this.email,
        profileImageUrl: profileImageUrl ?? this.profileImageUrl,
        permissions: permissions,
        branding: branding,
      );
}
