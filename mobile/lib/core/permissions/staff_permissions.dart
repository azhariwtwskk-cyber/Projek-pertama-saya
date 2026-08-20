/// Permission keys as issued by CPMSPro on login (section 39). These are
/// used ONLY to decide what the UI shows/hides — every mutating endpoint
/// must independently re-check the same permission server-side, so hiding
/// a button here is a UX convenience, never the actual access control.
class StaffPermission {
  const StaffPermission._();

  static const String dashboardView = 'staff.dashboard.view';
  static const String workOrderView = 'staff.workorder.view';
  static const String workOrderUpdate = 'staff.workorder.update';
  static const String dailyWorkCreate = 'staff.dailywork.create';
  static const String pmView = 'staff.pm.view';
  static const String pmComplete = 'staff.pm.complete';
  static const String attendanceClock = 'staff.attendance.clock';
  static const String assetView = 'staff.asset.view';
  static const String notificationView = 'staff.notification.view';
}

/// Convenience wrapper around the flat permission list returned by the
/// server so feature code reads `permissions.can(StaffPermission.pmView)`
/// instead of re-implementing `.contains` everywhere.
class StaffPermissions {
  const StaffPermissions(this._granted);

  final Set<String> _granted;

  factory StaffPermissions.fromList(List<dynamic> raw) =>
      StaffPermissions(raw.map((e) => e.toString()).toSet());

  /// CRITICAL: the real `auth/login.php`/`me.php` never return a
  /// `permissions[]` array at all (see
  /// mobile/docs/INTEGRATION_REPAIR_REPORT.md) — [StaffUser.fromJson]
  /// always constructs this with an empty list for every real login.
  /// Treating "empty" as "deny everything" (the original behaviour) hid
  /// every permission-gated quick action — Add Daily Work, PM Tasks, Scan
  /// QR — behind a permission the backend can never grant, making those
  /// features completely unreachable from Home for every real staff
  /// account even though the endpoints themselves work fine and are
  /// already independently authorized server-side per the class doc
  /// above. An empty list therefore means "the server hasn't told us
  /// anything," which defaults to allow, not deny; a genuinely non-empty
  /// list (mock data today, real data if the backend adds this later)
  /// still restricts normally.
  bool can(String permission) =>
      _granted.isEmpty || _granted.contains(permission);

  List<String> get all => _granted.toList(growable: false);
}
