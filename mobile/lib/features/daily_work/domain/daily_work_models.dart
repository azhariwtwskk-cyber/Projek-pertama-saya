/// Values are chosen to match `staff/daily-work/options.php`'s real
/// category list exactly (the backend doesn't enforce this list
/// server-side — `submit.php` accepts any non-empty string — but sending
/// the same strings the CPMSPro web portal's own dropdown uses keeps
/// reporting/filtering there consistent). See
/// mobile/docs/INTEGRATION_REPAIR_REPORT.md.
enum DailyWorkCategory {
  cleaning,
  maintenance,
  electrical,
  plumbing,
  landscape,
  security,
  fireSafety,
  other
}

extension DailyWorkCategoryLabel on DailyWorkCategory {
  String get label {
    switch (this) {
      case DailyWorkCategory.cleaning:
        return 'Cleaning';
      case DailyWorkCategory.maintenance:
        return 'Maintenance';
      case DailyWorkCategory.electrical:
        return 'Electrical';
      case DailyWorkCategory.plumbing:
        return 'Plumbing';
      case DailyWorkCategory.landscape:
        return 'Landscape';
      case DailyWorkCategory.security:
        return 'Security';
      case DailyWorkCategory.fireSafety:
        return 'Fire Safety';
      case DailyWorkCategory.other:
        return 'Other';
    }
  }
}

enum DailyWorkStatus { draft, submitted, verified, rejected }

class DailyWorkEntry {
  const DailyWorkEntry({
    required this.id,
    required this.title,
    required this.category,
    required this.location,
    required this.description,
    required this.startTime,
    this.completionTime,
    this.photoCount = 0,
    this.photoUrls = const [],
    this.remarks,
    this.status = DailyWorkStatus.submitted,
    this.supervisorRemarks,
  });

  final String id;
  final String title;
  final DailyWorkCategory category;
  final String location;
  final String description;
  final DateTime startTime;
  final DateTime? completionTime;
  final int photoCount;
  final List<String> photoUrls;
  final String? remarks;
  final DailyWorkStatus status;

  /// The Property Admin's own remarks/rejection reason from Daily Work
  /// Review (`cpms/property_portal/daily_work_review.php`) — populated
  /// whenever [status] is [DailyWorkStatus.rejected] or
  /// [DailyWorkStatus.verified] with a note attached.
  final String? supervisorRemarks;

  bool get isReadOnly => status == DailyWorkStatus.verified;

  Duration? get duration => completionTime?.difference(startTime);
}
